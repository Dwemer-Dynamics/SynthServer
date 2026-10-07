<?php

require_once __DIR__.'/synth_protocol_state.php';

// Match the existing 384-dimensional embedding service without accepting SQL-shaped or nonfinite values.
function synth_memory_vector_json(array $vector): string
{
    if (!array_is_list($vector) || count($vector)!==384) throw new InvalidArgumentException('Invalid memory vector dimensions');
    foreach ($vector as $value) {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs($value)>3.402823466e38) {
            throw new InvalidArgumentException('Invalid memory vector value');
        }
    }
    return json_encode($vector,JSON_THROW_ON_ERROR);
}

// Optional enrichment may fail without losing accepted text, but cancellation must still fence publication.
function synth_memory_optional_embedding(string $text, ?callable $embed): ?array
{
    try {
        $embedding = $embed === null ? null : $embed($text);
        if ($embedding !== null) synth_memory_vector_json($embedding);
        return $embedding;
    } catch (SYNTHProtocolCancelled $error) {
        throw $error;
    } catch (Throwable $error) {
        Logger::warn('Native memory embedding unavailable; retaining text recall.');
        return null;
    }
}

// Queue only a fully visible frozen batch; current actor/request ownership is checked under both locks.
function synth_memory_enqueue($db, int $batchId): ?int
{
    return $db->withNativeRequestLock(static function ($connection, array $event) use ($db,$batchId) {
        $result = @pg_query($connection,synth_memory_visible_batch_cte($db,$event)
            .'SELECT id,actor_identity_id FROM native_memory_batches WHERE id='.intval($batchId));
        $batch = $result ? pg_fetch_assoc($result) : false;
        if (!$result) throw new RuntimeException('Unable to resolve memory job input');
        if (!$batch) return null;
        if (@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-memory:'||$1,0))",[$batch['actor_identity_id']]) === false) {
            throw new RuntimeException('Unable to lock memory job owner');
        }
        // The initial read precedes the actor lock; an archive may have committed while we waited.
        $result=@pg_query($connection,synth_memory_visible_batch_cte($db,$event)
            .'SELECT id FROM native_memory_batches WHERE id='.intval($batchId));
        if (!$result) throw new RuntimeException('Unable to recheck memory input');
        if (!pg_fetch_assoc($result)) return null;
        $result = @pg_query_params($connection,
            "UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp()
             WHERE actor_identity_id=$1 AND job_type='memory' AND memory_batch_id IS NOT NULL AND state IN ('pending','running')
                AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",[$batch['actor_identity_id']]);
        if (!$result) throw new RuntimeException('Unable to retire expired memory jobs');
        $result = @pg_query_params($connection,
            "INSERT INTO synth_meta.worker_jobs (job_type,session_id,request_id,turn_id,generation,actor_identity_id,
                memory_batch_id,payload,deadline_at)
             SELECT 'memory',$1,$2,$3,$4,$5,$6,jsonb_build_object('context_sequence',$7::bigint),clock_timestamp()+INTERVAL '10 minutes'
             WHERE NOT EXISTS (SELECT 1 FROM synth_meta.memory_summaries WHERE batch_id=$6)
               AND NOT EXISTS (SELECT 1 FROM synth_meta.worker_jobs WHERE memory_batch_id=$6
                    AND session_id=$1 AND request_id=$2 AND state='failed')
               AND (SELECT count(*) FROM synth_meta.worker_jobs WHERE actor_identity_id=$5
                    AND job_type='memory' AND memory_batch_id IS NOT NULL AND state IN ('pending','running'))<2
             ON CONFLICT DO NOTHING RETURNING id",
            [$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],
             $batch['actor_identity_id'],$batchId,$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to enqueue native memory');
        $created = pg_fetch_assoc($result);
        if ($created) return intval($created['id']);
        $result = @pg_query_params($connection,
            "SELECT id FROM synth_meta.worker_jobs WHERE memory_batch_id=$1 AND session_id=$2 AND request_id=$3
             AND generation=$4 AND job_type='memory' AND state IN ('pending','running')",
            [$batchId,$event['session_id'],$event['request_id'],$event['generation']]);
        if (!$result) throw new RuntimeException('Unable to read existing memory job');
        $existing = pg_fetch_assoc($result);
        return $existing ? intval($existing['id']) : null;
    },2000);
}

// Claims and expired-lease replacement are atomic; each attempt receives an independent fencing token.
function synth_memory_claim($db, int $jobId): ?array
{
    return $db->withNativeRequestLock(static function ($connection, array $event) use ($db,$jobId) {
        if (!synth_memory_lock_job_input($connection,$db,$event,$jobId)) return null;
        $result = @pg_query_params($connection,
            "UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp()
             WHERE id=$1 AND session_id=$2 AND request_id=$3 AND generation=$4 AND job_type='memory'
                AND memory_batch_id IS NOT NULL AND state IN ('pending','running')
                AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation']]);
        if (!$result) throw new RuntimeException('Unable to retire expired memory attempt');
        $token = bin2hex(random_bytes(16));
        $result = @pg_query_params($connection,
            "UPDATE synth_meta.worker_jobs SET state='running',attempts=attempts+1,lease_token=$1,
                lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
             WHERE id=$2 AND job_type='memory' AND memory_batch_id IS NOT NULL
                AND session_id=$3 AND request_id=$4 AND generation=$5
                AND payload->>'context_sequence'=$6 AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp() AND attempts<3
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
             RETURNING id,memory_batch_id,lease_token,attempts",
            [$token,$jobId,$event['session_id'],$event['request_id'],$event['generation'],(string)$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to claim native memory job');
        $job = pg_fetch_assoc($result);
        if (!$job) return null;
        $result = @pg_query_params($connection,'SELECT input_payload,packed_message FROM synth_meta.memory_batches WHERE id=$1',[$job['memory_batch_id']]);
        $batch = $result ? pg_fetch_assoc($result) : false;
        if (!$batch) throw new RuntimeException('Claimed memory batch unavailable');
        return array_merge($job,$batch);
    },2000);
}

// Publish a bounded result only from the current lease, atomically marking the job ready.
function synth_memory_complete($db, int $jobId, string $token, string $summary, ?array $embedding = null): int
{
    $summary = trim($summary);
    if ($summary==='' || strlen($summary)>65536 || !mb_check_encoding($summary,'UTF-8')) {
        throw new InvalidArgumentException('Invalid native memory summary');
    }
    $vector = $embedding === null ? null : synth_memory_vector_json($embedding);
    return $db->withNativeRequestLock(static function ($connection, array $event) use ($db,$jobId,$token,$summary,$vector) {
        if (!synth_memory_lock_job_input($connection,$db,$event,$jobId)) throw new SYNTHProtocolCancelled('memory_lease_lost');
        $result = @pg_query_params($connection,
            "WITH accepted AS (
                UPDATE synth_meta.worker_jobs SET state='ready',lease_until=NULL,updated_at=clock_timestamp()
                WHERE id=$1 AND job_type='memory' AND session_id=$2 AND request_id=$3 AND generation=$4
                    AND state='running' AND lease_token=$5 AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp()
                    AND payload->>'context_sequence'=$7
                RETURNING id,memory_batch_id
             ) INSERT INTO synth_meta.memory_summaries (batch_id,job_id,summary,embedding)
               SELECT memory_batch_id,id,$6,$8::public.vector FROM accepted RETURNING id",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$token,$summary,(string)$event['context_sequence'],$vector]);
        if (!$result) throw new RuntimeException('Unable to publish native memory');
        $saved = pg_fetch_assoc($result);
        if (!$saved) throw new SYNTHProtocolCancelled('memory_lease_lost');
        return intval($saved['id']);
    },2000);
}

// Claims and publication serialize with archive decisions, then recheck complete source visibility.
function synth_memory_lock_job_input($connection, $db, array $event, int $jobId): bool
{
    $result=@pg_query_params($connection,'SELECT actor_identity_id,memory_batch_id FROM synth_meta.worker_jobs
        WHERE id=$1 AND session_id=$2 AND request_id=$3 AND generation=$4 AND job_type=\'memory\'
            AND memory_batch_id IS NOT NULL',[$jobId,$event['session_id'],$event['request_id'],$event['generation']]);
    if (!$result) throw new RuntimeException('Unable to resolve memory job owner');
    $job=pg_fetch_assoc($result);
    if (!$job) return false;
    if (@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-memory:'||$1,0))",[$job['actor_identity_id']])===false) {
        throw new RuntimeException('Unable to lock memory job input');
    }
    $result=@pg_query($connection,synth_memory_visible_batch_cte($db,$event)
        .'SELECT id FROM native_memory_batches WHERE id='.intval($job['memory_batch_id'])
        .' AND actor_identity_id='.intval($job['actor_identity_id']));
    if (!$result) throw new RuntimeException('Unable to validate memory job input');
    return pg_fetch_assoc($result)!==false;
}

// Store only a fixed failure code; provider bodies and credentials never enter the retry record.
function synth_memory_fail($db, int $jobId, string $token): bool
{
    return $db->withNativeRequestLock(static function ($connection, array $event) use ($jobId,$token) {
        $result = @pg_query_params($connection,
            "UPDATE synth_meta.worker_jobs SET state=CASE WHEN attempts>=3 OR deadline_at<=clock_timestamp() THEN 'failed' ELSE 'pending' END,
                lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
                last_error='provider_failed',updated_at=clock_timestamp()
             WHERE id=$1 AND job_type='memory' AND session_id=$2 AND request_id=$3 AND generation=$4
                AND state='running' AND lease_token=$5 AND lease_until>clock_timestamp()
                AND payload->>'context_sequence'=$6 RETURNING id",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$token,(string)$event['context_sequence']]);
        if (!$result) throw new RuntimeException('Unable to record native memory failure');
        return pg_fetch_assoc($result)!==false;
    },2000);
}

// The caller binds the original request; generation runs outside locks and cannot publish after lease loss.
function synth_memory_process_job($db, int $jobId, callable $generate, ?callable $embed = null): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Memory providers require an idle database connection');
    $job = synth_memory_claim($db,$jobId);
    if (!$job) return null;
    $previousLease = $GLOBALS['SYNTH_MEMORY_JOB_LEASE'] ?? null;
    $GLOBALS['SYNTH_MEMORY_JOB_LEASE'] = ['id'=>$jobId,'token'=>$job['lease_token']];
    try {
        $input = json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $summary = $generate($input);
        $embedding = synth_memory_optional_embedding($summary,$embed);
        return synth_memory_complete($db,$jobId,$job['lease_token'],$summary,$embedding);
    } catch (SYNTHProtocolCancelled $error) {
        throw $error;
    } catch (Throwable $error) {
        synth_memory_fail($db,$jobId,$job['lease_token']);
        throw $error;
    } finally {
        if ($previousLease === null) unset($GLOBALS['SYNTH_MEMORY_JOB_LEASE']);
        else $GLOBALS['SYNTH_MEMORY_JOB_LEASE']=$previousLease;
    }
}

// Derived memory is usable only when every direct and inherited source is visible to this exact reader/save.
function synth_memory_visible_batch_cte($db, array $event, string $actor = '', ?int $journalReaderIdentityId = null): string
{
    // Management may inspect one exact saved NPC, but never union different readers' source visibility.
    if ($journalReaderIdentityId!==null && ($journalReaderIdentityId<1 || $actor!=='')) {
        throw new InvalidArgumentException('Memory inspection requires one exact saved actor.');
    }
    // Cache only visible IDs; correlated dependency probes must not repeat actor/audience resolution.
    return synth_protocol_history_cte($db,$event,$actor,null,false,false,$journalReaderIdentityId,false,
        $journalReaderIdentityId===null?'diary':'middleterm').", native_memory_visible_sources AS MATERIALIZED (
        SELECT DISTINCT history_event_id FROM native_history
    ), native_memory_owned_batches AS MATERIALIZED (
        SELECT b.id FROM synth_meta.memory_batches b
        WHERE EXISTS (SELECT 1 FROM native_history_scopes s
            WHERE s.reader_identity_id=b.actor_identity_id AND s.playthrough_id=b.playthrough_id)
    ), native_memory_source_counts AS MATERIALIZED (
        SELECT d.batch_id,count(*) AS source_count,count(h.history_event_id) AS visible_count
        FROM native_memory_owned_batches owned JOIN synth_meta.memory_batch_sources d ON d.batch_id=owned.id
        LEFT JOIN native_memory_visible_sources h ON h.history_event_id=d.history_event_id
        GROUP BY d.batch_id
    ), native_memory_direct_batches AS MATERIALIZED (
        SELECT b.* FROM synth_meta.memory_batches b JOIN native_memory_source_counts sources ON sources.batch_id=b.id
        WHERE sources.visible_count=sources.source_count AND sources.source_count=jsonb_array_length(b.input_payload->'events')
    ), native_memory_batch_families AS MATERIALIZED (
        SELECT b.id,b.id AS root_id FROM native_memory_direct_batches b
        WHERE NOT EXISTS (SELECT 1 FROM synth_meta.memory_batch_rebuilds rebuild WHERE rebuild.batch_id=b.id)
        UNION ALL
        SELECT b.id,rebuild.root_batch_id FROM synth_meta.memory_batch_rebuilds rebuild
        JOIN native_memory_direct_batches b ON b.id=rebuild.batch_id
        JOIN native_memory_direct_batches original ON original.id=rebuild.root_batch_id
        WHERE original.actor_identity_id=b.actor_identity_id
            AND original.playthrough_id=b.playthrough_id AND original.first_gamets=b.first_gamets
            AND original.last_gamets=b.last_gamets AND original.packed_message=b.packed_message
            AND original.input_payload->'events'=b.input_payload->'events'
            AND NOT EXISTS (SELECT 1 FROM synth_meta.memory_batch_rebuilds nested WHERE nested.batch_id=original.id)
            AND NOT EXISTS (SELECT history_event_id,position FROM synth_meta.memory_batch_sources WHERE batch_id=b.id
                EXCEPT SELECT history_event_id,position FROM synth_meta.memory_batch_sources WHERE batch_id=original.id)
    ), native_memory_latest_batches AS MATERIALIZED (
        SELECT root_id,max(id) AS id FROM native_memory_batch_families GROUP BY root_id
    ), native_memory_effective_summaries AS (
        SELECT s.id,s.batch_id,s.job_id,s.created_at,s.summary AS original_summary,
            COALESCE(edit.revision,0) AS summary_revision,COALESCE(edit.summary,s.summary) AS summary,
            CASE WHEN edit.revision IS NULL THEN s.embedding ELSE NULL::public.vector END AS embedding,
            COALESCE(edit.native_vec,s.native_vec) AS native_vec
        FROM synth_meta.memory_summaries s JOIN native_memory_batch_families b ON b.id=s.batch_id
        LEFT JOIN LATERAL (SELECT revision,summary,native_vec FROM synth_meta.memory_summary_revisions
            WHERE summary_id=s.id ORDER BY revision DESC LIMIT 1) edit ON TRUE
    ), native_memory_summary_edges AS MATERIALIZED (
        SELECT b.id,s.batch_id AS parent_id,
            d.summary_revision=current.summary_revision AND latest.id=s.batch_id AS current_reference
        FROM native_memory_direct_batches b
        JOIN native_memory_batch_families child_family ON child_family.id=b.id
        JOIN synth_meta.memory_batch_summary_sources d ON d.batch_id=b.id
        JOIN synth_meta.memory_summaries s ON s.id=d.summary_id
        JOIN native_memory_effective_summaries current ON current.id=s.id
        JOIN native_memory_batch_families family ON family.id=s.batch_id
        JOIN native_memory_latest_batches latest ON latest.root_id=family.root_id
        LEFT JOIN synth_meta.memory_summary_revisions frozen ON frozen.summary_id=s.id AND frozen.revision=d.summary_revision
        JOIN native_memory_direct_batches parent ON parent.id=s.batch_id
        WHERE parent.actor_identity_id=b.actor_identity_id AND parent.playthrough_id=b.playthrough_id
            AND parent.last_gamets<b.first_gamets
            AND b.input_payload->'previous_summary'->>'id'=s.id::text
            AND ((NOT (b.input_payload->'previous_summary' ? 'revision') AND d.summary_revision=0)
                OR (jsonb_typeof(b.input_payload->'previous_summary'->'revision')='number'
                    AND b.input_payload->'previous_summary'->>'revision'=d.summary_revision::text))
            AND b.input_payload->'previous_summary'->>'summary'=CASE WHEN d.summary_revision=0 THEN s.summary ELSE frozen.summary END
    ), native_memory_valid_batches(id) AS (
        SELECT b.id FROM native_memory_direct_batches b JOIN native_memory_batch_families family ON family.id=b.id
        WHERE NOT (b.input_payload ? 'previous_summary')
            AND NOT EXISTS (SELECT 1 FROM synth_meta.memory_batch_summary_sources d WHERE d.batch_id=b.id)
        UNION
        SELECT edge.id FROM native_memory_valid_batches valid
            JOIN native_memory_summary_edges edge ON edge.parent_id=valid.id
    ), native_memory_evidence_batches AS MATERIALIZED (
        SELECT b.* FROM native_memory_direct_batches b JOIN native_memory_valid_batches valid USING (id)
    ), native_memory_unarchived AS MATERIALIZED (
        SELECT b.* FROM native_memory_evidence_batches b
        JOIN native_memory_latest_batches latest ON latest.id=b.id
        LEFT JOIN synth_meta.memory_batch_controls control ON control.batch_id=latest.root_id
        WHERE NOT COALESCE(control.archived,FALSE)
    ), native_memory_recallable(id) AS (
        SELECT b.id FROM native_memory_unarchived b WHERE NOT (b.input_payload ? 'previous_summary')
        UNION
        SELECT edge.id FROM native_memory_recallable parent
            JOIN native_memory_summary_edges edge ON edge.parent_id=parent.id
            JOIN native_memory_unarchived child ON child.id=edge.id
            WHERE edge.current_reference
    ), native_memory_batches AS MATERIALIZED (
        SELECT b.* FROM native_memory_unarchived b JOIN native_memory_recallable valid USING(id)
    ) ";
}

// Select one chronological scene/time group; do not truncate a line or mix unknown locations.
function synth_memory_build_batch(array $rows, int $minimumEvents, int $intervalTicks): ?array
{
    if ($minimumEvents < 1 || $minimumEvents > 150 || $intervalTicks < 1) {
        throw new InvalidArgumentException('Invalid native memory batch limits');
    }
    $events = [];
    $text = '';
    $group = null;
    foreach (array_slice($rows, 0, 150) as $row) {
        $gamets = intval($row['gamets']);
        $location = trim(preg_replace('/\s+/u', ' ', strval($row['source_location'] ?? '')));
        $key = [strtolower($location), (int)round($gamets / $intervalTicks)];
        if ($group !== null && ($location === '' || $key !== $group)) {
            if (count($events) >= $minimumEvents) break;
            $events = [];
            $text = '';
        }
        $line = trim(strval($row['data']));
        $nextText = $text === '' ? $line : $text."\n\n".$line;
        if ($line === '' || strlen($nextText) > 65536) break;
        $events[] = [
            'history_event_id'=>intval($row['history_event_id']), 'type'=>strval($row['type']),
            'gamets'=>$gamets, 'location'=>$location, 'data'=>$line,
        ];
        $text = $nextText;
        $group = $key;
    }
    if (count($events) < $minimumEvents) return null;
    $payload = ['schema'=>'synth.memory.input.v1','events'=>$events];
    return ['input_payload'=>$payload,'packed_message'=>$text,
        'source_hash'=>hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'first_gamets'=>$events[0]['gamets'],'last_gamets'=>$events[array_key_last($events)]['gamets']];
}

// Resolve coverage, scheduling and execution to the same proven responder under the native owner lock.
function synth_memory_response_context($connection, $db, array $event): ?array
{
    require_once __DIR__.'/synth_rechat_routes.php';
    $scene=synth_rechat_response_scene_locked($connection,$event);
    if ($scene!==null) {
        $actor=synth_protocol_response_actor_identity($db,$event,$scene);
        if ($actor===null) return null;
        $result=@pg_query_params($connection,"SELECT id FROM synth.actor_identities WHERE game='fo4'
            AND upper(full_form_id)=upper($1) AND lower(origin_plugin)=lower($2) AND playthrough_id=$3",
            [$actor['form_id'],$actor['origin_plugin'],$actor['playthrough_id']]);
        if (!$result) throw new RuntimeException('Unable to resolve memory scene actor');
        $identity=pg_fetch_assoc($result);
        return $identity ? ['target_identity_id'=>$identity['id'],'playthrough_id'=>$actor['playthrough_id'],
            'context_payload'=>json_encode($scene['payload'],JSON_THROW_ON_ERROR)] : null;
    }
    $result=@pg_query_params($connection,'SELECT target_identity_id,playthrough_id,context_payload
        FROM synth_meta.context_snapshots WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
        [$event['session_id'],$event['generation'],$event['context_sequence']]);
    if (!$result) throw new RuntimeException('Unable to read memory owner');
    return pg_fetch_assoc($result) ?: null;
}

// Rebase one eligible descendant; manual corrections require approval for this exact frozen input.
function synth_memory_rebuild_batch($db): ?int
{
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db) {
        $context=synth_memory_response_context($connection,$db,$event);
        if (!$context || !$context['target_identity_id']) return null;
        if (@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-memory:'||$1,0))",[$context['target_identity_id']])===false) {
            throw new RuntimeException('Unable to lock memory rebuild');
        }
        $result=@pg_query($connection,synth_memory_visible_batch_cte($db,$event)."
            SELECT b.*,latest.root_id,parent.id AS previous_id,parent.summary AS previous_text,parent.summary_revision
            FROM native_memory_evidence_batches b JOIN native_memory_latest_batches latest ON latest.id=b.id
            JOIN synth_meta.memory_batch_summary_sources edge ON edge.batch_id=b.id
            JOIN synth_meta.memory_summaries frozen ON frozen.id=edge.summary_id
            JOIN native_memory_batch_families parent_family ON parent_family.id=frozen.batch_id
            JOIN native_memory_latest_batches parent_latest ON parent_latest.root_id=parent_family.root_id
            JOIN native_memory_batches parent_batch ON parent_batch.id=parent_latest.id
            JOIN native_memory_effective_summaries parent ON parent.batch_id=parent_batch.id
            LEFT JOIN synth_meta.memory_batch_controls control ON control.batch_id=latest.root_id
            WHERE NOT COALESCE(control.archived,FALSE) AND parent_batch.last_gamets<b.first_gamets
                AND NOT EXISTS (SELECT 1 FROM native_memory_batches active WHERE active.id=b.id)
                AND (control.regenerate_batch_id=b.id OR NOT EXISTS (
                    SELECT 1 FROM synth_meta.memory_summaries summary
                    JOIN synth_meta.memory_summary_revisions correction ON correction.summary_id=summary.id
                    WHERE summary.batch_id=b.id))
            ORDER BY b.first_gamets,b.id LIMIT 1");
        if (!$result) throw new RuntimeException('Unable to select memory rebuild');
        $source=pg_fetch_assoc($result);
        if (!$source) return null;
        $input=json_decode($source['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $input['previous_summary']=['id'=>intval($source['previous_id']),'summary'=>$source['previous_text']];
        if (intval($source['summary_revision'])>0) $input['previous_summary']['revision']=intval($source['summary_revision']);
        $json=json_encode($input,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result=@pg_query_params($connection,'INSERT INTO synth_meta.memory_batches
            (actor_identity_id,playthrough_id,session_id,generation,context_sequence,source_hash,input_payload,packed_message,first_gamets,last_gamets)
            VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8,$9,$10) RETURNING id',
            [$source['actor_identity_id'],$source['playthrough_id'],$event['session_id'],$event['generation'],$event['context_sequence'],
                hash('sha256',$json),$json,$source['packed_message'],$source['first_gamets'],$source['last_gamets']]);
        if (!$result) throw new RuntimeException('Unable to freeze rebuilt memory');
        $id=pg_fetch_assoc($result)['id'];
        if (@pg_query_params($connection,'INSERT INTO synth_meta.memory_batch_sources(batch_id,history_event_id,position)
            SELECT $1,history_event_id,position FROM synth_meta.memory_batch_sources WHERE batch_id=$2',[$id,$source['id']])===false
            || @pg_query_params($connection,'INSERT INTO synth_meta.memory_batch_summary_sources(batch_id,summary_id,summary_revision)
                VALUES($1,$2,$3)',[$id,$source['previous_id'],$source['summary_revision']])===false
            || @pg_query_params($connection,'INSERT INTO synth_meta.memory_batch_rebuilds(batch_id,root_batch_id) VALUES($1,$2)',[$id,$source['root_id']])===false) {
            throw new RuntimeException('Unable to freeze rebuild dependencies');
        }
        if (@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='cancelled',lease_token=NULL,lease_until=NULL,
            last_error='memory_rebuilt',updated_at=clock_timestamp() WHERE job_type='memory' AND state IN ('pending','running')
                AND memory_batch_id=$1",[$source['id']])===false) throw new RuntimeException('Unable to retire replaced memory work');
        return intval($id);
    },2000);
}

// Capture immutable inputs only. Scheduling/provider execution is a separate integration boundary.
function synth_memory_capture_batch($db, int $minimumEvents = 5, int $intervalTicks = 1000000): ?int
{
    if ($minimumEvents < 1 || $minimumEvents > 150 || $intervalTicks < 1) {
        throw new InvalidArgumentException('Invalid native memory batch limits');
    }
    return $db->withNativeRequestLock(static function ($connection, array $event) use ($db, $minimumEvents, $intervalTicks) {
        $context=synth_memory_response_context($connection,$db,$event);
        if (!$context || !$context['target_identity_id']) return null;
        // Different runtime sessions can share an actor. Serialize coverage decisions for that actor too.
        if (@pg_query_params($connection,
            "SELECT pg_advisory_xact_lock(hashtextextended('synth-memory:'||$1,0))",
            [$context['target_identity_id']]) === false) {
            throw new RuntimeException('Unable to lock native memory coverage');
        }
        $payload = json_decode($context['context_payload'], true, flags: JSON_THROW_ON_ERROR);
        $gameTime = intval($payload['world']['game_time_ticks'] ?? 0);
        $settledCutoff = $gameTime - intval(1 / 0.0000024);
        if ($settledCutoff <= 0) return null;
        // Reuse the existing one-game-hour settlement window; ancestry is the authority, not game time.
        $query = synth_memory_visible_batch_cte($db, $event).", memory_candidates AS (
            SELECT *,lower(btrim(regexp_replace(COALESCE(source_location,''),'\\s+',' ','g'))) AS scene_location,
                round(gamets::numeric/".intval($intervalTicks).",0) AS time_bucket
            FROM native_history WHERE gamets>0 AND gamets<".intval($settledCutoff)."
                AND type IN ('inputtext','inputtext_s','chat','backgroundchat','narration',
                    'conversation_start','conversation_end','infoaction','funcret','death','combatend',
                    'combatbark','itemfound','itemtransfer')
                AND COALESCE(delivery_state,'spoken') IN ('emitted','spoken')
                AND data NOT ILIKE '%#MEMORY:%'
        ), memory_boundaries AS (
            SELECT *,CASE WHEN scene_location='' OR
                scene_location IS DISTINCT FROM lag(scene_location) OVER chronology OR
                time_bucket IS DISTINCT FROM lag(time_bucket) OVER chronology THEN 1 ELSE 0 END AS boundary
            FROM memory_candidates WINDOW chronology AS (ORDER BY gamets,ts,history_event_id)
        ), memory_groups AS (
            SELECT *,sum(boundary) OVER (ORDER BY gamets,ts,history_event_id) AS scene_group
            FROM memory_boundaries
        ), memory_uncovered AS (
            SELECT g.* FROM memory_groups g WHERE NOT EXISTS (
                SELECT 1 FROM native_memory_evidence_batches b JOIN synth_meta.memory_batch_sources d ON d.batch_id=b.id
                WHERE d.history_event_id=g.history_event_id)
        ), memory_next_group AS (
            SELECT scene_group FROM memory_uncovered GROUP BY scene_group
            HAVING count(*)>=".intval($minimumEvents)." ORDER BY scene_group LIMIT 1
        ) SELECT history_event_id,type,data,gamets,source_location FROM memory_uncovered
            WHERE scene_group=(SELECT scene_group FROM memory_next_group)
            ORDER BY gamets ASC,ts ASC,history_event_id ASC LIMIT 150";
        $result = @pg_query($connection, $query);
        if (!$result) throw new RuntimeException('Unable to read native memory sources');
        $batch = synth_memory_build_batch(pg_fetch_all($result) ?: [], $minimumEvents, $intervalTicks);
        if ($batch === null) return null;
        // Freeze the latest strictly earlier, fully visible summary; never attach mutable/latest-global memory at execution.
        $result = @pg_query_params($connection,synth_memory_visible_batch_cte($db,$event)
            .'SELECT s.id,s.summary,s.summary_revision FROM native_memory_batches b JOIN native_memory_effective_summaries s ON s.batch_id=b.id
              WHERE b.last_gamets<$1 ORDER BY b.last_gamets DESC,s.id DESC LIMIT 1',[$batch['first_gamets']]);
        if (!$result) throw new RuntimeException('Unable to resolve prior native memory');
        $previous = pg_fetch_assoc($result);
        if ($previous) $batch['input_payload']['previous_summary']=['id'=>intval($previous['id']),'summary'=>$previous['summary']];
        if ($previous && intval($previous['summary_revision'])>0) {
            $batch['input_payload']['previous_summary']['revision']=intval($previous['summary_revision']);
        }
        $json = json_encode($batch['input_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $batch['source_hash'] = hash('sha256',$json);
        $result = @pg_query_params($connection,
            'INSERT INTO synth_meta.memory_batches (actor_identity_id,playthrough_id,session_id,generation,
                context_sequence,source_hash,input_payload,packed_message,first_gamets,last_gamets)
             VALUES ($1,$2,$3,$4,$5,$6,$7::jsonb,$8,$9,$10)
             ON CONFLICT (actor_identity_id,source_hash) DO NOTHING RETURNING id',
            [$context['target_identity_id'],$context['playthrough_id'],$event['session_id'],$event['generation'],
             $event['context_sequence'],$batch['source_hash'],$json,$batch['packed_message'],$batch['first_gamets'],$batch['last_gamets']]);
        if (!$result) throw new RuntimeException('Unable to freeze native memory input');
        $created = pg_fetch_assoc($result);
        if (!$created) {
            // READ COMMITTED sees a concurrently committed identical batch on this new statement.
            $result = @pg_query_params($connection,
                'SELECT id FROM synth_meta.memory_batches WHERE actor_identity_id=$1 AND source_hash=$2',
                [$context['target_identity_id'],$batch['source_hash']]);
            $existing = $result ? pg_fetch_assoc($result) : false;
            if (!$existing) throw new RuntimeException('Native memory batch conflict unavailable');
            return intval($existing['id']);
        }
        $result = @pg_query_params($connection,
            'INSERT INTO synth_meta.memory_batch_sources (batch_id,history_event_id,position)
             SELECT $1,(e.event->>\'history_event_id\')::bigint,e.ordinality
             FROM jsonb_array_elements($2::jsonb->\'events\') WITH ORDINALITY e(event,ordinality)',
            [$created['id'],$json]);
        if (!$result) throw new RuntimeException('Unable to freeze native memory dependencies');
        if ($previous && @pg_query_params($connection,
            'INSERT INTO synth_meta.memory_batch_summary_sources (batch_id,summary_id,summary_revision) VALUES ($1,$2,$3)',
            [$created['id'],$previous['id'],$previous['summary_revision']]) === false) {
            throw new RuntimeException('Unable to freeze prior native memory dependency');
        }
        return intval($created['id']);
    }, 2000);
}

<?php

require_once __DIR__.'/synth_rechat_routes.php';

// Preserve the legacy consecutive-success distribution without temp files or unnecessary 100% rolls.
function synth_rechat_roll_budget(int $rounds, int $probability): int
{
    if ($rounds<=0 || $probability<=0) return 0;
    if ($probability>=100) return $rounds;
    $budget=0;
    while ($budget<$rounds && random_int(1,100)<=$probability) ++$budget;
    return $budget;
}

// Called under the native session lock: walk only completed, canonically owned, actually emitted actions.
function synth_rechat_action_chain_origin($connection, array $event, array $origin, bool $singleStep = false): array
{
    $visited=[];
    for ($depth=0;$depth<64;++$depth) {
        if (($origin['type'] ?? '')!=='action_result') return $origin;
        $receipt=$origin['payload']['result'] ?? [];
        $action=$receipt['action_id'] ?? null; $key=$receipt['idempotency_key'] ?? null;
        if (!is_string($action) || !is_string($key) || isset($visited[$action])) {
            throw new LogicException('rechat_action_ancestry_invalid');
        }
        $visited[$action]=true;
        $result=@pg_query_params($connection,"SELECT p.canonical_event,p.event_sha256,p.event_type,e.response_body,
                i.request_id,i.capability,i.arguments,a.full_form_id,a.origin_plugin,a.playthrough_id,
                target.full_form_id AS target_form_id,target.origin_plugin AS target_plugin,target.playthrough_id AS target_playthrough
            FROM synth_meta.action_intents i
            JOIN synth_meta.action_terminal_results t ON t.action_id=i.action_id AND t.idempotency_key=i.idempotency_key
            JOIN synth_meta.protocol_events receipt ON receipt.session_id=i.session_id AND receipt.request_id=$8
                AND receipt.generation=i.generation AND receipt.turn_id=i.turn_id AND receipt.event_type='action_result'
                AND receipt.canonical_event=$9::jsonb AND receipt.event_sha256=$10
                AND receipt.canonical_event->'payload'->'result'=t.result
            JOIN synth_meta.request_executions accepted ON accepted.session_id=receipt.session_id
                AND accepted.request_id=receipt.request_id AND accepted.generation=receipt.generation
                AND accepted.turn_id=receipt.turn_id AND accepted.event_type=receipt.event_type
                AND accepted.request_sha256=receipt.event_sha256 AND accepted.state IN ('claimed','streaming','complete')
            JOIN synth_meta.request_executions e ON e.session_id=i.session_id AND e.request_id=i.request_id
                AND e.generation=i.generation AND e.turn_id=i.turn_id
            JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                AND p.event_sha256=e.request_sha256
            JOIN synth_meta.runtime_sessions s ON s.session_id=i.session_id
            JOIN synth.actor_identities a ON a.id=i.actor_identity_id AND a.game='fo4' AND a.playthrough_id=s.playthrough_id
            LEFT JOIN synth.actor_identities target ON target.id=i.target_identity_id
            WHERE i.action_id=$1 AND i.idempotency_key=$2 AND i.session_id=$3 AND i.generation=$4 AND i.turn_id=$5
                AND i.state='complete' AND t.result=$6::jsonb AND t.status=t.result->>'status'
                AND s.runtime_variant=$7 AND e.state='complete'",
            [$action,$key,$event['session_id'],$event['generation'],$event['turn_id'],
             json_encode($receipt,JSON_THROW_ON_ERROR),$event['runtime_variant'],$origin['request_id'],
             synth_protocol_canonical_json($origin),hash('sha256',synth_protocol_canonical_json($origin))]);
        if (!$result) throw new RuntimeException('rechat_action_ancestry_query_failed');
        $row=pg_fetch_assoc($result);
        if (!$row) throw new LogicException('rechat_action_ancestry_unavailable');
        $origin=json_decode($row['canonical_event'],true,flags:JSON_THROW_ON_ERROR);
        if (($origin['protocol_version'] ?? null)!==2 || ($origin['request_id'] ?? null)!==$row['request_id']
            || ($origin['type'] ?? null)!==$row['event_type']
            || !hash_equals($row['event_sha256'],hash('sha256',synth_protocol_canonical_json($origin)))) {
            throw new LogicException('rechat_action_ancestry_invalid');
        }
        foreach (['session_id','generation','turn_id','context_sequence','runtime_variant'] as $field) {
            if (($origin[$field] ?? null)!==($event[$field] ?? null)) throw new LogicException('rechat_action_ancestry_owner_mismatch');
        }
        if (!synth_protocol_owns_dialogue_turn($origin)) throw new LogicException('rechat_action_ancestry_invalid');
        $lines=synth_rechat_completed_lines(synth_protocol_decode_bytea($row['response_body']),$origin);
        if ($lines===null) throw new LogicException('rechat_action_emission_unavailable');
        $matches=0;
        $actor=['form_id'=>$row['full_form_id'],'origin_plugin'=>$row['origin_plugin'],'playthrough_id'=>$row['playthrough_id']];
        $target=$row['target_form_id']===null ? null : ['form_id'=>$row['target_form_id'],
            'origin_plugin'=>$row['target_plugin'],'playthrough_id'=>$row['target_playthrough']];
        if ($target!==null && $target['playthrough_id']!==$actor['playthrough_id']) throw new LogicException('rechat_action_emission_mismatch');
        foreach ($lines as $entry) {
            $line=$entry['line'];
            if ($line['type']!=='action') continue;
            $emitted=$line['payload']['action'] ?? [];
            if (($emitted['action_id'] ?? null)!==$action) continue;
            if (($emitted['idempotency_key'] ?? null)!==$key || ($emitted['capability'] ?? null)!==$row['capability']
                || ($emitted['name'] ?? null)!==substr($row['capability'],7)
                || synth_rechat_actor_key($emitted['actor'] ?? [])!==synth_rechat_actor_key($actor)
                || ($target===null ? isset($emitted['target'])
                    : synth_rechat_actor_key($emitted['target'] ?? [])!==synth_rechat_actor_key($target))
                || synth_protocol_canonical_json($emitted['arguments'] ?? [])!==
                    synth_protocol_canonical_json(json_decode($row['arguments'],true,flags:JSON_THROW_ON_ERROR))) {
                throw new LogicException('rechat_action_emission_mismatch');
            }
            ++$matches;
        }
        if ($matches!==1) throw new LogicException('rechat_action_emission_unavailable');
        // History follows each proven edge so intermediate replies are not lost or replaced by sibling results.
        if ($singleStep) return $origin;
    }
    throw new LogicException('rechat_action_ancestry_limit');
}

// Resolve the same root/reservation link for initial preparation and subsequent admission.
function synth_rechat_find_chain($connection, array $event, string $rootRequest): ?array
{
    $result=@pg_query_params($connection,"SELECT c.* FROM synth_meta.rechat_chains c
        WHERE c.session_id=$1 AND (c.root_request_id=$2 OR EXISTS (
            SELECT 1 FROM synth_meta.rechat_continuations d WHERE d.chain_id=c.id
                AND d.session_id=c.session_id AND d.request_id=$2 AND d.status='reserved'))",
        [$event['session_id'],$rootRequest]);
    if (!$result) throw new RuntimeException('rechat_chain_query_failed');
    $chains=pg_fetch_all($result);
    if (count($chains)>1) throw new LogicException('rechat_chain_ambiguous');
    return $chains[0] ?? null;
}

// Reserve ownership and budget only; a reservation is not permission to generate before actor selection.
function synth_rechat_reserve_continuation($db, array $event, array $policy, ?string $initialTerminal = null): array
{
    if ($initialTerminal!==null && !in_array($initialTerminal,['no_candidate','ineligible'],true)) {
        throw new InvalidArgumentException('rechat_initial_terminal_invalid');
    }
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event,$policy,$initialTerminal) {
        if ($owner!==$event) throw new LogicException('rechat_owner_changed');
        // Native lock preserves caller transactions; this savepoint additionally makes admission all-or-nothing.
        if (!@pg_query($connection,'SAVEPOINT synth_rechat_admission')) throw new RuntimeException('rechat_savepoint_failed');
        try {
            $proof=synth_rechat_completed_parent($db,$event);
            if ($proof===null) throw new LogicException('rechat_parent_unavailable');
            $parent=$event['payload']['reply_to_request_id'];
            $result=@pg_query_params($connection,"SELECT d.*,c.mode,c.policy,c.budget,c.used_rounds
                FROM synth_meta.rechat_continuations d JOIN synth_meta.rechat_chains c ON c.id=d.chain_id
                WHERE d.session_id=$1 AND (d.request_id=$2 OR d.parent_request_id=$3)",
                [$event['session_id'],$event['request_id'],$parent]);
            if (!$result) throw new RuntimeException('rechat_replay_query_failed');
            $rows=pg_fetch_all($result);
            if ($rows) {
                if (count($rows)!==1 || $rows[0]['request_id']!==$event['request_id']
                    || $rows[0]['parent_request_id']!==$parent) throw new LogicException('rechat_parent_already_continued');
                $saved=$rows[0];
                if ($saved['parent_line_id']!==$event['payload']['reply_to_line_id']
                    || !hash_equals($saved['parent_response_sha256'],$proof['response_sha256'])) {
                    throw new LogicException('rechat_replay_proof_changed');
                }
                // Return only immutable per-request fields: later rounds must not change this replay.
                unset($saved['used_rounds']);
                $saved['policy']=json_decode($saved['policy'],true,flags:JSON_THROW_ON_ERROR);
                if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_admission')) throw new RuntimeException('rechat_savepoint_release_failed');
                return $saved;
            }
            $origin=synth_rechat_action_chain_origin($connection,$event,$proof['request']);
            $rootRequest=$origin['request_id'];
            $chain=synth_rechat_find_chain($connection,$event,$rootRequest);
            $rechatParent=($origin['type'] ?? '')==='trigger' && ($origin['payload']['kind'] ?? '')==='rechat';
            if (!$chain && $rechatParent) throw new LogicException('rechat_parent_reservation_missing');
            if (!$chain) {
                // These values must come from the selected authoritative profile, not the trigger payload.
                foreach (['rounds','probability','narration_chance','narration_cooldown'] as $key) {
                    if (!is_int($policy[$key] ?? null)) throw new InvalidArgumentException('rechat_policy_invalid');
                }
                foreach (['private','allow_actions','random_narration'] as $key) {
                    if (!is_bool($policy[$key] ?? null)) throw new InvalidArgumentException('rechat_policy_invalid');
                }
                if (!in_array($policy['mode'] ?? '',['tight','conversational','group','random'],true)) {
                    throw new InvalidArgumentException('rechat_policy_invalid');
                }
                foreach (['source_profile_id','source_actor_identity_id'] as $key) {
                    if (isset($policy[$key]) && (!is_int($policy[$key]) || $policy[$key]<1)) throw new InvalidArgumentException('rechat_policy_invalid');
                }
                $policy=array_intersect_key($policy,array_flip(['rounds','probability','mode','private','allow_actions',
                    'random_narration','narration_chance','narration_cooldown','source_profile_id','source_actor_identity_id']));
                $mode=$policy['mode']==='random' ? ['tight','conversational','group'][random_int(0,2)] : $policy['mode'];
                $budget=($policy['private'] || $initialTerminal!==null) ? 0 : synth_rechat_roll_budget($policy['rounds'],$policy['probability']);
                $terminal=$policy['private'] ? 'private' : ($initialTerminal ?? ($budget===0 ? 'budget_exhausted' : null));
                $result=@pg_query_params($connection,"INSERT INTO synth_meta.rechat_chains
                    (session_id,root_request_id,generation,context_sequence,turn_id,runtime_variant,mode,policy,budget,terminal_reason)
                    VALUES($1,$2,$3,$4,$5,$6,$7,$8::jsonb,$9,$10) RETURNING *",
                    [$event['session_id'],$rootRequest,$event['generation'],$event['context_sequence'],$event['turn_id'],
                     $event['runtime_variant'],$mode,json_encode($policy,JSON_THROW_ON_ERROR),$budget,$terminal]);
                if (!$result) throw new RuntimeException('rechat_chain_insert_failed');
                $chain=pg_fetch_assoc($result);
            }
            foreach (['session_id','turn_id','runtime_variant'] as $field) {
                if ($chain[$field]!==$event[$field]) throw new LogicException('rechat_chain_owner_mismatch');
            }
            foreach (['generation','context_sequence'] as $field) {
                if (intval($chain[$field])!==$event[$field]) throw new LogicException('rechat_chain_owner_mismatch');
            }
            $terminal=$chain['terminal_reason'];
            $used=intval($chain['used_rounds']); $budget=intval($chain['budget']);
            if ($terminal===null && $used>=$budget) $terminal='budget_exhausted';
            $round=$terminal===null ? $used+1 : null;
            $result=@pg_query_params($connection,"INSERT INTO synth_meta.rechat_continuations
                (session_id,request_id,chain_id,parent_request_id,parent_line_id,parent_response_sha256,round_number,status,terminal_reason)
                VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING *",
                [$event['session_id'],$event['request_id'],$chain['id'],$parent,$event['payload']['reply_to_line_id'],
                 $proof['response_sha256'],$round,$terminal===null ? 'reserved' : 'terminal',$terminal]);
            if (!$result) throw new RuntimeException('rechat_reservation_insert_failed');
            $saved=pg_fetch_assoc($result);
            $result=@pg_query_params($connection,"UPDATE synth_meta.rechat_chains
                SET used_rounds=$2,terminal_reason=$3 WHERE id=$1",
                [$chain['id'],$round ?? $used,$terminal ?? ($round===$budget ? 'budget_exhausted' : null)]);
            if (!$result) throw new RuntimeException('rechat_budget_update_failed');
            if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_admission')) throw new RuntimeException('rechat_savepoint_release_failed');
            return $saved+['mode'=>$chain['mode'],'policy'=>json_decode($chain['policy'],true,flags:JSON_THROW_ON_ERROR),'budget'=>$chain['budget']];
        } catch (Throwable $error) {
            if (@pg_query($connection,'ROLLBACK TO SAVEPOINT synth_rechat_admission')===false
                || @pg_query($connection,'RELEASE SAVEPOINT synth_rechat_admission')===false) {
                throw new RuntimeException('rechat_admission_rollback_failed',0,$error);
            }
            throw $error;
        }
    },2000);
}

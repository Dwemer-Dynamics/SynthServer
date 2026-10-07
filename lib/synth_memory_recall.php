<?php

require_once __DIR__.'/synth_memory.php';
require_once __DIR__.'/provider_stream.php';
require_once __DIR__.'/synth_diary_state.php';

// Optional vector work stays outside SQL locks; provider failure leaves text recall available.
function synth_memory_fetch_embedding(string $text): ?array
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return null;
    synth_protocol_assert_request_active();
    if (!filter_var($GLOBALS['FEATURES']['MEMORY_EMBEDDING']['USE_TEXT2VEC'] ?? false,FILTER_VALIDATE_BOOLEAN)) return null;
    if (!$GLOBALS['db']->isTransactionIdle()) throw new LogicException('Memory embeddings require an idle database connection');
    $text = trim($text);
    if ($text==='' || strlen($text)>65536 || !mb_check_encoding($text,'UTF-8')) return null;
    $url = rtrim(strval($GLOBALS['FEATURES']['MEMORY_EMBEDDING']['TXTAI_URL'] ?? ''),'/');
    if (!in_array(strtolower(strval(parse_url($url,PHP_URL_SCHEME))),['http','https'],true)) return null;
    $context = stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json',
        'content'=>json_encode(['text'=>$text],JSON_THROW_ON_ERROR),'timeout'=>10]]);
    $headers = [];
    $response = synth_provider_fetch($url.'/embed',$context,$headers,1024*1024);
    if ($response === false) return null;
    try {
        $payload = json_decode($response,true,flags:JSON_THROW_ON_ERROR);
        $vector = $payload['embedding'] ?? null;
        if (is_string($vector)) $vector = json_decode($vector,true,flags:JSON_THROW_ON_ERROR);
        if (!is_array($vector)) return null;
        synth_memory_vector_json($vector);
        return $vector;
    } catch (JsonException | InvalidArgumentException $error) {
        return null;
    }
}

// Keep independently bounded semantic and text-only pools so either source cannot evict the other's fallback.
function synth_memory_rank_text_candidates(array $rows, bool $hybrid=false): array
{
    usort($rows,static function(array $a,array $b) use($hybrid): int {
        if ($hybrid) {
            foreach (['mixed_distance','distance'] as $key) {
                $order=($a[$key] ?? INF)<=>($b[$key] ?? INF);if ($order!==0) return $order;
            }
        }
        $semanticOrder=$hybrid && ($a['distance'] ?? null)!==null;
        foreach ($semanticOrder ? ['gamets_truncated','rowid'] : ['rank_all','rank_any','gamets_truncated','rowid'] as $key) {
            $order=$b[$key]<=>$a[$key];if ($order!==0) return $order;
        }
        return strcmp($a['memory_source'],$b['memory_source']);
    });
    if (!$hybrid) return array_slice($rows,0,50);
    $semantic=[];$text=[];
    foreach ($rows as $row) {
        if ($row['distance']!==null) { if (count($semantic)<50) $semantic[]=$row; }
        elseif (count($text)<50) $text[]=$row;
    }
    return array_merge($semantic,$text);
}

// Both owned dialogue and journal queries use the same bounded pools without fabricated vector distances.
function synth_memory_candidate_query(string $source, bool $hybrid, bool $recent=false): string
{
    if (!in_array($source,['recall_candidates','diary_ranked'],true)) throw new LogicException('Unknown memory candidate source');
    $ranked="SELECT *,rank_any AS rank_any_fts_raw,rank_all AS rank_all_fts_raw,
        rank_any+rank_all AS rank_fts,distance-(rank_any+rank_all) AS mixed_distance FROM $source";
    if ($recent) return " $ranked ORDER BY gamets_truncated DESC,rowid DESC LIMIT 1";
    if (!$hybrid) return " $ranked ORDER BY rank_all DESC,rank_any DESC,gamets_truncated DESC,rowid DESC LIMIT 50";
    return ", ranked_memory_candidates AS MATERIALIZED ($ranked)
        (SELECT * FROM ranked_memory_candidates WHERE distance IS NOT NULL
            ORDER BY mixed_distance ASC,distance ASC,gamets_truncated DESC,rowid DESC LIMIT 50)
        UNION ALL
        (SELECT * FROM ranked_memory_candidates WHERE distance IS NULL AND rank_any>0
            ORDER BY rank_all DESC,rank_any DESC,gamets_truncated DESC,rowid DESC LIMIT 50)";
}

// Isolate optional diary timeouts so PostgreSQL does not discard the valid dialogue-memory transaction.
function synth_memory_query_diaries($connection,string $query,array $parameters): array
{
    if (@pg_query($connection,'SAVEPOINT synth_diary_recall')===false) throw new RuntimeException('Unable to isolate diary recall');
    if (!@pg_send_query_params($connection,$query,$parameters)) throw new RuntimeException('Unable to query diary recall');
    $result=pg_get_result($connection);
    // Consume the query result before issuing savepoint recovery commands on this connection.
    while (pg_get_result($connection)!==false) {}
    $success=$result!==false && pg_result_status($result)===PGSQL_TUPLES_OK;
    $state=$result===false ? null : pg_result_error_field($result,PGSQL_DIAG_SQLSTATE);
    if (!$success && @pg_query($connection,'ROLLBACK TO SAVEPOINT synth_diary_recall')===false) {
        throw new RuntimeException('Unable to recover diary recall');
    }
    if (@pg_query($connection,'RELEASE SAVEPOINT synth_diary_recall')===false) throw new RuntimeException('Unable to finish diary recall');
    if ($success) return pg_fetch_all($result) ?: [];
    if ($state==='57014') {
        synth_protocol_assert_request_active();
        Logger::warn('Native diary recall query cancelled; continuing without this optional diary result.');
        return [];
    }
    throw new RuntimeException('Unable to read native diary memory');
}

// Every direct source must be visible to the current reader/save; never fall back to global summaries.
function synth_memory_recall($db, string $actor, array $terms, int $timeFloor = 0, ?array $embedding = null, bool $recent = false): array
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return [];
    $normalized = [];
    foreach ($terms as $term) {
        if (!is_string($term) || !preg_match_all('/[\p{L}\p{N}_]+/u',$term,$matches)) continue;
        foreach ($matches[0] as $word) {
            if (mb_strlen($word,'UTF-8')>=3 && strlen($word)<=128) $normalized[$word]=$word;
            if (count($normalized)>=32) break 2;
        }
    }
    if (!$recent && $embedding === null && $normalized === []) return [];
    $vector = $embedding === null ? null : synth_memory_vector_json($embedding);
    return $db->withNativeRequestLock(static function($connection,array $event) use ($db,$actor,$normalized,$timeFloor,$vector,$recent) {
        $query = synth_memory_visible_batch_cte($db,$event,$actor).", recall_query AS (
            SELECT $1::bigint AS floor,
                CASE WHEN $2='' THEN NULL::tsquery ELSE to_tsquery('english'::regconfig,$2) END AS any_query,
                CASE WHEN $3='' THEN NULL::tsquery ELSE to_tsquery('english'::regconfig,$3) END AS all_query,
                $4::public.vector AS search_vector
        ), visible_summaries AS (
            SELECT s.*,b.last_gamets AS gamets_truncated FROM native_memory_effective_summaries s
                JOIN native_memory_batches b ON b.id=s.batch_id
        ), recall_candidates AS (
            SELECT s.id AS rowid,s.summary,s.summary_revision,s.gamets_truncated,'dialogue'::text AS classifier,'native'::text AS memory_source,
                COALESCE(ts_rank(s.native_vec,q.any_query),0) AS rank_any,
                COALESCE(ts_rank(s.native_vec,q.all_query),0) AS rank_all,
                s.embedding <-> q.search_vector AS distance
            FROM visible_summaries s CROSS JOIN recall_query q
            WHERE (q.floor<=0 OR s.gamets_truncated<q.floor)
                ".($recent || $vector !== null ? '' : "AND NOT (s.native_vec @@ to_tsquery('english'::regconfig,'Reminiscence'))")."
                AND ".($recent ? 'TRUE' : '((q.search_vector IS NOT NULL AND s.embedding IS NOT NULL)
                    OR (s.embedding IS NULL AND s.native_vec @@ q.any_query)
                    OR (q.search_vector IS NULL AND s.native_vec @@ q.any_query))')."
        )".synth_memory_candidate_query('recall_candidates',$vector!==null,$recent);
        $result = @pg_query_params($connection,$query,[$timeFloor,implode(' | ',$normalized),implode(' & ',$normalized),$vector]);
        if (!$result) throw new RuntimeException('Unable to read native memory');
        $rows=pg_fetch_all($result) ?: [];
        // Diary reflections are separate evidence, not short-term dialogue summaries or fabricated vectors.
        if (!$recent) {
            $readers=[];
            if (trim($actor)==='' && ($GLOBALS['SYNTH_NAME'] ?? '')==='The Narrator') {
                $reader=$db->fetchOne(synth_protocol_history_cte($db,$event,'',null,true)
                    .' SELECT DISTINCT reader_identity_id FROM native_history_scopes LIMIT 1');
                if (intval($reader['reader_identity_id'] ?? 0)>0) {
                    $readers[]=[intval($reader['reader_identity_id']),'narrator',false];
                    if (!filter_var($GLOBALS['NARRATOR_ONLY_DIARY_ACCESS'] ?? false,FILTER_VALIDATE_BOOLEAN)) $readers[]=[0,'npc',true];
                }
            } elseif (trim($actor)!=='') {
                $reader=$db->fetchOne(synth_protocol_history_cte($db,$event,$actor)
                    .' SELECT DISTINCT reader_identity_id FROM native_history_scopes LIMIT 1');
                if (intval($reader['reader_identity_id'] ?? 0)>0) $readers[]=[intval($reader['reader_identity_id']),'npc',false];
            }
            foreach ($readers as [$readerId,$role,$allJournals]) {
                $query=synth_diary_history_cte($db,$event,$readerId,$role,$allJournals).", diary_search AS (
                    SELECT history_event_id AS rowid,COALESCE(NULLIF(content_revision,0),history_event_id) AS summary_revision,
                        result_payload->>'content' AS summary,embedding,
                        CASE WHEN gamets ~ '^[0-9]{1,16}$' THEN gamets::bigint ELSE NULL END AS gamets_truncated,
                        to_tsvector('english'::regconfig,COALESCE(result_payload->>'topic','')||' '||(result_payload->>'content')) AS document
                    FROM native_diaries
                ), diary_ranked AS (
                    SELECT rowid,summary_revision,summary,gamets_truncated,'diary'::text AS classifier,
                        'native_diary'::text AS memory_source,
                        ts_rank(document,to_tsquery('english'::regconfig,$2)) AS rank_any,
                        ts_rank(document,to_tsquery('english'::regconfig,$3)) AS rank_all,embedding <-> $4::public.vector AS distance
                    FROM diary_search WHERE gamets_truncated IS NOT NULL AND ($1::bigint<=0 OR gamets_truncated<$1)
                        AND (($4::public.vector IS NOT NULL AND embedding IS NOT NULL)
                            OR (embedding IS NULL AND document @@ to_tsquery('english'::regconfig,$2))
                            OR ($4::public.vector IS NULL AND document @@ to_tsquery('english'::regconfig,$2)))
                        ".($vector===null ? "AND NOT (document @@ to_tsquery('english'::regconfig,'Reminiscence'))" : '')."
                )".synth_memory_candidate_query('diary_ranked',$vector!==null);
                $diaries=synth_memory_query_diaries($connection,$query,[$timeFloor,implode(' | ',$normalized),implode(' & ',$normalized),$vector]);
                $rows=array_merge($rows,$diaries);
                $rows=synth_memory_rank_text_candidates($rows,$vector!==null);
            }
        }
        return $rows;
    },2000);
}

<?php
declare(strict_types=1);

// Management archive only: these reads do not change diary recall or save ownership.
function synth_diary_browser_filters(array $query): array
{
    $values = ['author'=>'', 'role'=>'', 'save'=>'', 'search'=>'', 'page'=>'1'];
    foreach ($values as $key=>$default) {
        $value=$query[$key] ?? $default;
        if (!is_string($value) || !preg_match('//u',$value)) throw new InvalidArgumentException('Invalid diary filter.');
        $values[$key]=$value;
    }
    if ($values['author']!=='' && !preg_match('/^(npc|player|narrator):[1-9][0-9]{0,17}$/D',$values['author']))
        throw new InvalidArgumentException('Invalid diary author.');
    if (!in_array($values['role'],['','npc','player','narrator'],true)
        || ($values['save']!=='' && !preg_match('/^[a-f0-9]{12}$/D',$values['save']))
        || strlen($values['search'])>512 || !preg_match('/^[1-9][0-9]{0,4}$/D',$values['page']))
        throw new InvalidArgumentException('Invalid diary filter.');
    $values['page']=(int)$values['page'];
    return $values;
}

function synth_diary_browser_cte(): string
{
    return <<<'SQL'
WITH written_diaries AS (
    SELECT r.history_event_id AS id, i.actor_identity_id, i.diary_role AS role,
        i.diary_role||':'||i.actor_identity_id::text AS author_key,
        COALESCE(NULLIF(i.input_payload#>>'{binding,name}',''),a.display_name,'Unknown author') AS author,
        r.result_payload->>'topic' AS topic, r.result_payload->>'content' AS content,
        h.recorded_at AS written_at, h.event_payload->>'gamets' AS gamets,
        COALESCE(i.input_payload#>>'{context,world,location}','') AS location,
        COALESCE(i.input_payload#>>'{context,player,display_name}','Sole Survivor') AS player_name,
        substr(encode(sha256(convert_to(h.playthrough_id,'UTF8')),'hex'),1,12) AS save_key
    FROM synth_meta.diary_results r
    JOIN synth_meta.diary_inputs i ON i.id=r.input_id
    JOIN synth_meta.history_events h ON h.id=r.history_event_id
        AND h.session_id=i.session_id AND h.request_id=i.request_id
        AND h.generation=i.generation AND h.context_sequence=i.context_sequence
    JOIN synth.actor_identities a ON a.id=i.actor_identity_id
        AND a.playthrough_id=h.playthrough_id AND a.game='fo4'
    WHERE h.event_payload->>'type'='diary_result'
        AND h.event_payload->>'delivery_state'='derived'
        AND r.result_sha256=encode(sha256(convert_to(r.result_payload::text,'UTF8')),'hex')
        AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')
        AND h.event_payload->'diary_result'=r.result_payload
        AND h.event_payload->>'diary_result_sha256'=r.result_sha256
        AND h.event_payload->>'diary_input_sha256'=i.input_sha256
        AND i.input_payload->>'actor_identity_id'=i.actor_identity_id::text
        AND i.input_payload->>'diary_role'=i.diary_role
        AND octet_length(r.result_payload->>'topic') BETWEEN 1 AND 512
        AND octet_length(r.result_payload->>'content') BETWEEN 1 AND 65536
)
SQL;
}

function synth_diary_browser_rows($db, string $query, array $parameters=[]): array
{
    $result=$db->execQuery($query,$parameters);
    if (!$result) throw new RuntimeException('Diaries could not be loaded.');
    return pg_fetch_all($result) ?: [];
}

function synth_diary_browser_summary($db): array
{
    $rows=synth_diary_browser_rows($db,synth_diary_browser_cte()
        .' SELECT *,count(*) OVER() AS total FROM written_diaries ORDER BY written_at DESC,id DESC LIMIT 1');
    return ['total'=>(int)($rows[0]['total'] ?? 0),'latest'=>$rows[0] ?? null];
}

function synth_diary_browser_load($db, array $query): array
{
    $filters=synth_diary_browser_filters($query);
    $conditions=[];$parameters=[];
    foreach (['author'=>'author_key','role'=>'role','save'=>'save_key'] as $key=>$column) {
        if ($filters[$key]!=='') {
            $parameters[]=$filters[$key];$conditions[]=$column.'=$'.count($parameters);
        }
    }
    if ($filters['search']!=='') {
        $parameters[]=$filters['search'];
        $conditions[]="strpos(lower(topic||' '||content),lower($".count($parameters)."::text))>0";
    }
    $where=$conditions ? ' WHERE '.implode(' AND ',$conditions) : '';
    $cte=synth_diary_browser_cte();
    $count=synth_diary_browser_rows($db,$cte.' SELECT count(*) AS total FROM written_diaries'.$where,$parameters);
    $total=(int)$count[0]['total'];
    $page=min($filters['page'],max(1,(int)ceil($total/20)));
    $filters['page']=$page;
    $parameters[] = 20;$limit='$'.count($parameters);
    $parameters[] = ($page-1)*20;$offset='$'.count($parameters);
    $entries=synth_diary_browser_rows($db,$cte.' SELECT * FROM written_diaries'.$where
        .' ORDER BY written_at DESC,id DESC LIMIT '.$limit.' OFFSET '.$offset,$parameters);
    $authors=synth_diary_browser_rows($db,$cte
        .' SELECT author_key,author,role,count(*) AS total FROM written_diaries GROUP BY author_key,author,role ORDER BY author,role LIMIT 1000');
    $saves=synth_diary_browser_rows($db,$cte
        .' SELECT save_key,max(player_name) AS player_name,max(written_at) AS written_at FROM written_diaries GROUP BY save_key ORDER BY written_at DESC LIMIT 1000');
    return compact('filters','entries','authors','saves','total')+['pages'=>max(1,(int)ceil($total/20))];
}

function synth_diary_browser_h($value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function synth_diary_browser_time(string $value): string
{
    $time=strtotime($value);
    return $time===false ? 'Date unavailable' : gmdate('d M Y, H:i',$time).' UTC';
}

function synth_diary_browser_save_label(array $row): string
{
    return ($row['player_name'] ?? 'Sole Survivor').' · '.synth_diary_browser_time($row['written_at'] ?? '')
        .' · '.substr($row['save_key'] ?? '',0,4);
}

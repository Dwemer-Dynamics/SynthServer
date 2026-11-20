<?php

// Moved from lib/data_functions.php: party, beings in range and nearby actor/item payloads
// The original file requires this one, so every caller is unchanged.

function PackIntoSummary($onlyMissingDiary=false)
{

    $db = \Synth\Bootstrap::db();

    if ($onlyMissingDiary) {
        $results = $db->query("insert into core_memory (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope)
        select gamets,1,message,message,'diary',uid,
            case
                when nullif(trim(speaker), '') is null then ''
                else '|' || trim(both '|' from trim(speaker)) || '|'
            end,
            'global'
        from core_memory_log
        where event in ('diary','auto_diary')
        and uid not in (select uid from core_memory where source_type='summary' AND classifier in  ('diary','auto_diary'))");

        $maxRow=0;

    } else {
        $lastGameTsRecord = \Synth\Bootstrap::db()->fetchOne("select gamets as gamets from core_context_event order by gamets desc LIMIT 1"); // 2.1ms
        $results = \Synth\Bootstrap::db()->fetchAll("select gamets_truncated from core_memory where source_type='summary' order by gamets_truncated desc LIMIT 1"); // 0.5ms, faster

        $maxRow = isset($results[0]["gamets_truncated"]) ? intval($results[0]["gamets_truncated"]) : 0;
        $minRow = intval($lastGameTsRecord["gamets"]);
        $minRowTs = intval($lastGameTsRecord["gamets"] -  ( 1 /0.0000024));

        $pfi = intval(\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_INTERVAL"] ?? 10) * 100000;
        $minEventsPerSummary = intval(\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["AUTO_CREATE_SUMMARY_MIN_EVENTS"] ?? 5);
        if ($minEventsPerSummary < 1) {
            $minEventsPerSummary = 1;
        }
        // Queue boundaries are hard-cut by location changes. Dialogue rows inherit
        // the most recent world-context location so they remain in the scene queue.
        $query="insert into core_memory (gamets_truncated,n,packed_message,summary,classifier,uid,scope,companions)
                                with source_rows as (
                                    select
                                        uid,
                                        gamets,
                                        coalesce(ts, 0) as ts,
                                        message,
                                        speaker,
                                        listener,
                                        round(gamets::numeric/$pfi, 0) as time_bucket,
                                        trim(regexp_replace(coalesce(
                                            substring(message from '(?i)\\(context\\s+(?:new\\s+)?location:\\s*([^,\\)]+)'),
                                            substring(message from '(?i)\\(at\\s+([^\\)]+)\\)'),
                                            ''
                                        ), '\\s+', ' ', 'g')) as location_key
                                    from memory_v
                                    where message not ilike 'Dear Diary%'
                                      and gamets>$maxRow
                                ),
                                location_grouped_rows as (
                                    select
                                        *,
                                        count(location_key) filter (where location_key <> '') over (
                                            order by gamets asc, ts asc, uid asc
                                            rows between unbounded preceding and current row
                                        ) as location_group
                                    from source_rows
                                ),
                                normalized_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        time_bucket,
                                        case
                                            when location_group=0 then null
                                            else lower(max(nullif(location_key, '')) over (partition by location_group))
                                        end as location_key
                                    from location_grouped_rows
                                ),
                                queue_boundaries as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        time_bucket,
                                        location_key,
                                        lag(location_key) over (order by gamets asc, ts asc, uid asc) as prev_location_key,
                                        lag(time_bucket) over (order by gamets asc, ts asc, uid asc) as prev_time_bucket
                                    from normalized_rows
                                ),
                                queued_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        case
                                            when prev_time_bucket is null then 1
                                            when location_key is null then 1
                                            when prev_location_key is null then 1
                                            when location_key<>prev_location_key then 1
                                            when time_bucket<>prev_time_bucket then 1
                                            else 0
                                        end as is_new_queue
                                    from queue_boundaries
                                ),
                                grouped_rows as (
                                    select
                                        uid,
                                        gamets,
                                        ts,
                                        message,
                                        speaker,
                                        listener,
                                        sum(is_new_queue) over (
                                            order by gamets asc, ts asc, uid asc
                                            rows between unbounded preceding and current row
                                        ) as queue_id
                                    from queued_rows
                                ),
                                grouped_summaries as (
                                    select
                                        queue_id,
                                        max(gamets) as gamets_truncated,
                                        count(*) as n,
                                        STRING_AGG(message, chr(13) || chr(10) || chr(13) || chr(10) order by gamets asc, ts asc, uid asc) AS packed_message,
                                        NULL as summary,
                                        'dialogue' as classifier,
                                        max(uid) as uid,
                                        'global' as scope
                                    from grouped_rows
                                    group by queue_id
                                    having count(*)>=$minEventsPerSummary
                                ),
                                queue_participants as (
                                    select queue_id, '|' || string_agg(name, '|' order by name) || '|' as companions
                                    from (
                                        select distinct
                                            queue_id,
                                            trim(regexp_replace(person, '\\s*\\([^)]*\\)\\s*$', '', 'g')) as name
                                        from grouped_rows
                                        cross join lateral unnest(array[speaker, listener]) as participant(person)
                                        where lower(trim(person)) not in ('', '-', '--', 'unknown', 'the narrator')
                                    ) participant_names
                                    where name <> ''
                                    group by queue_id
                                )
                                select * from (
                                    select
                                        summaries.gamets_truncated,
                                        summaries.n,
                                        summaries.packed_message,
                                        summaries.summary,
                                        summaries.classifier,
                                        summaries.uid,
                                        summaries.scope,
                                        participants.companions
                                    from grouped_summaries summaries
                                    left join queue_participants participants on participants.queue_id=summaries.queue_id
                                    order by summaries.gamets_truncated asc
                                ) as T
                                where gamets_truncated>$maxRow and gamets_truncated<$minRowTs";
        //error_log($query);

        $results = $db->query($query);

        $results = $db->query("insert into core_memory (gamets_truncated,n,packed_message,summary,classifier,uid,companions,scope)
                                    select gamets,1,message,message,'diary',uid,
                                        case
                                            when nullif(trim(speaker), '') is null then ''
                                            else '|' || trim(both '|' from trim(speaker)) || '|'
                                        end,
                                        'global'
                                    from core_memory_log
                                    where event='diary'
                                    and gamets>$maxRow
                                ");

    }


    return $maxRow;
}

function DataRechatHistory()
{

    $db = \Synth\Bootstrap::db();
    // Include only actual rechat turns. Player input is not part of the rechat budget.
    // Keep the row payload so callers can scope the count to the current speaker.
    $lastRechat=$db->fetchAll("select type,data,gamets FROM  core_context_event a  WHERE type in ('rechat','narration')
    and localts>".(time()-120)."  order by gamets desc,ts desc LIMIT 10 OFFSET 0");

    return $lastRechat;

}

function extractDialogueTarget($string) {
    // Check if the string contains a directed-dialogue tag.
    if ($string && preg_match('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+/i', $string)) {
        // Extract the target's name using regular expression
        preg_match('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+([^\)]+)\)/i', $string, $matches);

        // Check if a match is found and extract the target's name
        if (isset($matches[1])) {
            $target = $matches[1];

            // Remove the directed-dialogue tag from the original string
            $cleanedString = preg_replace('/\((?:talking|whispering|shouting|speaking privately)\s+to\s+[^\)]+\)/i', '', $string);
            if (strpos($cleanedString,"{$GLOBALS["SYNTH_NAME"]}:")===0) {
                $cleanedString=str_replace("{$GLOBALS["SYNTH_NAME"]}:","",$cleanedString);
            }

            return ['target' => $target, 'cleanedString' => trim($cleanedString)];
        }
    }

    // Return the original string if no target is found
    return ['target' => null, 'cleanedString' => $string];
}

function DataGetTrackedStat($stat) {
    $db = \Synth\Bootstrap::db();

    // Try to get from core_player table first
    try {
        require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
        $player = new Player();
        $value = $player->get($stat);

        if ($value !== null) {
            return json_encode([['id' => $stat, 'value' => $value]]);
        }
    } catch (Exception $e) {
        Logger::debug("Could not read stat from core_player: " . $e->getMessage());
    }

    return json_encode([]);
}

function ResolvePlayerBackstory($player = null): string
{
    $playerBio = '';

    if ($player === null) {
        try {
            require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
            $player = new Player();
        } catch (Throwable $e) {
            Logger::debug("Could not initialize Player while resolving backstory: " . $e->getMessage());
        }
    }

    if ($player instanceof Player) {
        try {
            $playerBio = trim((string)($player->get('bio') ?? ''));
        } catch (Throwable $e) {
            Logger::debug("Could not read player bio from core_player: " . $e->getMessage());
        }
    }

    if ($playerBio !== '') {
        return $playerBio;
    }

    return '';
}

function synthPartyRowsToConf(array $guys)
{
    $finalparty=[];
    foreach ($guys as $guy) {
        if (isset($guy["name"])) {
            $finalparty[$guy["name"]]=$guy;
            $npcMaster=new NpcMaster();
            $currentNpcData=$npcMaster->getByName($guy["name"]);
            if (isset($currentNpcData["core"])&&!empty($currentNpcData["core"]))
                $finalparty[$guy["name"]]["core"]=$currentNpcData["core"];

        }
    }

    return json_encode($finalparty);
}

function synthGetCurrentPartyFromConfOpts()
{
    $db = \Synth\Bootstrap::db();

    $results = $db->fetchAll("SELECT config_value AS value FROM public.core_config_setting WHERE scope = 'conf' AND config_key='CurrentParty'");
    if (!is_array($results) || empty($results)) {
        return json_encode([]);
    }

    $partyData = trim((string)($results[0]["value"] ?? ""));
    if ($partyData === "") {
        return json_encode([]);
    }

    $partyData = rtrim($partyData, ',');
    $guys = json_decode("[" . $partyData . "]", true);
    if (!is_array($guys)) {
        Logger::warn("DataGetCurrentPartyConf: Failed to parse CurrentParty JSON");
        return json_encode([]);
    }

    return synthPartyRowsToConf($guys);
}

function DataGetCurrentPartyConf() {
    return synthGetCurrentPartyFromConfOpts();
}

function DataBeingsInRange()
{
    if (isset($GLOBALS["CACHE_BEINGS_IN_RANGE"])) {
        return \Synth\Bootstrap::config("CACHE_BEINGS_IN_RANGE");
    }

    $names = synthNearbyActorNamesFromPayload(false, true);
    $GLOBALS["CACHE_BEINGS_IN_RANGE"] = empty($names) ? "" : "|" . implode("|", $names) . "|";
    return \Synth\Bootstrap::config("CACHE_BEINGS_IN_RANGE");
}

function DataBeingsInRangeExcluding($excludeNPC="", $excludePlayer=true)
{
    if (isset($GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer])) {
        return \Synth\Bootstrap::config("CACHE_BEINGS_IN_RANGE_EXCLUDING")[$excludeNPC][(int)$excludePlayer];
    }

    if (trim($excludeNPC) > "")
        $exNPC = trim($excludeNPC);
    else
        $exNPC = "x_y_z";

    $beingsArrayNew=[];
    foreach (synthNearbyActorNamesFromPayload(false, !$excludePlayer) as $name) {
        if (strpos($name, $exNPC) !== 0) {
            $beingsArrayNew[] = $name;
        }
    }
    if (empty($beingsArrayNew)) {
        $GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "";
        return "";
    }
    $beingsFormatted=implode("|",$beingsArrayNew);
    $GLOBALS["CACHE_BEINGS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "|".$beingsFormatted."|";
    return \Synth\Bootstrap::config("CACHE_BEINGS_IN_RANGE_EXCLUDING")[$excludeNPC][(int)$excludePlayer];
}

function DataBeingsOrDeathsInRangeExcluding($excludeNPC="", $excludePlayer=true)
{
    if (isset($GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer])) {
        return \Synth\Bootstrap::config("CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING")[$excludeNPC][(int)$excludePlayer];
    }

    if (trim($excludeNPC) > "")
        $exNPC = trim($excludeNPC);
    else
        $exNPC = "x_y_z";

    $beingsArrayNew=[];
    foreach (synthNearbyActorNamesFromPayload(false, !$excludePlayer) as $name) {
        if (strpos($name, $exNPC) !== 0) {
            $beingsArrayNew[] = $name;
        }
    }
    if (empty($beingsArrayNew)) {
        $GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "";
        return "";
    }
    $beingsFormatted=implode("|",$beingsArrayNew);
    $GLOBALS["CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING"][$excludeNPC][(int)$excludePlayer] = "|".$beingsFormatted."|";
    return \Synth\Bootstrap::config("CACHE_BEINGS_OR_DEATHS_IN_RANGE_EXCLUDING")[$excludeNPC][(int)$excludePlayer];
}

function synthDataActorStatusSuffixPattern()
{
    return '/\s*\((?:busy|hostile|in combat|far away|too far away|too_far|too_quiet|closed_door|different_interior_cells|interior_exterior_boundary|navmesh_no_path|path_unavailable|path_ratio_blocked|path_ratio_distance_blocked|invalid_actor|invalid_position|invalid_distance|restrained|dead|disabled|unavailable|audible|narrator|checking(?: hearing|: [^)]+)?|can hear you(?:, muffled|: [^)]+)?|can[\'"]?t hear you(?: clearly)?(?:: [^)]+)?|no (?:target|crosshair target))\)\s*$/iu';
}

function synthDataStripActorStateSuffix($name)
{
    $name = trim((string)$name);
    if ($name === "") {
        return "";
    }

    $name = trim($name, "|");
    $name = preg_replace(synthDataActorStatusSuffixPattern(), '', $name);
    return trim((string)$name);
}

function synthDataActorStatusBlocksCloseRange($token)
{
    if (!preg_match('/\s*\(([^()]*)\)\s*$/u', (string)$token, $matches)) {
        return false;
    }

    $status = strtolower(trim((string)$matches[1]));
    if ($status === "") {
        return false;
    }

    if (strpos($status, "can hear you") === 0) {
        return false;
    }

    return preg_match('/^(?:busy|hostile|in combat|far away|too far away|too_far|too_quiet|closed_door|different_interior_cells|interior_exterior_boundary|navmesh_no_path|path_unavailable|path_ratio_blocked|path_ratio_distance_blocked|invalid_actor|invalid_position|invalid_distance|restrained|dead|disabled|unavailable|checking|can[\'"]?t hear you|no target|no crosshair target)/i', $status) === 1;
}

function synthLatestNearbyActorsPayload()
{
    $db = \Synth\Bootstrap::db();

    $rows = $db->fetchAll("SELECT party FROM core_context_event WHERE type='nearby_actors' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    return is_array($payload) ? $payload : null;
}

function synthPeoplePipeFromNearbyActorsPayload($excludeFarAway = false)
{
    $payload = synthLatestNearbyActorsPayload();
    if (!is_array($payload)) {
        return "";
    }

    $actors = $payload['actors'] ?? [];
    if (!is_array($actors)) {
        return "";
    }

    $names = [];
    foreach ($actors as $actor) {
        if (!is_array($actor)) {
            continue;
        }

        $name = trim((string)($actor['name'] ?? ''));
        if ($name === "" || $name === "<no name>") {
            continue;
        }

        $sceneEligible = filter_var($actor['scene_eligible'] ?? ($actor['eligible'] ?? true), FILTER_VALIDATE_BOOLEAN);
        if (!$sceneEligible) {
            continue;
        }

        $status = trim((string)($actor['status'] ?? ''));
        $token = $name;
        if ($status !== "" && stripos($status, "can hear you") !== 0) {
            $token .= " ({$status})";
        }

        if ($excludeFarAway && synthDataActorStatusBlocksCloseRange($token)) {
            continue;
        }
        if (preg_match('/\((?:dead|disabled)\)\s*$/i', $token)) {
            continue;
        }

        $cleanName = synthDataStripActorStateSuffix($token);
        if ($cleanName === "") {
            continue;
        }

        if (!in_array($cleanName, $names, true)) {
            $names[] = $cleanName;
        }
    }

    $player = trim((string)($payload['player'] ?? ''));
    if ($player !== "" && !in_array($player, $names, true)) {
        $names[] = $player;
    }

    if (empty($names)) {
        return "";
    }

    return "|" . implode("|", $names) . "|";
}

function synthNearbyActorNamesFromPayload($excludeFarAway = false, $includePlayer = true)
{
    $pipe = synthPeoplePipeFromNearbyActorsPayload($excludeFarAway);
    if ($pipe === "") {
        return [];
    }

    $names = array_values(array_filter(array_map('trim', explode('|', trim($pipe, '|'))), static function ($name) {
        return $name !== '';
    }));

    if (!$includePlayer) {
        $player = trim((string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? ""));
        if ($player !== "") {
            $names = array_values(array_filter($names, static function ($name) use ($player) {
                return strcasecmp($name, $player) !== 0;
            }));
        }
    }

    return $names;
}

function DataBeingsInCloseRange($excludeFarAway=false)
{
    if (!empty($GLOBALS["SYNTH_ROLEMASTER_BORED_ACTORS"]) &&
        is_array(\Synth\Bootstrap::config("SYNTH_ROLEMASTER_BORED_ACTORS"))) {
        return "|" . implode("|", array_values(\Synth\Bootstrap::config("SYNTH_ROLEMASTER_BORED_ACTORS"))) . "|";
    }

    $structuredPeople = synthPeoplePipeFromNearbyActorsPayload($excludeFarAway);
    if ($structuredPeople !== "") {
        return $structuredPeople;
    }

    return "";
}

function synthLatestNearbyItemsPayload()
{
    $db = \Synth\Bootstrap::db();

    $rows = $db->fetchAll("SELECT party FROM core_context_event WHERE type='nearby_items' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    return is_array($payload) ? $payload : null;
}

function synthNearbyPayloadFirstString(array $item, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $value = trim((string)$item[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function synthNormalizeNearbyPayloadItem(array $item): ?array
{
    $refId = synthNearbyPayloadFirstString($item, ['refid', 'ref_id', 'reference_id', 'reference', 'id']);
    $baseId = synthNearbyPayloadFirstString($item, ['baseid', 'base_id', 'formid', 'form_id', 'base_formid']);
    $name = synthNearbyPayloadFirstString($item, ['name', 'item_name', 'display_name', 'base_name']);

    if ($name === '' || strcasecmp($name, '<no name>') === 0 || strcasecmp($name, 'Missing Name') === 0) {
        return null;
    }
    if ($refId === '' && $baseId === '') {
        return null;
    }
    if ($refId === '') {
        $refId = $baseId;
    }
    if ($baseId === '' || strcasecmp($baseId, 'Unknown') === 0) {
        $baseId = $refId;
    }

    $item['refid'] = $refId;
    $item['baseid'] = $baseId;
    $item['name'] = $name;
    return $item;
}

function synthAppendUniqueNearbyPayloadItem(array &$items, array $item): void
{
    $needle = strtolower(($item['refid'] ?? '') . '|' . ($item['name'] ?? ''));
    foreach ($items as $existing) {
        $existingKey = strtolower(($existing['refid'] ?? '') . '|' . ($existing['name'] ?? ''));
        if ($existingKey !== '' && $existingKey === $needle) {
            return;
        }
    }

    $items[] = $item;
}

function synthNormalizeNearbyPayloadItemsContainer($items): array
{
    if (!is_array($items)) {
        return [];
    }

    foreach (['refid', 'ref_id', 'reference_id', 'reference', 'id', 'baseid', 'base_id', 'formid', 'form_id', 'name', 'item_name', 'display_name'] as $field) {
        if (array_key_exists($field, $items)) {
            return [$items];
        }
    }

    return array_values($items);
}

function synthItemsStringFromNearbyItemsPayload()
{
    $payload = synthLatestNearbyItemsPayload();
    if (!is_array($payload)) {
        return "";
    }

    $items = synthNormalizeNearbyPayloadItemsContainer($payload['items'] ?? []);
    if (empty($items) && (!isset($payload['held_item']) || !is_array($payload['held_item']))) {
        return "";
    }

    $normalizedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized = synthNormalizeNearbyPayloadItem($item);
        if ($normalized !== null) {
            synthAppendUniqueNearbyPayloadItem($normalizedItems, $normalized);
        }
    }
    if (isset($payload['held_item']) && is_array($payload['held_item'])) {
        $heldItem = synthNormalizeNearbyPayloadItem($payload['held_item']);
        if ($heldItem !== null) {
            $heldItem['holding'] = true;
            synthAppendUniqueNearbyPayloadItem($normalizedItems, $heldItem);
        }
    }
    $items = $normalizedItems;
    if (empty($items)) {
        return "";
    }

    $playerName = trim((string)($payload['player'] ?? (\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player")));
    if ($playerName === "") {
        $playerName = "Player";
    }

    $parts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $refId = trim((string)($item['refid'] ?? ''));
        $baseId = trim((string)($item['baseid'] ?? ''));
        $name = trim((string)($item['name'] ?? ''));
        if ($refId === "" || $baseId === "" || $name === "" || $name === "<no name>") {
            continue;
        }

        if (filter_var($item['stealing'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $name .= " (STEALING)";
        }
        if (filter_var($item['looking_at'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $name .= " ({$playerName} is looking at this)";
        }
        if (filter_var($item['holding'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $name .= " ({$playerName} is holding this)";
        }

        $parts[] = "{$refId}:{$baseId}:{$name}";
    }

    return implode(',', $parts);
}

function DataItemsInCloseRange()
{
    $structuredItems = synthItemsStringFromNearbyItemsPayload();
    if ($structuredItems !== "") {
        return $structuredItems;
    }

    return "";
}

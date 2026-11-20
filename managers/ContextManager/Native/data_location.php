<?php

// Moved from lib/data_functions.php: traveling party, world context and location/region/weather text
// The original file requires this one, so every caller is unchanged.

function synthGetLatestTravelingPartyMemberNames(): array
{
    $party = json_decode(DataGetCurrentPartyConf(), true);
    if (!is_array($party)) {
        return [];
    }

    $names = [];
    foreach (array_keys($party) as $name) {
        $name = trim((string)$name);
        if ($name === '' || $name === '<no name>') {
            continue;
        }

        $names[] = $name;
    }

    return array_values(array_unique($names));
}

function synthIsActorInCurrentTravelingParty(string $actorName): bool
{
    $actorName = trim($actorName);
    if ($actorName === '') {
        return false;
    }

    foreach (synthGetLatestTravelingPartyMemberNames() as $memberName) {
        if (strcasecmp(trim((string)$memberName), $actorName) === 0) {
            return true;
        }
    }

    return false;
}

function DataGetCurrentTask(string $scope = 'journal')
{
    $db = \Synth\Bootstrap::db();

    if (!in_array($scope,['journal','speaker'],true)) throw new InvalidArgumentException('Unknown quest prompt scope.');

    $includeActiveQuests = function_exists('synthPromptContextOptionEnabled')
        ? synthPromptContextOptionEnabled('enabled_general_subsections', 'active_quests')
        : true;

    if (!$includeActiveQuests) {
        return "";
    }

    $event=\Synth\Bootstrap::requestEvent()['json'] ?? [];
    if (($event['protocol_version'] ?? 1)===2) {
        require_once dirname(__DIR__, 3).'/lib'.'/quest_knowledge.php';
        $context=synth_protocol_current_context($db,$event);
        return synthQuestKnowledgePrompt(synthQuestKnowledgeView($db,$event,$context ?? [],null,
            synth_protocol_quest_response_is_narrator($db,$event)));
    }

    $data = "";

    // Explicit journal readers retain the player's view. Automatic NPC prompts
    // use only the request-bound change authorized by quest reaction admission.
    $snapshot = $scope==='speaker' ? synth_protocol_speaker_quest_snapshot($db) : synth_protocol_prompt_snapshot($db);
    if (($snapshot['_synth_quest_prompt_scope'] ?? '')==='none') return '';
    $results = $snapshot !== null ? array_slice(synthSnapshotQuestRows($snapshot), 0, 8)
        : $db->fetchAll("SElECT name, briefing as description,gamets,status FROM core_quest order by CASE WHEN status='selected' THEN 0 ELSE 1 END, gamets desc LIMIT 8");
    if (!$results) {
        if ($snapshot !== null) {
            return "\n\n#Active Quests\n" . (array_key_exists('active_quests', $snapshot)
                ? 'No active quests in the captured journal snapshot.' : 'Quest journal observation unavailable.');
        }
        Logger::info("No quests ".__FILE__." ".__LINE__." ".__FUNCTION__);
        return $data . "\n\n#Active Quests\nNo active quests right now.";
    }

    // dont think we need to limit it now since we dont require exactly two to format Current: xxx Previous: yyy
    //    if (sizeof($results)>2) {
    //        Logger::info("Too much quests ".__FILE__);
    //        return $data;
    //    }

    $heading=($snapshot['_synth_quest_prompt_scope'] ?? '')==='reaction' ? 'Observed Quest Change' : 'Active Quests';
    $data .= "\n\n<active_quests>\n#{$heading}\n";
    foreach ($results as $row) {
        $questDesc = trim($row["description"]);
        if (strcasecmp($questDesc, trim((string)$row["name"])) === 0) {
            $questDesc = "";
        }
        $questPrefix = (($row["status"] ?? '') === 'selected') ? 'Current: ' : '';
        if (!empty($questDesc)) {
            $data .= "## {$questPrefix}{$row["name"]}: $questDesc\n";
        } else {
            $data .= "## {$questPrefix}{$row["name"]}\n";
        }
    }
    $data .="</active_quests>\n";
    return $data;
}

function DataLastRetFunc($actor, $lastNelements = -2)
{
    $db = \Synth\Bootstrap::db();
    $lastDialogFull = array();
    $results = $db->fetchAll("select  a.data  as data  FROM  core_context_event a
    WHERE data like '%$actor%' and type in ('funcret')  order by gamets desc,ts desc LIMIT 1 OFFSET 0");
    $lastData = "";
    foreach ($results as $row) {
        $pattern = "/\{(.*?)\(/";
        preg_match($pattern, $row["data"], $matches);
        $functionName = $matches[1];
        $lastDialogFull[] = array('role' => 'function', 'name' => $functionName, 'content' => $row["data"]);

    }

    $lastDialogFullReversed = array_reverse($lastDialogFull);
    $lastDialog = array_slice($lastDialogFullReversed, $lastNelements);
    $last_location = null;

    // Remove Context Location part when repeated
    foreach ($lastDialog as $k => $message) {
        preg_match('/\(Context location: [^)]+?\)/', $message['content'], $matches);
        $current_location = isset($matches[1]) ? $matches[1] : null;
        if ($current_location === $last_location) {
            $message['content'] = preg_replace('/\(Context location: [^)]+?\)/', '', $message['content']);
        } else {
            $last_location = $current_location;
        }
        $lastDialog[$k]["content"] = $message['content'];
    }


    return $lastDialog;

}

function DataLastAction($actor)
{
    $db = \Synth\Bootstrap::db();

    $lastDialogFull = array();
    $cnActor = $db->escape($actor);
    $results = $db->fetchOne("select  *  FROM public.core_action_issued
    WHERE actorname='$cnActor' order by gamets desc,ts desc LIMIT 1 OFFSET 0");

    return $results;

}

function DataActorHasDied($actor)
{
    $db = \Synth\Bootstrap::db();

    $lastDialogFull = array();
    $cnActor = $db->escape($actor);

    $rows = \Synth\Bootstrap::db()->fetchAll("select 1 as n,gamets from core_context_event where type='death'
        and (data like '%defeated $cnActor%' or data like '%killed $cnActor%')
        order by gamets desc limit 1");
    if ($rows)
        return true;

    return false;

}

function DataLastKnowDate()
{
    $snapshot = synth_protocol_prompt_snapshot(\Synth\Bootstrap::db() ?? null);
    if ($snapshot !== null) return synthWorldContextDateText($snapshot['world'] ?? null);
    if (isset($GLOBALS["CACHE_LAST_KNOW_DATE"])) {
        return \Synth\Bootstrap::config("CACHE_LAST_KNOW_DATE");
    }

    $payloadDate = synthWorldContextDateText(synthLatestWorldContextPayload());
    if ($payloadDate !== "") {
        $GLOBALS["CACHE_LAST_KNOW_DATE"] = $payloadDate;
        return \Synth\Bootstrap::config("CACHE_LAST_KNOW_DATE");
    }

    $GLOBALS["CACHE_LAST_KNOW_DATE"] = "";
    return "";
}

function DataLastKnownLocation()
{
    $snapshot = synth_protocol_prompt_snapshot(\Synth\Bootstrap::db() ?? null);
    if ($snapshot !== null) return synthWorldContextLocationFromPayload($snapshot['world'] ?? null);
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION"])) {
        return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION");
    }

    $location = synthWorldContextLocationFromPayload(synthLatestWorldContextPayload());
    $GLOBALS["CACHE_LAST_KNOWN_LOCATION"] = $location;
    return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION");

}

function synthWorldContextIsUnknown($value): bool
{
    return \Synth\Managers\WorldManager\WorldManager::isUnknown($value);
}

function synthLatestWorldContextPayload(): ?array
{
    $db = \Synth\Bootstrap::db();
    $snapshot = synth_protocol_prompt_snapshot($db);
    if ($snapshot !== null) return is_array($snapshot['world'] ?? null) ? $snapshot['world'] : null;

    $rows = $db->fetchAll(
        "SELECT party, data
           FROM core_context_event
          WHERE type='world_context'
            AND COALESCE(party, '') <> ''
          ORDER BY localts DESC, ts DESC, rowid DESC
          LIMIT 1"
    );
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    if (!is_array($payload)) {
        return null;
    }

    $contextText = (string)($rows[0]['data'] ?? '');
    if ($contextText !== '' && preg_match('/\(\s*Context\s+location:\s*([^,\)]+)/iu', $contextText, $matches)) {
        $contextLocation = trim((string)($matches[1] ?? ''));
        $payloadLocation = trim((string)($payload['location'] ?? ''));
        $payloadWorldspace = trim((string)($payload['worldspace'] ?? ''));
        if ($contextLocation !== ''
            && !synthWorldContextIsUnknown($contextLocation)
            && (synthWorldContextIsUnknown($payloadLocation) || strcasecmp($payloadLocation, $payloadWorldspace) === 0)
        ) {
            $payload['location'] = $contextLocation;
        }
    }

    return $payload;
}

function synthWorldContextLocationFromPayload(?array $payload): string
{
    return \Synth\Managers\WorldManager\WorldManager::fromPayload($payload)->location();
}

function synthWorldContextWorldspaceFromPayload(?array $payload): string
{
    return \Synth\Managers\WorldManager\WorldManager::fromPayload($payload)->worldspace();
}

function synthWorldContextDateText(?array $payload): string
{
    return \Synth\Managers\WorldManager\WorldManager::fromPayload($payload)->date();
}

function synthWorldContextTimeText(?array $payload): string
{
    return \Synth\Managers\WorldManager\WorldManager::fromPayload($payload)->time();
}

function normalizeLocationContextToken($value, $stripStateSuffix = false)
{
    $value = trim((string) $value);
    if ($value === "") {
        return "";
    }

    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string) $value, " \t\n\r\0\x0B,");

    if ($stripStateSuffix) {
        $value = preg_replace('/\s+(outdoors|interior)\s*$/iu', '', $value);
        $value = trim((string) $value, " \t\n\r\0\x0B,");
    }

    return $value;
}

function getCanonicalRegionGroups()
{
    return [
        "Commonwealth" => ["Commonwealth", "Boston", "Massachusetts"],
        "Far Harbor" => ["Far Harbor", "The Island", "Mount Desert Island"],
        "Nuka-World" => ["Nuka-World", "Nuka World"],
    ];
}

function canonicalizeRegionName($value)
{
    static $aliasMap = null;

    if ($aliasMap === null) {
        $aliasMap = [];
        foreach (getCanonicalRegionGroups() as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $aliasKey = strtolower(normalizeLocationContextToken($alias, true));
                if ($aliasKey !== "") {
                    $aliasMap[$aliasKey] = $canonical;
                }
            }
        }
    }

    $valueKey = strtolower(normalizeLocationContextToken($value, true));
    if ($valueKey === "") {
        return "";
    }

    return $aliasMap[$valueKey] ?? "";
}

function getCanonicalRegionAliases($value)
{
    $canonical = canonicalizeRegionName($value);
    $groups = getCanonicalRegionGroups();

    if ($canonical !== "" && isset($groups[$canonical])) {
        return $groups[$canonical];
    }

    $value = normalizeLocationContextToken($value, true);
    return $value !== "" ? [$value] : [];
}

function DataLastKnownLocationContextParts($cached = false)
{
    $snapshot = synth_protocol_prompt_snapshot(\Synth\Bootstrap::db() ?? null);
    if ($snapshot !== null) {
        $location = synthWorldContextLocationFromPayload($snapshot['world'] ?? null);
        return ['location' => $location, 'location_base' => normalizeLocationContextToken($location, true), 'region_raw' => ''];
    }
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"])) {
        return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS");
    }

    $payload = synthLatestWorldContextPayload();
    $payloadLocation = synthWorldContextLocationFromPayload($payload);
    if ($payloadLocation !== "") {
        $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"] = [
            "location" => $payloadLocation,
            "location_base" => normalizeLocationContextToken($payloadLocation, true),
            "region_raw" => "",
        ];
        return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS");
    }

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS"] = [
        "location" => "",
        "location_base" => "",
        "region_raw" => "",
    ];

    return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS");
}

function DataLastKnownLocationBaseHuman($cached = false)
{
    $parts = DataLastKnownLocationContextParts($cached);
    return $parts["location_base"] ?? "";
}

function locationFieldMatchesCandidate($row, $field, $candidateKey)
{
    if (!isset($row[$field])) {
        return false;
    }

    return strtolower(normalizeLocationContextToken($row[$field], true)) === $candidateKey;
}

function resolveCanonicalRegionFromLocationRows($rows, $candidateKey)
{
    if (!is_array($rows) || empty($rows) || $candidateKey === "") {
        return "";
    }

    $prioritizedMatches = [
        ["matchField" => "name", "valueField" => "region"],
        ["matchField" => "region", "valueField" => "region"],
    ];

    foreach ($prioritizedMatches as $rule) {
        foreach ($rows as $row) {
            if (locationFieldMatchesCandidate($row, $rule["matchField"], $candidateKey)) {
                $canonical = canonicalizeRegionName($row[$rule["valueField"]] ?? "");
                if ($canonical !== "") {
                    return $canonical;
                }
            }
        }
    }

    foreach (["region", "name"] as $field) {
        foreach ($rows as $row) {
            $canonical = canonicalizeRegionName($row[$field] ?? "");
            if ($canonical !== "") {
                return $canonical;
            }
        }
    }

    return "";
}

function locationsTableColumns()
{
    $db = \Synth\Bootstrap::db();

    if (isset($GLOBALS["CACHE_LOCATIONS_TABLE_COLUMNS"])) {
        return \Synth\Bootstrap::config("CACHE_LOCATIONS_TABLE_COLUMNS");
    }

    $columns = [];
    try {
        $rows = $db->fetchAll(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'core_location_legacy'"
        );
        foreach ($rows as $row) {
            $column = strtolower((string) ($row["column_name"] ?? ""));
            if ($column !== "") {
                $columns[$column] = true;
            }
        }
    } catch (Throwable $e) {
        Logger::warn("[locations] Could not inspect locations table columns: " . $e->getMessage());
    }

    if (empty($columns)) {
        $columns["name"] = true;
    }

    $GLOBALS["CACHE_LOCATIONS_TABLE_COLUMNS"] = $columns;
    return $columns;
}

function lookupCanonicalRegionByLocationCandidate($candidate)
{
    $db = \Synth\Bootstrap::db();

    $candidateKey = trim(strtolower(normalizeLocationContextToken($candidate, true)));
    if ($candidateKey === "") {
        return "";
    }

    if (isset($GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey])) {
        return \Synth\Bootstrap::config("CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE")[$candidateKey];
    }

    $columns = locationsTableColumns();
    $candidateEsc = $db->escape($candidateKey);

    $selectFields = ["name"];
    $whereFields = ["name"];

    if (isset($columns["region"])) {
        $selectFields[] = "region";
        $whereFields[] = "region";
    } elseif (isset($columns["worldspace"])) {
        $selectFields[] = "worldspace AS region";
        $whereFields[] = "worldspace";
    } else {
        $selectFields[] = "'' AS region";
    }

    $whereClauses = [];
    foreach (array_unique($whereFields) as $field) {
        if (isset($columns[strtolower($field)])) {
            $whereClauses[] = "LOWER({$field}) = '{$candidateEsc}'";
        }
    }

    if (empty($whereClauses)) {
        $GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey] = "";
        return "";
    }

    $rows = $db->fetchAll(
        "SELECT " . implode(", ", $selectFields) . "
           FROM core_location_legacy
          WHERE " . implode(" OR ", $whereClauses)
    );

    $canonical = resolveCanonicalRegionFromLocationRows($rows, $candidateKey);
    if ($canonical === "" && is_array($rows)) {
        foreach ($rows as $row) {
            if (!locationFieldMatchesCandidate($row, "name", $candidateKey)) {
                continue;
            }

            $parentCandidate = normalizeLocationContextToken($row["region"] ?? "", true);
            if ($parentCandidate !== "" && $parentCandidate !== $candidateKey) {
                $canonical = lookupCanonicalRegionByLocationCandidate($parentCandidate);
                if ($canonical !== "") {
                    break;
                }
            }
        }
    }
    $GLOBALS["CACHE_CANONICAL_REGION_BY_LOCATION_CANDIDATE"][$candidateKey] = $canonical;

    return $canonical;
}

function DataLastKnownCanonicalRegionHuman($cached = false)
{
    $cacheKey = "REGION_CANONICAL";
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey])) {
        return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION_HUMAN")[$cacheKey];
    }

    $parts = DataLastKnownLocationContextParts($cached);
    $canonical = "";

    $currentLocation = $parts["location_base"] ?? "";
    if ($currentLocation !== "") {
        $canonical = lookupCanonicalRegionByLocationCandidate($currentLocation);
    }

    $reportedRegion = $parts["region_raw"] ?? "";
    if ($canonical === "" && $reportedRegion !== "") {
        $canonical = canonicalizeRegionName($reportedRegion);
    }
    if ($canonical === "" && $reportedRegion !== "") {
        $canonical = lookupCanonicalRegionByLocationCandidate($reportedRegion);
    }
    if ($canonical === "" && $reportedRegion !== "") {
        $canonical = normalizeLocationContextToken($reportedRegion, true);
    }

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey] = $canonical;
    return $canonical;
}

function DataLastKnownLocationHuman($region=false,$cached=false)
{

    $cache_key = $region ? "REGION" : "LOC";
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cache_key]))
        return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION_HUMAN")[$cache_key];

    $parts = DataLastKnownLocationContextParts($cached);
    $val = $region ? ($parts["region_raw"] ?? "") : ($parts["location"] ?? "");

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cache_key] = $val;
    return $val;

}

function buildWorldPrompt($gamets = 0)
{
    $world = \Synth\Managers\WorldManager\WorldManager::fromPayload(synthLatestWorldContextPayload());
    // The older lookups run only when the latest world_context payload has no location or weather.
    $fallbackLocation = trim($world->location()) === '' ? trim(DataLastKnownLocationHuman(false, false)) : '';
    $fallbackWeather = $world->weather() === '' ? trim(DataLastKnownWeatherHuman()) : '';
    return $world->promptBlock($fallbackLocation, $fallbackWeather);
}

function DataLastKnownWeatherHuman()
{
    $snapshot = synth_protocol_prompt_snapshot(\Synth\Bootstrap::db() ?? null);
    if ($snapshot !== null) return trim(strval($snapshot['world']['weather'] ?? ''));
    $cacheKey = "WEATHER";
    if (isset($GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey])) {
        return \Synth\Bootstrap::config("CACHE_LAST_KNOWN_LOCATION_HUMAN")[$cacheKey];
    }

    $db = \Synth\Bootstrap::db();

    $payload = synthLatestWorldContextPayload();
    if (is_array($payload)) {
        $payloadWeather = trim((string)($payload['weather'] ?? ''));
        if ($payloadWeather !== "") {
            $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey] = $payloadWeather;
            return $payloadWeather;
        }
    }

    $GLOBALS["CACHE_LAST_KNOWN_LOCATION_HUMAN"][$cacheKey] = "";
    return "";
}

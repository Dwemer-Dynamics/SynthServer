<?php

// Moved from lib/chat_helper_functions.php: people lists, audience, rechat mode and scoped people for events
// The original file requires this one, so every caller is unchanged.

function normalizePeoplePipeList($peopleNames)
{
    if (!is_array($peopleNames) || empty($peopleNames)) {
        return "";
    }

    $cleanPeople = [];
    foreach ($peopleNames as $name) {
        $name = trim((string)$name);
        $name = trim($name, "|");
        if ($name === "") {
            continue;
        }
        if (!shouldIncludeActorNameInPeopleList($name)) {
            continue;
        }
        if (!in_array($name, $cleanPeople, true)) {
            $cleanPeople[] = $name;
        }
    }

    if (empty($cleanPeople)) {
        return "";
    }

    return "|" . implode("|", $cleanPeople) . "|";
}

function parsePeoplePipeList($peoplePipe)
{
    $peoplePipe = trim((string)$peoplePipe);
    if ($peoplePipe === "") {
        return [];
    }

    $tokens = explode("|", $peoplePipe);
    $cleanPeople = [];
    foreach ($tokens as $token) {
        $token = trim((string)$token);
        if ($token === "") {
            continue;
        }
        if (!in_array($token, $cleanPeople, true)) {
            $cleanPeople[] = $token;
        }
    }

    return $cleanPeople;
}

function synthNormalizePeopleValue($value)
{
    $names = [];

    $appendName = static function ($candidate) use (&$names) {
        $candidate = trim((string)$candidate);
        $candidate = trim($candidate, "|");
        if ($candidate === "") {
            return;
        }
        if (!in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    };

    $appendValue = function ($candidate) use (&$appendValue, $appendName) {
        if (is_string($candidate)) {
            foreach (parsePeoplePipeList($candidate) as $name) {
                $appendName($name);
            }
            return;
        }

        if (!is_array($candidate)) {
            return;
        }

        foreach (["name", "actor_name", "display_name"] as $key) {
            if (isset($candidate[$key]) && is_scalar($candidate[$key])) {
                $appendName($candidate[$key]);
                return;
            }
        }

        foreach ($candidate as $item) {
            $appendValue($item);
        }
    };

    $appendValue($value);
    return normalizePeoplePipeList($names);
}

function synthDecodeAudienceSnapshotField($rawField)
{
    $rawField = trim((string)$rawField);
    if ($rawField === "") {
        return "";
    }

    $payload = json_decode($rawField, true);
    if (!is_array($payload)) {
        return "";
    }

    if (!empty($payload["people"])) {
        $people = synthNormalizePeopleValue($payload["people"]);
        if ($people !== "") {
            return $people;
        }
    }

    if (!empty($payload["companions"])) {
        return synthNormalizePeopleValue($payload["companions"]);
    }

    return "";
}

function synthSetCurrentTurnPeopleSnapshot($peoplePipe)
{
    $normalized = normalizePeoplePipeList(parsePeoplePipeList($peoplePipe));
    if ($normalized === "") {
        \Synth\Bootstrap::forget('SYNTH_TURN_PEOPLE_SNAPSHOT');
        return "";
    }

    \Synth\Bootstrap::set('SYNTH_TURN_PEOPLE_SNAPSHOT', $normalized);
    return $normalized;
}

function synthGetCurrentTurnPeopleSnapshot()
{
    if (!\Synth\Bootstrap::has('SYNTH_TURN_PEOPLE_SNAPSHOT')) {
        return "";
    }

    return normalizePeoplePipeList(parsePeoplePipeList(\Synth\Bootstrap::config("SYNTH_TURN_PEOPLE_SNAPSHOT")));
}

function normalizeDialogTextForComparison($text)
{
    $text = trim((string)$text);
    if ($text === "") {
        return "";
    }

    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim((string)$text);
    if ($text === "") {
        return "";
    }

    return mb_strtolower($text, 'UTF-8');
}

function synthActorStatusSuffixPattern()
{
    return '/\s*\((?:busy|hostile|in combat|far away|too far away|too_far|too_quiet|closed_door|different_interior_cells|interior_exterior_boundary|navmesh_no_path|path_unavailable|path_ratio_blocked|path_ratio_distance_blocked|invalid_actor|invalid_position|invalid_distance|restrained|dead|disabled|unavailable|audible|narrator|checking(?: hearing|: [^)]+)?|can hear you(?:, muffled|: [^)]+)?|can[\'"]?t hear you(?: clearly)?(?:: [^)]+)?|no (?:target|crosshair target))\)\s*$/iu';
}

function stripActorStateSuffix($name)
{
    $name = trim((string)$name);
    if ($name === "") {
        return "";
    }

    $name = trim($name, "|");
    $name = preg_replace(synthActorStatusSuffixPattern(), '', $name);
    return trim((string)$name);
}

function normalizeActorNameForComparison($name)
{
    $name = stripActorStateSuffix($name);
    if ($name === "") {
        return "";
    }

    return strtolower($name);
}

function isSystemContextLabel($name)
{
    $name = trim((string)$name);
    if ($name === "") {
        return true;
    }

    $nameLower = mb_strtolower($name, 'UTF-8');
    if ($nameLower[0] === '(' || $nameLower[0] === '[') {
        return true;
    }

    if (strpos($nameLower, "context ") === 0 || strpos($nameLower, "(context ") === 0) {
        return true;
    }

    return false;
}

function isLikelyGenericAudienceLabel($name)
{
    $normalized = normalizeActorNameForComparison($name);
    $normalized = preg_replace('/\s+/u', ' ', $normalized);
    $normalized = trim((string)$normalized);
    if ($normalized === "") {
        return true;
    }

    $blockedExact = [
        "everyone",
        "people",
        "the people",
        "local people",
        "nearby people",
        "locals",
        "the locals",
        "patrons",
        "local patrons",
        "the patrons",
        "crowd",
        "the crowd",
        "audience",
        "the audience",
        "bystanders",
        "onlookers",
        "townsfolk",
        "npcs",
        "nearby npcs",
        "nearby npc",
        "other patrons",
        "other people",
    ];
    if (in_array($normalized, $blockedExact, true)) {
        return true;
    }

    if (preg_match('/^(?:all\s+)?(?:local|nearby|other|the)?\s*(?:patrons?|people|locals?|npcs?|crowd|audience|bystanders?|onlookers?|townsfolk)$/u', $normalized)) {
        return true;
    }

    return false;
}

function shouldIncludeActorNameInPeopleList($name)
{
    $name = trim((string)$name);
    if ($name === "") {
        return false;
    }

    if (isSystemContextLabel($name)) {
        return false;
    }

    if (isLikelyGenericAudienceLabel($name)) {
        return false;
    }

    return true;
}

function isWhisperExecutionMode()
{
    $mode = \Synth\Bootstrap::has('SYNTH_EXECUTION_MODE') ? strtoupper(trim((string)\Synth\Bootstrap::config("SYNTH_EXECUTION_MODE"))) : "";
    return ($mode === "WHISPER");
}

function isCloseExecutionMode()
{
    $mode = \Synth\Bootstrap::has('SYNTH_EXECUTION_MODE') ? strtoupper(trim((string)\Synth\Bootstrap::config("SYNTH_EXECUTION_MODE"))) : "";
    return ($mode === "CLOSE");
}

function isPrivateConversationExecutionMode()
{
    return isWhisperExecutionMode() || isCloseExecutionMode();
}

function isShoutExecutionMode()
{
    $mode = \Synth\Bootstrap::has('SYNTH_EXECUTION_MODE') ? strtoupper(trim((string)\Synth\Bootstrap::config("SYNTH_EXECUTION_MODE"))) : "";
    return ($mode === "SHOUT");
}

function buildPrivateConversationPeople($listenerName = "")
{
    $participants = [];

    if (!empty(\Synth\Bootstrap::config('PLAYER_NAME'))) {
        appendUniqueActorName($participants, \Synth\Bootstrap::config('PLAYER_NAME'));
    }

    $listenerName = trim((string)$listenerName);
    if ($listenerName !== "") {
        appendUniqueActorName($participants, $listenerName);
    }

    return normalizePeoplePipeList($participants);
}

function buildWhisperPrivatePeople($listenerName = "")
{
    return buildPrivateConversationPeople($listenerName);
}

function buildDialogueTargetSuffix($listenerName, $isSpeakingLoudly = false)
{
    $listenerName = trim((string)$listenerName);
    if ($listenerName === "") {
        return "";
    }

    $listenerNames = parseDialogueListenerNames($listenerName);
    if (!empty($listenerNames)) {
        $listenerName = trim((string)$listenerNames[0]);
    }

    if ($listenerName === "") {
        return "";
    }

    if ($isSpeakingLoudly) {
        return "(speaking loudly to {$listenerName} from far away)";
    }

    if (isWhisperExecutionMode()) {
        return "(whispering to {$listenerName})";
    }

    if (isCloseExecutionMode()) {
        return "(speaking privately to {$listenerName})";
    }

    if (isShoutExecutionMode()) {
        return "(shouting to {$listenerName})";
    }

    return "(talking to {$listenerName})";
}

function parseDialogueListenerNames($listenerName)
{
    $listenerName = trim((string)$listenerName);
    if ($listenerName === "") {
        return [];
    }

    $parts = preg_split('/\s*(?:,|&|\band\b)\s*/iu', $listenerName);
    if (!is_array($parts)) {
        return [$listenerName];
    }

    $normalized = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== "") {
            $normalized[] = $part;
        }
    }

    return array_values(array_unique($normalized));
}

function synthGetRechatMode()
{
    $mode = strtolower(trim((string)(\Synth\Bootstrap::config("RECHAT_MODE") ?? "")));
    if (in_array($mode, ["tight", "conversational", "group", "random"], true)) {
        return $mode;
    }

    return "random";
}

function isOpenRechatEnabled()
{
    return synthGetRechatMode() !== "tight";
}

function synthBuildRechatModeSessionKey(array $members)
{
    $members = synthNormalizeRechatActorList($members);
    sort($members, SORT_NATURAL | SORT_FLAG_CASE);

    if (empty($members)) {
        $members[] = trim((string)(\Synth\Bootstrap::config("SYNTH_NAME") ?? "rechat"));
    }

    return md5(implode("|", $members) . "_" . floor(time() / 120));
}

function synthResolveEffectiveRechatMode($configuredMode, array $members)
{
    $configuredMode = strtolower(trim((string)$configuredMode));
    if ($configuredMode !== "random") {
        return $configuredMode;
    }

    $sessionKey = synthBuildRechatModeSessionKey($members);
    $modeFile = sys_get_temp_dir() . "/synth_rechat_mode_" . $sessionKey . ".json";

    if (file_exists($modeFile)) {
        $data = json_decode(file_get_contents($modeFile), true);
        $storedMode = strtolower(trim((string)($data["mode"] ?? "")));
        if (in_array($storedMode, ["tight", "conversational", "group"], true)) {
            return $storedMode;
        }
    }

    $randomModes = ["tight", "conversational", "group"];
    $rolledMode = $randomModes[array_rand($randomModes)];
    @file_put_contents($modeFile, json_encode([
        "mode" => $rolledMode,
        "configured_mode" => "random",
        "ts" => time(),
    ]));

    return $rolledMode;
}

function normalizeDialogueListenerName($listenerName)
{
    $listenerName = stripActorStateSuffix($listenerName);
    if ($listenerName === "") {
        return "";
    }

    if (strcasecmp($listenerName, "Sole Survivor") === 0 && !empty(\Synth\Bootstrap::config('PLAYER_NAME'))) {
        return trim((string)\Synth\Bootstrap::config("PLAYER_NAME"));
    }

    if (function_exists('synthNormalizeNarratorRoleplayActorName')) {
        return synthNormalizeNarratorRoleplayActorName($listenerName);
    }

    return $listenerName;
}

function isPlayerDialogueListenerName($listenerName)
{
    $listenerName = normalizeDialogueListenerName($listenerName);
    if ($listenerName === "") {
        return false;
    }

    $playerName = \Synth\Bootstrap::has('PLAYER_NAME') ? trim((string)\Synth\Bootstrap::config("PLAYER_NAME")) : "";
    if ($playerName !== "" && strcasecmp($listenerName, $playerName) === 0) {
        return true;
    }

    return in_array(strtolower($listenerName), ["player", "me"], true);
}

function synthGetStrictRechatListenerName()
{
    if (empty(\Synth\Bootstrap::config('ENFORCE_STRICT_RECHAT_RESPONSE'))) {
        return "";
    }

    if (
        !\Synth\Bootstrap::has('gameRequest') ||
        !is_array(\Synth\Bootstrap::gameRequest()) ||
        !in_array((\Synth\Bootstrap::gameRequest()[0] ?? ""), ["rechat", "continue"], true)
    ) {
        return "";
    }

    $listenerName = normalizeDialogueListenerName(\Synth\Bootstrap::config("RECHAT_PREVIOUS_SPEAKER") ?? "");
    if ($listenerName === "") {
        return "";
    }

    return $listenerName;
}

function synthGetStrictDirectedPlayerListenerName()
{
    if (empty(\Synth\Bootstrap::config('ENFORCE_STRICT_RECHAT_RESPONSE'))) {
        return "";
    }

    if (!\Synth\Bootstrap::has('gameRequest') || !is_array(\Synth\Bootstrap::gameRequest())) {
        return "";
    }

    if (!in_array((\Synth\Bootstrap::gameRequest()[0] ?? ""), ["inputtext", "inputtext_s"], true)) {
        return "";
    }

    if (!function_exists('synthIsStrictDirectedPlayerResponseContext') || !synthIsStrictDirectedPlayerResponseContext()) {
        return "";
    }

    return normalizeDialogueListenerName(\Synth\Bootstrap::config("PLAYER_NAME") ?? "");
}

function getNormalizedNearbyDialogueListenerNames($excludeFarAway=true)
{
    $npcList = DataBeingsInCloseRange($excludeFarAway);
    if (!is_string($npcList) || $npcList === "") {
        return [];
    }

    $npcs = explode("|", $npcList);
    $normalizedNearby = [];
    foreach ($npcs as $nearbyNpcName) {
        $nearbyNpcName = normalizeDialogueListenerName($nearbyNpcName);
        if ($nearbyNpcName !== "") {
            $normalizedNearby[] = $nearbyNpcName;
        }
    }

    return array_values(array_unique($normalizedNearby));
}

function buildOpenRechatListenerCandidates(array $listenerNames, $speakerName)
{
    $speakerName = normalizeDialogueListenerName($speakerName);
    $candidates = [];

    foreach ($listenerNames as $listenerName) {
        $listenerName = normalizeDialogueListenerName($listenerName);
        if ($listenerName !== "") {
            $candidates[] = $listenerName;
        }
    }

    foreach (getNormalizedNearbyDialogueListenerNames(true) as $nearbyNpcName) {
        if ($nearbyNpcName === "") {
            continue;
        }
        if ($speakerName !== "" && strcasecmp($nearbyNpcName, $speakerName) === 0) {
            continue;
        }
        if (strcasecmp($nearbyNpcName, "The Narrator") === 0) {
            continue;
        }
        $candidates[] = $nearbyNpcName;
    }

    return array_values(array_unique($candidates));
}

function synthNormalizeRechatActorList(array $names)
{
    $normalized = [];
    foreach ($names as $name) {
        $name = normalizeDialogueListenerName($name);
        if ($name !== "") {
            $normalized[] = $name;
        }
    }

    return array_values(array_unique($normalized));
}

function synthExtractPeopleListFromPipeString($peoplePipe)
{
    $peoplePipe = trim((string)$peoplePipe);
    if ($peoplePipe === "") {
        return [];
    }

    $parts = explode("|", $peoplePipe);
    $names = [];
    foreach ($parts as $part) {
        $part = stripActorStateSuffix($part);
        if ($part !== "") {
            $names[] = $part;
        }
    }

    return synthNormalizeRechatActorList($names);
}

function synthRechatCollectStructuredClassifiers($value, &$parts)
{
    static $classifierKeys = [
        "race" => true,
        "species" => true,
        "category" => true,
        "type" => true,
        "class" => true,
        "creature_type" => true,
        "creaturetype" => true,
        "actor_type" => true,
        "actortype" => true,
        "base" => true,
        "baseid" => true,
        "base_id" => true,
        "formid" => true,
        "form_id" => true,
        "editorid" => true,
        "editor_id" => true,
        "voiceid" => true,
        "voice_id" => true,
    ];

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            synthRechatCollectStructuredClassifiers($decoded, $parts);
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $key => $fieldValue) {
        $fieldKey = strtolower((string)$key);
        if (isset($classifierKeys[$fieldKey])) {
            if (is_scalar($fieldValue)) {
                $fieldText = trim((string)$fieldValue);
                if ($fieldText !== "") {
                    $parts[] = $fieldText;
                }
            } elseif (is_array($fieldValue)) {
                synthRechatCollectStructuredClassifiers($fieldValue, $parts);
            }
            continue;
        }

        if (is_array($fieldValue)) {
            synthRechatCollectStructuredClassifiers($fieldValue, $parts);
        }
    }
}

function synthRechatClassifierContains($text, $term)
{
    $term = trim((string)$term);
    if ($term === "") {
        return false;
    }

    return preg_match('/(^|[^a-z0-9])' . preg_quote($term, '/') . '([^a-z0-9]|$)/i', $text) === 1;
}

function synthRechatActorBlockReason($actorName, $npcData = null)
{
    $actorLabel = strtolower(normalizeDialogueListenerName($actorName));
    if (in_array($actorLabel, ["rex", "dogmeat"], true)) {
        return "creature/animal category is not allowed for rechat: " . $actorLabel;
    }

    $parts = [];
    if (is_array($npcData)) {
        foreach (["race", "voiceid", "base", "refid"] as $key) {
            if (!empty($npcData[$key]) && is_scalar($npcData[$key])) {
                $parts[] = (string)$npcData[$key];
            }
        }
        foreach (["metadata", "extended_data", "appearance"] as $key) {
            if (!empty($npcData[$key])) {
                synthRechatCollectStructuredClassifiers($npcData[$key], $parts);
            }
        }
    }

    $text = strtolower(implode(" ", array_filter($parts, static function ($part) {
        return trim((string)$part) !== "";
    })));

    $blockedTerms = [
        "cyberdog",
        "dogmeat",
        "dog",
        "deathclaw",
        "cazador",
        "radscorpion",
        "gecko",
        "nightstalker",
        "bloatfly",
        "mantis",
        "mole rat",
        "molerat",
        "brahmin",
        "bighorner",
        "centaur",
        "spore carrier",
        "spore plant",
        "tunneler",
        "lakelurk",
        "fire ant",
        "giant ant",
        "radroach",
        "yao guai",
        "feral",
    ];

    foreach ($blockedTerms as $term) {
        if (synthRechatClassifierContains($text, $term)) {
            return "creature/animal category is not allowed for rechat: " . $term;
        }
    }

    if (synthRechatClassifierContains($text, "creature") &&
        !synthRechatClassifierContains($text, "robot") &&
        !synthRechatClassifierContains($text, "protectron") &&
        !synthRechatClassifierContains($text, "securitron") &&
        !synthRechatClassifierContains($text, "mister handy") &&
        !synthRechatClassifierContains($text, "mr handy") &&
        !synthRechatClassifierContains($text, "mister gutsy") &&
        !synthRechatClassifierContains($text, "mr gutsy") &&
        !synthRechatClassifierContains($text, "eyebot") &&
        !synthRechatClassifierContains($text, "robobrain") &&
        !synthRechatClassifierContains($text, "sentry bot")) {
        return "generic creature category is not allowed for rechat";
    }

    return "";
}

function synthParseServerSideRechatPayload($rawData)
{
    $payload = [
        "speaker" => trim((string)(\Synth\Bootstrap::config("SYNTH_NAME") ?? "")),
        "listener_hint" => "",
        "rechat_target_hint" => "",
        "origin_line" => trim((string)$rawData),
        "rechat_depth" => 0,
        "chain_id" => "",
        "speaker_formid" => "",
        "listener_formid" => "",
        "target_formid" => "",
        "audience" => [],
        "chain_members" => [],
    ];

    $rawData = trim((string)$rawData);
    if ($rawData === "" || $rawData[0] !== "{") {
        return $payload;
    }

    $decoded = json_decode($rawData, true);
    if (!is_array($decoded)) {
        return $payload;
    }

    if (!empty($decoded["speaker"])) {
        $payload["speaker"] = normalizeDialogueListenerName($decoded["speaker"]);
    }
    if (!empty($decoded["listener_hint"])) {
        $payload["listener_hint"] = normalizeDialogueListenerName($decoded["listener_hint"]);
    }
    if (!empty($decoded["rechat_target_hint"])) {
        $payload["rechat_target_hint"] = normalizeDialogueListenerName($decoded["rechat_target_hint"]);
    }
    if (!empty($decoded["origin_line"])) {
        $payload["origin_line"] = trim((string)$decoded["origin_line"]);
    }
    if (!empty($decoded["rechat_depth"])) {
        $payload["rechat_depth"] = max(0, intval($decoded["rechat_depth"]));
    }
    if (!empty($decoded["chain_id"])) {
        $payload["chain_id"] = trim((string)$decoded["chain_id"]);
    }
    foreach (["speaker_formid", "listener_formid", "target_formid"] as $formIdKey) {
        if (!empty($decoded[$formIdKey])) {
            $payload[$formIdKey] = trim((string)$decoded[$formIdKey]);
        }
    }

    $appendActorNames = function ($value, array &$names) use (&$appendActorNames) {
        if (is_string($value)) {
            foreach (parsePeoplePipeList($value) as $name) {
                $name = normalizeDialogueListenerName($name);
                if ($name !== "") {
                    $names[] = $name;
                }
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            if (is_string($item)) {
                $name = normalizeDialogueListenerName($item);
                if ($name !== "") {
                    $names[] = $name;
                }
            } elseif (is_array($item)) {
                foreach (["name", "actor_name", "display_name"] as $key) {
                    if (!empty($item[$key])) {
                        $name = normalizeDialogueListenerName($item[$key]);
                        if ($name !== "") {
                            $names[] = $name;
                        }
                        break;
                    }
                }
            } else {
                $appendActorNames($item, $names);
            }
        }
    };

    $audienceNames = [];
    if (!empty($decoded["audience"])) {
        $appendActorNames($decoded["audience"], $audienceNames);
    }
    if (!empty($decoded["audience_snapshot"]) && is_array($decoded["audience_snapshot"])) {
        if (!empty($decoded["audience_snapshot"]["people"])) {
            $appendActorNames($decoded["audience_snapshot"]["people"], $audienceNames);
        }
        if (!empty($decoded["audience_snapshot"]["companions"])) {
            $appendActorNames($decoded["audience_snapshot"]["companions"], $audienceNames);
        }
    }

    $chainMemberNames = [];
    if (!empty($decoded["chain_members"])) {
        $appendActorNames($decoded["chain_members"], $chainMemberNames);
    }

    $payload["audience"] = synthNormalizeRechatActorList($audienceNames);
    $payload["chain_members"] = synthNormalizeRechatActorList($chainMemberNames);

    return $payload;
}

function synthLatestNearbyActorStatusMapForRechat(): array
{
    $db = \Synth\Bootstrap::db() ?? null;
    if (!$db || !method_exists($db, "fetchAll")) {
        return [];
    }

    $rows = $db->fetchAll("SELECT party, localts, ts FROM core_context_event WHERE type='nearby_actors' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $eventLocalTs = intval($rows[0]["localts"] ?? 0);
    if ($eventLocalTs <= 0) {
        $eventLocalTs = intval($rows[0]["ts"] ?? 0);
    }
    $ageSeconds = $eventLocalTs > 0 ? max(0, time() - $eventLocalTs) : PHP_INT_MAX;
    $fresh = $ageSeconds <= 12;

    $payload = json_decode($rows[0]["party"] ?? "", true);
    if (!is_array($payload) || !is_array($payload["actors"] ?? null)) {
        return [
            "__snapshot_present" => true,
            "__snapshot_fresh" => false,
            "__snapshot_age_seconds" => $ageSeconds,
        ];
    }

    $map = [
        "__snapshot_present" => true,
        "__snapshot_fresh" => $fresh,
        "__snapshot_age_seconds" => $ageSeconds,
    ];
    foreach ($payload["actors"] as $actor) {
        if (!is_array($actor)) {
            continue;
        }

        $name = normalizeDialogueListenerName($actor["name"] ?? "");
        if ($name === "" || $name === "<no name>") {
            continue;
        }

        $map[strtolower($name)] = [
            "status" => strtolower(trim((string)($actor["status"] ?? ""))),
            "reason" => strtolower(trim((string)($actor["spatial_reason"] ?? ""))),
            "eligible" => filter_var($actor["eligible"] ?? true, FILTER_VALIDATE_BOOLEAN),
            "can_hear_player" => filter_var($actor["can_hear_player"] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    return $map;
}

function synthRechatNearbyActorBlockReason(
    string $candidate,
    array $nearbyStatusMap,
    bool $requirePresence = true,
    bool $blockOnStale = true
): string
{
    if (empty($nearbyStatusMap)) {
        return "";
    }

    if (isset($nearbyStatusMap["__snapshot_fresh"]) && !$nearbyStatusMap["__snapshot_fresh"]) {
        if (!$blockOnStale) {
            return "";
        }
        $age = intval($nearbyStatusMap["__snapshot_age_seconds"] ?? 0);
        return "latest nearby actor scene is stale ({$age}s old)";
    }

    $key = strtolower(normalizeDialogueListenerName($candidate));
    if ($key === "" || !isset($nearbyStatusMap[$key])) {
        if (!$requirePresence) {
            return "";
        }
        return "not present in latest nearby actor scene";
    }

    $state = $nearbyStatusMap[$key];
    if (empty($state["eligible"])) {
        return "latest nearby actor snapshot marks actor ineligible";
    }

    $status = (string)($state["status"] ?? "");
    $reason = (string)($state["reason"] ?? "");
    $combined = trim($status . " " . $reason);

    $hardBlockedTokens = [
        "busy",
        "sleeping",
        "unconscious",
        "restrained",
        "essential_down",
        "essential down",
        "bleedout",
        "dying",
        "dead",
        "disabled",
    ];
    foreach ($hardBlockedTokens as $token) {
        if (strpos($combined, $token) !== false) {
            return "latest nearby actor status is {$combined}";
        }
    }

    if (!empty($state["can_hear_player"]) ||
        strpos($status, "can hear you") === 0 ||
        $reason === "line_of_sight_clear" ||
        $reason === "path_fallback_clear" ||
        $reason === "distance_fallback_clear" ||
        $reason === "immediate_proximity" ||
        $reason === "open_door_muffled" ||
        $reason === "line_of_sight_blocked_muffled") {
        return "";
    }

    $blockedTokens = [
        "far away",
        "too_far",
        "too far",
        "too_quiet",
        "can't hear",
        "cannot hear",
        "closed_door",
        "different_interior_cells",
        "interior_exterior_boundary",
        "navmesh_no_path",
        "path_unavailable",
        "path_ratio_blocked",
        "path_ratio_distance_blocked",
        "invalid_actor",
        "invalid_position",
        "invalid_distance",
        "unavailable",
    ];

    foreach ($blockedTokens as $token) {
        if ($token !== "" && strpos($combined, $token) !== false) {
            return "latest nearby actor status is {$combined}";
        }
    }

    return "";
}

function synthResolveServerSideRechatTarget(array $payload)
{
    $speakerName = normalizeDialogueListenerName($payload["speaker"] ?? (\Synth\Bootstrap::config("SYNTH_NAME") ?? ""));
    $listenerHint = normalizeDialogueListenerName($payload["listener_hint"] ?? "");
    $rechatTargetHint = normalizeDialogueListenerName($payload["rechat_target_hint"] ?? "");
    $configuredRechatMode = synthGetRechatMode();

    $peoplePipe = "";
    $payloadAudience = synthNormalizeRechatActorList($payload["audience"] ?? []);
    $hasAuthoritativePayloadAudience = !empty($payloadAudience);
    if ($hasAuthoritativePayloadAudience) {
        $audience = $payloadAudience;
        $peoplePipe = normalizePeoplePipeList($audience);
        Logger::info("[RECHAT_SELECT] Using authoritative payload audience: " . $peoplePipe);
    } else {
        foreach ([$rechatTargetHint, $listenerHint] as $scopeTarget) {
            if ($scopeTarget === "") {
                continue;
            }
            $peoplePipe = lookupConversationPeopleSourceOfTruth($speakerName, $scopeTarget);
            if ($peoplePipe !== "") {
                break;
            }
        }
        $audience = synthExtractPeopleListFromPipeString($peoplePipe);
    }

    $rechatMode = synthResolveEffectiveRechatMode($configuredRechatMode, array_merge(
        [$speakerName, $listenerHint, $rechatTargetHint],
        $audience
    ));

    $candidates = [];
    $addCandidate = function ($candidateName) use (&$candidates, $audience) {
        $candidateName = normalizeDialogueListenerName($candidateName);
        if ($candidateName === "") {
            return;
        }
        foreach ($audience as $audienceName) {
            if (strcasecmp($candidateName, $audienceName) === 0) {
                $candidates[] = $candidateName;
                return;
            }
        }
    };

    if ($rechatMode === "tight") {
        $addCandidate($listenerHint);
    } elseif ($rechatMode === "conversational") {
        $addCandidate($rechatTargetHint);
        $addCandidate($listenerHint);
        foreach ($audience as $audienceName) {
            $addCandidate($audienceName);
        }
    } else {
        foreach ($audience as $audienceName) {
            if ($rechatTargetHint !== "" && strcasecmp($audienceName, $rechatTargetHint) === 0) {
                continue;
            }
            if ($listenerHint !== "" && strcasecmp($audienceName, $listenerHint) === 0) {
                continue;
            }
            $addCandidate($audienceName);
        }
        $addCandidate($rechatTargetHint);
        $addCandidate($listenerHint);
        foreach ($audience as $audienceName) {
            $addCandidate($audienceName);
        }
    }

    $candidates = synthNormalizeRechatActorList($candidates);
    $npcMaster = new NpcMaster();
    $selected = "";
    $nearbyStatusMap = synthLatestNearbyActorStatusMapForRechat();

    foreach ($candidates as $candidate) {
        if ($candidate === "") {
            continue;
        }
        if ($speakerName !== "" && strcasecmp($candidate, $speakerName) === 0) {
            continue;
        }
        if (strcasecmp($candidate, "The Narrator") === 0) {
            continue;
        }
        if (isPlayerDialogueListenerName($candidate)) {
            continue;
        }
        $candidateNpcData = $npcMaster->getByName($candidate);
        if (!$candidateNpcData) {
            continue;
        }
        $blockReason = synthRechatActorBlockReason($candidate, $candidateNpcData);
        if ($blockReason !== "") {
            Logger::info("[RECHAT_SELECT] Skipping {$candidate}: {$blockReason}");
            continue;
        }
        $nearbyBlockReason = synthRechatNearbyActorBlockReason(
            $candidate,
            $nearbyStatusMap,
            !$hasAuthoritativePayloadAudience,
            !$hasAuthoritativePayloadAudience
        );
        if ($nearbyBlockReason !== "") {
            Logger::info("[RECHAT_SELECT] Skipping {$candidate}: {$nearbyBlockReason}");
            continue;
        }

        $selected = $candidate;
        break;
    }

    Logger::info("[RECHAT_SELECT] speaker={$speakerName} listener={$listenerHint} target={$rechatTargetHint} " .
        "mode={$rechatMode} audience=" . implode(",", $audience) .
        " candidates=" . implode(",", $candidates) .
        " selected={$selected}");

    return [
        "speaker" => $speakerName,
        "listener_hint" => $listenerHint,
        "rechat_target_hint" => $rechatTargetHint,
        "audience" => $audience,
        "people_pipe" => $peoplePipe,
        "candidates" => $candidates,
        "selected" => $selected,
        "mode" => $rechatMode,
        "configured_mode" => $configuredRechatMode,
        "origin_line" => trim((string)($payload["origin_line"] ?? "")),
        "chain_id" => trim((string)($payload["chain_id"] ?? "")),
    ];
}

function synthBuildServerSideRechatSessionKey(array $resolvedTarget)
{
    $chainId = trim((string)($resolvedTarget["chain_id"] ?? ""));
    if ($chainId !== "") {
        return md5("chain_" . $chainId);
    }

    $members = array_merge(
        [$resolvedTarget["speaker"] ?? "", $resolvedTarget["listener_hint"] ?? "", $resolvedTarget["rechat_target_hint"] ?? ""],
        $resolvedTarget["audience"] ?? []
    );

    $members = synthNormalizeRechatActorList($members);
    sort($members, SORT_NATURAL | SORT_FLAG_CASE);

    if (empty($members)) {
        $members[] = trim((string)($resolvedTarget["speaker"] ?? \Synth\Bootstrap::config("SYNTH_NAME") ?? "rechat"));
    }

    return md5("members_" . implode("|", $members));
}

function synthSwitchActiveNpcProfile($npcName)
{
    $npcName = normalizeDialogueListenerName($npcName);
    if ($npcName === "" || strcasecmp($npcName, "The Narrator") === 0) {
        return false;
    }

    $npcMaster = new NpcMaster();
    $currentNpcData = $npcMaster->getByName($npcName);
    if (!$currentNpcData) {
        Logger::warn("[RECHAT_SELECT] Could not load NPC profile for {$npcName}");
        return false;
    }

    $profile = new CoreProfile();
    if (empty($currentNpcData["profile_id"])) {
        $defaultProfile = $profile->getDefaultNpc();
        if ($defaultProfile) {
            $currentNpcData["profile_id"] = intval($defaultProfile["id"]);
            $npcMaster->updateByArray($currentNpcData);
        }
    }

    if (empty($currentNpcData["profile_id"])) {
        Logger::warn("[RECHAT_SELECT] NPC {$npcName} has no profile_id and no default fallback");
        return false;
    }

    $currentProfileData = $profile->getById(intval($currentNpcData["profile_id"]));
    if (!$currentProfileData) {
        Logger::warn("[RECHAT_SELECT] Could not load profile slot {$currentNpcData["profile_id"]} for {$npcName}");
        return false;
    }

    if (!class_exists("LLMRandomizer")) {
        require_once(dirname(__DIR__, 3).'/lib' . "/llm_randomizer.php");
    }

    $connector = new LLMConnector();
    $connectorSlot = null;
    $connectorId = 0;
    if (class_exists("LLMRandomizer")) {
        $connectorSlot = LLMRandomizer::getConnectorSlot($currentProfileData, $currentNpcData, $npcMaster);
        $connectorId = intval(LLMRandomizer::getConnectorIdForSlot($currentProfileData, $connectorSlot));
    }
    if ($connectorId <= 0 && !empty($currentProfileData["connector_id"])) {
        $connectorId = intval($currentProfileData["connector_id"]);
    }

    $currentConnectorData = ($connectorId > 0) ? $connector->getById($connectorId) : null;
    if (!$currentConnectorData) {
        $slotLabel = ($connectorSlot !== null) ? " slot {$connectorSlot}" : "";
        Logger::warn("[RECHAT_SELECT] Could not load connector id {$connectorId}{$slotLabel} for {$npcName}");
        return false;
    }

    $connector->setOldGlobals($currentConnectorData);
    $profile->setOldGlobals($currentProfileData);
    $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData, false);

        \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_NPC_DATA', $currentNpcData);
    \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_PROFILE_DATA', $currentProfileData);
    \Synth\Bootstrap::set('SYNTH_CORE_CURRENT_CONNECTOR_DATA', $currentConnectorData);
    if (function_exists('synthRuntimeSetActiveProfile')) {
        synthRuntimeSetActiveProfile($currentNpcData['md5']);
    } else {
        \Synth\Bootstrap::set('active_profile', $currentNpcData['md5']);
    }

    $partyConf = \Synth\Bootstrap::has('CACHE_PARTY') ? \Synth\Bootstrap::config("CACHE_PARTY") : DataGetCurrentPartyConf();
    \Synth\Bootstrap::set('CACHE_PARTY', $partyConf);
    $currentParty = json_decode($partyConf, true);
    \Synth\Bootstrap::set('IS_NPC', !(is_array($currentParty) && in_array($npcName, array_keys($currentParty), true)));

    return true;
}

function convertDirectedDialogueTagsToVerb($eventData, $verb)
{
    $eventData = (string)$eventData;
    if ($eventData === "") {
        return $eventData;
    }

    return preg_replace_callback(
        '/\(\s*([Tt]alking|[Ww]hispering|[Ss]houting|[Ss]peaking\s+privately)\s+to\s+([^()]+?)\s*\)/u',
        static function ($matches) use ($verb) {
            $prefix = ctype_upper(substr((string)$matches[1], 0, 1)) ? $verb : strtolower($verb);
            $target = trim((string)$matches[2]);
            return "({$prefix} to {$target})";
        },
        $eventData
    );
}

function convertTalkingTagsToWhispering($eventData)
{
    return convertDirectedDialogueTagsToVerb($eventData, 'Whispering');
}

function convertTalkingTagsToPrivate($eventData)
{
    return convertDirectedDialogueTagsToVerb($eventData, 'Speaking privately');
}

function convertTalkingTagsToShouting($eventData)
{
    return convertDirectedDialogueTagsToVerb($eventData, 'Shouting');
}

function extractTalkTargetMetadata($eventData)
{
    $metadata = [
        "hasExplicitTarget" => false,
        "isBroadcast" => false,
        "targets" => [],
    ];

    $eventData = (string)$eventData;
    if ($eventData === "") {
        return $metadata;
    }

    if (!preg_match('/\(\s*(?:(?:talking|whispering|shouting|speaking\s+privately)\s+to|speaking\s+loudly\s+to)\s+([^()]+?)(?:\s+from\s+far\s+away)?\s*\)/i', $eventData, $matches)) {
        return $metadata;
    }

    $metadata["hasExplicitTarget"] = true;
    $targetHint = trim($matches[1]);
    if ($targetHint === "") {
        return $metadata;
    }

    if (strcasecmp($targetHint, "everyone") === 0) {
        $metadata["isBroadcast"] = true;
        return $metadata;
    }

    $splitTargets = preg_split('/\s*(?:,|&| and )\s*/i', $targetHint);
    if (is_array($splitTargets)) {
        foreach ($splitTargets as $splitTarget) {
            $splitTarget = trim((string)$splitTarget);
            if ($splitTarget !== "") {
                $metadata["targets"][] = $splitTarget;
            }
        }
    }

    if (empty($metadata["targets"])) {
        $metadata["targets"][] = $targetHint;
    }

    return $metadata;
}

function talkTargetsIncludeName($targetNames, $candidateName)
{
    $candidateNormalized = normalizeActorNameForComparison($candidateName);
    if ($candidateNormalized === "") {
        return false;
    }

    if (!is_array($targetNames) || empty($targetNames)) {
        return false;
    }

    foreach ($targetNames as $targetName) {
        if (normalizeActorNameForComparison($targetName) === $candidateNormalized) {
            return true;
        }
    }

    return false;
}

function extractSpeakerNameFromInputEvent($eventData)
{
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    if (!preg_match('/^\s*([^:]{1,128})\s*:/u', $eventData, $matches)) {
        return "";
    }

    $speakerName = trim($matches[1]);
    $speakerName = trim($speakerName, "|");
    if ($speakerName === "") {
        return "";
    }
    if (isSystemContextLabel($speakerName)) {
        return "";
    }

    return $speakerName;
}

function extractCoreUtteranceFromInputEvent($eventData)
{
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    if (preg_match('/^\s*[^:]{1,128}\s*:\s*(.*)$/us', $eventData, $matches)) {
        $eventData = trim((string)$matches[1]);
    }

    $eventData = preg_replace('/\s*\(\s*(?:(?:talking|whispering|speaking\s+privately)\s+to|speaking\s+loudly\s+to)\s+[^)]*\)\s*$/iu', '', $eventData);
    return trim((string)$eventData);
}

function extractSpeakerNameFromChatEvent($eventData)
{
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    // Strip optional "(Context ...)" prefix used by background chatter.
    $eventData = preg_replace('/^\s*\(\s*context[^)]*\)\s*/iu', '', $eventData);
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    if (!preg_match('/^\s*([^:]{1,128})\s*:/u', $eventData, $matches)) {
        return "";
    }

    $speakerName = trim($matches[1]);
    $speakerName = trim($speakerName, "|");
    if (isSystemContextLabel($speakerName)) {
        return "";
    }
    return ($speakerName === "") ? "" : $speakerName;
}

function extractCoreUtteranceFromChatEvent($eventData)
{
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    // Strip optional "(Context ...)" prefix used by ambient lines.
    $eventData = preg_replace('/^\s*\(\s*context[^)]*\)\s*/iu', '', $eventData);
    $eventData = trim((string)$eventData);

    if (preg_match('/^\s*[^:]{1,128}\s*:\s*(.*)$/us', $eventData, $matches)) {
        $eventData = trim((string)$matches[1]);
    }

    $eventData = preg_replace('/\s*\(\s*(?:(?:talking|whispering|shouting|speaking\s+privately)\s+to|speaking\s+loudly\s+to)\s+[^)]*\)\s*$/iu', '', $eventData);
    return trim((string)$eventData);
}

function synthGetVisibleChatDeliveryStates()
{
    // presented: a native-turn line with a receipt-confirmed text presentation.
    // interrupted: a line the player only partly heard (dialogue.conversation), kept in context marked as cut off.
    return ['emitted', 'spoken', 'presented', 'interrupted'];
}

function synthGetInFlightChatDeliveryStates()
{
    return ['pending', 'emitted'];
}

function synthBuildChatDeliveryStateSql($column = 'delivery_state', ?array $states = null, $defaultState = 'spoken')
{
    $column = trim((string)$column);
    if ($column === '') {
        $column = 'delivery_state';
    }

    if ($states === null) {
        $states = synthGetVisibleChatDeliveryStates();
    }

    $normalizedStates = array_values(array_unique(array_filter(array_map(static function ($state) {
        return trim((string)$state);
    }, $states))));
    if (empty($normalizedStates)) {
        $normalizedStates = ['spoken'];
    }

    $defaultState = trim((string)$defaultState);
    if ($defaultState === '') {
        $defaultState = 'spoken';
    }

    $quotedStates = array_map(static function ($state) {
        return "'" . str_replace("'", "''", $state) . "'";
    }, $normalizedStates);

    return "COALESCE({$column}, '{$defaultState}') IN (" . implode(', ', $quotedStates) . ")";
}

function lookupSpatialCompanionsFromSpeech($speakerName, $listenerName = "", $utterance = "")
{
    $db = \Synth\Bootstrap::db();

    $speakerName = trim((string)$speakerName);
    if ($speakerName === "") {
        return [];
    }

    $speakerEscaped = $db->escape($speakerName);
    $rows = $db->fetchAll(
        "SELECT rowid, companions, listener, speaker, speech, topic, localts
         FROM core_speech
         WHERE LOWER(speaker)=LOWER('{$speakerEscaped}')
           AND localts > " . (time() - 900) . "
         ORDER BY rowid DESC
         LIMIT 80"
    );
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $wantedListener = normalizeActorNameForComparison($listenerName);
    $wantedUtterance = normalizeDialogTextForComparison($utterance);

    $bestRow = null;
    $bestScore = -1;

    foreach ($rows as $row) {
        $topic = trim((string)($row["topic"] ?? ""));
        if ($topic === "" || stripos($topic, "spatial:") === false) {
            continue;
        }

        $rowListener = normalizeActorNameForComparison($row["listener"] ?? "");
        $rowUtterance = normalizeDialogTextForComparison($row["speech"] ?? "");

        $score = 0;
        if ($wantedListener !== "" && $rowListener === $wantedListener) {
            $score += 4;
        }

        if ($wantedUtterance !== "" && $rowUtterance !== "") {
            if ($rowUtterance === $wantedUtterance) {
                $score += 8;
            } elseif (strpos($rowUtterance, $wantedUtterance) !== false || strpos($wantedUtterance, $rowUtterance) !== false) {
                $score += 3;
            }
        }

        if ($wantedListener === "" && $wantedUtterance === "") {
            $score = 1;
        }

        if ($score <= 0) {
            continue;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestRow = $row;
            if ($score >= 12) {
                break;
            }
        }
    }

    if (!is_array($bestRow)) {
        return [];
    }

    $names = parsePeoplePipeList($bestRow["companions"] ?? "");
    $rowListenerRaw = trim((string)($bestRow["listener"] ?? ""));
    if ($rowListenerRaw !== "") {
        $names[] = $rowListenerRaw;
    }
    $rowSpeakerRaw = trim((string)($bestRow["speaker"] ?? ""));
    if ($rowSpeakerRaw !== "") {
        $names[] = $rowSpeakerRaw;
    }

    return array_values(array_unique(array_filter(array_map(static function ($name) {
        return trim((string)$name);
    }, $names))));
}

function extractActorNameFromInfoActionEvent($eventData)
{
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    $patterns = [
        '/^\s*([^:]{1,128}?)\s+uses\s+/iu',
        '/^\s*([^:]{1,128}?)\s+receives\s+/iu',
        '/^\s*([^:]{1,128}?)\s+teleports?\s+to\s+/iu',
        '/^\s*([^:]{1,128}?)\s+is\s+killed\b/iu',
        '/^\s*Could not teleport\s+([^:]{1,128}?)\s+to\s+/iu',
        '/^\s*Could not kill\s+([^:]{1,128}?)(?:\.|\s|$)/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $eventData, $matches)) {
            $actorName = trim($matches[1]);
            $actorName = trim($actorName, "|");
            return ($actorName === "") ? "" : $actorName;
        }
    }

    return "";
}

function appendUniqueActorName(&$names, $name)
{
    $name = trim((string)$name);
    $name = trim($name, "|");
    if ($name === "") {
        return;
    }
    if (!shouldIncludeActorNameInPeopleList($name)) {
        return;
    }

    $normalizedCandidate = normalizeActorNameForComparison($name);
    if ($normalizedCandidate === "") {
        return;
    }

    foreach ($names as $existingName) {
        if (normalizeActorNameForComparison($existingName) === $normalizedCandidate) {
            return;
        }
    }

    $names[] = $name;
}

function isStrictSpatialPeopleModeEnabled()
{
    // Strict mode defaults to enabled: never fall back to broad nearby lists when spatial
    // evidence is missing. Events are scoped to known participants/listener only.
    return true;
}

function mergeParticipantsWithPeoplePipe($participantNames, $peoplePipe)
{
    $mergedPeople = parsePeoplePipeList($peoplePipe);
    if (is_array($participantNames)) {
        foreach ($participantNames as $participantName) {
            appendUniqueActorName($mergedPeople, $participantName);
        }
    }

    return normalizePeoplePipeList($mergedPeople);
}

function isNarratorPrivateActionName($actionName)
{
    $actionName = trim((string)$actionName);
    if ($actionName === "") {
        return false;
    }

    if (function_exists('synthFindActionCatalogRowByNameOrCode')) {
        $row = synthFindActionCatalogRowByNameOrCode($actionName, false);
        if (is_array($row)) {
            return !empty($row['available_to_narrator'])
                && empty($row['available_to_npc'])
                && empty($row['available_to_followers']);
        }
    }

    return false;
}

function isNarratorPrivatePlayerAlias($targetName, $listenerName = "")
{
    $targetName = trim((string)$targetName);
    $listenerName = trim((string)$listenerName);
    if ($targetName === "") {
        return true;
    }

    $normalizedTarget = normalizeActorNameForComparison($targetName);
    if ($normalizedTarget === "" || $normalizedTarget === "player" || $normalizedTarget === "me" || $normalizedTarget === "the narrator" || $normalizedTarget === "narrator") {
        return true;
    }

    $playerName = trim((string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player"));
    if ($playerName !== "" && strcasecmp($targetName, $playerName) === 0) {
        return true;
    }

    if ($listenerName !== "" && strcasecmp($targetName, $listenerName) === 0) {
        return true;
    }

    return false;
}

function extractNarratorPrivateActionTargetName($eventType, $eventData)
{
    $eventType = strtolower(trim((string)$eventType));
    $eventData = trim((string)$eventData);
    if ($eventData === "") {
        return "";
    }

    if ($eventType === "logaction") {
        $payload = json_decode($eventData, true);
        if (is_array($payload) && isNarratorPrivateActionName($payload["action"] ?? "")) {
            return trim((string)($payload["target"] ?? ""));
        }
        return "";
    }

    if ($eventType === "funcret") {
        $payload = json_decode($eventData, true);
        if (is_array($payload) && isNarratorPrivateActionName($payload["action"] ?? ($payload["command"] ?? ""))) {
            return trim((string)($payload["target"] ?? ($payload["arg"] ?? "")));
        }
        return "";
    }

    if ($eventType === "infoaction") {
        return extractActorNameFromInfoActionEvent($eventData);
    }

    return "";
}

function shouldScopeNarratorPrivateActionEvent($eventType, $eventData, $listenerName = "")
{
    $eventType = strtolower(trim((string)$eventType));
    $listenerNormalized = normalizeActorNameForComparison($listenerName);
    $eventData = trim((string)$eventData);

    if (!in_array($eventType, ["logaction", "funcret", "infoaction"], true) || $eventData === "") {
        return false;
    }

    if ($eventType === "logaction") {
        $payload = json_decode($eventData, true);
        if (!is_array($payload) || !isNarratorPrivateActionName($payload["action"] ?? "")) {
            return false;
        }

        $characterName = trim((string)($payload["character"] ?? ""));
        return $listenerNormalized === "the narrator" || strcasecmp($characterName, "The Narrator") === 0;
    }

    if ($eventType === "funcret") {
        $payload = json_decode($eventData, true);
        return is_array($payload) && isNarratorPrivateActionName($payload["action"] ?? ($payload["command"] ?? ""));
    }

    if ($listenerNormalized !== "the narrator") {
        return false;
    }

    return preg_match('/^\s*([^:]{1,128}?)\s+(?:receives|teleports?\s+to|is\s+killed)\b/iu', $eventData) === 1
        || preg_match('/^\s*Could not teleport\s+/iu', $eventData) === 1
        || preg_match('/^\s*Could not kill\s+/iu', $eventData) === 1;
}

function buildNarratorPrivatePeopleForEvent($eventType, $eventData, $listenerName = "")
{
    if (!shouldScopeNarratorPrivateActionEvent($eventType, $eventData, $listenerName)) {
        return "";
    }

    $playerName = trim((string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player"));
    $participants = ["The Narrator"];
    if ($playerName !== "") {
        $participants[] = $playerName;
    }

    return normalizePeoplePipeList($participants);
}

function buildCanonicalAudiencePeople($fallbackPeople = "", $participants = [])
{
    $audienceNames = [];
    foreach (parsePeoplePipeList($fallbackPeople) as $audienceName) {
        $audienceName = stripActorStateSuffix($audienceName);
        if ($audienceName !== "") {
            appendUniqueActorName($audienceNames, $audienceName);
        }
    }

    if (is_array($participants)) {
        foreach ($participants as $participantName) {
            $participantName = stripActorStateSuffix($participantName);
            if ($participantName !== "") {
                appendUniqueActorName($audienceNames, $participantName);
            }
        }
    }

    return normalizePeoplePipeList($audienceNames);
}

function buildNarratorSharedPeopleForEvent($eventType, $eventData, $listenerName = "", $fallbackPeople = "")
{
    if (!empty(\Synth\Bootstrap::config('HIDE_NARRATOR_DIALOGUE'))) {
        return "";
    }

    $eventType = strtolower((string)$eventType);
    $participants = [];

    if ($eventType === "narrator_inputtext") {
        $speakerName = extractSpeakerNameFromInputEvent($eventData);
        if ($speakerName === "" && !empty(\Synth\Bootstrap::config('PLAYER_NAME'))) {
            $speakerName = trim((string)\Synth\Bootstrap::config("PLAYER_NAME"));
        }
        if ($speakerName !== "") {
            appendUniqueActorName($participants, $speakerName);
        }
        appendUniqueActorName($participants, ($listenerName !== "") ? $listenerName : "The Narrator");

        $sharedPeople = buildCanonicalAudiencePeople($fallbackPeople, $participants);
        return ($sharedPeople !== "") ? $sharedPeople : normalizePeoplePipeList($participants);
    }

    if ($eventType !== "chat") {
        return "";
    }

    $speakerName = extractSpeakerNameFromChatEvent($eventData);
    if (normalizeActorNameForComparison($speakerName) !== "the narrator") {
        return "";
    }

    if (stripos((string)$eventData, '(whispering to ') !== false ||
        stripos((string)$eventData, '(speaking privately to ') !== false) {
        return "";
    }

    appendUniqueActorName($participants, $speakerName);
    $targetMeta = extractTalkTargetMetadata($eventData);
    foreach ($targetMeta["targets"] as $targetName) {
        appendUniqueActorName($participants, $targetName);
    }

    $sharedPeople = buildCanonicalAudiencePeople($fallbackPeople, $participants);
    return ($sharedPeople !== "") ? $sharedPeople : normalizePeoplePipeList($participants);
}

function extractGenericEventParticipants($eventType, $eventData)
{
    $participants = [];
    $eventType = strtolower((string)$eventType);
    $eventData = (string)$eventData;

    $structured = json_decode(trim($eventData), true);
    if (is_array($structured)) {
        foreach (["speaker", "npc", "actor", "listener", "target", "player", "player_name"] as $key) {
            if (!array_key_exists($key, $structured)) {
                continue;
            }
            $people = synthNormalizePeopleValue($structured[$key]);
            foreach (parsePeoplePipeList($people) as $name) {
                appendUniqueActorName($participants, $name);
            }
        }

        $audience = $structured["audience_snapshot"] ?? null;
        if (is_string($audience)) {
            $decodedAudience = json_decode($audience, true);
            $audience = is_array($decodedAudience) ? $decodedAudience : $audience;
        }
        if (is_array($audience)) {
            foreach (["people", "companions", "actors"] as $key) {
                if (!array_key_exists($key, $audience)) {
                    continue;
                }
                foreach (parsePeoplePipeList(synthNormalizePeopleValue($audience[$key])) as $name) {
                    appendUniqueActorName($participants, $name);
                }
            }
        }
        return $participants;
    }

    $speakerName = extractSpeakerNameFromInputEvent($eventData);
    if ($speakerName === "") {
        $speakerName = extractSpeakerNameFromChatEvent($eventData);
    }
    if ($speakerName !== "") {
        appendUniqueActorName($participants, $speakerName);
    }

    $targetMeta = extractTalkTargetMetadata($eventData);
    if (!empty($targetMeta["targets"])) {
        foreach ($targetMeta["targets"] as $targetName) {
            appendUniqueActorName($participants, $targetName);
        }
    }

    $infoActionActor = extractActorNameFromInfoActionEvent($eventData);
    if ($infoActionActor !== "") {
        appendUniqueActorName($participants, $infoActionActor);
    }

    if ($eventType === "narrator_inputtext") {
        appendUniqueActorName($participants, "The Narrator");
    }

    return $participants;
}

function buildStrictFallbackPeopleForEvent($eventType, $eventData, $listenerName, $fallbackPeople = "")
{
    $eventType = strtolower((string)$eventType);
    $eventData = (string)$eventData;

    $names = extractGenericEventParticipants($eventType, $eventData);

    if (shouldAutoAppendListenerToPeople($eventType, $eventData, $listenerName)) {
        appendUniqueActorName($names, $listenerName);
    }

    if (empty($names) && $fallbackPeople !== "") {
        // Strict mode still allows a narrowed fallback token if caller already provided
        // a single/limited scoped list.
        $fallbackNames = parsePeoplePipeList($fallbackPeople);
        if (count($fallbackNames) === 1) {
            appendUniqueActorName($names, $fallbackNames[0]);
        }
    }

    return normalizePeoplePipeList($names);
}

function lookupLatestSpatialCompanionsByParticipant($participantName, $maxAgeSeconds = 900)
{
    $db = \Synth\Bootstrap::db();

    $participantName = trim((string)$participantName);
    if ($participantName === "") {
        return [];
    }

    $participantEscaped = $db->escape($participantName);
    $participantPipeToken = "|" . strtolower($participantName) . "|";
    $participantPipeEscaped = $db->escape($participantPipeToken);
    $ageSeconds = max(30, intval($maxAgeSeconds));
    $cutoff = time() - $ageSeconds;

    $rows = $db->fetchAll(
        "SELECT rowid, companions, listener, speaker, topic
         FROM core_speech
         WHERE localts > {$cutoff}
           AND topic LIKE '%spatial:%'
           AND (
                LOWER(speaker)=LOWER('{$participantEscaped}')
                OR LOWER(listener)=LOWER('{$participantEscaped}')
                OR LOWER(companions) LIKE '%{$participantPipeEscaped}%'
           )
         ORDER BY rowid DESC
         LIMIT 20"
    );

    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $row = $rows[0];
    $names = parsePeoplePipeList($row["companions"] ?? "");
    $listenerName = trim((string)($row["listener"] ?? ""));
    if ($listenerName !== "") {
        $names[] = $listenerName;
    }
    $speakerName = trim((string)($row["speaker"] ?? ""));
    if ($speakerName !== "") {
        $names[] = $speakerName;
    }

    $deduped = [];
    foreach ($names as $name) {
        appendUniqueActorName($deduped, $name);
    }

    return $deduped;
}

function lookupConversationPeopleSourceOfTruth($speakerName, $targetName, $maxAgeSeconds = 300)
{
    $db = \Synth\Bootstrap::db();

    $speakerName = trim((string)$speakerName);
    $targetName = trim((string)$targetName);
    if ($speakerName === "" || $targetName === "") {
        return "";
    }

    $speakerNormalized = normalizeActorNameForComparison($speakerName);
    $targetNormalized = normalizeActorNameForComparison($targetName);
    if ($speakerNormalized === "" || $targetNormalized === "") {
        return "";
    }

    $ageSeconds = max(30, intval($maxAgeSeconds));
    $cutoff = time() - $ageSeconds;
    $rows = $db->fetchAll(
        "SELECT rowid, type, data, people
         FROM core_context_event
         WHERE localts > {$cutoff}
           AND type IN ('chat','prechat','inputtext','inputtext_s','narrator_inputtext')
           AND people IS NOT NULL
           AND TRIM(people) <> ''
         ORDER BY rowid DESC
         LIMIT 120"
    );

    if (!is_array($rows) || empty($rows)) {
        return "";
    }

    foreach ($rows as $row) {
        $rowType = strtolower((string)($row["type"] ?? ""));
        $rowData = (string)($row["data"] ?? "");
        $rowPeople = normalizePeoplePipeList(parsePeoplePipeList($row["people"] ?? ""));
        if ($rowType === "" || $rowData === "" || $rowPeople === "") {
            continue;
        }

        if (in_array($rowType, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
            $rowSpeaker = extractSpeakerNameFromInputEvent($rowData);
        } else {
            $rowSpeaker = extractSpeakerNameFromChatEvent($rowData);
        }
        if ($rowSpeaker === "") {
            continue;
        }

        $rowSpeakerNormalized = normalizeActorNameForComparison($rowSpeaker);
        if ($rowSpeakerNormalized === "") {
            continue;
        }

        $rowTargetMeta = extractTalkTargetMetadata($rowData);
        $rowTargets = $rowTargetMeta["targets"] ?? [];

        $directMatch = (
            $rowSpeakerNormalized === $speakerNormalized &&
            !empty($rowTargets) &&
            talkTargetsIncludeName($rowTargets, $targetName)
        );
        $reverseMatch = (
            $rowSpeakerNormalized === $targetNormalized &&
            !empty($rowTargets) &&
            talkTargetsIncludeName($rowTargets, $speakerName)
        );
        if (!$directMatch && !$reverseMatch) {
            continue;
        }

        $rowNames = parsePeoplePipeList($rowPeople);
        appendUniqueActorName($rowNames, $speakerName);
        appendUniqueActorName($rowNames, $targetName);
        $candidatePipe = normalizePeoplePipeList($rowNames);
        if ($candidatePipe === "") {
            continue;
        }

        return $candidatePipe;
    }

    return "";
}

function buildScopedPeopleFromSpatialEvidence($eventType, $eventData, $listenerName, $fallbackPeople = "")
{
    $eventType = strtolower((string)$eventType);
    $participants = extractGenericEventParticipants($eventType, $eventData);
    $lookupCandidates = $participants;
    appendUniqueActorName($lookupCandidates, $listenerName);

    if (empty($lookupCandidates)) {
        return $fallbackPeople;
    }

    $targetMeta = extractTalkTargetMetadata($eventData);
    $primaryTarget = "";
    if (!empty($targetMeta["targets"])) {
        $primaryTarget = trim((string)$targetMeta["targets"][0]);
    }
    $speakerName = extractSpeakerNameFromInputEvent($eventData);
    if ($speakerName === "") {
        $speakerName = extractSpeakerNameFromChatEvent($eventData);
    }

    $bestSpatialPeople = [];
    foreach ($lookupCandidates as $candidateName) {
        $spatialPeople = [];
        if ($speakerName !== "" && normalizeActorNameForComparison($candidateName) === normalizeActorNameForComparison($speakerName)) {
            $spatialPeople = lookupSpatialCompanionsFromSpeech($speakerName, $primaryTarget);
        }

        if (empty($spatialPeople)) {
            $spatialPeople = lookupLatestSpatialCompanionsByParticipant($candidateName);
        }

        if (!empty($spatialPeople)) {
            $bestSpatialPeople = $spatialPeople;
            break;
        }
    }

    if (!empty($bestSpatialPeople)) {
        foreach ($participants as $participantName) {
            $bestSpatialPeople[] = $participantName;
        }

        $scopedFromSpatial = normalizePeoplePipeList($bestSpatialPeople);
        if ($scopedFromSpatial !== "") {
            return $scopedFromSpatial;
        }
    }

    if (!empty($participants)) {
        $participantScoped = normalizePeoplePipeList($participants);
        if ($participantScoped !== "") {
            return $participantScoped;
        }
    }

    return $fallbackPeople;
}

function buildScopedPeopleForChatEvent($eventData, $fallbackPeople = "")
{
    $eventData = (string)$eventData;
    if ($eventData === "") {
        return $fallbackPeople;
    }

    $hasContextLocationPrefix = preg_match('/^\s*\(\s*context location:/iu', $eventData) === 1;

    if (stripos($eventData, ' background chat)') !== false) {
        $speakerName = extractSpeakerNameFromChatEvent($eventData);
        $backgroundNames = parsePeoplePipeList(DataBeingsInRange());
        if (empty($backgroundNames)) {
            $backgroundNames = parsePeoplePipeList($fallbackPeople);
        }
        if ($speakerName !== "") {
            appendUniqueActorName($backgroundNames, $speakerName);
        }
        $backgroundScoped = normalizePeoplePipeList($backgroundNames);
        if ($backgroundScoped !== "") {
            return $backgroundScoped;
        }
    }

    $targetMeta = extractTalkTargetMetadata($eventData);
    if ($hasContextLocationPrefix) {
        $speakerName = extractSpeakerNameFromChatEvent($eventData);
        $contextNames = parsePeoplePipeList(DataBeingsInRange());
        if (empty($contextNames)) {
            $contextNames = parsePeoplePipeList($fallbackPeople);
        }
        if ($speakerName !== "") {
            appendUniqueActorName($contextNames, $speakerName);
        }
        if (!empty($targetMeta["targets"])) {
            foreach ($targetMeta["targets"] as $targetName) {
                $targetName = trim((string)$targetName);
                if ($targetName !== "") {
                    appendUniqueActorName($contextNames, $targetName);
                }
            }
        }
        $contextScoped = normalizePeoplePipeList($contextNames);
        if ($contextScoped !== "") {
            return $contextScoped;
        }
    }

    if ($targetMeta["isBroadcast"]) {
        return $fallbackPeople;
    }

    $participants = [];
    $speakerName = extractSpeakerNameFromChatEvent($eventData);
    if ($speakerName !== "") {
        $participants[] = $speakerName;
    }

    if (!empty($targetMeta["targets"])) {
        foreach ($targetMeta["targets"] as $targetName) {
            $targetName = trim((string)$targetName);
            if ($targetName !== "") {
                $participants[] = $targetName;
            }
        }
    }

    $primaryTarget = "";
    if (!empty($targetMeta["targets"])) {
        $primaryTarget = trim((string)$targetMeta["targets"][0]);
    }

    // Explicit targeted chat: use the latest conversation SOT people list first
    // (player input or initial speaker chat), then fail-closed to participants.
    if ($targetMeta["hasExplicitTarget"] && !$targetMeta["isBroadcast"]) {
        if ($speakerName !== "" && $primaryTarget !== "") {
            $sotPeople = lookupConversationPeopleSourceOfTruth($speakerName, $primaryTarget, 300);
            if ($sotPeople !== "") {
                return $sotPeople;
            }
        }

        $scopedPeople = normalizePeoplePipeList($participants);
        if ($scopedPeople !== "") {
            return $scopedPeople;
        }
    }

    $coreUtterance = extractCoreUtteranceFromChatEvent($eventData);
    $spatialPeople = lookupSpatialCompanionsFromSpeech($speakerName, $primaryTarget, $coreUtterance);
    if (empty($spatialPeople) && $speakerName !== "" && !$targetMeta["hasExplicitTarget"]) {
        // For ambient/untargeted lines, allow recent speaker spatial context fallback.
        // For explicit "(talking to X)" lines, keep strict participant-only scope until
        // direct speech-row match/backfill arrives to avoid stale broad leakage.
        $spatialPeople = lookupSpatialCompanionsFromSpeech($speakerName, $primaryTarget, "");
        if (empty($spatialPeople)) {
            $spatialPeople = lookupLatestSpatialCompanionsByParticipant($speakerName, 180);
        }
    }
    if (!empty($spatialPeople)) {
        foreach ($participants as $participantName) {
            $participantName = trim((string)$participantName);
            if ($participantName !== "") {
                $spatialPeople[] = $participantName;
            }
        }

        $spatialScoped = normalizePeoplePipeList($spatialPeople);
        if ($spatialScoped !== "") {
            return $spatialScoped;
        }
    }

    $scopedPeople = normalizePeoplePipeList($participants);
    if ($scopedPeople !== "") {
        return $scopedPeople;
    }

    return $fallbackPeople;
}

function buildScopedPeopleForInfoActionEvent($eventData, $fallbackPeople = "")
{
    $actorName = extractActorNameFromInfoActionEvent($eventData);
    $fallbackNames = parsePeoplePipeList($fallbackPeople);
    $fallbackNames = array_values(array_filter($fallbackNames, static function ($name) {
        return normalizeActorNameForComparison($name) !== "the narrator";
    }));
    $fallbackScoped = normalizePeoplePipeList($fallbackNames);
    if ($fallbackScoped !== "") {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'SCOPE_INFOACTION', "actor='{$actorName}' initial_detection_scoped='{$fallbackScoped}'");
        return $fallbackScoped;
    }

    if ($actorName !== "") {
        $scopedPeople = normalizePeoplePipeList([$actorName]);
        if ($scopedPeople !== "") {
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'SCOPE_INFOACTION', "actor='{$actorName}' fallback_actor_scoped='{$scopedPeople}'");
            return $scopedPeople;
        }
    }

    return $fallbackPeople;
}

function shouldAutoAppendListenerToPeople($eventType, $eventData, $listenerName)
{
    $eventType = strtolower((string)$eventType);
    $listenerName = trim((string)$listenerName);
    if ($listenerName === "") {
        return false;
    }

    $listenerNormalized = normalizeActorNameForComparison($listenerName);
    if ($listenerNormalized === "the narrator" && $eventType !== "narrator_inputtext") {
        return false;
    }

    if (!in_array($eventType, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
        return true;
    }

    if ($eventType === "narrator_inputtext") {
        return (strcasecmp(normalizeActorNameForComparison($listenerName), "the narrator") === 0);
    }

    $targetMeta = extractTalkTargetMetadata($eventData);
    if (!$targetMeta["hasExplicitTarget"] || $targetMeta["isBroadcast"]) {
        return true;
    }

    if (empty($targetMeta["targets"])) {
        return false;
    }

    return talkTargetsIncludeName($targetMeta["targets"], $listenerName);
}

function buildScopedPeopleForPlayerInput($eventType, $eventData, $listenerName, $fallbackPeople = "")
{
    $eventType = strtolower((string)$eventType);
    if (!in_array($eventType, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
        return $fallbackPeople;
    }

    if ($eventType === "narrator_inputtext") {
        $participants = [];
        $speakerName = extractSpeakerNameFromInputEvent($eventData);
        if ($speakerName === "" && !empty(\Synth\Bootstrap::config('PLAYER_NAME'))) {
            $speakerName = trim((string)\Synth\Bootstrap::config("PLAYER_NAME"));
        }
        if ($speakerName !== "") {
            appendUniqueActorName($participants, $speakerName);
        }
        appendUniqueActorName($participants, ($listenerName !== "") ? $listenerName : "The Narrator");

        if (!empty(\Synth\Bootstrap::config('HIDE_NARRATOR_DIALOGUE'))) {
            return normalizePeoplePipeList($participants);
        }

        $sharedPeople = buildCanonicalAudiencePeople($fallbackPeople, $participants);
        return ($sharedPeople !== "") ? $sharedPeople : normalizePeoplePipeList($participants);
    }

    $targetMeta = extractTalkTargetMetadata($eventData);
    if ($targetMeta["isBroadcast"]) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'SCOPE', "Broadcast target detected for {$eventType}; keeping fallback people");
        return $fallbackPeople;
    }

    $targetNames = $targetMeta["targets"];

    // Keep private conversations private: only include the listener when it is an explicit target.
    if (!empty($listenerName) && talkTargetsIncludeName($targetNames, $listenerName) && !in_array($listenerName, $targetNames, true)) {
        $targetNames[] = $listenerName;
    }

    // Include the speaker so player-directed lines remain attributable in people context.
    $speakerName = extractSpeakerNameFromInputEvent($eventData);
    if ($speakerName === "" && !empty(\Synth\Bootstrap::config('PLAYER_NAME'))) {
        $speakerName = trim((string)\Synth\Bootstrap::config("PLAYER_NAME"));
    }
    if ($speakerName !== "" && !in_array($speakerName, $targetNames, true)) {
        $targetNames[] = $speakerName;
    }

    if (empty($targetNames) && !empty($listenerName)) {
        $targetNames[] = $listenerName;
    }

    $scopedPeople = normalizePeoplePipeList($targetNames);
    if ($scopedPeople !== "") {
        $debugTargets = implode(",", $targetNames);
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'SCOPE', "{$eventType} listener='{$listenerName}' targets='{$debugTargets}' scoped='{$scopedPeople}'");
        return $scopedPeople;
    }

    \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'SCOPE', "{$eventType} produced empty scoped people; using fallback");
    return $fallbackPeople;
}

function buildScopedPeopleForEvent($eventType, $eventData, $listenerName, $fallbackPeople = "")
{
    $eventType = strtolower((string)$eventType);

    $narratorPrivatePeople = buildNarratorPrivatePeopleForEvent($eventType, $eventData, $listenerName);
    if ($narratorPrivatePeople !== "") {
        return $narratorPrivatePeople;
    }

    $narratorSharedPeople = buildNarratorSharedPeopleForEvent($eventType, $eventData, $listenerName, $fallbackPeople);
    if ($narratorSharedPeople !== "") {
        return $narratorSharedPeople;
    }

    $effectiveFallback = $fallbackPeople;
    if (isStrictSpatialPeopleModeEnabled() && !in_array($eventType, ["infoaction", "funcret"], true)) {
        $strictFallback = buildStrictFallbackPeopleForEvent($eventType, $eventData, $listenerName, $fallbackPeople);
        if ($strictFallback !== "") {
            $effectiveFallback = $strictFallback;
        } else {
            // Keep empty in strict mode instead of widening to broad nearby people.
            $effectiveFallback = "";
        }
    }

    if (in_array($eventType, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
        return buildScopedPeopleForPlayerInput($eventType, $eventData, $listenerName, $effectiveFallback);
    }

    if ($eventType === "chat") {
        return buildScopedPeopleForChatEvent($eventData, $effectiveFallback);
    }

    if (in_array($eventType, ["infoaction", "funcret"], true)) {
        return buildScopedPeopleForInfoActionEvent($eventData, $effectiveFallback);
    }

    return buildScopedPeopleFromSpatialEvidence($eventType, $eventData, $listenerName, $effectiveFallback);
}

function isNarratorHistoricContextLine($content)
{
    $content = trim((string)$content);
    if (strpos($content, 'The Narrator:') !== 0) {
        return false;
    }

    return preg_match('/^The Narrator:\s*\(/', $content) === 1
        || strpos($content, 'The Narrator: background dialogue:') === 0
        || strpos($content, 'The Narrator: action moved to new location:') === 0
        || strpos($content, 'The Narrator: SCENARIO CHANGE') === 0
        || preg_match('/^The Narrator:\s*about\s+\d+\s+hours\s+later/i', $content) === 1;
}

function filterHistoricContextForNarratorVisibility(array $contextDataHistoric, $actorName)
{
    $narratorReader=synth_protocol_response_is_narrator(normalizeActorNameForComparison($actorName));
    $hideNarratorDialogue = !empty(\Synth\Bootstrap::config('HIDE_NARRATOR_DIALOGUE'));
    $nativeHistory=(\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1)===2;

    $filtered=array_values(array_filter($contextDataHistoric, static function ($entry) use ($hideNarratorDialogue,$narratorReader,$nativeHistory) {
        if ($narratorReader || !is_array($entry)) {
            return true;
        }

        $content = isset($entry["content"]) ? (string)$entry["content"] : "";
        if (preg_match('/\(\s*(?:Talking|Whispering|Shouting|Speaking privately|Speaking loudly)\s+to\s+The Narrator(?:\s+from\s+far\s+away)?\s*\)/i', $content) === 1
            && !($nativeHistory && ($entry['_synth_history_listener'] ?? '')==='npc')) {
            return false;
        }

        if (!$hideNarratorDialogue) {
            return true;
        }

        if ($nativeHistory && ($entry['_synth_history_speech'] ?? '')==='npc') return true;
        if ($nativeHistory && ($entry['_synth_history_speech'] ?? '')==='narrator') return false;
        if (strpos($content, 'The Narrator:') === 0) {
            return isNarratorHistoricContextLine($content);
        }

        return true;
    }));
    // Internal provenance must not become an unsupported provider message field.
    foreach ($filtered as &$entry) {
        if (is_array($entry)) unset($entry['_synth_history_speech'],$entry['_synth_history_listener']);
    }
    unset($entry);
    return $filtered;
}

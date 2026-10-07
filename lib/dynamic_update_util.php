<?php
require_once __DIR__.'/synth_profile_jobs.php';
require_once __DIR__.'/synth_diary_scope.php';
// Function to process diary entries for all nearby NPCs (triggered by C++ with 400 unit range)
function processNearbyDiary($gameRequest, $eventType) {
    global $db;
    
    // Note: The C++ code handles the 400 unit range filtering and sends individual diary requests
    // This function processes the diary_nearby event type but won't actually be called for bulk processing
    // Individual diary requests will come through the normal diary handler
    
    Logger::info("DIARY_NEARBY: diary_nearby event received - C++ will handle individual NPCs within 400 units");
    
    // Just log that the nearby diary was triggered
    synth_buffer_command_response_line(
        "The Narrator",
        "DebugNotification",
        ["message" => "Checking for nearby NPCs within 400 units..."]
    );
}

// Function to generate diary entry for a nearby NPC (similar to followers but for any NPC)
function generateNearbyDiary($npcName, $gameRequest, $eventType) {
    global $db;
    if (synth_diary_requires_owned_context()) {
        Logger::warn('Native diary requires an owned generation pipeline; legacy nearby writer refused.');
        return false;
    }

    $defaultDiaryConnector = function_exists('synthResolveDiaryConnectorName') ? synthResolveDiaryConnectorName() : null;
    if ($defaultDiaryConnector === null) {
        Logger::info("DIARY_NEARBY: No diary connector configured for $npcName");
        return false;
    }
    
    // Temporarily switch context to this NPC
    $originalSYNTHName = $GLOBALS["SYNTH_NAME"];
    $GLOBALS["SYNTH_NAME"] = $npcName;
    
    try {
        $NPC_CONF = [
            "SYNTH_NAME" => $npcName,
            "PLAYER_NAME" => $GLOBALS["PLAYER_NAME"],
            "SYNTH_PERS" => "An NPC in the world of Fallout.",
            "PROMPT_HEAD" => isset($GLOBALS["PROMPT_HEAD"]) ? $GLOBALS["PROMPT_HEAD"] : "You are an NPC in the world of Fallout.",
            "COMMAND_PROMPT" => isset($GLOBALS["COMMAND_PROMPT"]) ? $GLOBALS["COMMAND_PROMPT"] : "",
            "CONTEXT_HISTORY" => isset($GLOBALS["CONTEXT_HISTORY"]) ? $GLOBALS["CONTEXT_HISTORY"] : 25,
            "CONTEXT_HISTORY_DIARY" => isset($GLOBALS["CONTEXT_HISTORY_DIARY"]) ? $GLOBALS["CONTEXT_HISTORY_DIARY"] : 0,
            "CONNECTORS_DIARY" => $defaultDiaryConnector
        ];

        if ($npcName === "The Narrator") {
            require_once(__DIR__ . "/core/narrator.class.php");
            $narrator = new Narrator();
            $profileData = $narrator->getNarratorData();
        } else {
            require_once(__DIR__ . "/core/npc_master.class.php");
            $npcMaster = new NpcMaster();
            $profileData = $npcMaster->getByName($npcName);
        }

        if (is_array($profileData) && !empty($profileData)) {
            $NPC_CONF["SYNTH_PERS"] = $profileData["core"] ?? $profileData["npc_static_bio"] ?? $NPC_CONF["SYNTH_PERS"];
            $NPC_CONF["PROMPT_HEAD"] = $profileData["prompt_head"] ?? $NPC_CONF["PROMPT_HEAD"];
            $NPC_CONF["SYNTH_BACKGROUND"] = $profileData["npc_static_bio"] ?? "";
            $NPC_CONF["SYNTH_PERSONALITY"] = $profileData["personality"] ?? "";
            $NPC_CONF["SYNTH_APPEARANCE"] = $profileData["appearance"] ?? "";
            $NPC_CONF["SYNTH_OCCUPATION"] = $profileData["occupation"] ?? "";
            $NPC_CONF["SYNTH_SKILLS"] = $profileData["skills"] ?? "";
            $NPC_CONF["SYNTH_SPEECHSTYLE"] = $profileData["speechstyle"] ?? "";
            $NPC_CONF["SYNTH_GOALS"] = $profileData["goals"] ?? "";
            Logger::info("DIARY_NEARBY: Loaded database profile for $npcName");
        } else {
            Logger::info("DIARY_NEARBY: Using default profile for $npcName");
        }

        $resolvedDiaryConnector = function_exists('synthResolveDiaryConnectorName')
            ? synthResolveDiaryConnectorName($NPC_CONF["CONNECTORS_DIARY"] ?? $defaultDiaryConnector)
            : $defaultDiaryConnector;
        if ($resolvedDiaryConnector === null) {
            Logger::warn("DIARY_NEARBY: Unable to resolve diary connector for $npcName");
            return false;
        }
        $NPC_CONF["CONNECTORS_DIARY"] = $resolvedDiaryConnector;

        // Always enforce PLAYER_NAME from database-synced global, overriding any stale value in profiles
        // Ensures all '#PLAYER_NAME#' replacements resolve to current in-game name
        $NPC_CONF["PLAYER_NAME"] = $GLOBALS["PLAYER_NAME"];
        
        // Use centralized function from data_functions.php
        $dynamicBio = buildDynamicBiography($NPC_CONF);
        
        $head = [
            ["role" => "system", "content" => strtr(
                $NPC_CONF["PROMPT_HEAD"] . "\n" . $NPC_CONF["SYNTH_PERS"] . $dynamicBio . "\n" . $NPC_CONF["COMMAND_PROMPT"],
                ["#PLAYER_NAME#" => $NPC_CONF["PLAYER_NAME"]]
            )]
        ];
        
        // Use diary-specific context history if this is a diary request and CONTEXT_HISTORY_DIARY is set
        if (isset($NPC_CONF["CONTEXT_HISTORY_DIARY"]) && $NPC_CONF["CONTEXT_HISTORY_DIARY"] > 0) {
            $lastNDataForContext = $NPC_CONF["CONTEXT_HISTORY_DIARY"] + 0;
        } else {
            $lastNDataForContext = (isset($NPC_CONF["CONTEXT_HISTORY"])) ? ($NPC_CONF["CONTEXT_HISTORY"] + 0) : 25;
        }

        $sqlfilter = " and type<>'prechat'";
        $contextDataHistoric = DataLastDataExpandedFor("{$NPC_CONF["SYNTH_NAME"]}", $lastNDataForContext * -1, $sqlfilter);
        $historyData = "";
        foreach ($contextDataHistoric as $element) {
            $historyData .= trim("{$element["content"]}") . PHP_EOL . PHP_EOL;
        }

        // Build user prompt for diary generation (like regular diary)
        $prompt = [];
        if (!empty($contextDataHistoric)) {
            $prompt[] = ["role" => "user", "content" => "Recent context: " . $historyData];
        }

        $diaryPrompt = strtr($GLOBALS["DIARY_PROMPT"], ['#SYNTH_NAME#'=>$npcName,'#PLAYER_NAME#'=>$NPC_CONF["PLAYER_NAME"]]);
        $prompt[] = ["role" => "user", "content" => $diaryPrompt];

        $contextData = array_merge($head, $prompt);
        
        // Set the request type for diary so connector knows to use diary grammar
        $originalGameRequest = isset($GLOBALS["gameRequest"]) ? $GLOBALS["gameRequest"] : null;
        $GLOBALS["gameRequest"] = [0 => "diary", 1 => time(), 2 => $gameRequest[2], 3 => "Auto diary for " . $npcName];
        
        // Generate diary entry using LLM
        require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."connector".DIRECTORY_SEPARATOR."{$NPC_CONF["CONNECTORS_DIARY"]}.php");
        
        $connectionHandler = new $resolvedDiaryConnector();
        // Prefer connector's configured max_tokens; fallback to memory max token config; then a sane default
        $maxTokens = null;
        if (isset($GLOBALS["CONNECTOR"][$resolvedDiaryConnector]["max_tokens"]) && $GLOBALS["CONNECTOR"][$resolvedDiaryConnector]["max_tokens"] !== '') {
            $maxTokens = (int)$GLOBALS["CONNECTOR"][$resolvedDiaryConnector]["max_tokens"];
        } elseif (isset($GLOBALS["CONNECTOR"][$resolvedDiaryConnector]["MAX_TOKENS_MEMORY"])) {
            $maxTokens = (int)$GLOBALS["CONNECTOR"][$resolvedDiaryConnector]["MAX_TOKENS_MEMORY"];
        } else {
            $maxTokens = 2048;
        }

        // Pass provider-agnostic MAX_TOKENS override so connectors map to correct API field
        $connectionHandler->open($contextData, ["MAX_TOKENS" => $maxTokens]);
        
        $buffer = "";
        $totalBuffer = "";
        $breakFlag = false;
        
        while (true) {
            if ($breakFlag) {
                break;
            }
            
            if ($connectionHandler->isDone()) {
                $breakFlag = true;
            }
            
            $buffer .= $connectionHandler->process();
            $totalBuffer .= $buffer;
        }
        
        $connectionHandler->close("generateNearbyDiary");
        
        // Restore original gameRequest after diary generation
        if ($originalGameRequest !== null) {
            $GLOBALS["gameRequest"] = $originalGameRequest;
        } else {
            unset($GLOBALS["gameRequest"]);
        }
        
        if (!empty(trim($buffer))) {
            // Save diary entry to database
            $topic = DataLastKnowDate();
            $location = DataLastKnownLocation();
            $momentum=time();
            $db->insert(
                'diarylog',
                array(
                    'ts' => $gameRequest[1],
                    'gamets' => $gameRequest[2],
                    'topic' => $topic . " (Nearby diary: $eventType)",
                    'content' => trim($buffer),
                    'tags' => "Nearby-diary,$eventType",
                    'people' => $npcName,
                    'location' => $location,
                    'sess' => $momentum,
                    'localts' => time()
                )
            );
            
            // Log memory
            if (function_exists('logMemory')) {
                logMemory($npcName, $npcName, trim($buffer), $momentum, $gameRequest[2], 'nearby_diary', $gameRequest[1]);
            }
            
            return true;
        }
        
    } catch (Exception $e) {
        Logger::error("DIARY_NEARBY: Error generating diary for $npcName: " . $e->getMessage());
    } finally {
        // Restore original context
        $GLOBALS["SYNTH_NAME"] = $originalSYNTHName;
    }
    
    return false;
}

// Function to process AUTO_DIARY for nearby NPCs with auto_diary_enabled
function synthAutoDiaryNormalizeAudienceName($rawName): string
{
    $name = trim((string)$rawName, " \t\n\r\0\x0B|");
    if ($name === '' || strcasecmp($name, '<no name>') === 0) {
        return '';
    }

    if (preg_match('/\s*\((dead|disabled|unavailable|invalid_actor|invalid_position|different_interior_cells|interior_exterior_boundary)\)\s*$/i', $name)) {
        return '';
    }

    if (function_exists('synthDataStripActorStateSuffix')) {
        $name = synthDataStripActorStateSuffix($name);
    } else {
        $name = preg_replace('/\s*\((?:busy|hostile|in combat|far away|too far away|too_far|too_quiet|closed_door|navmesh_no_path|path_unavailable|restrained|audible|checking(?: hearing|: [^)]+)?|can hear you(?:, muffled|: [^)]+)?)\)\s*$/i', '', $name);
    }

    return trim((string)$name);
}

function synthAutoDiaryAudienceNames(array $gameRequest): array
{
    $snapshot = [];
    if (!empty($gameRequest[4])) {
        $decoded = json_decode((string)$gameRequest[4], true);
        if (is_array($decoded)) {
            $snapshot = $decoded;
        }
    }

    $rawNames = [];
    $appendNames = static function ($value) use (&$rawNames): void {
        if (is_string($value)) {
            foreach (preg_split('/[|,]/', $value) ?: [] as $name) {
                $rawNames[] = $name;
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    if (array_key_exists('eligible', $entry) && !filter_var($entry['eligible'], FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }
                    $rawNames[] = $entry['name'] ?? '';
                } else {
                    $rawNames[] = $entry;
                }
            }
        }
    };

    $appendNames($snapshot['people'] ?? null);
    $appendNames($snapshot['companions'] ?? null);
    $appendNames($snapshot['actors'] ?? null);

    $source = 'event_snapshot';
    if (empty($rawNames)) {
        $source = 'latest_nearby_actors_fallback';
        $fallback = DataBeingsInCloseRange(true);
        $appendNames($fallback);
    }

    $playerName = trim((string)($GLOBALS['PLAYER_NAME'] ?? ''));
    $names = [];
    $seen = [];
    foreach ($rawNames as $rawName) {
        $name = synthAutoDiaryNormalizeAudienceName($rawName);
        if ($name === '' || ($playerName !== '' && strcasecmp($name, $playerName) === 0)) {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }

    return ['names' => $names, 'source' => $source];
}

function buildPlayerDiaryPrompt(string $playerName): string
{
    $template = trim((string)($GLOBALS["PLAYER_DIARY_PROMPT"] ?? ''));
    if ($template === '') {
        $template = "Please write a short summary of #PLAYER_NAME#'s recent dialogues and events written above into #PLAYER_NAME#'s diary. WRITE AS IF YOU WERE #PLAYER_NAME#. Use first person and start the diary entry with the current date and time.";
    }

    return strtr($template, ['#PLAYER_NAME#' => $playerName]);
}

function getConfiguredPlayerDiaryName(Player $player): string
{
    $playerName = trim((string)($player->get('player_name') ?? ($GLOBALS["PLAYER_NAME"] ?? '')));
    if ($playerName === '') {
        $playerName = 'Player';
    }

    return $playerName;
}

function isPlayerDiaryEnabled(): bool
{
    if (!class_exists('Player')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
    }

    try {
        $player = new Player();
        return $player->getBool('diary_enabled', false);
    } catch (Throwable $e) {
        Logger::error("PLAYER_DIARY: Failed to read player diary setting: " . $e->getMessage());
        return false;
    }
}

function isPlayerAutoDiaryEnabled(): bool
{
    if (!class_exists('Player')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
    }

    try {
        $player = new Player();
        return $player->getBool('auto_diary_enabled', false);
    } catch (Throwable $e) {
        Logger::error("PLAYER_DIARY: Failed to read player auto diary setting: " . $e->getMessage());
        return false;
    }
}

function isPlayerAutoDiaryWaitEnabled(): bool
{
    if (!class_exists('Player')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
    }

    try {
        $player = new Player();
        return $player->getBool('auto_diary_wait_enabled', false);
    } catch (Throwable $e) {
        Logger::error("PLAYER_DIARY: Failed to read player auto diary wait setting: " . $e->getMessage());
        return false;
    }
}

function generatePlayerDiary($gameRequest, $eventType)
{
    global $db;
    if (synth_diary_requires_owned_context()) {
        Logger::warn('Native diary requires an owned generation pipeline; legacy player writer refused.');
        return false;
    }

    if (!class_exists('Player')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
    }
    if (!class_exists('LLMConnector')) {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "llm_connector.class.php");
    }

    $player = new Player();
    $playerName = getConfiguredPlayerDiaryName($player);
    $allPlayerData = $player->getAll();

    $playerAppearance = trim((string)($allPlayerData['appearance'] ?? ''));
    $playerBackground = trim((string)($allPlayerData['background'] ?? ''));
    if ($playerBackground === '') {
        $playerBackground = trim((string)($allPlayerData['bio'] ?? ''));
    }
    $playerSpeechStyle = trim((string)($allPlayerData['speech_style'] ?? ''));

    $playerAppearance = strtr($playerAppearance, ['#PLAYER_NAME#' => $playerName]);
    $playerBackground = strtr($playerBackground, ['#PLAYER_NAME#' => $playerName]);
    $playerSpeechStyle = strtr($playerSpeechStyle, ['#PLAYER_NAME#' => $playerName]);

    $systemParts = [
        "You are writing {$playerName}'s private diary in the world of Fallout.",
        "Write in first person as {$playerName}.",
        "Do not write as The Narrator or as any NPC companion.",
        "Ground the entry in the recent dialogue and events provided.",
        "Use the provided background and speech style details to keep the entry consistent with {$playerName}'s history and voice."
    ];

    $resolvedPromptHead = trim(strtr((string)($GLOBALS["PROMPT_HEAD"] ?? ''), [
        '#PLAYER_NAME#' => $playerName,
        '#SYNTH_NAME#' => $playerName
    ]));
    if ($resolvedPromptHead !== '') {
        $systemParts[] = $resolvedPromptHead;
    }

    $playerDetails = [];
    if ($playerAppearance !== '') {
        $playerDetails[] = "#Appearance\n{$playerAppearance}";
    }
    if ($playerBackground !== '') {
        $playerDetails[] = "#Background\n{$playerBackground}";
    }
    if ($playerSpeechStyle !== '') {
        $playerDetails[] = "#Speech Style\n{$playerSpeechStyle}";
    }
    if (!empty($playerDetails)) {
        $systemParts[] = "#Character Details\n" . implode("\n\n", $playerDetails);
    }

    $head[] = [
        'role' => 'system',
        'content' => implode("\n\n", $systemParts)
    ];

    if (isset($GLOBALS["CONTEXT_HISTORY_DIARY"]) && $GLOBALS["CONTEXT_HISTORY_DIARY"] > 0) {
        $lastNDataForContext = $GLOBALS["CONTEXT_HISTORY_DIARY"] + 0;
    } else {
        $lastNDataForContext = isset($GLOBALS["CONTEXT_HISTORY"]) ? ($GLOBALS["CONTEXT_HISTORY"] + 0) : 25;
    }

    $sqlfilter = " and type<>'prechat' and type<>'itemfound' and type<>'infoaction' ";
    $contextDataHistoric = DataLastDataExpandedFor($playerName, $lastNDataForContext * -1, $sqlfilter);
    $historyData = "";
    foreach ($contextDataHistoric as $element) {
        $historyData .= trim((string)($element["content"] ?? '')) . PHP_EOL . PHP_EOL;
    }

    $prompt = [];
    if (!empty($contextDataHistoric)) {
        $prompt[] = ["role" => "user", "content" => "Recent context: " . $historyData];
    }

    $prompt[] = [
        "role" => "user",
        "content" => buildPlayerDiaryPrompt($playerName)
    ];

    $contextData = array_merge($head, $prompt);

    $originalGameRequest = $GLOBALS["gameRequest"] ?? null;
    $GLOBALS["gameRequest"] = [0 => "diary_player", 1 => time(), 2 => $gameRequest[2], 3 => "Diary for " . $playerName];

    $hadForce = array_key_exists('FORCE_MAX_TOKENS', $GLOBALS);
    $prevForce = $hadForce ? $GLOBALS['FORCE_MAX_TOKENS'] : null;
    if ($hadForce) {
        unset($GLOBALS['FORCE_MAX_TOKENS']);
    }

    $buffer = "";

    try {
        $connector = new LLMConnector();
        $coreConnectorId = trim((string)$player->get('diary_connector_id'));
        if ($coreConnectorId === '') {
            foreach (['CORE_CONNECTOR_SUMMARY', 'CORE_CONNECTOR_PLAYER', 'CORE_CONNECTOR_PROFILES'] as $globalConnectorKey) {
                $candidateConnectorId = trim((string)($GLOBALS[$globalConnectorKey] ?? ''));
                if ($candidateConnectorId !== '') {
                    $coreConnectorId = $candidateConnectorId;
                    break;
                }
            }
        }

        $currentConnectorData = null;
        if ($coreConnectorId !== '') {
            $currentConnectorData = $connector->getById($coreConnectorId);
        }

        if (!empty($currentConnectorData)) {
            Logger::info("PLAYER_DIARY: Using core connector {$coreConnectorId} ({$currentConnectorData["driver"]}/{$currentConnectorData["model"]})");
            $connector->setOldGlobals($currentConnectorData);
            $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
            unset($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["stop"]);

            $maxTokens = null;
            if (isset($currentConnectorData["max_tokens"]) && $currentConnectorData["max_tokens"] !== null && $currentConnectorData["max_tokens"] !== '') {
                $maxTokens = (int)$currentConnectorData["max_tokens"];
            } elseif (isset($GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["max_tokens"])) {
                $maxTokens = (int)$GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["max_tokens"];
            } elseif (isset($GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["MAX_TOKENS_MEMORY"])) {
                $maxTokens = (int)$GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["MAX_TOKENS_MEMORY"];
            } else {
                $maxTokens = 2048;
            }

            $connectionHandler = $connector->getConnector($currentConnectorData);
            $buffer = (string)$connectionHandler->fast_request($contextData, ["MAX_TOKENS" => $maxTokens], "diary");
        } else {
            $diaryConnector = function_exists('synthResolveDiaryConnectorName') ? synthResolveDiaryConnectorName() : null;
            if ($diaryConnector === null) {
                Logger::warn("PLAYER_DIARY: No diary connector configured");
                return false;
            }

            Logger::warn("PLAYER_DIARY: Falling back to configured diary connector '{$diaryConnector}'");
            require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "connector" . DIRECTORY_SEPARATOR . "{$diaryConnector}.php");

            $maxTokens = null;
            if (isset($GLOBALS["CONNECTOR"][$diaryConnector]["max_tokens"]) && $GLOBALS["CONNECTOR"][$diaryConnector]["max_tokens"] !== '') {
                $maxTokens = (int)$GLOBALS["CONNECTOR"][$diaryConnector]["max_tokens"];
            } elseif (isset($GLOBALS["CONNECTOR"][$diaryConnector]["MAX_TOKENS_MEMORY"])) {
                $maxTokens = (int)$GLOBALS["CONNECTOR"][$diaryConnector]["MAX_TOKENS_MEMORY"];
            } else {
                $maxTokens = 2048;
            }

            $connectionHandler = new $diaryConnector();
            $connectionHandler->open($contextData, ["MAX_TOKENS" => $maxTokens]);

            while (true) {
                $chunk = $connectionHandler->process();
                if ($chunk !== null && $chunk !== false) {
                    $buffer .= $chunk;
                }

                if ($connectionHandler->isDone()) {
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        Logger::error("PLAYER_DIARY: Error generating diary for {$playerName}: " . $e->getMessage());
    } finally {
        if (isset($connectionHandler) && is_object($connectionHandler) && method_exists($connectionHandler, 'close')) {
            try {
                $connectionHandler->close("generatePlayerDiary");
            } catch (Throwable $closeError) {
                Logger::debug("PLAYER_DIARY: Connector close warning for {$playerName}: " . $closeError->getMessage());
            }
        }

        if ($hadForce) {
            $GLOBALS['FORCE_MAX_TOKENS'] = $prevForce;
        }

        if ($originalGameRequest !== null) {
            $GLOBALS["gameRequest"] = $originalGameRequest;
        } else {
            unset($GLOBALS["gameRequest"]);
        }
    }

    if (!empty(trim($buffer))) {
        $topic = DataLastKnowDate();
        $location = DataLastKnownLocation();
        $momentum = time();

        $db->insert(
            'diarylog',
            array(
                'ts' => $gameRequest[1],
                'gamets' => $gameRequest[2],
                'topic' => $topic . " (Player diary: $eventType)",
                'content' => trim($buffer),
                'tags' => "Player-diary,$eventType",
                'people' => $playerName,
                'location' => $location,
                'sess' => $momentum,
                'localts' => time()
            )
        );

        if (function_exists('logMemory')) {
            logMemory($playerName, $playerName, trim($buffer), $momentum, $gameRequest[2], 'player_diary', $gameRequest[1]);
        }

        synth_buffer_command_response_line($playerName, "DebugNotification", [
            "message" => "Diary Entry Written for " . $playerName,
        ]);

        return true;
    }

    return false;
}

function processAutoDiary($gameRequest, $eventType) {
    global $db;
    
    Logger::info("AUTO_DIARY: Function called for event type: $eventType");
    $playerDiaryEnabled = isPlayerDiaryEnabled();
    $playerAutoDiaryEnabled = isPlayerAutoDiaryEnabled();
    $playerAutoDiaryWaitEnabled = isPlayerAutoDiaryWaitEnabled();
    $shouldProcessPlayerDiary = ($eventType === "goodnight" && $playerDiaryEnabled && $playerAutoDiaryEnabled)
        || ($eventType === "waitstart" && $playerDiaryEnabled && $playerAutoDiaryEnabled && $playerAutoDiaryWaitEnabled);
    
    $audience = synthAutoDiaryAudienceNames((array)$gameRequest);
    $nearbyNpcs = $audience['names'];
    Logger::info("AUTO_DIARY: Resolved " . count($nearbyNpcs) . " eligible nearby NPC name(s) from " . $audience['source']);
    
    // Add The Narrator if auto diary is enabled for narrator
    // The Narrator is always "nearby" (conceptually omnipresent)
    if (!empty($GLOBALS["NARRATOR_AUTO_DIARY_ENABLED"])) {
        $nearbyNpcs[] = "The Narrator";
        Logger::info("AUTO_DIARY: Added The Narrator to auto diary processing (auto toggle enabled)");
    }
    
    if (empty($nearbyNpcs) && !$shouldProcessPlayerDiary) {
        Logger::info("AUTO_DIARY: No nearby NPCs, narrator, or player diary entries to process");
        return;
    }
    
    $processedCount = 0;
    $generatedCount = 0;
    $diaryCooldownPeriod = isset($GLOBALS["DIARY_COOLDOWN"]) ? intval($GLOBALS["DIARY_COOLDOWN"]) : 30;
    
    if ($shouldProcessPlayerDiary) {
        try {
            if (!class_exists('Player')) {
                require_once(__DIR__ . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
            }

            $player = new Player();
            $playerName = getConfiguredPlayerDiaryName($player);
            $playerNameSafe = preg_replace('/[^a-zA-Z0-9_]/', '_', $playerName);
            $cooldownKey = "DIARY_LAST_TIMESTAMP_PLAYER_" . $playerNameSafe;

            $diaryRecord = $db->fetchAll("SELECT value FROM conf_opts WHERE id='" . $db->escape($cooldownKey) . "'");
            if (!empty($diaryRecord) && isset($diaryRecord[0]['value'])) {
                $lastTimestamp = intval($diaryRecord[0]['value']);
                $timeSinceLastDiary = time() - $lastTimestamp;

                if ($timeSinceLastDiary < $diaryCooldownPeriod) {
                    $remaining = $diaryCooldownPeriod - $timeSinceLastDiary;
                    Logger::debug("AUTO_DIARY: {$playerName} is on diary cooldown (remaining: {$remaining}s), skipping");
                } else {
                    $processedCount++;
                    if (generatePlayerDiary($gameRequest, $eventType)) {
                        $generatedCount++;
                        $db->query("DELETE FROM conf_opts WHERE id='" . $db->escape($cooldownKey) . "'");
                        $db->insert('conf_opts', [
                            'id' => $cooldownKey,
                            'value' => (string)time()
                        ]);
                        Logger::info("AUTO_DIARY: Successfully generated player diary for {$playerName}");
                    } else {
                        Logger::warn("AUTO_DIARY: Failed to generate player diary for {$playerName}");
                    }
                }
            } else {
                $processedCount++;
                if (generatePlayerDiary($gameRequest, $eventType)) {
                    $generatedCount++;
                    $db->insert('conf_opts', [
                        'id' => $cooldownKey,
                        'value' => (string)time()
                    ]);
                    Logger::info("AUTO_DIARY: Successfully generated player diary for {$playerName}");
                } else {
                    Logger::warn("AUTO_DIARY: Failed to generate player diary for {$playerName}");
                }
            }
        } catch (Throwable $e) {
            Logger::error("AUTO_DIARY: Error processing player diary: " . $e->getMessage());
        }
    }

    Logger::info("AUTO_DIARY: Processing $eventType event for " . count($nearbyNpcs) . " nearby NPCs/entities");
    
    foreach ($nearbyNpcs as $npcName) {
        if (empty($npcName)) {
            continue;
        }
        
        // Special handling for The Narrator
        if ($npcName === "The Narrator") {
            // Check if narrator auto diary is enabled
            if (empty($GLOBALS["NARRATOR_AUTO_DIARY_ENABLED"])) {
                Logger::debug("AUTO_DIARY: The Narrator auto diary is disabled, skipping");
                continue;
            }
            
            // Check narrator's diary cooldown
            $cooldownKey = "DIARY_LAST_TIMESTAMP_The_Narrator";
            $diaryRecord = $db->fetchAll("SELECT value FROM conf_opts WHERE id='" . $db->escape($cooldownKey) . "'");
            
            if (!empty($diaryRecord) && isset($diaryRecord[0]['value'])) {
                $lastTimestamp = intval($diaryRecord[0]['value']);
                $timeSinceLastDiary = time() - $lastTimestamp;
                
                if ($timeSinceLastDiary < $diaryCooldownPeriod) {
                    $remaining = $diaryCooldownPeriod - $timeSinceLastDiary;
                    Logger::debug("AUTO_DIARY: The Narrator is on diary cooldown (remaining: {$remaining}s), skipping");
                    continue;
                }
            }
            
            $processedCount++;
            
            // Generate diary for The Narrator
            Logger::info("AUTO_DIARY: Generating diary for The Narrator");
            $success = generateFollowerDiary("The Narrator", $gameRequest, $eventType);
            
            if ($success) {
                $generatedCount++;
                // Update cooldown timestamp
                $db->query("DELETE FROM conf_opts WHERE id='" . $db->escape($cooldownKey) . "'");
                $db->insert('conf_opts', [
                    'id' => $cooldownKey,
                    'value' => (string)time()
                ]);
                Logger::info("AUTO_DIARY: Successfully generated diary for The Narrator");
            } else {
                Logger::warn("AUTO_DIARY: Failed to generate diary for The Narrator");
            }
            
            continue; // Skip to next NPC
        }
        
        // Regular NPC processing
        // Get NPC data from database
        $npcMaster = new NpcMaster();
        $currentNpcData = $npcMaster->getByName($npcName);
        
        if (empty($currentNpcData)) {
            Logger::debug("AUTO_DIARY: NPC '$npcName' not found in database, skipping");
            continue;
        }
        
        // Load profile first so we can check profile defaults
        $profile = new CoreProfile();
        $currentProfileData = $profile->getById($currentNpcData["profile_id"]);
        $profile->setOldGlobals($currentProfileData);
        $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData);
        
        // Check auto_diary_enabled with proper profile inheritance
        $autoDiaryEnabled = false;
        $profileMeta = [];
        
        // First, get the profile default from metadata
        try {
            if (!empty($currentProfileData['metadata'])) {
                $decodedProfileMeta = json_decode($currentProfileData['metadata'], true);
                $profileMeta = is_array($decodedProfileMeta) ? $decodedProfileMeta : [];
                if (is_array($profileMeta) && isset($profileMeta['AUTO_DIARY_ENABLED'])) {
                    $autoDiaryEnabled = ($profileMeta['AUTO_DIARY_ENABLED'] === '1' || 
                                        $profileMeta['AUTO_DIARY_ENABLED'] === 1 || 
                                        $profileMeta['AUTO_DIARY_ENABLED'] === true);
                }
            }
        } catch (Throwable $e) {
            Logger::debug("AUTO_DIARY: Error reading profile metadata for $npcName: " . $e->getMessage());
        }
        
        // Then, check for NPC-specific override in extended_data
        if (!empty($currentNpcData['extended_data'])) {
            $extendedData = json_decode($currentNpcData['extended_data'], true);
            if (is_array($extendedData) && 
                array_key_exists('auto_diary_enabled', $extendedData) && 
                $extendedData['auto_diary_enabled'] !== null && 
                $extendedData['auto_diary_enabled'] !== '') {
                // NPC has explicit override
                $autoDiaryEnabled = !empty($extendedData['auto_diary_enabled']);
                Logger::debug("AUTO_DIARY: NPC '$npcName' has explicit override: " . ($autoDiaryEnabled ? 'enabled' : 'disabled'));
            }
        }
        
        if (!$autoDiaryEnabled) {
            Logger::debug("AUTO_DIARY: NPC '$npcName' does not have auto_diary_enabled (checked both NPC override and profile default), skipping");
            continue;
        }
        
        $processedCount++;
        
        // Check diary cooldown for this specific NPC
        $npcNameSafe = preg_replace('/[^a-zA-Z0-9_]/', '_', $npcName);
        $cooldownKey = "DIARY_LAST_TIMESTAMP_" . $npcNameSafe;
        $npcDiaryCooldownPeriod = array_key_exists('DIARY_COOLDOWN', $profileMeta)
            ? max(0, intval($profileMeta['DIARY_COOLDOWN']))
            : $diaryCooldownPeriod;
        
        $diaryRecord = $db->fetchAll("SELECT value FROM conf_opts WHERE id='" . $db->escape($cooldownKey) . "'");
        
        if (!empty($diaryRecord)) {
            $lastTrigger = (int) $diaryRecord[0]['value'];
            $timeElapsed = time() - $lastTrigger;

            if ($timeElapsed < $npcDiaryCooldownPeriod) {
                Logger::info("AUTO_DIARY: Skipping $npcName (cooldown active: " . ($npcDiaryCooldownPeriod - $timeElapsed) . " seconds remaining)");
                continue;
            }
        }

        // Check AUTO_DIARY_WAIT with profile inheritance
        $autoDiaryWait = false;
        
        // Get profile default for AUTO_DIARY_WAIT
        try {
            if (!empty($currentProfileData['metadata'])) {
                $profileMeta = json_decode($currentProfileData['metadata'], true);
                if (is_array($profileMeta) && isset($profileMeta['AUTO_DIARY_WAIT_ENABLED'])) {
                    $autoDiaryWait = ($profileMeta['AUTO_DIARY_WAIT_ENABLED'] === '1' || 
                                     $profileMeta['AUTO_DIARY_WAIT_ENABLED'] === 1 || 
                                     $profileMeta['AUTO_DIARY_WAIT_ENABLED'] === true);
                }
            }
        } catch (Throwable $e) {}
        
        // Check for NPC override
        if (!empty($currentNpcData['extended_data'])) {
            $extendedData = json_decode($currentNpcData['extended_data'], true);
            if (is_array($extendedData) && 
                array_key_exists('auto_diary_wait_enabled', $extendedData) && 
                $extendedData['auto_diary_wait_enabled'] !== null && 
                $extendedData['auto_diary_wait_enabled'] !== '') {
                $autoDiaryWait = !empty($extendedData['auto_diary_wait_enabled']);
            }
        }
        
        // For goodnight events, always generate. For waitstart events, check the setting.
        $shouldGenerate = ($eventType === "goodnight") || ($eventType === "waitstart" && $autoDiaryWait);
        
        Logger::info("AUTO_DIARY: $npcName - eventType=$eventType, autoDiaryWait=" . 
                    ($autoDiaryWait ? 'true' : 'false') . 
                    ", shouldGenerate=" . ($shouldGenerate ? 'true' : 'false'));

        if ($shouldGenerate) {
            // Generate diary entry for this NPC
            if (generateFollowerDiary($npcName, $gameRequest, $eventType)) {
                $generatedCount++;
                $db->upsertRowOnConflict(
                    'conf_opts',
                    array(
                        'id' => $cooldownKey,
                        'value' => time()
                    ),
                    "id"
                );
                Logger::info("AUTO_DIARY: Generated diary entry for $npcName");
            } else {
                Logger::warn("AUTO_DIARY: Failed to generate diary entry for $npcName; cooldown was not started");
            }
        } else {
            Logger::info("AUTO_DIARY: Skipped $npcName - AUTO_DIARY_WAIT disabled for this profile");
        }
    }
    
    Logger::info("AUTO_DIARY: Processed $processedCount NPCs with auto_diary_enabled, generated $generatedCount diary entries");
}

// Function to process a single NPC's dynamic profile
function processSingleDynamicProfile($npcName, $gameRequest, bool $prepareOnly = false) {
    global $db;
    synth_profile_assert_write_target($db,$npcName);
    
    // Ensure required dependencies are loaded
    if (!function_exists('DataSpeechJournal') || !function_exists('buildDynamicProfileDisplay')) {
        require_once(__DIR__ . "/../lib/data_functions.php");
    }
    
    // Handle The Narrator separately
    if ($npcName === "The Narrator") {
        return processNarratorDynamicProfile($db,$prepareOnly);
    }
    
    // Check if profile exists for this NPC
    $npcMaster=new NpcMaster();
    $npcData=$npcMaster->getByName($npcName);
    if (!$npcData) {
        Logger::debug("processSingleDynamicProfile: No profile found for $npcName");
        return false;
    }

    // Load profile, maybe DYNAMIC_PROFILE_FIELDS is defined there
    $profile=new CoreProfile();
    $currentProfileData=$profile->getById($npcData["profile_id"] ?? null);

    $npcDataMetadata=json_decode($npcData["metadata"] ?? "{}",true);
    if (!is_array($npcDataMetadata)) {
        $npcDataMetadata = [];
    }
    $profileMetadata=json_decode($currentProfileData["metadata"] ?? "{}",true);
    if (!is_array($profileMetadata)) {
        $profileMetadata = [];
    }
    if (empty($npcDataMetadata["DYNAMIC_PROFILE_FIELDS"])) {
        $npcData["DYNAMIC_PROFILE_FIELDS"]=$profileMetadata["DYNAMIC_PROFILE_FIELDS"] ?? [];
    } else
        $npcData["DYNAMIC_PROFILE_FIELDS"]=$npcDataMetadata["DYNAMIC_PROFILE_FIELDS"];

   
    try {
        $characterDynamicProfile = $npcData["dynamic_profile"] ?? $GLOBALS["DYNAMIC_PROFILE"] ?? false;
        
        if (($npcData["dynamic_profile"] ?? null) === null
            && isset($profileMetadata["DYNAMIC_PROFILE_ENABLED"])
            && $profileMetadata["DYNAMIC_PROFILE_ENABLED"]==1) {
            $characterDynamicProfile=true;
        }

        // restores logic now that DYNAMIC_PROFILE_FIELDS is in npcData
        $characterDynamicProfileFields = $npcData["DYNAMIC_PROFILE_FIELDS"] ??
            $GLOBALS["DYNAMIC_PROFILE_FIELDS"] ?? // use default conf.php settings
            ["personality", "speechstyle", "goals"]; //fallback

        // Check if DYNAMIC_PROFILE is enabled for this NPC
        if (!$characterDynamicProfile) {
            Logger::debug("processSingleDynamicProfile: DYNAMIC_PROFILE disabled for $npcName");
            return false;
        }
        
       
        // Check if core connector is configured for dynamic profile updates
        $connector = new LLMConnector();
        $currentConnectorData = $connector->getById($GLOBALS["CORE_CONNECTOR_PROFILES"]);
        if (!$currentConnectorData) {
            Logger::debug("processSingleDynamicProfile: No core connector configured while updating profile for $npcName");
            if ($prepareOnly) throw new RuntimeException('Native profile connector unavailable.');
            return false;
        }
        
        // Get dynamic profile fields to update
        $fieldsToUpdate = $characterDynamicProfileFields;
        
        if (empty($fieldsToUpdate)) {
            Logger::debug("processSingleDynamicProfile: No fields selected for dynamic updates for $npcName");
            return false;
        }
        
        synth_profile_capture_input($db,$npcName,$npcData);
        $historyData = getDynamicProfileHistoryData($npcName);
        if ($prepareOnly) return synth_profile_prepare_fields($npcName,$fieldsToUpdate,$historyData);
        $updatedFields = [];
        $successCount = 0;
        
        foreach ($fieldsToUpdate as $field) {
            error_log("[processSingleDynamicProfile] Updating $npcName $field");

            $result = updateDynamicProfileField($npcName, $field, $historyData);

            if ($field=="skills" && $result!==false && !isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) {
                $skillsData=getInGameSkillDataFor($npcName);
                $result.="\n$skillsData";
            }

            if ($result !== false) {
                $updatedFields[$field] = $result;
                $successCount++;
            }
        }
        
        if ($successCount > 0) {
            // Save the updated profile
            $success = saveDynamicProfileUpdates($npcName, $updatedFields, $db);
            if ($success) {
                Logger::info("processSingleDynamicProfile: Successfully updated $successCount fields for $npcName: " . implode(', ', array_keys($updatedFields)));
                return true;
            }
        }
        
    } catch (Exception $e) {
        Logger::error("processSingleDynamicProfile: Error processing $npcName: " . $e->getMessage());
        if ($prepareOnly) throw $e;
        return false;
    }
    
    return false;
}

/**
 * Process dynamic profile updates for The Narrator
 * @param object $db Database connection
 * @return bool Success status
 */
function processNarratorDynamicProfile($db, bool $prepareOnly = false) {
    require_once(__DIR__ . "/core/narrator.class.php");
    require_once(__DIR__ . "/core/core_profiles.class.php");
    require_once(__DIR__ . "/core/llm_connector.class.php");
    
    if (!function_exists('DataSpeechJournal') || !function_exists('buildDynamicProfileDisplay')) {
        require_once(__DIR__ . "/../lib/data_functions.php");
    }
    
    $narrator = new Narrator();
    
    // Check if dynamic profile is enabled for narrator
    if (!$narrator->getBool('dynamic_profile', false)) {
        Logger::debug("processNarratorDynamicProfile: DYNAMIC_PROFILE disabled for The Narrator");
        return false;
    }
    
    // Get dynamic profile fields
    $fieldsToUpdate = $narrator->getDynamicProfileFields();
    
    if (empty($fieldsToUpdate)) {
        Logger::debug("processNarratorDynamicProfile: No fields selected for dynamic updates for The Narrator");
        return false;
    }
    
    try {
        // Check if core connector is configured
        $connector = new LLMConnector();
        $currentConnectorData = $connector->getById($GLOBALS["CORE_CONNECTOR_PROFILES"]);
        if (!$currentConnectorData) {
            Logger::debug("processNarratorDynamicProfile: No core connector configured");
            if ($prepareOnly) throw new RuntimeException('Native profile connector unavailable.');
            return false;
        }
        
        synth_profile_capture_input($db,'The Narrator',array_replace($narrator->getNarratorData(),$narrator->getAll()));
        // Get history data for narrator
        $historyData = getDynamicProfileHistoryData("The Narrator");
        if ($prepareOnly) return synth_profile_prepare_fields('The Narrator',$fieldsToUpdate,$historyData);
        $updatedFields = [];
        $successCount = 0;
        
        foreach ($fieldsToUpdate as $field) {
            error_log("[processNarratorDynamicProfile] Updating The Narrator $field");
            
            $result = updateDynamicProfileField("The Narrator", $field, $historyData);
            
            if ($result !== false) {
                $updatedFields[$field] = $result;
                $successCount++;
            }
        }
        
        if ($successCount > 0) {
            // Save the updated profile to narrator table
            $success = saveNarratorDynamicProfileUpdates($updatedFields);
            if ($success) {
                Logger::info("processNarratorDynamicProfile: Successfully updated $successCount fields for The Narrator: " . implode(', ', array_keys($updatedFields)));
                return true;
            }
        }
        
    } catch (Exception $e) {
        Logger::error("processNarratorDynamicProfile: Error processing The Narrator: " . $e->getMessage());
        if ($prepareOnly) throw $e;
        return false;
    }
    
    return false;
}

/**
 * Save narrator dynamic profile updates to core_narrator table
 * @param array $updatedFields Associative array of field => value
 * @return bool Success status
 */
function saveNarratorDynamicProfileUpdates($updatedFields) {
    require_once(__DIR__ . "/core/narrator.class.php");
    if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) return synth_profile_record_result($GLOBALS['db'],'The Narrator',$updatedFields);
    synth_profile_assert_write_target($GLOBALS['db'],'The Narrator');
    
    try {
        $narrator = new Narrator();
        $success = true;
        
        foreach ($updatedFields as $field => $value) {
            // Map field names to narrator table keys
            $fieldKey = $field; // personality, speechstyle, goals are stored directly
            
            if (!$narrator->set($fieldKey, $value)) {
                $success = false;
                Logger::error("saveNarratorDynamicProfileUpdates: Failed to save field '$field'");
            } else {
                Logger::info("saveNarratorDynamicProfileUpdates: Saved field '$field' for The Narrator");
            }
        }
        
        // Update timestamp
        $stamp=isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])
            ? intval(synth_protocol_current_context($GLOBALS['db'],$GLOBALS['SYNTH_REQUEST_EVENT']['json'])['payload']['world']['game_time_ticks'] ?? 0) : time();
        if (!$narrator->set('gamets_last_updated', (string)$stamp)) $success=false;
        
        return $success;
        
    } catch (Exception $e) {
        Logger::error("saveNarratorDynamicProfileUpdates: Error saving updates: " . $e->getMessage());
        return false;
    }
}

// Function to generate diary entry for a specific follower
function generateFollowerDiary($followerName, $gameRequest, $eventType) {
    global $db;
    if (synth_diary_requires_owned_context()) {
        Logger::warn('Native diary requires an owned generation pipeline; legacy follower/narrator writer refused.');
        return false;
    }
    $promptCharacterName = $followerName;
    
    error_log("generateFollowerDiary called for $followerName");
    
    // Special handling for The Narrator
    if ($followerName === "The Narrator") {
        // Load narrator data
        require_once(__DIR__ . "/core/narrator.class.php");
        $narrator = new Narrator();
        
        // Load settings into GLOBALS first
        $narrator->loadIntoGlobals();
        
        // Get narrator data for profile/connector lookup
        $narratorData = $narrator->getNarratorData();
        
        if (empty($narratorData) || !isset($narratorData["profile_id"])) {
            Logger::warn("generateFollowerDiary: Failed to load narrator data or no profile_id");
            return false;
        }
        
        // Load narrator character data (SYNTH_NAME, SYNTH_PERS, etc.)
        $narrator->loadCharacterIntoGlobals();
        $promptCharacterName = $narrator->getRoleplayName();
        
        // Load the profile to get connector information
        $profile = new CoreProfile();
        $currentProfileData = $profile->getById($narratorData["profile_id"]);
        
        if (empty($currentProfileData)) {
            Logger::warn("generateFollowerDiary: Failed to load narrator profile");
            return false;
        }
        
        // Load the narrator's connector for diary generation
        $connector = new LLMConnector();
        
        // Use diary_connector_id if set, otherwise fall back to regular connector_id
        $connectorId = null;
        $resolvedDiaryConnectorId = class_exists('LLMRandomizer')
            ? LLMRandomizer::getConnectorIdForField($currentProfileData, "diary_connector_id")
            : ($currentProfileData["diary_connector_id"] ?? null);
        if (!empty($resolvedDiaryConnectorId)) {
            $connectorId = $resolvedDiaryConnectorId;
            Logger::info("generateFollowerDiary: Using diary_connector_id: {$connectorId} for The Narrator");
        } elseif (isset($currentProfileData["connector_id"]) && !empty($currentProfileData["connector_id"])) {
            $connectorId = $currentProfileData["connector_id"];
            Logger::info("generateFollowerDiary: Using connector_id: {$connectorId} for The Narrator");
        } else {
            Logger::warn("generateFollowerDiary: No connector configured in narrator profile");
            return false;
        }
        
        $currentConnectorData = $connector->getById($connectorId);
        
        if (empty($currentConnectorData)) {
            Logger::warn("generateFollowerDiary: Failed to load connector data for The Narrator");
            return false;
        }
        
        $connector->setOldGlobals($currentConnectorData);
        $profile->setOldGlobals($currentProfileData);
        $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
        unset($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["stop"]);
        
    } else {
        // Regular NPC processing
        $npcMaster = new NpcMaster();
        $currentNpcData = $npcMaster->getByName($followerName);
        
        if (empty($currentNpcData)) {
            Logger::warn("generateFollowerDiary: NPC '$followerName' not found in database");
            return false;
        }
        
        $profile = new CoreProfile();
        $currentProfileData = $profile->getById($currentNpcData["profile_id"]);
            
        $connector = new LLMConnector();
        $diaryConnectorId = class_exists('LLMRandomizer')
            ? LLMRandomizer::getConnectorIdForField($currentProfileData, "diary_connector_id")
            : ($currentProfileData["diary_connector_id"] ?? null);
        if (empty($diaryConnectorId)) {
            Logger::warn("generateFollowerDiary: No diary connector configured for '$followerName'");
            return false;
        }
        $currentConnectorData = $connector->getById($diaryConnectorId);
        if (empty($currentConnectorData)) {
            Logger::warn("generateFollowerDiary: Diary connector '$diaryConnectorId' could not be loaded for '$followerName'");
            return false;
        }
       
        $connector->setOldGlobals($currentConnectorData);
        $profile->setOldGlobals($currentProfileData);
        $npcMaster->setOldGlobalsFromCurrentNpcData($currentNpcData);

        $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"] = $currentConnectorData;
        unset($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["stop"]);
    }

        
    // Use the same prompt system as regular diary entries
    // Build standard system prompt like main.php does
        
    // Use centralized function from data_functions.php
    $dynamicBiography = buildDynamicBiography($GLOBALS);
        
    $head[] = array('role' => 'system', 'content' =>  
    strtr($GLOBALS["PROMPT_HEAD"] . "\n\n#Character details\n".$GLOBALS["SYNTH_PERS"] . $dynamicBiography . "\n\n#General Instructions\n". $GLOBALS["COMMAND_PROMPT"],
        [
            "#PLAYER_NAME#" => $GLOBALS["PLAYER_NAME"],
            "#SYNTH_NAME#" => $promptCharacterName,
            "#NARRATOR_NAME#" => function_exists('synthGetNarratorRoleplayName') ? synthGetNarratorRoleplayName() : 'The Narrator',
        ])
    );
        
    // Use diary-specific context history if this is a diary request and CONTEXT_HISTORY_DIARY is set
    if (isset($GLOBALS["CONTEXT_HISTORY_DIARY"]) && $GLOBALS["CONTEXT_HISTORY_DIARY"] > 0) {
        $lastNDataForContext = $GLOBALS["CONTEXT_HISTORY_DIARY"]+0;
    } else {
        $lastNDataForContext = (isset($GLOBALS["CONTEXT_HISTORY"])) ? ($GLOBALS["CONTEXT_HISTORY"]+0) : 25;
    }

    $sqlfilter=" and type<>'prechat' and type<>'itemfound' and type<>'infoaction' ";
    $contextDataHistoric = DataLastDataExpandedFor("{$GLOBALS["SYNTH_NAME"]}", $lastNDataForContext * -1,$sqlfilter);
    $contextDataHistoric = filterHistoricContextForNarratorVisibility(
        $contextDataHistoric,
        $GLOBALS["SYNTH_NAME"] ?? ""
    );
    $historyData="";
    foreach ($contextDataHistoric as $element) {
    
        $historyData.=trim("{$element["content"]}").PHP_EOL.PHP_EOL;
        
    }

    if (function_exists('synthRenderNarratorContextText')) {
        $historyData = synthRenderNarratorContextText($historyData);
    }


    // Build user prompt for diary generation (like regular diary)
    
    if (!empty($contextDataHistoric)) {
        $prompt[] = ["role" => "user", "content" => "Recent context: " . $historyData];
    }

    $diaryPrompt=strtr($GLOBALS["DIARY_PROMPT"],['{$GLOBALS["SYNTH_NAME"]}'=>$promptCharacterName,'{$GLOBALS["PLAYER_NAME"]}'=>$GLOBALS["PLAYER_NAME"],"#PLAYER_NAME#"=>$GLOBALS["PLAYER_NAME"],"#SYNTH_NAME#"=>$promptCharacterName,"#NARRATOR_NAME#"=>function_exists('synthGetNarratorRoleplayName') ? synthGetNarratorRoleplayName() : 'The Narrator']);

    $prompt[] = 
        ["role" => "user", "content" => $diaryPrompt
        ]
    ;
    

    $contextData = array_merge($head, $prompt);
    if (function_exists('synthApplyNarratorRoleplayNameToContext')) {
        $contextData = synthApplyNarratorRoleplayNameToContext($contextData);
    }
    
    // Set the request type for diary so connector knows to use diary grammar
    $originalGameRequest = isset($GLOBALS["gameRequest"]) ? $GLOBALS["gameRequest"] : null;
    $GLOBALS["gameRequest"] = [0 => "diary", 1 => time(), 2 => $gameRequest[2], 3 => "Auto diary for " . $followerName];
    
    // Determine max tokens: prefer DB connector setting; fallback to driver config; then memory config; then default
    $maxTokens = null;
    if (isset($currentConnectorData["max_tokens"]) && $currentConnectorData["max_tokens"] !== null && $currentConnectorData["max_tokens"] !== '') {
        $maxTokens = (int)$currentConnectorData["max_tokens"];
    } elseif (isset($GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["max_tokens"])) {
        $maxTokens = (int)$GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["max_tokens"];
    } elseif (isset($GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["MAX_TOKENS_MEMORY"])) {
        $maxTokens = (int)$GLOBALS["CONNECTOR"][$currentConnectorData["driver"]]["MAX_TOKENS_MEMORY"];
    } else {
        $maxTokens = 2048;
    }

    // Temporarily disable any global FORCE_MAX_TOKENS to avoid accidental clamping
    $hadForce = array_key_exists('FORCE_MAX_TOKENS', $GLOBALS);
    $prevForce = $hadForce ? $GLOBALS['FORCE_MAX_TOKENS'] : null;
    if ($hadForce) { unset($GLOBALS['FORCE_MAX_TOKENS']); }

    $overrideParameters["MAX_TOKENS"] = $maxTokens;
    $connectionHandler = $connector->getConnector($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]);
    $buffer=$connectionHandler->fast_request($contextData,$overrideParameters,"diary");

    // Restore previous FORCE_MAX_TOKENS if it existed
    if ($hadForce) { $GLOBALS['FORCE_MAX_TOKENS'] = $prevForce; }

    
    // Restore original gameRequest after diary generation
    if ($originalGameRequest !== null) {
        $GLOBALS["gameRequest"] = $originalGameRequest;
    } else {
        unset($GLOBALS["gameRequest"]);
    }
        
    if (!empty(trim($buffer))) {
        // Save diary entry to database
        $topic = DataLastKnowDate();
        $location = DataLastKnownLocation();
        $momentum=time();
        $db->insert(
            'diarylog',
            array(
                'ts' => $gameRequest[1],
                'gamets' => $gameRequest[2],
                'topic' => $topic . " (Auto-diary: $eventType)",
                'content' => trim($buffer),
                'tags' => "Auto-diary,$eventType",
                'people' => $followerName,
                'location' => $location,
                'sess' => $momentum,
                'localts' => time()
            )
        );
            
        // Log memory
        if (function_exists('logMemory')) {
            logMemory($followerName, $followerName, trim($buffer),  $momentum, $gameRequest[2], 'auto_diary', $gameRequest[1]);
        }
        
        synth_buffer_command_response_line($followerName, "DebugNotification", [
            "message" => "Diary Entry Written for " . $followerName,
        ]);
        
        return true;
    }
    
    
    return false;
}

function getDynamicProfileHistoryData($npcName) {
    if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) {
        $db=$GLOBALS['db'];synth_profile_assert_write_target($db,$npcName);
        $identityId=$GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'][$npcName]['actor_identity_id'] ?? null;
        $limit=max(1,min(200,intval($GLOBALS['CONTEXT_HISTORY_DYNAMIC_PROFILE'] ?? $GLOBALS['CONTEXT_HISTORY'] ?? 50)));
        $rows=$db->fetchAll(synth_protocol_history_cte($db,$GLOBALS['SYNTH_REQUEST_EVENT']['json'],'',$identityId,$npcName==='The Narrator')
            ." SELECT history_event_id,type,left(data,4000) AS data FROM native_history
                ORDER BY history_event_id DESC LIMIT $limit");
        $history=json_encode(['schema'=>'synth.profile.history.v1','scope'=>$identityId===null ? 'canonical_player' : 'canonical_actor',
            'events'=>array_reverse($rows ?: [])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$npcName])) $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$npcName]['history']=$history;
        return $history;
    }
    $historyData = "";
    $lastPlace = "";
    $lastListener = "";
    $lastDateTime = "";
    $isNarratorTarget = ($npcName === "The Narrator");
    
    // Determine how much context history to use for dynamic profiles
    $dynamicProfileContextHistory = 50; // Default value
    if (isset($GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"]) && $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"] > 0) {
        $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY_DYNAMIC_PROFILE"];
    } elseif (isset($GLOBALS["CONTEXT_HISTORY"]) && $GLOBALS["CONTEXT_HISTORY"] > 0) {
        $dynamicProfileContextHistory = $GLOBALS["CONTEXT_HISTORY"];
    }
    
    foreach (json_decode(DataSpeechJournal($npcName, $dynamicProfileContextHistory), true) as $element) {
        $listenerName = trim((string)($element["listener"] ?? ""));
        $speakerName = trim((string)($element["speaker"] ?? ""));
        if (!$isNarratorTarget && ($listenerName == "The Narrator" || $speakerName == "The Narrator")) {
            continue;
        }
        if ($lastListener != $listenerName) {
            $listener = " (talking to {$listenerName})";
            $lastListener = $listenerName;
        } else {
            $listener = "";
        }
        
        if ($lastPlace != $element["location"]) {
            $place = " (at {$element["location"]})";
            $lastPlace = $element["location"];
        } else {
            $place = "";
        }

        if ($lastDateTime != substr($element["fallout_date"], 0, 15)) {
            $date = substr($element["fallout_date"], 0, 10);
            $time = substr($element["fallout_date"], 11);
            $dateTime = "(on date {$date} at {$time})";
            $lastDateTime = substr($element["fallout_date"], 0, 15);
        } else {
            $dateTime = "";
        }
        
        $historyData .= trim("{$speakerName}:".trim((string)($element["speech"] ?? ""))." $listener $place $dateTime").PHP_EOL;
    }
    
    return $historyData;
}


function updateDynamicProfileField($npcName, $field, $historyData, bool $prepareOnly = false) {
    synth_profile_assert_write_target($GLOBALS['db'],$npcName);
    // Map field names to their corresponding SYNTH prompts
    $fieldMapping = [
        'personality' => 'DYNAMIC_PROMPT_PERSONALITY',
        'occupation' => 'DYNAMIC_PROMPT_OCCUPATION',
        'skills' => 'DYNAMIC_PROMPT_SKILLS',
        'speechstyle' =>'DYNAMIC_PROMPT_SPEECHSTYLE',
        'goals' => 'DYNAMIC_PROMPT_GOALS'
    ];
    
    if (!isset($fieldMapping[$field])) {
        Logger::warn("updateDynamicProfileField: Unknown field '$field' for $npcName");
        return false;
    }

    $isNarrator = ($npcName === "The Narrator");
    $promptNpcName = $npcName;

    if ($isNarrator) {
        require_once(__DIR__ . "/core/narrator.class.php");
        $narrator = new Narrator();
        $npcData = $narrator->getNarratorData();
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = $narrator->getRoleplayName();
        $promptNpcName = $narrator->getRoleplayName();
    } else {
        require_once(__DIR__ . "/core/npc_master.class.php");
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($npcName);
    }

    if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) {
        $input=$GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$npcName] ?? null;
        if (!is_array($input) || $input['history']!==$historyData) throw new LogicException('Native profile prompt has no frozen input.');
        $npcData=$input['profile'];
    }
    if (!$npcData) {
        Logger::debug("updateDynamicProfileField: No profile found for $npcName");
        return false;
    }
    
    $promptName = $fieldMapping[$field];

    // Get current field value
    $currentValue = $npcData[$field] ?? '';
    
    // Map to database prompt keys (lowercase with underscores)
    $dbPromptKeyMapping = [
        'DYNAMIC_PROMPT_PERSONALITY' => 'dynamic_prompt_personality',
        'DYNAMIC_PROMPT_OCCUPATION' => 'dynamic_prompt_occupation',
        'DYNAMIC_PROMPT_SKILLS' => 'dynamic_prompt_skills',
        'DYNAMIC_PROMPT_SPEECHSTYLE' => 'dynamic_prompt_speechstyle',
        'DYNAMIC_PROMPT_GOALS' => 'dynamic_prompt_goals'
    ];
    
    // Load prompt from database with fallback to $GLOBALS
    $updatePrompt = null;
    if (isset($dbPromptKeyMapping[$promptName])) {
        try {
            $promptData = $GLOBALS["db"]->fetchOne("SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = '{$dbPromptKeyMapping[$promptName]}'");
            if ($promptData) {
                $updatePrompt = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
            }
        } catch (Exception $e) {
            Logger::warn("Failed to load {$promptName} from database, using fallback: " . $e->getMessage());
        }
    }
    
    // Fallback to $GLOBALS if database load failed
    if (empty($updatePrompt)) {
        $updatePrompt = $GLOBALS[$promptName] ?? '';
    }
    
    if (empty($updatePrompt)) {
        Logger::warn("updateDynamicProfileField: No prompt configured for field '$field' ($promptName)");
        return false;
    }
    
    // Replace placeholders in the prompt
    $updatePrompt = strtr($updatePrompt, [
        '{SYNTH_NAME}' => $promptNpcName,
        '{NARRATOR_NAME}' => function_exists('synthGetNarratorRoleplayName') ? synthGetNarratorRoleplayName() : 'The Narrator',
    ]);
    
    try {
        // Collect other profile fields for context (excluding the current field)
        $profileContext = [];
        if ($isNarrator) {
            $profileFields = [
                'core' => 'Core Identity',
                'npc_static_bio' => 'Basic Summary',
                'prompt_head' => 'Prompt Head / Style Rules',
                'personality' => 'Personality Traits',
                'speechstyle' => 'Speech Style',
                'goals' => 'Goals & Aspirations'
            ];
        } else {
            $profileFields = [
                'core' => 'Core Identity',
                'npc_static_bio' => 'Basic Summary',
                'personality' => 'Personality Traits',
                'appearance' => 'Physical Appearance',
                'occupation' => 'Occupation & Role',
                'skills' => 'Skills & Abilities',
                'speechstyle' => 'Speech Style',
                'goals' => 'Goals & Aspirations'
            ];
        }

        // Remove the current field from context
        unset($profileFields[$field]);

        foreach ($profileFields as $fieldName => $fieldLabel) {
            if (!empty(trim($npcData[$fieldName]))) {
                $profileValue = trim($npcData[$fieldName]);
                if ($isNarrator && function_exists('synthRenderNarratorRoleplayText')) {
                    $profileValue = synthRenderNarratorRoleplayText($profileValue);
                }
                $profileContext[] = "**{$fieldLabel}**: " . $profileValue;
            }
        }

        $profileContextString = !empty($profileContext) ? "\n\n* Current Character Profile:\n" . implode("\n\n", $profileContext) : '';

        // Build prompt for this specific field
        $head = [
            ["role" => "system", "content" => "You are an assistant. Analyze the dialogue history and character profile to update ONLY the " . ucfirst($field) . " for the character named '$promptNpcName'. Focus mostly on information about $promptNpcName and mostly ignore details about other characters mentioned in the dialogue."]
        ];

        $GLOBALS["SYNTH_NAME"] = $npcName; //note none of these prompts will contain #SYNTH_NAME, as the dialogue flow doesnt do this replacement (which may be a bug)
        $prompt = [
            ["role" => "user", "content" => "* Dialogue history:\n" . $historyData . ReplacePlayerNamePlaceholder($profileContextString)],
            ["role" => "user", "content" => "Character name: " . $promptNpcName . "\nCurrent " . ucfirst($field) . ":\n" . ReplacePlayerNamePlaceholder($isNarrator && function_exists('synthRenderNarratorRoleplayText') ? synthRenderNarratorRoleplayText($currentValue) : $currentValue)],
            ["role" => "user", "content" => ReplacePlayerNamePlaceholder($updatePrompt)]
        ];
        
        $contextData = array_merge($head, $prompt);
        if (function_exists('synthApplyNarratorRoleplayNameToContext')) {
            $contextData = synthApplyNarratorRoleplayNameToContext($contextData);
        }
        
        $connector=new LLMConnector();
        $currentConnectorData = $connector->getById($GLOBALS["CORE_CONNECTOR_PROFILES"]);
        if (!$currentConnectorData) throw new RuntimeException('Profile connector unavailable.');
        $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]=$currentConnectorData;
        if (!$prepareOnly) $connector->setOldGlobals($currentConnectorData);

        // Get max tokens from core connector configuration (no hardcoded cap)
        $maxTokens = !empty($currentConnectorData["max_tokens"]) ? (int)$currentConnectorData["max_tokens"] : 4000;
        
        if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) {
            if (intval($GLOBALS['CORE_CONNECTOR_PROFILES'])!==$input['connector_id']) throw new LogicException('Native profile connector changed.');
            $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$npcName]['prompts'][$field]=json_encode($contextData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if ($prepareOnly) $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$npcName]['options'][$field]=['max_tokens'=>$maxTokens];
            if (strlen(json_encode($GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS'][$npcName],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>1048576) {
                throw new LengthException('Native profile input exceeds its bound before provider dispatch.');
            }
        }
        if ($prepareOnly) return true;
        $connectionHandler = $connector->getConnector($currentConnectorData);
        $buffer=$connectionHandler->fast_request($contextData, ["max_tokens" => $maxTokens],"profile");
        synth_profile_assert_write_target($GLOBALS['db'],$npcName);
        
        //$connectionHandler->close();
        
        // Clean up the response
        $buffer = trim($buffer);
        
        if (!empty($buffer)) {
            Logger::debug("updateDynamicProfileField: Updated $field for $npcName");
            return $buffer;
        } else {
            Logger::info("updateDynamicProfileField: Empty response for field '$field' for $npcName");
            return false;
        }
        
    } catch (Exception $e) {
        Logger::error("updateDynamicProfileField: Error updating field '$field' for $npcName: " . $e->getMessage());
        if ($prepareOnly) throw $e;
        return false;
    }
}

function saveDynamicProfileUpdates($npcName, $updatedFields, $db, $updateTimeStamp = true, $generationSource = null) {
    if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) return synth_profile_record_result($db,$npcName,$updatedFields);
    synth_profile_assert_write_target($db,$npcName);
    try {


        //

        $npcMaster=new NpcMaster();
        $currentNpcData=$npcMaster->getByName($npcName);
    
        if ($currentNpcData) {
            foreach ($updatedFields as $field => $newValue) {
                $currentNpcData[$field]=$newValue;
            }

            if ($generationSource === 'manual' || $generationSource === 'auto') {
                $extended = $npcMaster->getExtendedData($currentNpcData);
                $extended['auto_profile_pending'] = false;
                $extended['auto_profile_generated'] = true;
                $extended['auto_profile_generated_by'] = $generationSource;
                $extended['auto_profile_generated_at_gamets'] = DataLastKnownGameTS();
                unset($extended['auto_profile_last_error'], $extended['auto_profile_last_attempt_ts']);
                $currentNpcData = $npcMaster->setExtendedData($currentNpcData, $extended);
            }
            
            // Backup NPC.
            $npcMaster->backupNpcById($currentNpcData["id"]);

            if ($updateTimeStamp) {
                $currentNpcData["gamets_last_updated"]=isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])
                    ? intval(synth_protocol_current_context($db,$GLOBALS['SYNTH_REQUEST_EVENT']['json'])['payload']['world']['game_time_ticks'] ?? 0)
                    : DataLastKnownGameTS();
            }
            
            if ($npcMaster->updateByArray($currentNpcData)===false) return false;
            
        }
        Logger::info("saveDynamicProfileUpdates: Successfully saved updates for $npcName");
        return true;
        
    } catch (Exception $e) {
        Logger::error("saveDynamicProfileUpdates: Error saving updates for $npcName: " . $e->getMessage());
        return false;
    }
}

function queueDynamicProfileBatch(array $npcNames, array $gameRequest): string {
    global $db;

    $npcNames = array_values(array_unique(array_filter(
        array_map(static fn($name) => trim((string)$name), $npcNames),
        static fn($name) => $name !== ''
    )));
    if (empty($npcNames)) {
        throw new InvalidArgumentException('Cannot queue an empty dynamic profile batch');
    }

    $queueData = [
        'timestamp' => time(),
        'npcs' => $npcNames,
        'gameRequest' => $gameRequest,
    ];
    $requestEvent=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
    if (($requestEvent['protocol_version'] ?? 1)===2) {
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || ($requestEvent['type'] ?? '')!=='profile_refresh') {
            throw new LogicException('Native profile queue requires its claimed profile refresh.');
        }
        synth_protocol_assert_request_active();
        $bindings=synth_profile_refresh_bindings($db,$requestEvent,true);
        foreach ($npcNames as $name) if (!isset($bindings[$name])) throw new RuntimeException('Native profile batch contains an unbound name.');
        $queueData['native_owner']=$requestEvent;
        $queueData['native_bindings']=array_intersect_key($bindings,array_flip($npcNames));
    } elseif (($requestEvent['protocol_version'] ?? null)===1) {
        $queueData['legacy_protocol_version']=1;
    }
    $encoded = json_encode($queueData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $queueId = 'dynamic_profiles_queue_' . time() . '_' . uniqid();

    if ($db->upsertRowOnConflict('conf_opts', [
        'id' => $queueId,
        'value' => $encoded,
    ], 'id') === false) {
        throw new RuntimeException('Could not persist the dynamic profile batch');
    }

    return $queueId;
}

function synthPostgresBoolean($value): bool {
    return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
}

function triggerImmediateProfileProcessing(?callable $profileProcessor = null, ?callable $nativePrepare = null, ?callable $nativeGenerate = null): array {
    global $db;

    $result = [
        'locked' => false,
        'jobs' => 0,
        'npcs' => 0,
        'updated' => 0,
    ];

    $lockRows = $db->fetchAll("SELECT pg_try_advisory_lock(hashtext('synth_dynamic_profile_worker')) AS acquired");
    if (empty($lockRows) || !synthPostgresBoolean($lockRows[0]['acquired'] ?? false)) {
        Logger::debug("triggerImmediateProfileProcessing: Another worker owns the queue lock");
        return $result;
    }

    $result['locked'] = true;
    $profileProcessor = $profileProcessor ?? 'processSingleDynamicProfile';

    try {
        $queueResults = $db->fetchAll("SELECT id, value FROM conf_opts WHERE id LIKE 'dynamic_profiles_queue_%' ORDER BY id LIMIT 5");
        if (empty($queueResults)) {
            Logger::debug("triggerImmediateProfileProcessing: No queue entries found");
            $result['updated']=synth_profile_dispatch_jobs($db,$nativePrepare,$nativeGenerate);
            return $result;
        }

        Logger::info("triggerImmediateProfileProcessing: Processing " . count($queueResults) . " queue entries");

        foreach ($queueResults as $queueRow) {
            $queueId = $queueRow['id'];
            $queueJson = $queueRow['value'];

            $queueData = json_decode($queueJson, true);
            if (is_array($queueData) && isset($queueData['native_owner'])) {
                try {
                    synth_profile_enqueue_batch($db,$queueId,$queueData);
                    $result['jobs']++;
                    $result['npcs']+=count($queueData['npcs']);
                } catch (Throwable $error) {
                    if (synth_profile_origin_rejected($error)) {
                        $owner=$queueData['native_owner'];
                        $state=$db->fetchOne('SELECT state FROM synth_meta.request_executions WHERE session_id='
                            .$db->escapeLiteral(strval($owner['session_id'] ?? '')).' AND request_id='.$db->escapeLiteral(strval($owner['request_id'] ?? '')));
                        // A just-admitted refresh can be queued before its response completes; keep waiting for it.
                        if ($state && !in_array($state['state'],['claimed','streaming'],true)) {
                            $db->execQuery('UPDATE synth.conf_opts SET id='.$db->escapeLiteral('dynamic_profiles_failed_'.hash('sha256',$queueId))
                                .' WHERE id='.$db->escapeLiteral($queueId));
                        }
                    }
                    Logger::warn('Native profile queue transfer failed; batch retained.');
                }
                continue;
            }
            if (!$queueData || !isset($queueData['npcs']) || !isset($queueData['gameRequest'])) {
                Logger::error("triggerImmediateProfileProcessing: Invalid queue data for $queueId");
                $db->delete("conf_opts", "id = '" . $db->escape($queueId) . "'");
                continue;
            }

            $npcs = is_array($queueData['npcs']) ? $queueData['npcs'] : [];
            $gameRequest = $queueData['gameRequest'];
            Logger::info("triggerImmediateProfileProcessing: Processing " . count($npcs) . " NPCs");

            $successCount = 0;
            $processBatch=static function() use($npcs,$gameRequest,$profileProcessor,&$successCount) {
                foreach ($npcs as $npcName) {
                    try {
                        synth_profile_assert_write_target($GLOBALS['db'],$npcName);
                        if ($profileProcessor($npcName, $gameRequest)) {
                            $successCount++;
                            Logger::debug("triggerImmediateProfileProcessing: Updated profile for $npcName");
                        }
                    } catch (Throwable $e) {
                        Logger::error("triggerImmediateProfileProcessing: Error processing $npcName: " . $e->getMessage());
                    }
                }
            };
            try {
                if (isset($queueData['native_owner'])) synth_profile_with_origin($db,$queueData,$processBatch);
                else {
                    // Old native queue payloads lost the snapshot; never reinterpret them as legacy work.
                    $legacyPayload=json_decode(strval($gameRequest[3] ?? ''),true);
                    if (is_array($legacyPayload) && (isset($legacyPayload['native_owner'])
                        || (isset($legacyPayload['session_id']) && ($queueData['legacy_protocol_version'] ?? null)!==1))) {
                        throw new RuntimeException('Unowned legacy-format native profile queue requires a fresh refresh.');
                    }
                    $processBatch();
                }
            } catch (Throwable $error) {
                Logger::warn('Native profile batch rejected: '.$error->getMessage());
            }

            // Keep a job durable until every queued NPC has been attempted.
            $db->delete("conf_opts", "id = '" . $db->escape($queueId) . "'");
            Logger::info("triggerImmediateProfileProcessing: Completed job - updated $successCount of " . count($npcs) . " profiles");
            $result['jobs']++;
            $result['npcs'] += count($npcs);
            $result['updated'] += $successCount;
        }

        $result['updated']+=synth_profile_dispatch_jobs($db,$nativePrepare,$nativeGenerate);
        Logger::info("triggerImmediateProfileProcessing: Total processed: {$result['jobs']} jobs, {$result['npcs']} NPCs");
    } catch (Throwable $e) {
        Logger::error("triggerImmediateProfileProcessing: Fatal error: " . $e->getMessage());
    } finally {
        $db->fetchAll("SELECT pg_advisory_unlock(hashtext('synth_dynamic_profile_worker')) AS released");
    }

    return $result;
}
?>

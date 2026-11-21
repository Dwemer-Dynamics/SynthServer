<?php

// Moved from lib/data_functions.php: dialogue history consolidation, compaction and journals
// The original file requires this one, so every caller is unchanged.

function removeTalkingToOccurrences($input) {
    $pattern = '/\((?:talking|whispering|shouting|speaking privately)\s+to\s+[^()]+\)/i';
    preg_match_all($pattern, $input, $matches, PREG_OFFSET_CAPTURE);

    // Get all positions of the matches
    $positions = $matches[0];

    // If there are no matches or only one match, return the input string as it is
    if (count($positions) <= 1) {
        return $input;
    }

    // Remove all but the last occurrence
    for ($i = 0; $i < count($positions) - 1; $i++) {
        $pos = $positions[$i][1];
        $input = substr_replace($input, '', $pos, strlen($positions[$i][0]));

        // After each removal, adjust the positions of subsequent matches
        for ($j = $i + 1; $j < count($positions); $j++) {
            $positions[$j][1] -= strlen($positions[$i][0]);
        }
    }

    return $input;
}

function moveDialogueTargetSuffixToEnd($input) {
    $input = trim((string)$input);
    if ($input === "") {
        return "";
    }

    $pattern = '/\s*(\((?:talking|whispering|shouting|speaking privately)\s+to [^()]+?\)|\(speaking loudly to [^()]+?\))\s*/i';
    if (preg_match_all($pattern, $input, $matches) !== 1 || empty($matches[1])) {
        return trim(preg_replace('/\s+/', ' ', $input));
    }

    $targetSuffix = trim((string)end($matches[1]));
    $withoutSuffix = preg_replace($pattern, ' ', $input);
    $withoutSuffix = trim(preg_replace('/\s+/', ' ', (string)$withoutSuffix));
    if ($withoutSuffix === "") {
        return $targetSuffix;
    }

    return "{$withoutSuffix} {$targetSuffix}";
}

function DataLastDataExpandedForNPC($actor, $lastNelements = -10,$sqlfilter="") {

        $db = \Synth\Bootstrap::db();

        $results = \Synth\Core\DbManager\Dao\SpeechDao::current($db)->expandedHistoryForActor((string)$actor);
         $rawData=[];
        foreach ($results as $row) {
            $rawData[] = $row;
        }


        $orderedData = array_reverse($rawData);

        $lastDialogFull=[];

        $lastlocation="";
        $lastSpeaker=null;
        $lastListener=null;
        $buffer="";
        foreach ($orderedData as $speechEvent)  {

            if (($speechEvent["gamets_diff"] * 0.0000024) > 1.0) { // more than one hour
                $lastDialogFull[$speechEvent["ts"]] = array('role' => "user", 'content' => "The Narrator: about ".number_format(floor($speechEvent["gamets_diff"]*0.0000024),0)." hours later...");
            }


            if ($lastlocation!=$speechEvent["location"]) {
                $lastlocation=$speechEvent["location"];
                $lastDialogFull[$speechEvent["ts"]] = array('role' => "user", 'content' => "The Narrator: action moved to new location: $lastlocation");
            }

            $currentSpeaker="user";


            if ($lastSpeaker==$actor)
                $currentSpeaker="assistant";
            else if ($speechEvent["speaker"]=="The Narrator")
                continue;

            if (($lastSpeaker!=$speechEvent["speaker"])&&($lastSpeaker!=null)) {
                $talkingto="";
                if ($lastListener!="The Narrator")
                    $talkingto="(talking to {$lastListener})";

                if ($lastSpeaker==\Synth\Bootstrap::config("PLAYER_NAME"))
                    $talkingto="";

                $lastDialogFull[$speechEvent["ts"]] = array('role' => $currentSpeaker, 'content' => "$lastSpeaker: $buffer $talkingto");
                $buffer="";
                $lastSpeaker=$speechEvent["speaker"];
            } else {
                $lastSpeaker=$speechEvent["speaker"];
            }
            $buffer.=$speechEvent["speech"];
            $lastListener=$speechEvent["listener"];

        }


        $results = \Synth\Core\DbManager\Dao\ContextEventDao::current($db)->recentItemAndActionEvents();
        $rawData=[];
        foreach ($results as $row) {
            $eventData = $row["data"];
            if (($row["type"] ?? "") === "itemtransfer") {
                $decodedEvent = json_decode($eventData, true);
                if (is_array($decodedEvent) && !empty($decodedEvent["text"])) {
                    $eventData = $decodedEvent["text"];
                }
            }
            $lastDialogFull[$row["ts"]]= array('role' => 'user', 'content' => "The Narrator: {$eventData}");
        }

        $results = \Synth\Core\DbManager\Dao\ContextEventDao::current($db)->latestPartyRows('world_context', 10);
        $rawData=[];
        foreach ($results as $row) {
            $payload = json_decode($row["party"] ?? "", true);
            if (!is_array($payload)) {
                continue;
            }
            $location = synthWorldContextLocationFromPayload($payload);
            $date = synthWorldContextDateText($payload);
            $time = synthWorldContextTimeText($payload);
            $weatherValue = $payload["weather"] ?? "";
            $weather = is_array($weatherValue)
                ? trim((string)($weatherValue["name"] ?? $weatherValue["summary"] ?? ""))
                : trim((string)$weatherValue);
            $parts = array_filter([
                $location !== "" ? "location: {$location}" : "",
                $weather !== "" ? "weather: {$weather}" : "",
                $date !== "" ? "date: {$date}" : "",
                $time !== "" ? "time: {$time}" : "",
            ]);
            if (empty($parts)) {
                continue;
            }
            $lastDialogFull[$row["ts"]]= array('role' => 'user', 'content' => "The Narrator: World context: " . implode(", ", $parts));
        }

        ksort($lastDialogFull);

        $results = \Synth\Core\DbManager\Dao\ContextEventDao::current($db)->inputEventsForActor((string)$actor);
        $rawData=[];
        foreach ($results as $row) {
            $rawData[] = $row;
        }
        $rawData = array_reverse($rawData);
        foreach ($rawData as $row) {
            $lastDialogFull[] = array('role' => 'user', 'content' => "{$row["data"]}");
        }



        $orderedData = array_slice($lastDialogFull, $lastNelements);

        Logger::info("Using NPC data retriever");


        return $orderedData;
}

function removeEmptyElements(array $array): array {
    return array_filter($array, function($value) {
        return !empty($value) || $value === 0 || $value === "0";
    });
}

/**
 * Consolidate repeated similar events
 *
 * @param array $events Array of event entries with role, content, subtype, type, gamets
 * @return array Consolidated array of events
 */
function consolidateEvents(array $events): array {
    // Hardcoded defaults - always enabled for efficiency
    $timeWindow = 300; // 5 minutes game time
    $typesToConsolidate = ["death", "itemfound", "infoaction"];

    $consolidated = [];
    $consolidationBuffer = [];

    foreach ($events as $event) {
        if (!isset($event['type']) || !in_array($event['type'], $typesToConsolidate)) {
            // Flush buffer if we hit a non-consolidatable event
            if (!empty($consolidationBuffer)) {
                $consolidated = array_merge($consolidated, flushConsolidationBuffer($consolidationBuffer));
                $consolidationBuffer = [];
            }
            $consolidated[] = $event;
            continue;
        }

        // Extract pattern from event content
        $pattern = extractEventPattern($event);
        if ($pattern === null) {
            // Can't extract pattern, add as-is
            if (!empty($consolidationBuffer)) {
                $consolidated = array_merge($consolidated, flushConsolidationBuffer($consolidationBuffer));
                $consolidationBuffer = [];
            }
            $consolidated[] = $event;
            continue;
        }

        // Check if this event can be merged with buffer
        $merged = false;
        $actorName = extractActorName($event['content']);

        foreach ($consolidationBuffer as $key => &$buffered) {
            if ($buffered['pattern'] === $pattern) {
                // Check time window
                $timeDiff = abs(($event['gamets'] ?? 0) - ($buffered['first_gamets'] ?? 0));
                if ($timeDiff <= $timeWindow) {
                    // Check if this is a different actor doing the same action (e.g., combat engagement)
                    $isMultiActorPattern = (strpos($pattern, 'combat:') === 0 || strpos($pattern, 'activate:') === 0);

                    if ($isMultiActorPattern && $actorName) {
                        // Multi-actor pattern: collect actor names
                        if (!isset($buffered['actors'])) {
                            $buffered['actors'] = [extractActorName($buffered['event']['content'])];
                        }
                        if (!in_array($actorName, $buffered['actors'])) {
                            $buffered['actors'][] = $actorName;
                        }
                    } elseif (strpos($pattern, 'itemfound:') === 0) {
                        // Item collection pattern: collect items
                        if (!isset($buffered['items'])) {
                            $buffered['items'] = [extractItemInfo($buffered['event']['content'])];
                        }
                        $buffered['items'][] = extractItemInfo($event['content']);
                    } else {
                        // Same actor repeating: increment count
                        $buffered['count']++;
                    }

                    $buffered['last_gamets'] = $event['gamets'] ?? 0;
                    $merged = true;
                    break;
                }
            }
        }
        unset($buffered);

        if (!$merged) {
            // Flush older patterns and start new buffer entry
            $consolidationBuffer[] = [
                'event' => $event,
                'pattern' => $pattern,
                'count' => 1,
                'first_gamets' => $event['gamets'] ?? 0,
                'last_gamets' => $event['gamets'] ?? 0,
                'actors' => $actorName ? [$actorName] : null,
                'items' => (strpos($pattern, 'itemfound:') === 0) ? [extractItemInfo($event['content'])] : null
            ];
        }
    }

    // Flush remaining buffer
    if (!empty($consolidationBuffer)) {
        $consolidated = array_merge($consolidated, flushConsolidationBuffer($consolidationBuffer));
    }

    return $consolidated;
}

/**
 * Extract actor name from event content
 *
 * @param string $content Event content
 * @return string|null Actor name or null if not extractable
 */
function extractActorName(string $content): ?string {
    // Extract actor from patterns like "ActorName does something"
    if (preg_match('/^([^:]+?)(?:\s+(?:engages combat with|activates|uses|casts|has defeated|found|took|looted|gave)\s+.+)$/i', $content, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

/**
 * Extract item information from item pickup event
 *
 * @param string $content Event content
 * @return string|null Item description with quantity
 */
function extractItemInfo(string $content): ?string {
    // Extract "N ItemName from/in X" or just "N ItemName"
    if (preg_match('/(?:found|took|looted|traded|gave)\s+(.+?)(?:,\(value.+\))?$/i', $content, $matches)) {
        $itemInfo = trim($matches[1]);

        // Extract just the item name (remove quantity) for blacklist check
        // Pattern: "2 9mm Pistol" or "9mm Pistol" or "a 9mm Pistol"
        if (preg_match('/^(?:\d+\s+|an?\s+)?(.+?)$/i', $itemInfo, $nameMatches)) {
            $itemName = trim($nameMatches[1]);

            // Check if item is blacklisted
            if (isItemBlacklisted($itemName)) {
                return null; // Filter out blacklisted items
            }
        }

        return $itemInfo;
    }
    return null;
}

/**
 * Extract consolidation pattern from event
 *
 * @param array $event Event data
 * @return string|null Pattern identifier or null if not extractable
 */
function extractEventPattern(array $event): ?string {
    $content = $event['content'] ?? '';
    $type = $event['type'] ?? '';

    if ($type === 'death') {
        // Pattern: "X DIED" (just death announcement)
        if (preg_match('/^(.+?)\s+died\s*$/i', $content, $matches)) {
            $victim = trim($matches[1]);
            return "death_announce:{$victim}";
        }
        // Pattern: "X has defeated Y" or "X killed Y" etc
        // Extract: actor + victim
        if (preg_match('/^(.+?)\s+(?:has defeated|defeated|killed|slain)\s+(.+?)(?:\s+with\s+.+)?(?:\s+in an awesome move)?$/i', $content, $matches)) {
            $actor = trim($matches[1]);
            $victim = trim($matches[2]);
            return "death:{$actor}->{$victim}";
        }
    } elseif ($type === 'itemfound') {
        // Pattern: "X found/took/looted N Y" or "X found/took/looted Y"
        // Group by actor only for multi-item consolidation
        if (preg_match('/^(.+?)\s+(found|took|looted|traded|gave)\s+(.+)$/i', $content, $matches)) {
            $actor = trim($matches[1]);
            $action = trim($matches[2]);
            return "itemfound:{$actor}->{$action}"; // Only actor+action, group all items together
        }
    } elseif ($type === 'infoaction') {
        // Pattern: "X engages combat with Y" - group by enemy only (multi-actor consolidation)
        if (preg_match('/^(.+?)\s+engages combat with\s+(.+?)$/i', $content, $matches)) {
            $enemy = trim($matches[2]);
            return "combat:{$enemy}"; // Only enemy in pattern, so multiple actors get grouped
        }
        // Pattern: "X activates Y" - group by object only (multi-actor consolidation)
        if (preg_match('/^(.+?)\s+activates\s+(.+?)$/i', $content, $matches)) {
            $object = trim($matches[2]);
            return "activate:{$object}"; // Only object in pattern
        }
    }

    return null;
}

/**
 * Flush consolidation buffer and format consolidated entries
 *
 * @param array $buffer Consolidation buffer
 * @return array Formatted events
 */
function flushConsolidationBuffer(array $buffer): array {
    $result = [];

    foreach ($buffer as $buffered) {
        $event = $buffered['event'];

        // Check if this is an item event (single or multi) and filter blacklisted items
        if (isset($buffered['items'])) {
            // Filter out null entries (blacklisted items)
            $filteredItems = array_filter($buffered['items']);

            // If all items were filtered out, skip this event entirely
            if (empty($filteredItems)) {
                continue;
            }

            // Check if this is a multi-item consolidation
            if (count($filteredItems) > 1) {
                // Multiple items picked up by same actor - list them
                $content = $event['content'];

                if (preg_match('/^(.+?)\s+(found|took|looted|traded|gave)\s+/i', $content, $matches)) {
                    $actor = trim($matches[1]);
                    $action = trim($matches[2]);

                    // Build item list from filtered items
                    $itemList = implode(', ', $filteredItems);
                    $event['content'] = "{$actor} {$action} {$itemList}";
                }
            }
            // Single item events will keep their original content (already filtered by extractItemInfo)
        } elseif (isset($buffered['actors']) && count($buffered['actors']) > 1) {
            // Multiple actors doing the same action - list them
            $actorList = implode(', ', $buffered['actors']);
            $content = $event['content'];

            // Replace single actor name with list and adjust verb to plural
            if (preg_match('/^(.+?)\s+(engages combat with|activates|uses|casts)\s+(.+?)$/i', $content, $matches)) {
                $action = trim($matches[2]);
                $target = trim($matches[3]);

                // Convert verb to plural form
                if (stripos($action, 'engages') !== false) {
                    $action = 'engage combat with';
                } elseif (stripos($action, 'activates') !== false) {
                    $action = 'activate';
                } elseif (stripos($action, 'uses') !== false) {
                    $action = 'use';
                } elseif (stripos($action, 'casts') !== false) {
                    $action = 'cast';
                }

                $event['content'] = "{$actorList} {$action} {$target}";
            }
        } elseif ($buffered['count'] > 1) {
            // Same event repeating - add count prefix for clarity (e.g., "2x SKEEVER DIED")
            $event['content'] = "{$buffered['count']}x " . trim($event['content']);
        }

        $result[] = $event;
    }

    return $result;
}

/**
 * Convert time difference in hours to a human-readable time category
 *
 * @param float $hoursAgo Number of in-game hours since the event
 * @return string Human-readable time category
 */
function getTimeCategory($hoursAgo) {
    if ($hoursAgo < 0.02) return "Happened Recently";
    if ($hoursAgo < 0.1) return "Moments Ago";
    if ($hoursAgo < 0.25) return "A few minutes ago";
    if ($hoursAgo < 0.5) return "A while ago";
    if ($hoursAgo < 1.5) return "About an hour ago";
    if ($hoursAgo < 4) return "A couple of hours ago";
    if ($hoursAgo < 12) return "Earlier in the day";
    if ($hoursAgo < 36) return "A day ago";
    return "Days ago";
}

function synthShouldExcludeEventFromPromptContext(array $row): bool
{
    $type = strtolower(trim(strval($row['type'] ?? '')));
    $data = trim(strval($row['data'] ?? ''));

    static $csvImportEventTypes = [
        'biography_import',
        'worldknowledge_import',
        'dynamic_worldknowledge_import',
        'description_import',
        'custom_action_import',
        'traditional_quest_import',
        'item_import',
        'npcvoice_refresh',
    ];

    static $snapshotEventTypes = [
        'nearby_actors',
        'nearby_items',
        'points_of_interest',
        'active_quests',
        'world_context',
        'inventory',
        'equipment',
        'condition',
        'activity',
        'addnpc',
        'infonpc',
        'infonpc_close',
        'infoloc',
        'infoitems',
        'named_cell',
        'world_locations',
        'world_factions',
        'loaded_plugins',
    ];

    static $promptOnlyEventTypes = [
        'ext_held_item_pickup',
        'ext_held_item_drop',
    ];

    if (in_array($type, $csvImportEventTypes, true)) {
        return true;
    }

    // These are current-state snapshots injected through live prompt sections,
    // not durable history. Replaying them from core_context_event makes stale actors/items
    // appear in later NPC prompts.
    if (in_array($type, $snapshotEventTypes, true)) {
        return true;
    }

    if (in_array($type, $promptOnlyEventTypes, true)) {
        return true;
    }

    if ($type === 'status_msg' && stripos($data, 'csv_import@') === 0) {
        return true;
    }

    if (preg_match('/^CSV upload(?:\s*\(|:| failed:)/i', $data) === 1) {
        return true;
    }

    if (preg_match('/^[^@]+@[0-9a-f]{8}@nullvoicetype$/i', $data) === 1) {
        return true;
    }

    return false;
}

function buildHistoricContext($actor, $lastNelements = -10,$sqlfilter="") {

    $db = \Synth\Bootstrap::db();

    if ($lastNelements == 0) { // if context_history is 0, all records will be retrieved
        $lastNelements = -1;
    }

    $nRecordsLimit = 32 + (2 * abs($lastNelements)); // reduce the default 1000 recs loaded from db to a number proportional to context_history

    if (isset(\Synth\Bootstrap::gameRequest()[2])) {
        $currentGameTs=intval(\Synth\Bootstrap::gameRequest()[2]);
    } else {
        $currentGameTs=intval(DataLastKnownGameTS());
    }

    $removeBooks = "";

    $ext_sqlfilter1 = \Synth\Bootstrap::config("EXT_CONTEXT_SQL_FILTER1") ?? "";
    $ext_sqlfilter2 = \Synth\Bootstrap::config("EXT_CONTEXT_SQL_FILTER2") ?? "";

    $lastDialogFull = array();
    $b_actor = (strlen($actor) > 0);
    if ($b_actor)
        $actorEscaped=$db->escape($actor);
    else
        $actorEscaped='';
    //$playerEscaped=$db->escape(\Synth\Bootstrap::config("PLAYER_NAME"));

    $visibleChatStateSql = synthBuildChatDeliveryStateSql('delivery_state');

    $nativeEvent = \Synth\Bootstrap::requestEvent()['json'] ?? [];
    $nativeHistory = ($nativeEvent['protocol_version'] ?? 1) === 2;
    $historySource = $nativeHistory ? 'native_history' : 'core_context_event';
    $historyPrefix = $nativeHistory ? synth_protocol_history_cte($db, $nativeEvent, $actor, responseRoles:true) : '';
    $identityColumns = $nativeHistory ? ',history_event_id,utterance_id,reader_identity_id,reader_player_id,speaker_identity_id,response_role,response_actor_identity_id' : '';
    // dialogue.conversation: rows of the request's own conversation fill the row limit first (the same fixed
    // preference as the native lane); the selected rows keep their chronological order.
    $conversationRank = $nativeHistory ? \Synth\Managers\Conversation\ConversationManager::rankSql($db, $nativeEvent) : null;
    if ($conversationRank !== null) $identityColumns .= ',ts,rowid';
    $query=$historyPrefix.($conversationRank !== null ? 'SELECT * FROM (' : '')."select
    case
      when type='infoaction' and a.data like '#%MEMORY%' then 'MEMORY'
      when type like 'info%' or type like 'funcret%' or type like 'location%' then 'CONTEXTI'
      when type='backgroundchat' or a.data like '%background chat%' then 'BACKDIAG'
      when type='quest' then 'QUEST'
      when type='itemfound' then 'ITEM'
      when type='rpg_lvl' then 'RPG_LVL'
      when type='death' then 'RPG_DEATH'
      when type='welcome' then 'RPG_SPAWN'
      when type='waitstart' then 'CONTEXTI'
      when type='waitstop' then 'CONTEXTI'
      when type='info_timeforward' then 'TIMELAPSE'
      when type like 'ext_%' then 'PLUGIN'
      else ''
    end as subtype,a.data  as data , gamets,localts,type,location {$identityColumns}
    FROM  {$historySource} a WHERE
    type<>'combatend'
    and type<>'bored' and type<>'init' and type<>'info' and type<>'funcret' and type<>'book'
    and type<>'updateprofile' and type<>'rechat' and type<>'setconf' and  type<>'status_msg'  and type<>'user_input'
    and type<>'instruction'
    and data not ilike '%sSubtitle%'
    and data not ilike '%sSpeakerName%'
    and type<>'request' and type<>'playerinfo' and type<>'im_alive' and type<>'region'
    AND type<>'narrator_welcome'
    and (type<>'chat' or {$visibleChatStateSql})
    AND type<>'funccall' AND type<>'togglemodel'
    {$removeBooks} {$sqlfilter} {$ext_sqlfilter1}
    ".(($b_actor && !$nativeHistory) ? "
    AND (
     people like '%|$actorEscaped|%'
     or people like '$actorEscaped'
     or people like '%|$actorEscaped (busy)|%'
     or people like '%|$actorEscaped (hostile)|%'
     or people like '%|$actorEscaped (in combat)|%'
     or people like '%|$actorEscaped (restrained)|%'
     or type='info_timeforward'
    )" : " ").
    //((false)?" and gamets>".($currentGameTs-(60*60*60*60)):"").
    " {$ext_sqlfilter2}
    ORDER BY ".($conversationRank !== null ? "({$conversationRank}) DESC," : '')." gamets desc, ts desc, rowid desc LIMIT {$nRecordsLimit} OFFSET 0 "
        .($conversationRank !== null ? ') ranked ORDER BY gamets desc, ts desc, rowid desc' : '');

    // Keep generic far-away actors out of historic context. Shared narrator rows are flattened on write.
    $results = $db->fetchAll($query);
    $speechRoutes=[];
    if ($nativeHistory) {
        require_once dirname(__DIR__, 3).'/lib'.'/synth_history_speech.php';
        $speechRoutes=synth_history_speech_routes($db,$nativeEvent,$actor,$results);
    }

    // Filter blacklisted event types
    if (\Synth\Bootstrap::has('EVENT_TYPE_FILTER') && !empty(\Synth\Bootstrap::config('EVENT_TYPE_FILTER'))) {
        $blacklistedEventTypes = array_map('trim', explode(',', strtolower(\Synth\Bootstrap::config("EVENT_TYPE_FILTER"))));
        $results = array_filter($results, function($row) use ($blacklistedEventTypes) {
            $eventType = strtolower($row["type"] ?? '');
            foreach ($blacklistedEventTypes as $blacklistedType) {
                if (!empty($blacklistedType) && $eventType === $blacklistedType) {
                    return false;
                }
            }
            return true;
        });
    }

    $results = array_filter($results, function ($row) {
        return !synthShouldExcludeEventFromPromptContext($row);
    });

    //error_log($query);
    $rawData=[];
    foreach ($results as $row) {
        if ($nativeHistory) {
            // Native rows already have persisted identity/idempotency. Equal text in one
            // clock tick can still be two distinct utterances; do not collapse those rows.
            $rawData[] = $row;
        } else {
            $rawData[md5($row["data"].$row["localts"])] = $row;
        }
    }


    $orderedData = array_reverse($rawData);

    //$orderedData = array_slice($orderedData, $lastNelements);


    $currentLocation = "";
    $writeLocation = true;

    $lastSpeaker = "";
    $buffer = [];
    $timeStampBuffer = [];

    $beingsPresent=null;
    $lastlocation="";
    $lastGameTs=0;
    $memoryLogToRemove=[];

    $lastTimeCategory = null; // Track last timestamp category for PROMPT_TIMESTAMP feature

    $focusOnChat=(\Synth\Bootstrap::config("CLEAN_CONTEXT_FOCUS_CHAT") ?? false);


    require_once dirname(__DIR__, 3).'/lib' . '/synth_history_trigger_prompt.php';
    foreach ($orderedData as $n=>$row) {
        $rowData = synth_history_trigger_prompt_data($row, $nativeHistory);

        if ($rowData==="The Narrator:") // Hunt empty rows
            continue;

        // Remove Context location from data
        $pattern = '/\s*\(Context location: .*?\)/';
        if ($rowData)
            $rowData = preg_replace($pattern, "", $rowData);

        // Figure out location form location field, and only add to context if changed
        $printLocation=false;
        $string = $row["location"];
        if (!empty($string)) {
            preg_match('/Context\s*(?:new\s*)?location:\s*([^,]+?)(?:,|$)/u', $string, $locationMatch);
        }

        if (!isset($locationMatch[1])) {
            //error_log(print_r($string,true));
            $locationFinal=$lastlocation;
        } else {
            $location = trim($locationMatch[1]);
            $locationFinal=$location;
        }

        if ($lastlocation!=$locationFinal) {
            $lastlocation=$locationFinal;
            $printLocation=true;
            $currentLocation=$lastlocation;
        }

        // Special case, logaction is the return data of an action call.
        if ($row["type"]=="logaction") {
            $logactionData=json_decode($rowData,true);
            if (is_array($logactionData)) {
                if ($logactionData["character"]!=\Synth\Bootstrap::config("SYNTH_NAME"))
                    continue;
            }
        }

        // Skip empty rows
        if (!$rowData)
            $rowData="";


        // A completed prepared response can prove its role even when its NPC is named The Narrator.
        $nativeSpeechKind=null;
        $nativeListenerKind=null;
        // Figure out real speaker
        if ($nativeHistory && in_array($row['type'], ['chat','prechat','inputtext','inputtext_s'], true)) {
            // Display text is not an identity: use the original snapshot's unambiguous speaker.
            $speakerId = intval($row['speaker_identity_id'] ?? 0);
            $speechRoute=$speechRoutes[intval($row['history_event_id'] ?? 0)] ?? null;
            if ($speechRoute!==null) {
                $speakerId=intval($speechRoute['speaker_identity_id']);
                $nativeSpeechKind=$speechRoute['role'];
                if ($speechRoute['narrator_named_npc_listener']) $nativeListenerKind='npc';
            } elseif (empty($row['utterance_id']) && ($row['response_role'] ?? '')==='npc' && $speakerId>0
                && $speakerId===intval($row['response_actor_identity_id'] ?? 0)) $nativeSpeechKind='npc';
            elseif (empty($row['utterance_id']) && ($row['response_role'] ?? '')==='narrator' && str_starts_with($rowData,'The Narrator:')) $nativeSpeechKind='narrator';
            $speaker = $speakerId > 0 && $speakerId === intval($row['reader_identity_id']) ? 'assistant'
                : ($speakerId > 0 && $speakerId === intval($row['reader_player_id']) ? 'player'
                    : ($nativeSpeechKind!=='npc' && str_starts_with($rowData, 'The Narrator:') && $row['type'] === 'chat' ? 'narratorchat' : 'npc'));
            if ($nativeSpeechKind==='narrator') $speaker='narratorchat';

        } else if (($row["type"]=="logaction") && (strpos($rowData, ((string)\Synth\Bootstrap::config('SYNTH_NAME'))) !== false))  {
            $speaker = "assistant";

        } else if ($row["type"]=="vision") {
            $speaker = "user";

        } else if ($row["subtype"]=="MEMORY") {
            $speaker = "memory";

        } else if ((strpos($rowData, (\Synth\Bootstrap::config('SYNTH_NAME') . ":")) !== false) && (strpos($rowData, "The Narrator:") === false)) {
            $speaker = "assistant";

        } else if ((strpos($rowData, (\Synth\Bootstrap::config('PLAYER_NAME') . ":")) === 0)) {
            $speaker = "player";

        } else if ((strpos($rowData, "The Narrator:") === 0) && $row["type"]=="chat") {
            $speaker = "narratorchat";

        } else if ($row["subtype"]=="BACKDIAG") {
            if ($focusOnChat)
                continue;
            $speaker = "backgroundchat";

        } else if ($row["subtype"]=="CONTEXTI") {
            if (strpos($rowData,"should not be visible")!==false)
                continue;
            if ($focusOnChat) {
                if (strpos($rowData," uses ")!==false)
                    continue;
                if (strpos($rowData," casts ")!==false)
                    continue;
                if (strpos($rowData," engages combat ")!==false)
                    continue;
                if (strpos($rowData," has defeated ")!==false)
                    continue;
                if (strpos($rowData," activates ")!==false)
                    continue;
            }


            $speaker = "narratorci";

        } else if ($row["subtype"]=="QUEST") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";

        } else if ($row["subtype"]=="ITEM") {
            if ($focusOnChat) {
                if (strpos($rowData,((string)\Synth\Bootstrap::config('SYNTH_NAME')))===false) // This NPC's item transactions conserved
                    continue;
            }
            $speaker = "narratorci";

        } else if ($row["subtype"]=="RPG_LVL") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";

        } else if ($row["subtype"]=="RPG_SPAWN") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";

        } else if ($row["subtype"]=="RPG_DEATH") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            $rowData = strtoupper($rowData);

        } else if ($row["subtype"]=="RPG_DEFEAT") {
            if ($focusOnChat)
                continue;
            $speaker = "narratorci";
            $rowData = strtoupper($rowData);

        } else if ($row["subtype"]=="TIMELAPSE") {
            $rowData = strtoupper($rowData);

        }  else if ($row["subtype"]=="PLUGIN") {
            $speaker = $row["type"];

        } else {

            $speaker = "npc";

        }

        // Compact info_timeforward events
        if ($row["type"] == "info_timeforward") {
            if (isset($previousRow) && $previousRow["type"] == "info_timeforward") {
                // Extract hours passed from the current row and the current date/time portion
                preg_match('/([\d.]+)\s*hours have passed\.?/i', $row["data"], $currentMatch);
                $currentHours = isset($currentMatch[1]) ? (float)$currentMatch[1] : 0;
                preg_match('/(Current date\/time: .+)$/i', $row["data"], $currentDateMatch);
                $currentDateTime = isset($currentDateMatch[1]) ? trim($currentDateMatch[1]) : '';

                // Extract hours passed from the previous row (if present)
                preg_match('/([\d.]+)\s*hours have passed\.?/i', $previousRow["content"], $previousMatch);
                $previousHours = isset($previousMatch[1]) ? (float)$previousMatch[1] : 0;

                // Sum the hours
                $totalHours = $currentHours + $previousHours;

                // error_log("[TIMEFORWARD] $totalHours = $currentHours + $previousHours ");

                // Build a normalized single-line content: "<hours> hours have passed. Current date/time: ..."
                if ($currentDateTime !== '') {
                    $previousRow["content"] = "{$totalHours} hours have passed. {$currentDateTime}";
                } else {
                    // Fallback: use the trimmed current row data if date/time portion wasn't found
                    $previousRow["content"] = "{$totalHours} hours have passed. " . trim($row["data"]);
                }

                continue; // Skip adding this row to the context
            } else {
                $row["role"]="narratorci";
                $row["content"]=trim($rowData);
                $row["gamets"]=$lastGameTs;// gamets will be previous record gamets

                $previousRow=$row;
                continue; // Skip adding this row to the context
            }
        } else if (isset($previousRow) && $previousRow["type"] == "info_timeforward") {
            $lastDialogFull[]=$previousRow;
            unset($previousRow);
        }

        //if ((\Synth\Bootstrap::config("FEATURES")["MISC"]["ADD_TIME_MARKS"])&&(true) && $row["type"] != "info_timeforward") {
        if ($row["type"] != "info_timeforward") {


            if ($lastGameTs==0)
                $lastGameTs=$row["gamets"];
            else {
                $timeGapInHours=round(($row["gamets"]-$lastGameTs) * 0.0000024, 0);

                if ($timeGapInHours>36) {
                    $timeGapInDays=round($timeGapInHours/24,1);
                    $lastDialogFull[] = array('role' => "narratorci", 'content' => "!!! IMPORTANT CONTEXT !!!
A MAJOR TIME JUMP HAS OCCURRED.
Elapsed time since last interaction: ~$timeGapInDays days
New setting: $currentLocation
!!! END CONTEXT !!! ");
                } else if ($timeGapInHours>5) {
                    $timeGapInDays=round($timeGapInHours/24,1);
                    $lastDialogFull[] = array('role' => "narratorci", 'content' => "(minor timelapse of about $timeGapInHours hours)");
                }
                $lastGameTs=$row["gamets"];
            }

            if ($printLocation ) {
                $hoursAgo=round(($currentGameTs-$row["gamets"]) * 0.0000024, 0);
                if (!isset($timeStampBuffer[$hoursAgo])) {
                    if ($currentLocation) {
                        if (DataLastKnownLocationHuman(false,true)==$currentLocation)   // Enforce current location.
                            $lastDialogFull[] = array('role' => "narratorci", 'content' => "LOCATION CHANGE, THIS IS THE CURRENT LOCATION: $currentLocation");

                        else
                            $lastDialogFull[] = array('role' => "narratorci", 'content' => "LOCATION CHANGE to $currentLocation, timeline mark: $hoursAgo hours ago  ");
                    }
                }
            } else {


            }
        }

        $lastSpeaker = $speaker;

        // Insert timestamp subdividers if PROMPT_TIMESTAMP is enabled
        if (!empty(\Synth\Bootstrap::config('PROMPT_TIMESTAMP')) && $row["type"] != "info_timeforward") {
            $hoursAgo = ($currentGameTs - $row["gamets"]) * 0.0000024;
            $currentTimeCategory = getTimeCategory($hoursAgo);

            // If category changed, insert a subdivider
            if ($lastTimeCategory !== null && $currentTimeCategory !== $lastTimeCategory) {
                $lastDialogFull[] = array('role' => "narratorci", 'content' => "--- {$currentTimeCategory} ---");
            }

            $lastTimeCategory = $currentTimeCategory;
        }

        $row= array('role' => $lastSpeaker, 'content' => trim($rowData),'subtype'=>$row["subtype"]?:strtoupper($lastSpeaker),'type'=>$row["type"],
            '_g' => intval($row['gamets'] ?? 0));
        if ($nativeSpeechKind!==null) $row['_synth_history_speech']=$nativeSpeechKind;
        if ($nativeListenerKind!==null) $row['_synth_history_listener']=$nativeListenerKind;
        $lastDialogFull[] = $row;
        $previousRow=$row;

    }

    if (isset($previousRow)) {
        if (sizeof($previousRow)>0) {
            if (sizeof($lastDialogFull) === 0 || $previousRow !== end($lastDialogFull)) {
                $lastDialogFull[]=$previousRow;
            }

        }
    }

    file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/context_for_{$actor}_stage_1_.txt",print_r($lastDialogFull,true));

    // Remove memory logs, only leave last one.
    $lastDialogFullOnlyLastMemory=[];
    $localFlag=0;
    foreach (array_reverse($lastDialogFull) as $element) {
        if ($element["role"]=="memory") {
            if ($localFlag==0) {
                $element["role"]="narratorci";
                $lastDialogFullOnlyLastMemory[]=$element;
                $localFlag++;
            } else {
                $localFlag++;
            }
        } else {
            $lastDialogFullOnlyLastMemory[]=$element;
        }
    }

    error_log("[buildHistoricContext] $localFlag memories removed");
    $lastDialogFull=array_reverse($lastDialogFullOnlyLastMemory);
    // End of memory logs cleaning

    // Consolidate repeated events to reduce context size
    $eventCountBefore = count($lastDialogFull);
    // Native event boundaries carry observation time and ownership; legacy pattern grouping
    // cannot safely infer that two captured events are one event or share a time window.
    $lastDialogFull = $nativeHistory ? $lastDialogFull : consolidateEvents($lastDialogFull);
    $eventCountAfter = count($lastDialogFull);
    if ($eventCountBefore > $eventCountAfter) {
        error_log("[buildHistoricContext] Consolidated events: {$eventCountBefore} -> {$eventCountAfter} (saved " . ($eventCountBefore - $eventCountAfter) . " slots)");
    }

    // Filter ambient combat deaths if configured.
    if (!empty(\Synth\Bootstrap::config('HIDE_AMBIENT_COMBAT'))) {
        $protectedCombatNames = array_filter(array_map('trim', [
            (string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? ''),
            (string)$actor,
        ]));
        try {
            $partyData = json_decode(DataGetCurrentPartyConf(), true);
            if (is_array($partyData)) {
                foreach (array_keys($partyData) as $partyName) {
                    $partyName = trim((string)$partyName);
                    if ($partyName !== '') {
                        $protectedCombatNames[] = $partyName;
                    }
                }
            }
        } catch (Throwable $e) {
            // Party lookup is best-effort; player/current actor protection still applies.
        }
        $protectedCombatNames = array_values(array_unique($protectedCombatNames));
        $beforeFilter = count($lastDialogFull);
        $lastDialogFull = array_values(array_filter($lastDialogFull, function($event) use ($protectedCombatNames) {
            // Keep non-death events
            if (!isset($event['type']) || $event['type'] !== 'death') {
                return true;
            }

            // Keep death events involving the player, current actor, or current party members.
            $content = $event['content'] ?? '';
            foreach ($protectedCombatNames as $name) {
                if ($name !== '' && stripos($content, $name) !== false) {
                    return true;
                }
            }

            return false;
        }));
        $afterFilter = count($lastDialogFull);
        if ($beforeFilter > $afterFilter) {
            error_log("[buildHistoricContext] Filtered ambient combat: {$beforeFilter} -> {$afterFilter} (removed " . ($beforeFilter - $afterFilter) . " events)");
        }
    }

    file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/context_for_{$actor}_stage_1_.txt",print_r($query,true),FILE_APPEND);

    return $lastDialogFull;

}

function compactHistoricContext($lastDialogFull,$actor,$compactContextInfo=false) {

    // Native history is already ownership-filtered. Keep its event/listener boundaries until
    // the configured history window and optional compact prompt formatter are applied.
    if ((\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1) === 2) {
        return array_map(static fn(array $line): array => [
            'role' => $line['role'], 'content' => $line['content'],
            '_g' => intval($line['_g'] ?? $line['gamets'] ?? 0),
        ] + array_intersect_key($line,['_synth_history_speech'=>true,'_synth_history_listener'=>true]), $lastDialogFull);
    }

    $lastrole="";
    $bufferSYNTH=[];
    $lastDialogFullCopy=[];
    $compactedBuffer = "";
    $assistantGamets = PHP_INT_MAX;

    foreach ($lastDialogFull as $n => $line) {
        if (($line["role"] == "assistant")) {
            $isJson=json_decode($line["content"],true);
            if (is_array($isJson)) {
                $lastDialogFullCopy[]=$line;
                continue;
            }
            $cleanedText=$line["content"];

            $bufferSYNTH[]=$cleanedText;
            $assistantGamets = min($assistantGamets, intval($line['_g'] ?? 0));


        } else {
            if ($lastrole=="assistant") {
                // This breaks with spaces?
                $compactedBuffer="";
                foreach ($bufferSYNTH as $m=>$singleline) {
                    $compactedBuffer .=" ";
                    if ($m>0) {
                        //$regexpNpcName = strtr(\Synth\Bootstrap::config("SYNTH_NAME"),["-"=>'\-', "["=>"\[", "]"=>"\]"]);
                        // Capture spoken text after a leading "Name:" (supports names with brackets and dashes)
                        // and optionally strip a trailing parenthetical note like "(talking to X)".
                        preg_match('/^\s*[^:]+:\s*(.*?)\s*(?:\([^)]*\))?\s*$/s', $singleline, $matches);
                        $extracted=$matches[1] ?? $singleline;
                        $compactedBuffer .= trim(removeTalkingToOccurrences($extracted));
                        $compactedBuffer=str_replace((\Synth\Bootstrap::config('SYNTH_NAME') . ";"),"",$compactedBuffer);

                    } else {
                        $compactedBuffer .= trim(removeTalkingToOccurrences($singleline));
                        $compactedBuffer=str_replace((\Synth\Bootstrap::config('SYNTH_NAME') . ":"),"",$compactedBuffer);
                    }


                }
                $lastDialogFullCopy[] = ["role"=>"assistant","content"=>trim($compactedBuffer), '_g' => $assistantGamets];

            }
            $bufferSYNTH=[];
            $assistantGamets = PHP_INT_MAX;
            $compactedBuffer="";
            $lastDialogFullCopy[]=$line;
        }



        $lastrole=$line["role"];
    }

    // Last entry
    if (sizeof($bufferSYNTH)>0) {
        foreach ($bufferSYNTH as $m=>$singleline) {
            $compactedBuffer .=" ";
            if ($m>0) {
                //$regexpNpcName = strtr(\Synth\Bootstrap::config("SYNTH_NAME"),["-"=>'\-', "["=>"\[", "]"=>"\]"]);
                // Same robust extraction for subsequent lines in the buffer
                preg_match('/^\s*[^:]+:\s*(.*?)\s*(?:\([^)]*\))?\s*$/s', $singleline, $matches);
                $extracted=$matches[1] ?? $singleline;
                $compactedBuffer .= trim(removeTalkingToOccurrences($extracted));
                $compactedBuffer=str_replace((\Synth\Bootstrap::config('SYNTH_NAME') . ";"),"",$compactedBuffer);

            } else {
                $compactedBuffer .= trim(removeTalkingToOccurrences($singleline));
                $compactedBuffer=str_replace((\Synth\Bootstrap::config('SYNTH_NAME') . ";"),"",$compactedBuffer);
            }



        }
        $lastDialogFullCopy[] = ["role"=>"assistant","content"=>trim($compactedBuffer), '_g' => $assistantGamets];
        $bufferSYNTH=[];
    }

    // file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/context_for_{$actor}_stage_1_5_.txt",print_r($lastDialogFullCopy,true));


    // Compact other info
    $lastSpeaker = "";
    $buffer = [];
    $lastDialogFull=[];
    $bufferGamets = 0;


    foreach ($lastDialogFullCopy as $n => $line) {
        $speaker=$line["role"];
        $lineGamets = intval($line['_g'] ?? 0);

        if ($speaker=="npc") { // Tricky, npc could be any char
            preg_match('/^([^:]+):/', $line["content"], $matches);
            // Output the extracted name
            $speakerNPC=$matches[1] ?? "";
            $speaker="npc_$speakerNPC";
        }


        if ($lastSpeaker == $speaker) {
            // Same speaker as last iteration, remove extra text
            if (strpos($speaker,"npc") === 0 || $speaker == "narratorchat") {
                $matches = [];

                // Clean talking to and npc name , only leave it on first line
                $matches = [];
                // And for compacting other dialog lines: capture content after the speaker name
                preg_match('/^\s*[^:]+:\s*(.*?)\s*(?:\([^)]*\))?\s*$/s', $line["content"], $matches);
                $buffer[]=$matches[1] ?? $line["content"];
                $bufferGamets = min($bufferGamets, $lineGamets);
            } else {

                if (!$compactContextInfo) {
                    $lastDialogFull[]=array('role' => $lastSpeaker, 'content' => trim(isset($buffer[0])?$buffer[0]:$line["content"]), '_g' => $bufferGamets);
                    if (isset($buffer[0])) {
                        $buffer = [];
                        $buffer[] = $line["content"];
                        $bufferGamets = $lineGamets;
                    } else
                        $buffer = [];
                } else {
                    $buffer[] = strtr($line["content"],["The Narrator:"=>"",(\Synth\Bootstrap::config('SYNTH_NAME') . ":")=>""]);
                    $bufferGamets = min($bufferGamets, $lineGamets);
                }

            }
        } else {

            if (sizeof($buffer) > 0) {
                if ($lastSpeaker=="narratorci" || $lastSpeaker=="narratorloc") {
                    if (!$compactContextInfo) {
                        $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => "".implode(" ", removeEmptyElements($buffer)), '_g' => $bufferGamets);  // Should be only one line
                    } else {
                        $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => "* ".implode("\n* ", removeEmptyElements($buffer)), '_g' => $bufferGamets);
                    }

                }
                else if ($lastSpeaker=="backgroundchat")
                    $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => implode("\n", removeEmptyElements($buffer)), '_g' => $bufferGamets);
                else
                    $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => moveDialogueTargetSuffixToEnd(implode(" ", removeEmptyElements($buffer))), '_g' => $bufferGamets);
            }
            $buffer = [];
            $buffer[] = $line["content"];
            $bufferGamets = $lineGamets;
            $lastSpeaker = $speaker;

            if ($speaker=="assistant") {    //Leave as is
                $lastDialogFull[] = $line;
                $lastSpeaker = "";
                $buffer = [];
                continue;
            }
        }

    }

    // Clean empty entries
    $bufferCopy=[];
    foreach ($buffer as $n=>$bufferEntry) {
        if (!empty(trim($bufferEntry)))
            $bufferCopy[]=$bufferEntry;

    }

    // Last buffer, probably user input.
    if (sizeof($bufferCopy)) {
        if ($lastSpeaker=="narratorci" || $lastSpeaker=="narratorloc")
            $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => implode("\n* ", $bufferCopy), '_g' => $bufferGamets);
        else if ($lastSpeaker=="backgroundchat")
            $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => implode("\n", $bufferCopy), '_g' => $bufferGamets);
        else
            $lastDialogFull[] = array('role' => $lastSpeaker, 'content' => moveDialogueTargetSuffixToEnd(implode(" ", $bufferCopy)), '_g' => $bufferGamets);
    }

    $contextDataHistory=[];
    foreach ($lastDialogFull as $n=>$lastDialogFullEntry) {
        if (!empty(trim($lastDialogFullEntry["content"])))
                $contextDataHistory[]=$lastDialogFullEntry;

    }

    file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/context_for_{$actor}_stage_2_.txt",print_r($contextDataHistory,true));
    return $contextDataHistory;
}

function replaceRoles($lastDialogFull,$actor,$lastNelements) {

     // Replace roles for user.
     foreach ($lastDialogFull as $n => $line) {
        if ($line["role"] == "player") {
            $lastDialogFull[$n]["role"] = "user";
        } else if (strpos($line["role"],"npc")===0) {

            $lastDialogFull[$n]["role"] = "user";

        } else if ($line["role"] == "backgroundchat") {

            $lastDialogFull[$n]["role"] = "user";
            if (strlen(trim($lastDialogFull[$n]["content"])) > 0) {
                $lastDialogFull[$n]["content"] = " (... ".PHP_EOL.$lastDialogFull[$n]["content"]."\n...)";
            }

        } else if ($line["role"] == "narratorci") {

            $lastDialogFull[$n]["role"] = "user";
            $lastDialogFull[$n]["content"] = $lastDialogFull[$n]["content"]."\n";

        } else if ($line["role"] == "narratorchat") {

            $lastDialogFull[$n]["role"] = "user";

        } else if ($line["role"] == "narratorloc") {

            $lastDialogFull[$n]["role"] = "user";

        }
    }

    // Date issues

    foreach ($lastDialogFull as $n => $line) {

        $pattern = '/(\w+), (\d{1,2}:\d{2} (?:AM|PM)), (\d{1,2})(?:st|nd|rd|th) of ([A-Za-z\'\ ]+), (\d+)/';
        $replacement = 'Day name: $1, Hour: $2, Day Number: $3, Month: $4, Year: $5';
        $result = preg_replace($pattern, $replacement, $line["content"]);
        $lastDialogFull[$n]["content"] = $result;
    }



    error_log("[SYNTH] Using effective context limit of : $lastNelements");
    $orderedData = array_slice($lastDialogFull, $lastNelements);
    // Keep the earliest source event for merged blocks; an unknown timestamp disables STM.
    \Synth\Bootstrap::set('CONTEXT_WINDOW_FLOOR', PHP_INT_MAX);
    foreach ($orderedData as &$entry) {
        \Synth\Bootstrap::set('CONTEXT_WINDOW_FLOOR', min(\Synth\Bootstrap::config('CONTEXT_WINDOW_FLOOR'), intval($entry['_g'] ?? 0)));
        unset($entry['_g'], $entry['gamets']);
    }
    unset($entry);

    file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/context_for_$actor.txt",print_r($orderedData,true));
    \Synth\Bootstrap::set('CONTEXT_BUILDING_DATA', $orderedData);

    return \Synth\Bootstrap::config("CONTEXT_BUILDING_DATA");

}

function DataLastDataExpandedFor($actor, $lastNelements = -10,$sqlfilter="")
{

    $localStartTime=microtime(true);

    $ctx1=buildHistoricContext($actor, $lastNelements ,$sqlfilter);
    error_log("[buildHistoricContext] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");


    $ctx2=compactHistoricContext($ctx1,$actor,false);  // Don't compact Context Info

    error_log("[compactHistoricContext] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

    $ctx3=replaceRoles($ctx2,$actor,$lastNelements);

    error_log("[replaceRoles] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

    // Cases of self rechat
    if ((sizeof($ctx3)>3)&&((\Synth\Bootstrap::gameRequest()[3] ?? "")=="rechat")) {
        $lastElement = $ctx3[sizeof($ctx3)-1];
        // Last element is assistant
        if ($lastElement["role"]=="assistant") {
            if (\Synth\Bootstrap::gameRequest()[3]=="rechat") {
                // NPC is rechatting himself

                Logger::warn("[RECHAT] actor is replying itself, case 1, aborting");

                synth_abort_json_response();
            }

        }

        $preLastElement = $ctx3[sizeof($ctx3)-2];
        // Pre last element is assistant, and last is a memory.
        if (($preLastElement["role"]=="assistant")&&(strpos($lastElement["content"],"MEMORY")!==false)) {
            if (\Synth\Bootstrap::gameRequest()[3]=="rechat") {
                // NPC is rechatting himself

                Logger::warn("[RECHAT] actor is replying itself,case 2, aborting");

                synth_abort_json_response();
            }

        }
    }

    //error_log("[DataLastDataExpandedFor end] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

    if ((\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1)===2) {
        require_once dirname(__DIR__, 3).'/lib'.'/chat_helper_functions.php';
        $ctx3=filterHistoricContextForNarratorVisibility($ctx3,$actor!=='' ? $actor : (\Synth\Bootstrap::config('SYNTH_NAME') ?? ''));
        \Synth\Bootstrap::set('CONTEXT_BUILDING_DATA', $ctx3);
    }
    return $ctx3;

}

function DataSpeechJournal($topic,$limit=50)
{

    $db = \Synth\Bootstrap::db();

    $lastDialogFull = [];
    $results = \Synth\Core\DbManager\Dao\SpeechDao::current($db)->linesAboutTopic((string)$topic);
    if (!$results) {
        return json_encode([]);
    }

    $data = [];

    foreach ($results as $row) {
        $data[] = $row;
    }

    if (sizeof($data) == 0) {
        return json_encode([]);
    } elseif (sizeof($data) < $limit) {
        $dataReversed = array_reverse($data);
    } else {
        $smalldata = array_slice($data, 0,$limit);
        $dataReversed = array_reverse($smalldata);
    }


    return json_encode($dataReversed);

}

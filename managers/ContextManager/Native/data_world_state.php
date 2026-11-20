<?php

// Moved from lib/data_functions.php: event queue, points of interest, conditions, survival, equipment and quest rows
// The original file requires this one, so every caller is unchanged.

function DataDequeue($timestamp = 0)
{
    $db = \Synth\Bootstrap::db();
    if ($timestamp !== 0) {
        $clause="and localts<={$timestamp} ";
    } else {
        $clause="";
    }
    // Use atomic UPDATE...RETURNING to prevent race conditions where multiple concurrent
    // requests could fetch the same dialogue before it's marked as sent
    $results = $db->fetchAll(
        "UPDATE responselog
         SET sent=1
         WHERE rowid IN (
             SELECT rowid FROM responselog WHERE sent=0 $clause ORDER BY rowid ASC
         )
         RETURNING *, rowid"
    );

    $finalData = array();
    foreach ($results as $row) {
        $finalData[] = $row;
    }

    return $finalData;

}

function DataLastDataFor($actor, $lastNelements = -10)
{
    $db = \Synth\Bootstrap::db();
    $lastDialogFull = array();
    $results = $db->fetchAll("select
    case
      when type like 'info%' or type like 'death%' or  type like 'funcret%' or type like 'location%' or data like '%background chat%' then 'The Narrator:'
      else ''
    end||a.data  as data
    FROM  core_context_event a WHERE data like '%$actor%'
    and type<>'combatend'
    and type<>'bored' and type<>'init' and type<>'lockpicked' and type<>'info' and type<>'funcret'  and type<>'quest'
    and type<>'user_input'
    and data not ilike '%sSubtitle%'
    and data not ilike '%sSpeakerName%'
    and type<>'funccall'  and type<>'togglemodel' order by gamets desc,ts desc,localts desc,rowid desc LIMIT 150 OFFSET 0");
    $lastData = "";


    foreach ($results as $row) {

        if ($lastData != md5($row["data"])) {
            if ((strpos($row["data"], "{$GLOBALS["SYNTH_NAME"]}:") !== false) || ((strpos($row["data"], "{$GLOBALS["PLAYER_NAME"]}:") !== false))) {
                $pattern = "/\(Context location:[^)]+?\)/"; // Remove only the exact context location pattern
                $replacement = "";
                $row["data"] = preg_replace($pattern, $replacement, $row["data"]); // // assistant vs user war
                if ((strpos($row["data"], "{$GLOBALS["SYNTH_NAME"]}:") !== false)) {
                    $role = "assistant";
                } else {
                    $role = "user";
                }

                $lastDialogFull[] = array('role' => $role, 'content' => $row["data"]);

            } else {
                $lastDialogFull[] = array('role' => 'user', 'content' => $row["data"]);
            }

        }
        $lastData = md5($row["data"]);

    }

    // Date issues

    foreach ($lastDialogFull as $n => $line) {

        $pattern = '/(\w+), (\d{1,2}:\d{2} (?:AM|PM)), (\d{1,2})(?:st|nd|rd|th) of ([A-Za-z\'\ ]+), (\d+)/';
        $replacement = 'Day name: $1, Hour: $2, Day Number: $3, Month: $4, Year: $5';
        $result = preg_replace($pattern, $replacement, $line["content"]);
        $lastDialogFull[$n]["content"] = $result;
    }


    // Clean context locations for SYNTHs dialog.


    $lastDialogFullReversed = array_reverse($lastDialogFull);
    $lastDialog = array_slice($lastDialogFullReversed, $lastNelements);
    $last_location = null;


    return $lastDialog;

}

/**
 * Get context for actor to send to llm
 */
function synthCleanNearbyActorPromptText($text): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }

    $text = str_replace("''", "'", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function synthAppendNearbyActorSentence(string $base, string $sentence): string
{
    $sentence = synthCleanNearbyActorPromptText($sentence);
    if ($sentence === '') {
        return $base;
    }

    $base = rtrim($base);
    if ($base === '') {
        return $sentence;
    }

    if (!preg_match('/[.!?]$/', $base)) {
        $base .= '.';
    }

    return $base . ' ' . $sentence;
}

function synthNormalizeUnknownProfileValue($value): string
{
    $value = trim((string)$value);
    if ($value === '' || strcasecmp($value, 'unknown') === 0 || strcasecmp($value, 'unknown unknown') === 0) {
        return '';
    }

    return $value;
}

function DataLastInfoFor($actorBeingCalled, $lastNelements = -2,$includeActorDescriptions=false,$excludeBusy=false)
{

    $lastDialog = array(); // Initialize the return array
    $followers=[];
    $actorsInRangeList=DataBeingsInCloseRange();
    $actorsInRange=strtr($actorsInRangeList,["|"=>"\n* "]);
    $actorDetailedList=explode("|",$actorsInRangeList);
    // Not always the same order
    shuffle($actorDetailedList);
    // error_log("[DataLastInfoFor] $actorsInRangeList");

    $nearbyContextOptionEnabled = static function (string $id): bool {
        if (function_exists('synthPromptContextOptionEnabled')) {
            return synthPromptContextOptionEnabled('enabled_nearby_actor_subsections', $id);
        }
        return true;
    };
    $nearbyActorsIncludeBasicSummary = $nearbyContextOptionEnabled('basic_summary');
    $nearbyActorsIncludeAppearance = $nearbyContextOptionEnabled('appearance');
    $nearbyActorsIncludeEquipment = $nearbyContextOptionEnabled('equipment');
    $nearbyActorsEquipmentDescriptions = $nearbyContextOptionEnabled('equipment_descriptions');
    $nearbyActorsIncludeActivity = $nearbyContextOptionEnabled('current_activity');
    $nearbyActorsIncludePower = $nearbyContextOptionEnabled('power_awareness');
    $nearbyActorsIncludeFactions = $nearbyContextOptionEnabled('factions');
    $nearbyActorsIncludeCustomState = $nearbyContextOptionEnabled('custom_state');

    $nearbyEquipmentDescriptionResolver = null;
    if ($nearbyActorsEquipmentDescriptions) {
        $nearbyEquipmentDescriptionResolver = static function ($itemName, $baseid = null) {
            $db = \Synth\Bootstrap::db();

            if (!empty($baseid)) {
                $record = lookupDescriptionByFormID((string)$baseid);
                if (!empty($record['description'])) {
                    return (string)$record['description'];
                }
            }

            $itemName = trim((string)$itemName);
            if ($itemName !== '' && $itemName !== '<Missing Name>' && isset($db)) {
                $escapedName = $db->escape($itemName);
                $rows = $db->fetchAll("SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER('{$escapedName}') LIMIT 1");
                if (!empty($rows[0]['description'])) {
                    return (string)$rows[0]['description'];
                }
            }

            return null;
        };
    }

    // Track seen faction descriptions to avoid duplicates
    $seenFactionFormIDs = [];
    $factionDescriptions = []; // Store unique faction descriptions

    // Actors
    if ($actorsInRange && $includeActorDescriptions) {
        $actorDetailedListWithProfile=[];
        foreach ($actorDetailedList as $actor) {
            if (empty($actor))
                continue;
            if ($excludeBusy)
                if ((strpos($actor,"(busy)")>0)||(strpos($actor,"(dead)")>0))
                    continue;

            $actorName=trim(str_replace("(far away)","",$actor));
            if ($actorName==\Synth\Bootstrap::config("SYNTH_NAME"))
                continue;

            /* if (!(strpos($GLOBALS["SYNTH_NAME"],"actor")===false)) { // debug
                Logger::warn("DataLastInfoFor: unexpected value for SYNTH_NAME={$GLOBALS["SYNTH_NAME"]} | actor={$actor} actorname={$actorName} ");
            } */

            if ((strpos($actor,"(")===false) && (\Synth\Bootstrap::config("SYNTH_NAME")!="The Narrator") && (strpos(\Synth\Bootstrap::config("SYNTH_NAME"),"actor")===false)) {
                $interactions=DirectConversationsWith($actor);
                if ($interactions==0) {
                    $ittext="{$actor} ({$GLOBALS["SYNTH_NAME"]} never talked to {$actorName} before, {$GLOBALS["SYNTH_NAME"]} should speak to this person as to a stranger or traveler...)";
                } else if ($interactions<5) {
                    $ittext="{$actor} ({$GLOBALS["SYNTH_NAME"]} has talked to {$actorName} a couple of times before)";
                } else {
                    $ittext="{$actor}";
                }
            } else {
                $ittext="{$actor}";
            }

            if ($actor==\Synth\Bootstrap::config("PLAYER_NAME")) {
                // Player - read from core_player table (don't reveal they're "the player character")
                $profileString = "$actor";

                try {
                    require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
                    $player = new Player();

                    // Add appearance if available
                    $appearance = $player->get('appearance');
                    if ($nearbyActorsIncludeAppearance && !empty($appearance)) {
                        $profileString .= ": " . synthCleanNearbyActorPromptText($appearance);
                    }

                    $playerBio = ResolvePlayerBackstory($player);
                    $bioKnownByAll = filter_var((string)($player->get('bio_known_by_all') ?? ''), FILTER_VALIDATE_BOOLEAN);
                    $isNarrator = isset($GLOBALS["SYNTH_NAME"]) && strcasecmp((string)\Synth\Bootstrap::config("SYNTH_NAME"), "The Narrator") === 0;
                    if ($nearbyActorsIncludeBasicSummary && $playerBio !== "" && ($bioKnownByAll || $isNarrator)) {
                        $profileString = synthAppendNearbyActorSentence($profileString, "Backstory: " . $playerBio);
                    }

                    // Add equipment if available
                    $playerMetadata = [
                        'equipment_structured' => $player->getJson('equipment_structured'),
                        'equipment' => $player->getJson('equipment'),
                    ];
                    $equipmentLines = $nearbyActorsIncludeEquipment
                        ? synthBuildEquipmentLinesFromMetadata($playerMetadata, $nearbyEquipmentDescriptionResolver)
                        : [];
                    if ($nearbyActorsIncludeEquipment && !empty($equipmentLines)) {
                        $equipmentText = implode(', ', array_map(static function ($line) {
                            return preg_replace('/^-\s*/', '', $line);
                        }, $equipmentLines));
                        $profileString = synthAppendNearbyActorSentence($profileString, "Equipment: " . $equipmentText);
                    }

                    if ($nearbyActorsIncludeCustomState) {
                        $profileExtra = synthBuildActorProfileEnrichmentText($actor, "player", [
                            "source" => "nearby_actors",
                        ]);
                        if ($profileExtra !== "") {
                            $profileString = synthAppendNearbyActorSentence($profileString, $profileExtra);
                        }
                    }

                    // Power Awareness: Add relative power assessment for player
                    if ($nearbyActorsIncludePower && isset($GLOBALS["POWER_AWARENESS_ENABLED"]) && \Synth\Bootstrap::config("POWER_AWARENESS_ENABLED")) {
                        require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "power_awareness.php");

                        // Get player's level
                        $playerLevel = getPlayerLevel();

                        // Get assessing actor's level (the NPC looking at the player)
                        if (!empty($GLOBALS["SYNTH_NAME"])) {
                            $assessorLevel = getNpcLevel(\Synth\Bootstrap::config("SYNTH_NAME"));

                            if ($assessorLevel !== null && $playerLevel !== null) {
                                $powerComparison = calculatePowerComparison($assessorLevel, $playerLevel);
                                $profileString .= " ({$powerComparison})";
                            }
                        }
                    }

                } catch (Exception $e) {
                    Logger::debug("Could not load player data for context: " . $e->getMessage());
                }

                // Don't append $ittext for player - profileString already starts with player name
                $actorDetailedListWithProfile[] = $profileString;

            } else {

                $actorName = preg_replace("/\s*\(.*?\)\s*/", "", $actor);
                $codename = npcNameToCodename($actorName);
                $npcMaster=new NpcMaster();
                $currentNpcData=$npcMaster->getByName($actorName);


                if (isset($currentNpcData["core"]) && !empty($currentNpcData["core"])) {
                    // NPC name should always be at core section.
                    $npcName = $currentNpcData["npc_name"];
                    error_log("[DataLastInfoFor] Actors around, Checking NPC Name: " . $npcName." actors in range: ".$actorsInRangeList);
                    // Format gender (capitalize first letter)
                    $gender = synthNormalizeUnknownProfileValue($currentNpcData["gender"] ?? "");
                    if ($gender !== "") {
                        $gender = ucfirst(strtolower($gender));
                    }
                    $race = synthNormalizeUnknownProfileValue($currentNpcData["race"] ?? "");

                    // Build name with race/gender in parentheses
                    $nameWithRaceGender = $npcName;
                    if (!empty($gender) && !empty($race)) {
                        $nameWithRaceGender .= " ({$gender} {$race})";
                    } elseif (!empty($race)) {
                        $nameWithRaceGender .= " ({$race})";
                    }

                    $profileString = $nearbyActorsIncludeBasicSummary
                        ? "{$nameWithRaceGender}: " . synthCleanNearbyActorPromptText($currentNpcData["core"] ?? "")
                        : $nameWithRaceGender;

                    // Add appearance if available
                    if ($nearbyActorsIncludeAppearance && !empty($currentNpcData["appearance"])) {
                        $profileString = synthAppendNearbyActorSentence($profileString, "Appearance: " . $currentNpcData["appearance"]);
                    }

                    // Get metadata once for both scale and equipment
                    $metaData = $npcMaster->getMetaData($currentNpcData);

                    // Add height description based on scale
                    if ($nearbyActorsIncludeAppearance && isset($metaData["stats"]["scale"])) {
                        $heightDesc = getHeightDescription(floatval($metaData["stats"]["scale"]));
                        if (!empty($heightDesc)) {
                            $profileString = synthAppendNearbyActorSentence($profileString, $heightDesc);
                        }
                    }

                    // Power Awareness: Add relative power assessment
                    if ($nearbyActorsIncludePower && isset($GLOBALS["POWER_AWARENESS_ENABLED"]) && \Synth\Bootstrap::config("POWER_AWARENESS_ENABLED")) {
                        require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . "power_awareness.php");

                        // Get current NPC's level
                        $npcLevel = isset($metaData["stats"]["level"]) ? intval($metaData["stats"]["level"]) : null;

                        // Get assessing actor's level (the NPC looking at this person)
                        if (!empty($GLOBALS["SYNTH_NAME"])) {
                            $assessorLevel = getNpcLevel(\Synth\Bootstrap::config("SYNTH_NAME"));

                            if ($assessorLevel !== null && $npcLevel !== null) {
                                $powerComparison = calculatePowerComparison($assessorLevel, $npcLevel);
                                $profileString .= " ({$powerComparison})";
                            }
                        }
                    }

                    $activityStatus = synthNormalizeActivityStatus($metaData);
                    if ($nearbyActorsIncludeActivity && !empty($activityStatus['fresh']) && !empty($activityStatus['summary'])) {
                        $profileString = synthAppendNearbyActorSentence($profileString, "Current activity: " . $activityStatus['summary']);
                    }

                    // Add equipment if available
                    $equipmentLines = $nearbyActorsIncludeEquipment
                        ? synthBuildEquipmentLinesFromMetadata($metaData, $nearbyEquipmentDescriptionResolver)
                        : [];
                    if ($nearbyActorsIncludeEquipment && !empty($equipmentLines)) {
                        $equipmentText = implode(', ', array_map(static function ($line) {
                            return preg_replace('/^-\s*/', '', $line);
                        }, $equipmentLines));
                        $profileString = synthAppendNearbyActorSentence($profileString, "Equipment: " . $equipmentText);
                    }

                    if ($nearbyActorsIncludeCustomState) {
                        $profileExtra = synthBuildActorProfileEnrichmentText($npcName, "npc", [
                            "source" => "nearby_actors",
                            "metadata" => $metaData,
                            "npc_data" => $currentNpcData,
                        ]);
                        if ($profileExtra !== "") {
                            $profileString = synthAppendNearbyActorSentence($profileString, $profileExtra);
                        }
                    }

                    // Add faction information after equipment
                    $extendedData = $npcMaster->getExtendedData($currentNpcData);
                    if ($nearbyActorsIncludeFactions && isset($extendedData['factions']) && is_array($extendedData['factions']) && count($extendedData['factions']) > 0) {
                        $factionNames = [];
                        foreach ($extendedData['factions'] as $faction) {
                            if (isset($faction['formid'])) {
                                // Lookup faction using helper function (supports XX prefix)
                                $factionRecord = lookupDescriptionByFormID($faction['formid']);

                                // Only add if found in descriptions table
                                if ($factionRecord && !empty($factionRecord['name'])) {
                                    $factionNames[] = $factionRecord['name'];

                                    // Track faction description (only once)
                                    if (!in_array($faction['formid'], $seenFactionFormIDs)) {
                                        $seenFactionFormIDs[] = $faction['formid'];
                                        if (!empty($factionRecord['description'])) {
                                            $factionDescriptions[$factionRecord['name']] = $factionRecord['description'];
                                        }
                                    }
                                }
                            }
                        }

                        if (!empty($factionNames)) {
                            $profileString .= ". " . synth_protocol_faction_prompt_label($extendedData, count($factionNames)) . ": " . implode(", ", $factionNames);
                        }
                    }

                    $actorDetailedListWithProfile[] = $profileString;

                }
                else {
                    error_log("[DataLastInfoFor] Actors around, Checking NPC Name: " . $ittext. " with no profile data, actors in range: ".$actorsInRangeList);
                    $actorDetailedListWithProfile[] = $ittext;
                }

            }
        }
        $actorDetailedListWithProfileSanitized=[];
        foreach ($actorDetailedListWithProfile as $e)
            if (!empty($e))
                $actorDetailedListWithProfileSanitized[]=$e;

        if (!empty($actorDetailedListWithProfileSanitized))
            $actorsInRange=implode("\n## ",$actorDetailedListWithProfileSanitized);
        else
            $actorsInRange="\nNo more actors in scene";// Catch
    }


    //Followers

    foreach (json_decode(DataGetCurrentPartyConf(),JSON_OBJECT_AS_ARRAY) as $followername=>$followerdata) {
        if (!$followername)
            continue;

        if ($followername==\Synth\Bootstrap::config("PLAYER_NAME")) {
            $followers[]="$followername (roleplayed by player)";

        } else {
            if (isset($followerdata["core"]))
                $followers[]="{$followerdata["core"]} level {$followerdata["level"]},{$followerdata["gender"]} {$followerdata["race"]}";
            else
                $followers[]="$followername, level {$followerdata["level"]},{$followerdata["gender"]} {$followerdata["race"]}";

            $followersV2[]=$followername;

        }

    }

    $followers[]="{$GLOBALS["PLAYER_NAME"]}";
    $followersV2[]=\Synth\Bootstrap::config("PLAYER_NAME");

    if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
    }
    $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<nearby_actors>\n# NEARBY ACTORS/NPC IN THE SCENE \n## $actorsInRange\n</nearby_actors>";

    // Add faction descriptions section if any factions were found
    if (!empty($factionDescriptions)) {
        $factionDescText = "";
        foreach ($factionDescriptions as $name => $desc) {
            $factionDescText .= "## {$name}: {$desc}\n";
        }
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<group_descriptions>\n# GROUP/FACTION DESCRIPTIONS\n{$factionDescText}</group_descriptions>";
    }

    // Add nearby items to context if available
    $itemsInRange = DataItemsInCloseRange();

    if (!empty($itemsInRange)) {
        $itemsList = explode(',', $itemsInRange);
        $formattedItems = [];
        $seenBaseIDs = [];
        $itemDescriptions = [];
        $groupedItems = [];
        $playerName = \Synth\Bootstrap::config("PLAYER_NAME") ?? "Player";
        $playerLookingTag = " ({$playerName} is looking at this)";
        $playerHoldingTag = " ({$playerName} is holding this)";
        $shorterNearbyItemList = !empty($GLOBALS["SHORTER_NEARBY_ITEM_LIST"]);

        foreach ($itemsList as $item) {
            $trimmedItem = trim($item);
            if (empty($trimmedItem)) continue;

            // Parse nearby item strings derived from structured game data.
            $parts = explode(':', $trimmedItem, 3);

            if (count($parts) >= 3) {
                // RefID, BaseID, and item name.
                $refID = $parts[0];
                $baseID = $parts[1];
                $itemName = $parts[2];

                // Strip prompt-only tags for blacklist and description lookup.
                $itemNameClean = str_replace([' (STEALING)', $playerLookingTag, $playerHoldingTag], '', $itemName);

                // Skip blacklisted items
                if (isItemBlacklisted($itemNameClean)) {
                    continue;
                }

                // Track unique base IDs for descriptions
                $hasDescription = false;
                if (!in_array($baseID, $seenBaseIDs)) {
                    $seenBaseIDs[] = $baseID;

                    // Look up descriptions through the shared form ID resolver.
                    $descRecord = lookupDescriptionByFormID($baseID);

                    if ($descRecord && !empty($descRecord['description'])) {
                        // Store description under clean name (without STEALING tag)
                        $itemDescriptions[$itemNameClean] = $descRecord['description'];
                        $hasDescription = true;
                    }
                }

                // If filter is enabled and item has no description, skip it
                if (isset($GLOBALS["GROUND_ITEMS_DESCRIPTIONS_ONLY"]) && \Synth\Bootstrap::config("GROUND_ITEMS_DESCRIPTIONS_ONLY") && !$hasDescription) {
                    continue;
                }

                if ($shorterNearbyItemList) {
                    $groupKey = $itemName;
                    if (!isset($groupedItems[$groupKey])) {
                        $groupedItems[$groupKey] = [
                            'count' => 0,
                            'sample_refid' => $refID,
                            'sample_baseid' => $baseID,
                            'sample_item_name' => $itemName,
                            'description' => $itemDescriptions[$itemNameClean] ?? '',
                        ];
                    }
                    $groupedItems[$groupKey]['count']++;
                    if (empty($groupedItems[$groupKey]['description']) && !empty($itemDescriptions[$itemNameClean])) {
                        $groupedItems[$groupKey]['description'] = $itemDescriptions[$itemNameClean];
                    }
                } else {
                    $safeName = synthEscapePromptItemText($itemName);
                    $normalizedRefId = synthNormalizePromptFormId($refID);
                    $normalizedBaseId = synthNormalizePromptFormId($baseID);
                    $displayItem = $normalizedRefId !== null
                        ? "- `{$normalizedRefId}:{$safeName}`"
                        : "- {$safeName}";
                    if ($normalizedBaseId !== null) {
                        $displayItem .= " [BaseID: `{$normalizedBaseId}`]";
                    }
                    $formattedItems[] = $displayItem;
                }
            } elseif (count($parts) == 2) {
                // RefID and item name only.
                $refID = $parts[0];
                $itemName = $parts[1];

                // Strip prompt-only tags for blacklist checks.
                $itemNameClean = str_replace([' (STEALING)', $playerLookingTag, $playerHoldingTag], '', $itemName);

                // Skip blacklisted items
                if (isItemBlacklisted($itemNameClean)) {
                    continue;
                }

                if ($shorterNearbyItemList) {
                    $groupKey = $itemName;
                    if (!isset($groupedItems[$groupKey])) {
                        $groupedItems[$groupKey] = [
                            'count' => 0,
                            'sample_refid' => $refID,
                            'sample_item_name' => $itemName,
                            'description' => '',
                        ];
                    }
                    $groupedItems[$groupKey]['count']++;
                } else {
                    $safeName = synthEscapePromptItemText($itemName);
                    $normalizedRefId = synthNormalizePromptFormId($refID);
                    $displayItem = $normalizedRefId !== null
                        ? "- `{$normalizedRefId}:{$safeName}`"
                        : "- {$safeName}";
                    $formattedItems[] = $displayItem;
                }
            }
        }

        if ($shorterNearbyItemList && !empty($groupedItems)) {
            foreach ($groupedItems as $group) {
                $safeName = synthEscapePromptItemText($group['sample_item_name']);
                $normalizedRefId = synthNormalizePromptFormId($group['sample_refid']);
                $normalizedBaseId = synthNormalizePromptFormId($group['sample_baseid'] ?? '');
                $displayItem = $normalizedRefId !== null
                    ? "- `{$normalizedRefId}:{$safeName}` ({$group['count']})"
                    : "- {$safeName} ({$group['count']})";
                if ($normalizedBaseId !== null) {
                    $displayItem .= " [BaseID: `{$normalizedBaseId}`]";
                }
                if (!empty($group['description'])) {
                    $displayItem .= ' - ' . synthEscapePromptItemText($group['description']);
                }
                $formattedItems[] = $displayItem;
            }
        }

        if (!empty($formattedItems)) {
            $itemsText = implode("\n", $formattedItems);

            // Add descriptions for unique items if available
            $descriptionText = "";
            if ($shorterNearbyItemList) {
                // Grouped items already carry their description inline.
            } elseif (!empty($itemDescriptions)) {
                $descParts = [];
                foreach ($itemDescriptions as $name => $desc) {
                    $descParts[] = synthEscapePromptItemText($name) . ': ' . synthEscapePromptItemText($desc);
                }
                $descriptionText = "\n\n# ITEM DESCRIPTIONS\n## " . implode("\n## ", $descParts);
            }

            $nearbyItemsHeader = $shorterNearbyItemList
                ? "# NEARBY ITEMS\nFormat: RefID:ItemName (quantity); use the RefID for PickupItem"
                : "# NEARBY ITEMS\nFormat: RefID:ItemName; use the RefID for PickupItem";
            $contextContent = "<nearby_items>\n{$nearbyItemsHeader}\n\n{$itemsText}{$descriptionText}\n</nearby_items>";
            if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
                $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
            }
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n" . $contextContent;
        }
    }

    /*
    if (!isset($GLOBALS["IS_NPC"]) || !$GLOBALS["IS_NPC"])
        $lastDialog[] = array('role' => 'user', 'content' => "# PARTY STATUS\n## ". (implode("\n## ",$followers)));
    else
        $lastDialog[] = array('role' => 'user', 'content' => "# YOU'RE NOT PART OF THE GROUP FORMED BY\n## ". (implode("\n## ",$followers)));

    $arr_poi = DataPosibleLocationsToGo();
    if (isset($arr_poi) && is_array($arr_poi) && (count($arr_poi) > 0)) {
        $lastDialog[] = array('role' => 'user', 'content' => "# POIs - Points of Interest nearby \n## ". (implode("\n## ",$arr_poi)));
    }
    */
    if (!empty($followersV2)) {
        $lastFollower = array_pop($followersV2);
        if (!empty($followersV2)) {
            $followersString = implode(", ", $followersV2) . " and " . $lastFollower;
        } else {
            $followersString = $lastFollower;
        }
    } else {
        $followersString = "";
    }

	if ($followersString!=\Synth\Bootstrap::config("PLAYER_NAME") && !empty($followersString)) {
	    if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
	        $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
	    }
	    $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<adventuring_party>
        # ADVENTURING PARTY
	     $followersString are together as an **adventuring party**, acting as close companions.
	     - The others **can know each other**, but they are **not part** of {$followersString}'s group.
	     - Generally speaking, any mention of **plans, missions, or objectives** refers **only to the adventuring party**, never to the other NPCs.
	     </adventuring_party>";
	}
    $arr_poi = DataPosibleLocationsToGo();
    if (isset($arr_poi) && is_array($arr_poi) && (count($arr_poi) > 0)) {
        // Filter blacklisted locations
        if (isset($GLOBALS["LOCATION_BLACKLIST"]) && !empty($GLOBALS["LOCATION_BLACKLIST"])) {
            $blacklistedLocations = array_map('trim', explode(',', strtolower(\Synth\Bootstrap::config("LOCATION_BLACKLIST"))));
            $arr_poi = array_filter($arr_poi, function($poi) use ($blacklistedLocations) {
                $poiLower = strtolower($poi);
                foreach ($blacklistedLocations as $blacklistedLocation) {
                    if (!empty($blacklistedLocation) && strpos($poiLower, $blacklistedLocation) !== false) {
                        return false;
                    }
                }
                return true;
            });
        }

        if (count($arr_poi) > 0) {
            if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
                $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
            }
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<points_of_interest>\n# POIs - Points of Interest nearby \n## ". (implode("\n## ",$arr_poi))."\n</points_of_interest>";
        }
    }



    // Rolemaster notes

    $timeCut=time();
    $rolemasterNotes=\Synth\Bootstrap::db()->fetchAll("SELECT data FROM core_director_note where type='scenenote' and localts+ttl>$timeCut order by localts asc");
    if (is_array($rolemasterNotes) && !empty($rolemasterNotes)) {
        $notes=[];
        foreach ($rolemasterNotes as $note)
            $notes[]= $note["data"];
        if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
        }
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<scene_notes>\n# SCENE NOTES \n## ".implode(".",$notes)."</scene_notes>";
    }

    // Compact CHIM-style nearby actor list from the latest structured game snapshot.
    $nearbyActorsList = !empty($GLOBALS["SYNTH_ROLEMASTER_BORED_ACTORS"]) &&
        is_array(\Synth\Bootstrap::config("SYNTH_ROLEMASTER_BORED_ACTORS"))
        ? array_values(\Synth\Bootstrap::config("SYNTH_ROLEMASTER_BORED_ACTORS"))
        : synthNearbyActorNamesFromPayload(false, false);
    if (!empty($nearbyActorsList)) {
        if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
            $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
        }
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= "\n<actors_nearby>\n" . implode(", ", $nearbyActorsList) . "\n</actors_nearby>";
    }


    $lastDialog=[];
    // This function originally returned an array, now it's directly filling PROMPT_NEARBY_SECTIONS.
    // MUST return an array, even if empty; Review where is called to ensure it's handled properly
    // Proposal: $lastDialog[]=array('role' => 'user', 'content' => $GLOBALS["PROMPT_NEARBY_SECTIONS"]);
    return $lastDialog;

}

function DataLocationsAround($current_location = "") {
    $locations = DataPosibleLocationsToGo();
    if (!is_array($locations) || empty($locations)) {
        return "";
    }

    if (strlen($current_location) > 0) {
        $currentLocation = strtolower(trim($current_location));
        $locations = array_values(array_filter($locations, static function ($location) use ($currentLocation) {
            return stripos((string)$location, $currentLocation) !== false;
        }));
    }

    return implode(",", $locations);
}

function DataPosibleLocationsToGo()
{
    if (isset($GLOBALS["CACHE_POSIBLE_LOCATIONS_TO_GO"])) {
        return \Synth\Bootstrap::config("CACHE_POSIBLE_LOCATIONS_TO_GO");
    }

    $retData = synthPointsOfInterestFromStructuredPayload();

    // Location blacklist // $LOCATION_BLACKLIST
    if (isset($GLOBALS["LOCATION_BLACKLIST"]) && (strlen(\Synth\Bootstrap::config("LOCATION_BLACKLIST"))>0)) {
        $LOCATION_BLACKLIST_ARRAY = explode(",", \Synth\Bootstrap::config("LOCATION_BLACKLIST"));
        //$LOCATION_BLACKLIST_ARRAY = empty($GLOBALS["LOCATION_BLACKLIST"]) ? [] : explode(",", $GLOBALS["LOCATION_BLACKLIST"]);
        if (count($LOCATION_BLACKLIST_ARRAY) > 0) {
            foreach ($retData as $k => $v) {
                foreach ($LOCATION_BLACKLIST_ARRAY as $blacklistedLocation) {
                    $blacklistedLocationTrimmed = trim($blacklistedLocation);
                    if (!empty($blacklistedLocationTrimmed) && (stripos($v, $blacklistedLocationTrimmed) !== false)) {
                        unset($retData[$k]);
                        break; // No need to check other blacklisted locations
                    }
                }
            }
        }
    }

    foreach ($retData as $k => $v) {
        if ($v=="Fallout") {
            $retData[$k].=" (exit)";
        }
    }
    $GLOBALS["CACHE_POSIBLE_LOCATIONS_TO_GO"] = array_values($retData);
    return \Synth\Bootstrap::config("CACHE_POSIBLE_LOCATIONS_TO_GO");
}

function synthLatestPointsOfInterestPayload()
{
    $db = \Synth\Bootstrap::db();

    $rows = $db->fetchAll("SELECT party FROM core_context_event WHERE type='points_of_interest' AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0");
    if (!is_array($rows) || empty($rows)) {
        return null;
    }

    $payload = json_decode($rows[0]['party'] ?? '', true);
    return is_array($payload) ? $payload : null;
}

function synthPointsOfInterestFromStructuredPayload()
{
    $payload = synthLatestPointsOfInterestPayload();
    if (!is_array($payload)) {
        return [];
    }

    $pois = $payload['pois'] ?? [];
    if (!is_array($pois) || empty($pois)) {
        return [];
    }

    $playerName = trim((string)($payload['player'] ?? (\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player")));
    $retData = [];
    foreach ($pois as $poi) {
        if (!is_array($poi)) {
            continue;
        }

        $displayName = trim((string)($poi['display_name'] ?? ''));
        if ($displayName === "") {
            $displayName = trim((string)($poi['destination_name'] ?? ''));
        }
        if ($displayName === "") {
            $displayName = trim((string)($poi['name'] ?? ''));
        }
        if ($displayName === "" || $displayName === "Unknown") {
            continue;
        }

        if (filter_var($poi['locked'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $displayName .= " (locked)";
        }
        if (filter_var($poi['looking_at'] ?? false, FILTER_VALIDATE_BOOLEAN) && $playerName !== "") {
            $displayName .= " ({$playerName} is looking at this)";
        }

        if (!in_array($displayName, $retData, true)) {
            $retData[] = $displayName;
        }
    }

    return $retData;
}

function DataPosibleMoveToTargets()
{
    if (isset($GLOBALS["CACHE_POSIBLE_MOVETO_TARGETS"])) {
        return \Synth\Bootstrap::config("CACHE_POSIBLE_MOVETO_TARGETS");
    }

    $retData = synthNearbyActorNamesFromPayload(true, false);

    $GLOBALS["CACHE_POSIBLE_MOVETO_TARGETS"] = array_values($retData);
    return \Synth\Bootstrap::config("CACHE_POSIBLE_MOVETO_TARGETS");
}

function DataPosibleInspectTargets($pack=true)
{
    if (isset($GLOBALS["CACHE_POSIBLE_INSPECT_TARGETS"][(int)$pack])) {
        return \Synth\Bootstrap::config("CACHE_POSIBLE_INSPECT_TARGETS")[(int)$pack];
    }

    $retData = synthNearbyActorNamesFromPayload(false, false);

    $compData=[];

    if ($pack) {
        foreach ($retData as $k => $v) {
            if (strlen($v) < 2) {
                unset($retData[$k]);
            } else {
                $retData[$k] = preg_replace("/\([^)]+\)/", '', $v);
                $retData[$k] = $v;
                if (!isset($compData[$v]))
                    $compData[$v]=0;
                $compData[$v]++; // Reduce same names (Chicken, Chicken -> Chicken)
                //$retData[$k]=$v;

            }

        }
        $retData=[];
        foreach ($compData as $l=>$n) {
            if ($n==1)
                $retData[]="$l";
            else
                $retData[]="$n $l";
        }


    }

    $GLOBALS["CACHE_POSIBLE_INSPECT_TARGETS"][(int)$pack] = array_values($retData);
    return \Synth\Bootstrap::config("CACHE_POSIBLE_INSPECT_TARGETS")[(int)$pack];
}

function synthDescribeConditionStat(string $kind, float $cur, float $max): string
{
    if ($max <= 0) {
        return "Unknown";
    }

    $pct = ($cur < 0 ? 0.0 : ($cur > $max ? $max : $cur)) / $max * 100.0;
    if ($kind === 'health') {
        if ($pct >= 75.0) {
            return "Near full health";
        }
        if ($pct >= 50.0) {
            return "Wounded";
        }
        if ($pct >= 25.0) {
            return "Badly wounded";
        }
        return "On the brink of collapse";
    }

    if ($kind === 'action_points') {
        if ($pct >= 75.0) {
            return "Ready";
        }
        if ($pct >= 50.0) {
            return "Partly spent";
        }
        if ($pct >= 25.0) {
            return "Low";
        }
        return "Nearly spent";
    }

    if ($pct >= 75.0) {
        return "Strong";
    }
    if ($pct >= 50.0) {
        return "Winded";
    }
    if ($pct >= 25.0) {
        return "Exhausted";
    }
    return "Spent";
}

function synthBuildCurrentConditionLinesFromMetadata($stats, array $metadata = [])
{
    $lines = [];

    if (is_array($stats) && !empty($stats)) {
        $health = synthDescribeConditionStat(
            'health',
            (float)($stats['health'] ?? 0),
            (float)($stats['health_max'] ?? 0)
        );
        $actionPoints = synthDescribeConditionStat(
            'action_points',
            (float)($stats['action_points'] ?? 0),
            (float)($stats['action_points_max'] ?? 0)
        );

        if ($health !== 'Unknown') {
            $lines[] = "- Health: {$health}";
        }
        if ($actionPoints !== 'Unknown') {
            $lines[] = "- Action Points: {$actionPoints}";
        }
        if (isset($stats['karma']) && is_numeric($stats['karma'])) {
            $karma = (int)$stats['karma'];
            if ($karma >= 250) {
                $lines[] = "- Karma: Good";
            } elseif ($karma <= -250) {
                $lines[] = "- Karma: Evil";
            } else {
                $lines[] = "- Karma: Neutral";
            }
        }
    }

    $activityStatus = synthNormalizeActivityStatus($metadata);
    if (!empty($activityStatus['is_dead'])) {
        $lines[] = "- State: Dead";
    } elseif (!empty($activityStatus['is_unconscious'])) {
        $lines[] = "- State: Unconscious";
    } elseif (!empty($activityStatus['is_in_combat'])) {
        $lines[] = "- State: In combat";
    }
    if (!empty($activityStatus['is_weapon_drawn'])) {
        $lines[] = "- Weapon: Drawn";
    }

    return $lines;
}

function synthBuildCurrentConditionBlockFromMetadata($stats, array $metadata = [])
{
    $lines = synthBuildCurrentConditionLinesFromMetadata($stats, $metadata);
    if (empty($lines)) {
        return '';
    }

    return "<condition>\n#Condition\n" . implode("\n", $lines) . "\n</condition>";
}

function synthPlayerSurvivalStageLabel(string $kind, int $stage): string
{
    $labels = [
        'hunger' => [
            1 => 'Peckish', 2 => 'Hungry', 3 => 'Starving',
            4 => 'Critically starving', 5 => 'Dying of starvation',
        ],
        'dehydration' => [
            1 => 'Thirsty', 2 => 'Dehydrated', 3 => 'Severely dehydrated',
            4 => 'Critically dehydrated', 5 => 'Dying of dehydration',
        ],
        'sleep_deprivation' => [
            1 => 'Tired', 2 => 'Sleep deprived', 3 => 'Severely sleep deprived',
            4 => 'Critically sleep deprived', 5 => 'Near collapse from sleep deprivation',
        ],
        'radiation' => [
            1 => 'Minor radiation poisoning', 2 => 'Advanced radiation poisoning',
            3 => 'Critical radiation poisoning', 4 => 'Deadly radiation poisoning',
            5 => 'Fatal radiation exposure',
        ],
    ];

    return $labels[$kind][max(0, min(5, $stage))] ?? '';
}

function synthIsPlayerSurvivalStateFresh(array $survival, int $staleSeconds = 180, ?int $now = null): bool
{
    $updatedAt = max(0, (int)($survival['updated_at'] ?? $survival['captured_at'] ?? 0));
    if ($updatedAt <= 0) {
        return false;
    }

    return (($now ?? time()) - $updatedAt) <= max(1, $staleSeconds);
}

function synthGetFreshPlayerSurvivalState(int $staleSeconds = 180): ?array
{
    try {
        require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'player.class.php');
        $player = new Player();
        $survival = $player->getJson('survival');
    } catch (Throwable $e) {
        Logger::debug('Could not read player survival state: ' . $e->getMessage());
        return null;
    }

    if (!is_array($survival) || empty($survival)) {
        return null;
    }

    if (!synthIsPlayerSurvivalStateFresh($survival, $staleSeconds)) {
        return null;
    }

    return $survival;
}

function synthPlayerSurvivalEntries(?array $survival = null): array
{
    $survival = $survival ?? synthGetFreshPlayerSurvivalState();
    if (!is_array($survival)) {
        return [];
    }

    $entries = [];
    if (!empty($survival['hardcore_enabled'])) {
        $needs = is_array($survival['needs'] ?? null) ? $survival['needs'] : [];
        foreach ([
            'hunger' => 'Hunger',
            'dehydration' => 'Thirst',
            'sleep_deprivation' => 'Sleep',
        ] as $key => $label) {
            $stage = max(0, min(5, (int)($needs[$key]['stage'] ?? 0)));
            $description = synthPlayerSurvivalStageLabel($key, $stage);
            if ($description !== '') {
                $entries[] = [
                    'key' => $key,
                    'label' => $label,
                    'description' => $description,
                ];
            }
        }
    }

    $radiation = is_array($survival['radiation'] ?? null) ? $survival['radiation'] : [];
    $radiationStage = max(0, min(5, (int)($radiation['stage'] ?? 0)));
    $radiationDescription = synthPlayerSurvivalStageLabel('radiation', $radiationStage);
    if ($radiationDescription !== '') {
        $entries[] = [
            'key' => 'radiation',
            'label' => 'Radiation',
            'description' => $radiationDescription,
        ];
    }

    return $entries;
}

function synthDescribePlayerSurvivalState(?array $survival = null): string
{
    $descriptions = array_map(static function (array $entry): string {
        return strtolower((string)($entry['description'] ?? ''));
    }, synthPlayerSurvivalEntries($survival));

    return implode(', ', array_values(array_filter($descriptions)));
}

function synthBuildPlayerSurvivalConditionBlock(?array $survival = null): string
{
    if (!synthPromptContextSectionEnabled('enabled_appearance_subsections', 'current_condition')) {
        return '';
    }

    $lines = array_map(static function (array $entry): string {
        return '- ' . $entry['label'] . ': ' . $entry['description'];
    }, synthPlayerSurvivalEntries($survival));

    if (empty($lines)) {
        return '';
    }

    $playerName = trim((string)(\Synth\Bootstrap::config('PLAYER_NAME') ?? 'The Sole Survivor'));
    if ($playerName === '') {
        $playerName = 'The Sole Survivor';
    }
    return "\n\n<condition>\n#{$playerName}'s Condition\n" . implode("\n", $lines) . "\n</condition>\n";
}

function synthPlayerSurvivalProfileEnricher(string $actorName, string $actorType, array $context = []): string
{
    if (strcasecmp(trim($actorType), 'player') !== 0
        || !empty($GLOBALS['SYNTH_SUPPRESS_PLAYER_SURVIVAL_NEARBY'])
        || !synthPromptContextSectionEnabled('enabled_appearance_subsections', 'current_condition')) {
        return '';
    }

    $description = synthDescribePlayerSurvivalState();
    return $description === '' ? '' : 'Survival: ' . $description;
}

function synthPromptContextSectionEnabled(string $bucket, string $id): bool
{
    if (function_exists('synthPromptContextOptionEnabled')) {
        return synthPromptContextOptionEnabled($bucket, $id);
    }
    return true;
}

function synthFO4EquipmentSlotLabel(string $slot): string
{
    $labels = [
        'face' => 'Face',
        'head' => 'Head',
        'hair' => 'Head',
        'body' => 'Body',
        'upper_body' => 'Body',
        'left_hand' => 'Left Hand',
        'right_hand' => 'Right Hand',
        'weapon' => 'Weapon',
        'pip_boy' => 'Pip-Boy',
        'backpack' => 'Backpack',
        'necklace' => 'Necklace',
        'headband' => 'Headband',
        'hat' => 'Hat',
        'eyeglasses' => 'Eyeglasses',
        'nosering' => 'Nose Ring',
        'earrings' => 'Earrings',
        'mask' => 'Mask',
        'choker' => 'Choker',
        'mouth_object' => 'Mouth Object',
        'body_addon_1' => 'Body Addon 1',
        'body_addon_2' => 'Body Addon 2',
        'body_addon_3' => 'Body Addon 3',
        'upper_body_addon' => 'Upper Body Addon',
        'lower_body_addon' => 'Lower Body Addon',
    ];

    return $labels[$slot] ?? ucwords(str_replace('_', ' ', $slot));
}

function synthNormalizeFO4EquipmentSlots(array $equipment): array
{
    $hasOldHairSlot = array_key_exists('hair', $equipment);
    $hasOldBodySlot = array_key_exists('upper_body', $equipment);
    $looksLikeOldFO4SlotNames = $hasOldHairSlot || $hasOldBodySlot;

    if ($looksLikeOldFO4SlotNames && !array_key_exists('face', $equipment) && array_key_exists('head', $equipment)) {
        $equipment['face'] = $equipment['head'];
        unset($equipment['head']);
    }

    if (!array_key_exists('head', $equipment) && $hasOldHairSlot) {
        $equipment['head'] = $equipment['hair'];
        unset($equipment['hair']);
    }

    if (!array_key_exists('body', $equipment) && $hasOldBodySlot) {
        $equipment['body'] = $equipment['upper_body'];
        unset($equipment['upper_body']);
    }

    return $equipment;
}

function synthFormatEquipmentCondition($condition): string
{
    if ($condition === null || $condition === '' || !is_numeric($condition)) {
        return '';
    }

    $value = (float)$condition;
    if ($value < 0) {
        return '';
    }
    if ($value <= 1.0) {
        return 'condition ' . (int)round($value * 100.0) . '%';
    }

    return 'condition ' . (int)round($value);
}

function synthBuildEquipmentLinesFromMetadata(array $metadata, ?callable $getItemDescription = null): array
{
    $equipment = [];
    if (isset($metadata['equipment_structured']) && is_array($metadata['equipment_structured'])) {
        $equipment = $metadata['equipment_structured'];
    } elseif (isset($metadata['equipment']) && is_array($metadata['equipment'])) {
        foreach ($metadata['equipment'] as $slot => $value) {
            $slot = (string)$slot;
            if (str_ends_with($slot, '_baseid') || str_ends_with($slot, '_slot') || str_ends_with($slot, '_condition')) {
                continue;
            }

            $equipment[$slot] = is_array($value)
                ? $value
                : [
                    'name' => (string)$value,
                    'baseid' => (string)($metadata['equipment'][$slot . '_baseid'] ?? ''),
                    'slot' => $metadata['equipment'][$slot . '_slot'] ?? null,
                    'condition' => $metadata['equipment'][$slot . '_condition'] ?? null,
                ];
        }
    }

    if (empty($equipment)) {
        return [];
    }
    $equipment = synthNormalizeFO4EquipmentSlots($equipment);

    $lines = [];
    $describedBaseids = [];
    foreach ($equipment as $slot => $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string)($item['name'] ?? ''));
        if ($name === '' || $name === '<Missing Name>' || $name === '<no name>' || isItemBlacklisted($name)) {
            continue;
        }

        $baseid = trim((string)($item['baseid'] ?? ''));
        $detailParts = [];
        $conditionText = synthFormatEquipmentCondition($item['condition'] ?? null);
        if ($conditionText !== '') {
            $detailParts[] = $conditionText;
        }

        if ($getItemDescription !== null && $baseid !== '' && !in_array($baseid, $describedBaseids, true)) {
            $description = $getItemDescription($name, $baseid);
            if (!empty($description)) {
                $detailParts[] = (string)$description;
                $describedBaseids[] = $baseid;
            }
        }

        $line = '- ' . synthFO4EquipmentSlotLabel((string)$slot) . ": {$name}";
        if (!empty($detailParts)) {
            $line .= ' (' . implode('; ', $detailParts) . ')';
        }
        $lines[] = $line;
    }

    return $lines;
}

function synthBuildEquipmentBlockFromMetadata(array $metadata, ?callable $getItemDescription = null, string $actorName = ''): string
{
    $lines = synthBuildEquipmentLinesFromMetadata($metadata, $getItemDescription);
    if (empty($lines)) {
        return '';
    }

    $heading = trim($actorName) !== ''
        ? "#{$actorName}'s Current Equipment"
        : '#Current Equipment';

    return "\n<equipment>\n{$heading}\nCurrently wearing/wielding:\n" . implode("\n", $lines) . "\n</equipment>";
}

function synthBuildNpcInspectSummary(string $npcName)
{
    $npcName = trim($npcName);
    if ($npcName === '') {
        return '';
    }

    $npcMaster = new NpcMaster();
    $currentNpcData = $npcMaster->getByName($npcName);
    if (!is_array($currentNpcData) || empty($currentNpcData)) {
        return '';
    }

    $metaData = $npcMaster->getMetaData($currentNpcData);
    if (!is_array($metaData)) {
        $metaData = [];
    }

    $sections = [];

    $conditionBlock = synthBuildCurrentConditionBlockFromMetadata($metaData['stats'] ?? null, $metaData);
    if ($conditionBlock !== '') {
        $sections[] = $conditionBlock;
    }

    $activityStatus = synthNormalizeActivityStatus($metaData);
    if (!empty($activityStatus['summary'])) {
        $sections[] = "<activity>\n#Activity\n" . ucfirst($activityStatus['summary']) . ".\n</activity>";
    }

    return implode("\n", $sections);
}

// Adapt the request-owned journal to legacy format without reading another session's mutable quest projection.
function synthSnapshotQuestRows(array $snapshot): array
{
    $rows = [];
    foreach (array_slice($snapshot['active_quests'] ?? [], 0, 16) as $quest) {
        $objectives = array_values(array_filter($quest['objectives'] ?? [],
            static fn($value): bool => is_string($value) && trim($value) !== ''));
        $briefing = $objectives !== [] ? implode('; ', array_slice($objectives, 0, 8))
            : 'Stage '.intval($quest['current_stage'] ?? 0).'; active objective count '.intval($quest['active_objectives'] ?? 0);
        $tracking = $quest['tracked'] ?? null;
        $briefing .= $tracking === true ? ' [Tracked by player]' :
            ($tracking === false ? ' [Not tracked by player]' : ' [Quest tracking unavailable]');
        $rows[] = ['id_quest' => $quest['form_id'], 'name' => $quest['display_name'],
            'briefing' => $briefing, 'description' => $briefing, 'notes' => '', 'status' => 'active'];
    }
    return $rows;
}

function DataQuestJournal($quest)
{
    $db = \Synth\Bootstrap::db();
    $event=\Synth\Bootstrap::requestEvent()['json'] ?? [];
    if (($event['protocol_version'] ?? 1)===2) {
        require_once dirname(__DIR__, 3).'/lib'.'/quest_knowledge.php';
        $context=synth_protocol_current_context($db,$event);
        $view=synthQuestKnowledgeView($db,$event,$context ?? [],null,
            synth_protocol_quest_response_is_narrator($db,$event));
        // The same bounded, attributed view is used by the tool and by ordinary dialogue.
        return json_encode(['quest_knowledge'=>synthQuestKnowledgePrompt($view)],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    $snapshot = synth_protocol_prompt_snapshot($db);
    if (empty($quest)||($quest=="None")||true) {

        $results = $snapshot !== null ? synthSnapshotQuestRows($snapshot)
            : $db->fetchAll("SElECT name,id_quest,briefing,briefing2 as notes, status FROM core_quest ORDER BY CASE WHEN status='selected' THEN 0 ELSE 1 END, gamets DESC");
        $finalRow = [];
        foreach ($results as $row) {
            if (isset($finalRow[$row["id_quest"]])) {
                continue;
            } else {
                $finalRow[$row["id_quest"]] = ["name"=>$row["name"],"briefing"=>$row["briefing"],"personal notes"=>$row["notes"],"status"=>$row["status"]];
            }
        }

        if (sizeof($finalRow) == 0) {
            $data[] = $snapshot !== null && !array_key_exists('active_quests', $snapshot)
                ? 'Quest journal observation unavailable.' : 'no active quests';
        } else {
            $data = array_values($finalRow);
        }

        $extraData = DataGetCurrentTask();

        $data[] = ["side note" => "$extraData"];

        return json_encode($data);

    } else {
        $lastDialogFull = array();
        $results = $db->fetchAll("SElECT  name,id_quest,briefing,data
      FROM core_quest where lower(id_quest)=lower('$quest') or lower(name)=lower('$quest') ");
        $lastOne = -1;
        $data = array();
        if (!$results) {
            $data["error"] = "quest not found, make sure you use id_quest";
            return json_encode($data);

        }
        foreach ($results as $row) {
            $lastOne++;
            $data[] = $row;
        }
        if ($lastOne >= 0) {
            $data[$lastOne]["stage_completed"] = "no";
        }

        if (sizeof($data) == 0) {
            $data["error"] = "quest not found, make sure you use id_quest";

        }

        return json_encode($data);

    }
}

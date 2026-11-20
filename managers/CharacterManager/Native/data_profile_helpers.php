<?php

// Moved from lib/data_functions.php: animation/expression helpers, profile builders and php variable export
// The original file requires this one, so every caller is unchanged.

function GetAnimationHex($mood)
{
    $mood = extractFirstEmoteMood($mood);
    if ($mood === '') {
        return "";
    }

    //error_log("Getting animation for mood: $mood");
    $ANIMATIONS=[
        "ArmsCrossed"=>"IdleExamine",        // Arms crossed
        "PointClose"=>"IdlePointClose",
        "HandsBehindBack"=>"IdleHandsBehindBack",    // 000B240A ? // Arms behind back
        //"DrawAttention"=>"0x0006FF15",     // Continous
        //"Cheer"=>"0x00066374",             // Continous
        "ApplauseSarcastic"=>"IdleApplaudSarcastic",  // Continous
        "WaveHand"=>"IdleWave",
        "Nervous"=>"IdleNervous",
        "ArmsRaised"=>"IdleSurrender",
        "NervousDialogue"=>"IdleDialogueMovingTalkA",
        "NervousDialogue1"=>"IdleDialogueMovingTalkB",
        "NervousDialogue2"=>"IdleDialogueMovingTalkC",
        "NervousDialogue3"=>"IdleDialogueMovingTalkD",
        "Cheer"=>"SpectatorCheer",
        "ComeThisWay"=>"IdleComeThisWay",
        "SarcasticMove"=>"IdleDialogueExpressiveStart",
        "Applause1"=>"IdleApplaud2",
        "Applause2"=>"IdleApplaud3",
        "Applause3"=>"IdleApplaud4",
        "Applause4"=>"IdleApplaud5",
        "DrinkPotion"=>"IdleDrinkPotion",        // Don't use while talking
        "PointFar"=>"IdlePointFar_01",
        "PointFar2"=>"IdlePointFar_02",
        "GiveSomething"=>"IdleGive",
        "TakeSomething"=>"IdleTake",
        "Salute"=>"IdleSalute",
        "CleanSweat"=>"IdleWipeBrow",
        "NoteRead"=>"IdleNoteRead",
        "LookFar"=>"IdleLookFar",
        "Laugh"=>"IdleLaugh",
        "CleanSword"=>"IdleCleanSword",
        "WarmArms"=>"IdleWarmArms",
        "Positive"=>"LooseDialogueResponsePositive",
        "Negative"=>"LooseDialogueResponseNegative",
        "HappyDialogue"=>"IdleDialogueHappyStart",
        "AngryDialogue"=>"IdleDialogueAngryStart",
        "Agitated"=>"IdleDialogueAngryStart",
        "HandOnChinGesture"=>"IdleDialogueHandOnChinGesture",

    ];

    if ($mood=="sarcastic") {
        return array_rand(array_flip([$ANIMATIONS["SarcasticMove"],$ANIMATIONS["CleanSweat"],$ANIMATIONS["Agitated"],$ANIMATIONS["ApplauseSarcastic"]]), 1);


    } else if ($mood=="sassy") {
        return array_rand(array_flip([$ANIMATIONS["SarcasticMove"],$ANIMATIONS["CleanSweat"],$ANIMATIONS["Agitated"],$ANIMATIONS["ApplauseSarcastic"]]), 1);


    } else if ($mood=="sardonic") {
        return array_rand(array_flip([$ANIMATIONS["SarcasticMove"],$ANIMATIONS["CleanSweat"],$ANIMATIONS["Agitated"],$ANIMATIONS["ApplauseSarcastic"]]), 1);


    } else if ($mood=="irritated") {
        return array_rand(array_flip([$ANIMATIONS["PointClose"],$ANIMATIONS["Negative"],$ANIMATIONS["AngryDialogue"]]), 1);


    } else if ($mood=="mocking") {
        return array_rand(array_flip([$ANIMATIONS["Applause1"],$ANIMATIONS["Applause2"],$ANIMATIONS["Applause3"],$ANIMATIONS["Applause4"]]), 1);


    } else if ($mood=="playful") {
        return array_rand(array_flip([$ANIMATIONS["Cheer"],$ANIMATIONS["HappyDialogue"],$ANIMATIONS["Positive"]]), 1);

    } else if ($mood=="teasing") {
        return array_rand(array_flip([$ANIMATIONS["NervousDialogue"],$ANIMATIONS["NervousDialogue1"],$ANIMATIONS["NervousDialogue2"],$ANIMATIONS["NervousDialogue3"]]), 1);


    } else if ($mood=="smug") {
        return $ANIMATIONS["Nervous"];


    } else if ($mood=="amused") {
        return $ANIMATIONS["ArmsRaised"];

    } else if ($mood=="smirking") {
        return $ANIMATIONS["Nervous"];


    } else if ($mood=="serious") {
        return array_rand(array_flip([$ANIMATIONS["CleanSweat"],$ANIMATIONS["PointClose"],$ANIMATIONS["HandOnChinGesture"]]), 1);


    } else if ($mood=="firm") {
        return array_rand(array_flip([$ANIMATIONS["CleanSweat"],$ANIMATIONS["PointClose"],$ANIMATIONS["HandOnChinGesture"]]), 1);


    } else if ($mood=="neutral") {
        return array_rand(array_flip([$ANIMATIONS["HappyDialogue"]]), 1);


    } else if ($mood=="drunk") {
        // No animation :(
        Logger::info("Using filter for mood drunk");
        $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"]='atempo=0.65';
        return "DrunkStart";

    } else if ($mood=="sober") {

        Logger::info("Resetting mood drunk.");

        return "DrunkStop";

    } else if ($mood=="high") {
        // No animation :(
        $GLOBALS["TTS_FFMPEG_FILTERS"]["tempo"]='atempo=1.45';

    }


    //error_log("Getting animation for mood: $mood, no result found");
    return "";

}

function GetExpression($mood) {
     $mood = extractFirstEmoteMood($mood);
     if ($mood === '') {
         return "";
     }
     $EXPRESSIONS=[
     "DialogueAnger",    "DialogueFear",    "DialogueHappy",     "DialogueSad",
     "DialogueSurprise", "DialoguePuzzled", "DialogueDisgusted", "MoodNeutral",
     "MoodAnger",        "MoodFear",        "MoodHappy",        "MoodSad",
     "MoodSurprise",    "MoodPuzzled",    "MoodDisgusted",    "CombatAnger",
     "CombatShout"
     ];

     $result="MoodNeutral";
     if ($mood=="sarcastic") {
        $result= array_rand(array_flip(["DialoguePuzzled"]), 1);


     } else if ($mood=="sassy") {
        $result= array_rand(array_flip(["DialoguePuzzled"]), 1);


     } else if ($mood=="sardonic") {
        $result= array_rand(array_flip(["DialoguePuzzled"]), 1);


     } else if ($mood=="irritated") {
        $result= array_rand(array_flip(["DialogueAnger"]), 1);


     } else if ($mood=="mocking") {
        $result= array_rand(array_flip(["DialogueHappy"]), 1);


     } else if ($mood=="playful") {
        $result= array_rand(array_flip(["DialogueHappy"]), 1);

     } else if ($mood=="teasing") {
        $result= array_rand(array_flip(["DialogueSurprise"]), 1);


     } else if ($mood=="smug") {
        $result= array_rand(array_flip(["DialogueAnger"]), 1);


     } else if ($mood=="amused") {
        $result= array_rand(array_flip(["DialogueSurprise"]), 1);

     } else if ($mood=="smirking") {
        $result= array_rand(array_flip(["DialogueHappy"]), 1);
     } else if (in_array($mood, ["sexy", "kindly", "lovely", "seductive", "happy"], true)) {
        $result="DialogueHappy";
     } else if (in_array($mood, ["desperate", "scared", "pleading"], true)) {
        $result="DialogueFear";
     } else if (in_array($mood, ["assertive", "angry"], true)) {
        $result="DialogueAnger";
     } else if ($mood=="sad") {
        $result="DialogueSad";
     } else if ($mood=="surprised") {
        $result="DialogueSurprise";
     } else if (in_array($mood, ["drunk", "shy"], true)) {
        $result="DialoguePuzzled";


     } else if ($mood=="serious") {
        $result= array_rand(array_flip(["MoodNeutral"]), 1);


     } else if ($mood=="firm") {
        $result= array_rand(array_flip(["MoodNeutral"]), 1);


     } if ($mood=="neutral") {
        $result= array_rand(array_flip(["MoodNeutral"]), 1);


     }


     $GLOBALS["PATCH_ORIGINAL_MOOD_ISSUED"]=$mood;
     return $result;

 }

function isOk($arr) {
    if (is_array($arr))
        if (sizeof($arr)>0)
            return true;

    return false;
}

function getArrayKey($arr,$key) {
    if (is_array($arr))
        if (isset($arr[$key]))
            return $arr[$key];

    return false;
}

function createProfile($npcname, $FORCE_PARMS = [], $overwrite = false, $baseprofile = '')
{
    // This should be done at NpcMaster::createProfile
    global $db;

    if ($npcname == "The Narrator")   // Refuse to add Narrator [review this]
        return;

    $path = dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
    $newConfFile = md5($npcname);

    $codename = npcNameToCodename($npcname);
    $baseprofileName = npcNameToCodename($baseprofile);

    $npcMaster = new NpcMaster();
    $currentNpcData = $npcMaster->getByName($npcname);

    $EMPTY_PROFILE=false;

    if (!$currentNpcData || $overwrite ) {
        error_log("Creating/overwriting:$overwrite  profile for $npcname");
        //sleep (1);
        $npcTemlate = $db->fetchAll("SELECT core FROM combined_bio_templates where npc_name='$codename'");
        $npcNewFields = $db->fetchAll("SELECT npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, worldknowledge_tags, voiceid, gender, race, refid FROM combined_bio_templates where npc_name='$codename'");

        // 3. Extract the bracketed portion and convert it to the "stripped" version
        //    e.g. Ranger [NCR Patrol] -> ncr_patrol
        $bracketMatch = '';
        if (preg_match('/\[(.*?)\]/', $npcname, $matches)) {
            $bracketMatch = trim($matches[1]);    // remove possible extra spaces
            $bracketMatch = strtolower($bracketMatch);
            $bracketMatch = str_replace(' ', '_', $bracketMatch);
        }

        // Original logic for pulling from database
        if (isset($npcTemlate[0]) && is_array($npcTemlate[0])) {
                error_log("Creating from template ");

            // Build core from template core (or npc_pers in translated case)
            $coreFull = '';
            if (array_key_exists('core', $npcTemlate[0])) {
                $coreFull = trim((string)$npcTemlate[0]['core']);
            } elseif (array_key_exists('npc_pers', $npcTemlate[0])) {
                $coreFull = trim((string)$npcTemlate[0]['npc_pers']);
            }
            if ($coreFull === '') {
                // Fallback: minimal core
                $coreFull = trim($npcname);
            }

            $npcMaster->create([

                    "npc_name" => $npcname,
                    'npc_static_bio' => $npcNewFields[0]["npc_static_bio"] ?? '',
                    'personality' => $npcNewFields[0]["personality"] ?? '',
                    'core' => $coreFull,
                    'relationships' => $npcNewFields[0]["relationships"] ?? '',
                    'occupation' => $npcNewFields[0]["occupation"] ?? '',
                    'appearance' => $npcNewFields[0]["appearance"] ?? '',
                    'skills' => $npcNewFields[0]["skills"] ?? '',
                    'speechstyle' => $npcNewFields[0]["speechstyle"] ?? '',
                    'goals' => $npcNewFields[0]["goals"] ?? '',
                    'worldknowledge_tags' => $npcNewFields[0]["worldknowledge_tags"] ?? ''

                ]
            );

            // RealNamesExtended support for generic npcs
        } elseif (!empty($bracketMatch)) {

            // Query for new SYNTH fields for bracket match (bio tables)
            $npcNewFields2 = $db->fetchAll("SELECT npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, worldknowledge_tags, voiceid, gender, race, refid FROM combined_bio_templates WHERE npc_name='" . $db->escape($bracketMatch) . "'");
            $npcCore2 = $db->fetchAll("SELECT core FROM combined_bio_templates WHERE npc_name='" . $db->escape($bracketMatch) . "'");

            if (!empty($npcNewFields2[0])) {
                error_log("Creating from template bracketMatch");
                // Build core from template core
                $coreFull2 = '';
                if (!empty($npcCore2[0]) && array_key_exists('core', $npcCore2[0])) {
                    $coreFull2 = trim((string)$npcCore2[0]['core']);
                }
                if ($coreFull2 === '') { $coreFull2 = trim($npcname); }

                $npcMaster->create([
                        "npc_name" => $npcname,
                        'npc_static_bio' => $npcNewFields2[0]["npc_static_bio"] ?? '',
                        'personality' => $npcNewFields2[0]["personality"] ?? '',
                        'core' => $coreFull2,
                        'relationships' => $npcNewFields2[0]["relationships"] ?? '',
                        'occupation' => $npcNewFields2[0]["occupation"] ?? '',
                        'appearance' => $npcNewFields2[0]["appearance"] ?? '',
                        'skills' => $npcNewFields2[0]["skills"] ?? '',
                        'speechstyle' => $npcNewFields2[0]["speechstyle"] ?? '',
                        'goals' => $npcNewFields2[0]["goals"] ?? '',
                        'worldknowledge_tags' => $npcNewFields2[0]["worldknowledge_tags"] ?? ''
                    ]
                );
            } else {
                error_log("Creating initial empty profile");
                $npcMaster->create([
                        "npc_name" => $npcname
                    ]
                );
            }
        } else {
            error_log("Creating initial empty profile");
            $npcMaster->create([
                    "npc_name" => $npcname
                ]
            );
            $EMPTY_PROFILE=true;
            $newData = $npcMaster->GetByName($npcname);


        }

        // Populate voiceid for core_npc_master from imported structured fields.
        $voiceid = "";

        if (isset($npcNewFields) && isset($npcNewFields[0]["voiceid"]) && !empty($npcNewFields[0]["voiceid"])) {
            $voiceid = trim($npcNewFields[0]["voiceid"]);
        } else if (isset($npcNewFields2) && isset($npcNewFields2[0]["voiceid"]) && !empty($npcNewFields2[0]["voiceid"])) {
            $voiceid = trim($npcNewFields2[0]["voiceid"]);
        }

        $currentData = $npcMaster->GetByName($npcname);
        $currentData["voiceid"] = $voiceid;

        $existingMetadata = [];
        if (!empty($currentData['metadata'])) {
            $decodedMetadata = json_decode((string)$currentData['metadata'], true);
            if (is_array($decodedMetadata)) {
                $existingMetadata = $decodedMetadata;
            }
        }
        $currentData['metadata'] = json_encode($existingMetadata, JSON_UNESCAPED_UNICODE);

        $existingExtendedData = [];
        if (!empty($currentData['extended_data'])) {
            $decodedExtendedData = json_decode((string)$currentData['extended_data'], true);
            if (is_array($decodedExtendedData)) {
                $existingExtendedData = $decodedExtendedData;
            }
        }
        $existingExtendedData['synth_core_migrated'] = 2;
        $currentData['extended_data'] = json_encode($existingExtendedData, JSON_UNESCAPED_UNICODE);
        $defaultProfileId = 1;
        try {
            $coreProfile = new CoreProfile();
            $defaultProfile = $coreProfile->getDefaultNpc();
            if (is_array($defaultProfile) && !empty($defaultProfile['id'])) {
                $defaultProfileId = (int)$defaultProfile['id'];
            }
        } catch (Throwable $e) {
            error_log("[CREATEPROFILE] Could not resolve default NPC profile, falling back to profile #1: " . $e->getMessage());
        }

        $currentData['profile_id'] = $defaultProfileId;
        if (empty($currentData['actor_identity_id'])) {
            $currentData['md5'] = md5($currentData["npc_name"]);
        }
        $currentData['gamets_last_updated'] = $GLOBALS["gameRequest"][2];

        if ($EMPTY_PROFILE) {
            error_log("[CREATEPROFILE] Created initial empty profile");
        }

        $npcMaster->updateByArray($currentData);
        return 1;
    }
    return 2;
}

function buildDynamicBiography(array $FOLLOWER_CONF, bool $forLetter = false, bool $forThought = false)
{
    /**
     * Build dynamic biography from structured SYNTH fields.
     * @param array $FOLLOWER_CONF Configuration array containing SYNTH fields
     * @param bool $forLetter If false, removes <letter_guidance> sections from SYNTH_SPEECHSTYLE
     * @param bool $forThought If false, removes <inner_thought_guidance> sections from SYNTH_SPEECHSTYLE
     * @return string The dynamic biography content
     */
    $dynamicBio = '';

    // Helper function to get item description from combined view
    $getItemDescription = function($itemName, $baseid = null) {
        global $db;

        // Try the shared runtime/stable/wildcard baseid resolver first if provided
        if (!empty($baseid)) {
            $record = lookupDescriptionByFormID((string) $baseid);
            if (!empty($record['description'])) {
                return $record['description'];
            }
        }

        // Fallback to name-based search
        if (!empty($itemName) && $itemName != '<Missing Name>') {
            $escapedName = $db->escape($itemName);
            $result = $db->fetchAll("SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER('{$escapedName}') LIMIT 1");
            if (!empty($result) && !empty($result[0]['description'])) {
                return $result[0]['description'];
            }
        }

        return null;
    };

    // List of new SYNTH fields to include
    $synthFields = [
        'SYNTH_BACKGROUND' => 'Basic Summary',
        'SYNTH_PERSONALITY' => 'Personality',
        'SYNTH_APPEARANCE' => 'Appearance',
        'SYNTH_OCCUPATION' => 'Occupation',
        'SYNTH_SKILLS' => 'Skills',
        'SYNTH_SPEECHSTYLE' => 'Speech Style',
        'SYNTH_GOALS' => 'Goals'
    ];
    // Authored expertise stays in the background once; Skills are only the exact actor's snapshot SPECIAL values.
    require_once dirname(__DIR__, 3).'/lib'.'/synth_biography.php';
    $FOLLOWER_CONF['SYNTH_BACKGROUND'] = synth_biography_background(strval($FOLLOWER_CONF['SYNTH_BACKGROUND'] ?? ''),
        strval($FOLLOWER_CONF['SYNTH_SKILLS'] ?? ''));
    $FOLLOWER_CONF['SYNTH_SKILLS'] = ($FOLLOWER_CONF['SYNTH_NAME'] ?? '') === 'The Narrator' ? ''
        : synth_actor_stats_for_request($GLOBALS['db'], strval($FOLLOWER_CONF['SYNTH_NAME'] ?? ''));
    $hasStructuredBiographyFields = false;
    foreach (array_keys($synthFields) as $structuredFieldName) {
        if (isset($FOLLOWER_CONF[$structuredFieldName]) && !empty(trim((string)$FOLLOWER_CONF[$structuredFieldName]))) {
            $hasStructuredBiographyFields = true;
            break;
        }
    }
    $EQUIPMENT_ADD="";
    $TARGET_EQUIPMENT_ADD="";
    $STATS_ADD="";
    $ACTIVITY_ADD="";

    $npcMaster=new NpcMaster();
    $currentNpcData=$npcMaster->getByName($FOLLOWER_CONF["SYNTH_NAME"]);
    $metaData=$npcMaster->getMetaData($currentNpcData);
    $extendedData=$npcMaster->getExtendedData($currentNpcData);
    $activityStatus = synthNormalizeActivityStatus($metaData);

    if (synthPromptContextSectionEnabled('enabled_appearance_subsections', 'current_activity') && !empty($activityStatus['summary'])) {
        $ACTIVITY_ADD = "\n\n<activity>\n#Activity\n" . ucfirst($activityStatus['summary']) . ".\n</activity>\n";
    }


    // Native equipment comes from the owned turn snapshot, not mutable biography metadata.
    $nativeEquipment = ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) === 2;
    if (!$nativeEquipment && $FOLLOWER_CONF["SYNTH_NAME"] !== "The Narrator" && isset($metaData["equipment"]) && is_array($metaData["equipment"])) {
        $equipmentParts = [];
        $describedBaseids = []; // Track which baseids we've already described
        $slots = [
            'helmet' => 'Helmet',
            'armor' => 'Armor',
            'boots' => 'Boots',
            'gloves' => 'Gloves',
            'amulet' => 'Amulet',
            'ring' => 'Ring',
            'cape' => 'Cape',
            'backpack' => 'Backpack',
            'left_hand' => 'Left Hand',
            'right_hand' => 'Right Hand'
        ];

        foreach ($slots as $slot => $label) {
            if (!empty($metaData["equipment"][$slot])) {
                $itemName = $metaData["equipment"][$slot];

                // Skip blacklisted items
                if (isItemBlacklisted($itemName)) {
                    continue;
                }

                $baseid = isset($metaData["equipment"][$slot . '_baseid']) ? $metaData["equipment"][$slot . '_baseid'] : null;

                $itemLine = "  - {$label}: {$itemName}";

                // Try to add item description only if we haven't described this baseid yet
                if (!empty($baseid) && !in_array($baseid, $describedBaseids)) {
                    $description = $getItemDescription($itemName, $baseid);
                    if ($description) {
                        $itemLine .= " - {$description}";
                        $describedBaseids[] = $baseid; // Mark this baseid as described
                    }
                } elseif (empty($baseid)) {
                    // No baseid, try name-based (won't dedupe without baseid)
                    $description = $getItemDescription($itemName, null);
                    if ($description) {
                        $itemLine .= " - {$description}";
                    }
                }

                $equipmentParts[] = $itemLine;
            }
        }

        if (!empty($equipmentParts)) {
            $EQUIPMENT_ADD = "\n<equipment>\n#Current Equipment\nYou are currently wearing/wielding:\n" . implode("\n", $equipmentParts);

            // Check if humanoid NPC has no body armor - if so, note they're naked
            $humanoidRaces = ['human', 'caucasian', 'asian', 'hispanic', 'africanamerican', 'african american',
                            'ghoul', 'nonferalghoul', 'non-feral ghoul', 'non feral ghoul',
                            'supermutant', 'super mutant', 'nightkin', 'synth'];
            $npcRace = isset($currentNpcData["race"]) ? strtolower(trim($currentNpcData["race"])) : '';

            if ($npcRace && in_array($npcRace, $humanoidRaces) && empty($metaData["equipment"]["armor"])) {
                $EQUIPMENT_ADD .= "\nNote: You are naked (no body armor/clothing worn).";
            }

            $EQUIPMENT_ADD .= "\n</equipment>";
        }
    }

    if (
        !$nativeEquipment && $FOLLOWER_CONF["SYNTH_NAME"] !== "The Narrator" &&
        synthPromptContextSectionEnabled('enabled_appearance_subsections', 'equipment')
    ) {
        $structuredEquipmentBlock = synthBuildEquipmentBlockFromMetadata(
            $metaData,
            $getItemDescription,
            $FOLLOWER_CONF["SYNTH_NAME"] ?? ''
        );
        if ($structuredEquipmentBlock !== '') {
            $EQUIPMENT_ADD = $structuredEquipmentBlock;
        }
    } elseif (!synthPromptContextSectionEnabled('enabled_appearance_subsections', 'equipment')) {
        $EQUIPMENT_ADD = "";
    }

    // Native inventory belongs to the exact turn snapshot, never this mutable legacy NPC metadata.
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) !== 2
        && $FOLLOWER_CONF["SYNTH_NAME"] !== "The Narrator" && isset($metaData["inventory"]) && is_array($metaData["inventory"])) {
        if (!isset($describedBaseids)) {
            $describedBaseids = [];
        }
        $inventoryContext = synthBuildInventoryPromptContext(
            $metaData["inventory"],
            $getItemDescription,
            $describedBaseids,
            !empty($GLOBALS["INVENTORY_ITEMS_DESCRIPTIONS_ONLY"])
        );
        if ($inventoryContext !== '') {
            $INVENTORY_ADD = "\n" . $inventoryContext;
        }
    }

	// Add current condition (qualitative HP/AP based on percent, with richer descriptors)
	if (isset($metaData["stats"]) && is_array($metaData["stats"])) {
		$s = $metaData["stats"];
		$describe = function(string $kind, float $cur, float $max): string {
			if ($max <= 0) return "Unknown";
			$pct = ($cur < 0 ? 0.0 : ($cur > $max ? $max : $cur)) / $max * 100.0;
			if ($kind === 'health') {
				if ($pct >= 75.0) return "Near full health";           // 100-75
				if ($pct >= 50.0) return "Wounded";                    // 74-50
				if ($pct >= 25.0) return "Badly wounded";              // 50-25
				return "On the brink of collapse";                      // 24-0
			}
			if ($pct >= 75.0) return "Ready to act";
			if ($pct >= 50.0) return "Action points partly spent";
			if ($pct >= 25.0) return "Action points low";
			return "Nearly out of action points";
		};
		$h = $describe('health', (float)($s['health'] ?? 0), (float)($s['health_max'] ?? 0));
		$ap = $describe('action_points', (float)($s['action_points'] ?? 0), (float)($s['action_points_max'] ?? 0));
		if ($h !== 'Unknown' || $ap !== 'Unknown') {
			$lines = [];
			if ($h !== 'Unknown') { $lines[] = "  - Health: {$h}"; }
			if ($ap !== 'Unknown') { $lines[] = "  - Action Points: {$ap}"; }
			if (!empty($lines)) {
				$STATS_ADD = "\n\n<condition>\n#Condition\n" . implode("\n", $lines)."\n</condition>\n";
			}
		}
	}

    $conditionLines = synthPromptContextSectionEnabled('enabled_appearance_subsections', 'current_condition')
        ? synthBuildCurrentConditionLinesFromMetadata($metaData["stats"] ?? null, $metaData)
        : [];
    if (!empty($conditionLines)) {
        $STATS_ADD = "\n\n<condition>\n#Condition\n" . implode("\n", $conditionLines)."\n</condition>\n";
    } else {
        $STATS_ADD = "";
    }
    // Add dialogue target's equipment (if DIALOGUE_TARGET is set)
    if (!$nativeEquipment && isset($GLOBALS["DIALOGUE_TARGET"]) && !empty($GLOBALS["DIALOGUE_TARGET"])) {
        $targetName = $GLOBALS["DIALOGUE_TARGET"];
        $targetNpcData = $npcMaster->getByName($targetName);

        if ($targetNpcData) {
            $targetMetaData = $npcMaster->getMetaData($targetNpcData);

            if (isset($targetMetaData["equipment"]) && is_array($targetMetaData["equipment"])) {
                $targetEquipmentParts = [];
                $slots = [
                    'helmet' => 'Helmet',
                    'armor' => 'Armor',
                    'boots' => 'Boots',
                    'gloves' => 'Gloves',
                    'amulet' => 'Amulet',
                    'ring' => 'Ring',
                    'left_hand' => 'Left Hand',
                    'right_hand' => 'Right Hand'
                ];

                foreach ($slots as $slot => $label) {
                    if (!empty($targetMetaData["equipment"][$slot])) {
                        $targetEquipmentParts[] = "  - {$label}: {$targetMetaData["equipment"][$slot]}";
                    }
                }

                if (!empty($targetEquipmentParts)) {
                    $TARGET_EQUIPMENT_ADD = "\n<target_equipment>\n#{$targetName}'s Equipment\n{$targetName} is currently wearing/wielding:\n" . implode("\n", $targetEquipmentParts);

                    // Check if humanoid NPC has no body armor - if so, note they're naked
                    $humanoidRaces = ['human', 'caucasian', 'asian', 'hispanic', 'africanamerican', 'african american',
                                    'ghoul', 'nonferalghoul', 'non-feral ghoul', 'non feral ghoul',
                                    'supermutant', 'super mutant', 'nightkin', 'synth'];
                    $targetRace = isset($targetNpcData["race"]) ? strtolower(trim($targetNpcData["race"])) : '';

                    if ($targetRace && in_array($targetRace, $humanoidRaces) && empty($targetMetaData["equipment"]["armor"])) {
                        $TARGET_EQUIPMENT_ADD .= "\nNote: {$targetName} is naked (no body armor/clothing worn).";
                    }

                    $TARGET_EQUIPMENT_ADD .= "\n</target_equipment>\n";
                }
            }

            if (synthPromptContextSectionEnabled('enabled_appearance_subsections', 'target_equipment')) {
                $targetEquipmentLines = synthBuildEquipmentLinesFromMetadata($targetMetaData, $getItemDescription);
                if (!empty($targetEquipmentLines)) {
                    $TARGET_EQUIPMENT_ADD = "\n<target_equipment>\n#{$targetName}'s Equipment\n{$targetName} is currently wearing/wielding:\n" . implode("\n", $targetEquipmentLines) . "\n</target_equipment>\n";
                }
            } else {
                $TARGET_EQUIPMENT_ADD = "";
            }
        }
    }

    $appearanceContextAdded = false;

    foreach ($synthFields as $fieldName => $label) {
        if (isset($FOLLOWER_CONF[$fieldName]) && !empty(trim($FOLLOWER_CONF[$fieldName]))) {
            $xmlLabel=strtr(strtolower($label),[" "=>"_"]);
            $fieldValue = trim($FOLLOWER_CONF[$fieldName]);

            // Apply conditional XML tag removal for SYNTH_SPEECHSTYLE field
            if ($fieldName === 'SYNTH_SPEECHSTYLE') {
                if (!$forLetter) {
                    // Remove <letter_guidance>...</letter_guidance> and its content
                    $fieldValue = preg_replace('/<letter_guidance>.*?<\/letter_guidance>/is', '', $fieldValue);
                }
                if (!$forThought) {
                    // Remove <inner_thought_guidance>...</inner_thought_guidance> and its content
                    $fieldValue = preg_replace('/<inner_thought_guidance>.*?<\/inner_thought_guidance>/is', '', $fieldValue);
                }
                // Clean up any excessive whitespace left after removal
                $fieldValue = trim(preg_replace('/\n{3,}/', "\n\n", $fieldValue));
            }


            $dynamicBio .= "\n<$xmlLabel>\n" . $fieldValue ."\n</$xmlLabel>";

            // Add groups (factions) right after SYNTH_BACKGROUND (basic_summary) section
            if ($fieldName=="SYNTH_BACKGROUND") {
                $extendedData = $npcMaster->getExtendedData($currentNpcData);
                if (isset($extendedData['factions']) && is_array($extendedData['factions']) && count($extendedData['factions']) > 0) {
                    $factionLines = [];
                    foreach ($extendedData['factions'] as $faction) {
                        if (isset($faction['formid'])) {
                            // Lookup faction using helper function (supports XX prefix)
                            $factionRecord = lookupDescriptionByFormID($faction['formid']);

                            // Only add to prompt if found in descriptions table
                            if ($factionRecord && !empty($factionRecord['name'])) {
                                $factionName = $factionRecord['name'];
                                $factionDesc = !empty($factionRecord['description']) ? $factionRecord['description'] : '';
                                $factionLines[] = "{$factionName} - {$factionDesc}";
                            }
                        }
                    }

                    if (count($factionLines) > 0) {
                        $dynamicBio .= "\n<groups>\n" . synth_protocol_faction_prompt_label($extendedData, count($factionLines)) . ":\n" . implode("\n", $factionLines) . "\n</groups>";
                    }
                }
            }

        }

        if ($fieldName=="SYNTH_APPEARANCE" && $hasStructuredBiographyFields) {
            $appearanceContextAdded = true;
            $dynamicBio.=$EQUIPMENT_ADD ?? "";
            $dynamicBio.=$TARGET_EQUIPMENT_ADD ?? "";
            $dynamicBio.=$INVENTORY_ADD ?? "";
            $dynamicBio.=$ACTIVITY_ADD ?? "";
            $dynamicBio.=$STATS_ADD ?? "";
        }
    }



    if (!$appearanceContextAdded) {
        $dynamicBio .= $EQUIPMENT_ADD ?? "";
        $dynamicBio .= $TARGET_EQUIPMENT_ADD ?? "";
        $dynamicBio .= $INVENTORY_ADD ?? "";
        $dynamicBio .= $ACTIVITY_ADD ?? "";
        $dynamicBio .= $STATS_ADD ?? "";
    }

    if (isset($GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"])) {
        foreach ($GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"] as $fName => $builder) {
            error_log("[buildDynamicBiography] BIOGRAPHY_BUILDER {$fName}");

            if (!is_callable($builder)) {
                error_log("[buildDynamicBiography] Builder {$fName} is not callable, skipping.");
                continue;
            }

            // Call the builder. Support both styles:
            //  - builder returns a new bio string
            //  - builder modifies the first argument by-reference
            // We call with call_user_func_array and pass $dynamicBio by-reference so
            // builders that accept a reference can mutate it directly. If the builder
            // returns a non-empty string, prefer that return value as the new bio.
            $result = null;
            try {
                $result = call_user_func_array($builder, array(&$dynamicBio, $currentNpcData));
            } catch (Throwable $e) {
                // Protect against hook errors - log and continue with current bio
                error_log("[buildDynamicBiography] Exception in builder {$fName}: " . $e->getMessage());
                continue;
            }

            if (is_string($result) && strlen(trim($result)) > 0) {
                // Builder returned a non-empty string -> use it
                $dynamicBio = $result;
            }
            // otherwise assume builder mutated $dynamicBio by reference (or left it unchanged)
        }
    }

    return $dynamicBio;
}

function buildDynamicProfileDisplay() {
    /**
     * Build formatted dynamic profile display for profile updates
     * @return string The formatted dynamic profile content
     */
    $currentDynamicProfile = '';
    $synthFields = [
        'SYNTH_BACKGROUND' => 'Background',
        'SYNTH_PERSONALITY' => 'Personality',
        'SYNTH_APPEARANCE' => 'Appearance',
        'SYNTH_OCCUPATION' => 'Occupation',
        'SYNTH_SKILLS' => 'Skills',
        'SYNTH_SPEECHSTYLE' => 'Speech Style',
        'SYNTH_GOALS' => 'Goals'
    ];

    foreach ($synthFields as $fieldName => $label) {
        if (isset($GLOBALS[$fieldName]) && !empty(trim($GLOBALS[$fieldName]))) {
            $currentDynamicProfile .= "\n$label:\n" . trim($GLOBALS[$fieldName]) . "\n";
        }
    }

    if (empty(trim($currentDynamicProfile))) {
        $currentDynamicProfile = "No dynamic profile information available.";
    }

    return $currentDynamicProfile;
}

/**
 * Parses a PHP file and extracts variable assignments into an associative array.
 *
 * Handles:
 * - Scalars: $name = 'SYNTH';
 * - Arrays: $data = ["a", "b"];
 * - Nested array keys: $a["x"]["y"] = 123;
 *
 * All values are returned in raw form (e.g., quoted strings are unquoted).
 *
 * @param string $filePath Path to the PHP file to parse.
 * @return array Associative array of variable names (or paths) => raw values.
 */
function extract_assignments($filePath) {
    $code = file_get_contents($filePath);
    $tokens = token_get_all($code);

    $variables = [];
    $varName = '';
    $indexStack = [];
    $collectValue = false;
    $valueBuffer = '';
    $bracketDepth = 0;
    $expectingKey = false;

    foreach ($tokens as $i => $token) {
        if (is_array($token)) {
            [$id, $text] = $token;

            if ($id === T_VARIABLE) {
                $varName = substr($text, 1);
                $indexStack = [];
                $collectValue = false;
                $valueBuffer = '';
                $bracketDepth = 0;
                $expectingKey = false;
            }

            elseif ($expectingKey && in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_STRING, T_LNUMBER, T_DNUMBER])) {
                $indexStack[] = trim($text, "'\"");
                $expectingKey = false;
            }

            elseif ($collectValue) {
                $valueBuffer .= $text;
            }

        } else {
            // Symbolic tokens
            if ($token === '[' && !$collectValue) {
                $expectingKey = true;
            }

            elseif ($token === '=' && !$collectValue) {
                $collectValue = true;
                $valueBuffer = '';
                $bracketDepth = 0;
            }

            elseif ($collectValue) {
                if ($token === '[') {
                    $bracketDepth++;
                    $valueBuffer .= $token;
                } elseif ($token === ']') {
                    $bracketDepth--;
                    $valueBuffer .= $token;
                } elseif (($token === ';' || $token === ',') && $bracketDepth === 0) {
                    // Don't add the terminating character to the buffer
                    $rawValue = trim($valueBuffer);

                    // Remove quotes and unescape if present
                    if (strlen($rawValue) >= 2) {
                        if ($rawValue[0] === '"' && $rawValue[-1] === '"') {
                            // Double-quoted string - remove quotes and unescape
                            $rawValue = stripcslashes(substr($rawValue, 1, -1));
                        } elseif ($rawValue[0] === "'" && $rawValue[-1] === "'") {
                            // Single-quoted string - remove quotes and unescape single quotes and backslashes
                            $rawValue = substr($rawValue, 1, -1);
                            $rawValue = str_replace(["\\'", "\\\\"], ["'", "\\"], $rawValue);
                        }
                    }

                    // Build full key
                    $fullKey = $varName;
                    foreach ($indexStack as $key) {
                        $fullKey .= "['$key']";
                    }

                    $variables[$fullKey] = $rawValue;

                    // Reset state
                    $collectValue = false;
                    $valueBuffer = '';
                    $indexStack = [];
                } else {
                    $valueBuffer .= $token;
                }
            }

            // Reset expectingKey if we see closing bracket
            if ($token === ']' && !$collectValue) {
                $expectingKey = false;
            }
        }
    }

    return $variables;
}

/**
 * Writes variable assignments to a PHP file using clean formatting.
 *
 * Accepts keys like 'VAR' or 'ARRAY["KEY"]["SUB"]' and writes them back to PHP code.
 * Automatically quotes strings, and leaves numbers, booleans, null, and arrays untouched.
 *
 * @param array $assignments The variable assignments, as [name => raw_value]
 * @param string $filePath Path to save the output PHP code
 */
function write_php_assignments(array $assignments, string $filePath): bool {
    $output = "<?php\n\n";

    foreach ($assignments as $key => $value) {
        // Clean the value - remove trailing semicolons and trim whitespace
        $cleaned = rtrim(trim($value), ';');

        // If the value is already quoted, unquote it first
        if (strlen($cleaned) >= 2) {
            if (($cleaned[0] === '"' && $cleaned[-1] === '"') ||
                ($cleaned[0] === "'" && $cleaned[-1] === "'")) {
                $cleaned = substr($cleaned, 1, -1);
            }
        }

        // Now determine the correct output format based on the cleaned value
        $lowerCleaned = strtolower($cleaned);

        if (in_array($lowerCleaned, ['true', 'false', 'null'], true)) {
            // Boolean or null values - output as-is
            $finalValue = $lowerCleaned;
        } elseif (is_numeric($cleaned) && !str_contains($cleaned, ' ')) {
            // Numeric values - output as-is
            $finalValue = $cleaned;
        } elseif (preg_match('/^\s*\[.*\]\s*$/s', $cleaned)) {
            // Array literals - output as-is
            $finalValue = $cleaned;
        } else {
            // String values - apply comprehensive sanitization for AI-generated content
            if (is_string($cleaned)) {
                // Sanitize AI-generated content to prevent PHP syntax errors
                $cleaned = str_replace("\0", '', $cleaned); // Remove null bytes
                $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned); // Remove control chars
                if (!mb_check_encoding($cleaned, 'UTF-8')) {
                    $cleaned = mb_convert_encoding($cleaned, 'UTF-8', 'UTF-8'); // Fix encoding
                }
                if (strlen($cleaned) > 100000) {
                    $cleaned = substr($cleaned, 0, 100000) . '... [truncated]'; // Limit length
                }
                $cleaned = str_replace(['<?php', '<?', '?>'], ['&lt;?php', '&lt;?', '?&gt;'], $cleaned); // Escape PHP tags

                // Additional sanitization for var_export compatibility
                $cleaned = str_replace('\\', '\\\\', $cleaned); // Escape backslashes
                $cleaned = str_replace("\r\n", "\n", $cleaned); // Normalize line endings
                $cleaned = str_replace("\r", "\n", $cleaned); // Convert Mac line endings
                $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned); // Limit consecutive newlines
            }

            // Use var_export for safer escaping instead of addslashes
            $finalValue = var_export($cleaned, true);
        }

        // Build the assignment line
        if (strpos($key, '[') !== false) {
            $line = "\${$key} = {$finalValue};";
        } else {
            $line = "\$$key = {$finalValue};";
        }

        $output .= $line . "\n";
    }

    return file_put_contents($filePath, $output, LOCK_EX);
}

function getInGameSkillDataFor($npcName) {

    $npcEscapedName=$GLOBALS["db"]->escape($npcName);
    $query="
WITH npc_weapons AS (
  SELECT
    TRIM(SUBSTRING(data FROM 'using weapon\s+(.+)$')) AS weapon
  FROM public.core_context_event
  WHERE type = 'death' AND data LIKE '%$npcEscapedName has defeated%'
)

SELECT
  'weapon' AS type,
  weapon AS item,
  COUNT(*) AS usage_count
FROM npc_weapons
where weapon is not null
GROUP BY weapon
HAVING COUNT(*)>1

ORDER BY type, usage_count DESC;
";
    $skillsData=$GLOBALS["db"]->fetchAll($query);

    if (sizeof ($skillsData)==0)
        return "";

    $weapons = [];

    foreach ($skillsData as $entry) {
        if ($entry['type'] === 'weapon') {
            $weapons[] = $entry['item'];
        }
    }

    $weaponsList = sizeof($weapons)>0?implode(', ', $weapons):"none";


    return "* Fav. Weapons: $weaponsList\n";
}

/**
 * Safely export a value to PHP code with comprehensive sanitization to prevent syntax errors
 *
 * This function sanitizes AI-generated content to prevent PHP syntax errors that can occur
 * with standard var_export() when dealing with special characters, encoding issues, etc.
 *
 * @param mixed $value The value to export
 * @param bool $return Whether to return the string instead of outputting it
 * @return string|null The exported PHP code
 */
function safe_var_export($value, $return = true) {
    // First, sanitize string values
    if (is_string($value)) {
        // Remove null bytes that can break PHP parsing
        $value = str_replace("\0", '', $value);

        // Ensure valid UTF-8 encoding
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // Remove or replace problematic characters
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // Limit length to prevent extremely long strings
        if (strlen($value) > 100000) {
            $value = substr($value, 0, 100000) . '... [truncated]';
        }

        // Ensure balanced quotes and backslashes don't break escaping
        $value = str_replace(['\\', "'", '"'], ['\\\\', "\\'", '\\"'], $value);
        $value = stripslashes($value); // Remove double escaping
    }

    // Try var_export first
    $exported = var_export($value, true);

    // Validate that the exported code is syntactically correct
    $testCode = "<?php return $exported; ?>";

    // Use eval to test syntax (in a safe way)
    $oldLevel = error_reporting(0);
    $syntaxValid = @eval("return true; $testCode") !== false;
    error_reporting($oldLevel);

    if (!$syntaxValid) {
        // Fallback: manual string escaping for safety
        if (is_string($value)) {
            $exported = "'" . addcslashes($value, "'\\") . "'";
        } else {
            // For non-strings, convert to string safely
            $exported = "'" . addcslashes((string)$value, "'\\") . "'";
        }
    }

    if ($return) {
        return $exported;
    } else {
        echo $exported;
        return null;
    }
}

/**
 * Safely update a PHP configuration file variable with proper error handling
 *
 * @param string $filePath Path to the PHP file
 * @param string $varName Variable name (without $)
 * @param mixed $value New value
 * @return array Result with success status and message
 */
function safe_update_php_variable($filePath, $varName, $value) {
    if (!file_exists($filePath)) {
        return ["success" => false, "error" => "File not found: " . basename($filePath)];
    }

    // Read current content
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ["success" => false, "error" => "Cannot read file: " . basename($filePath)];
    }

    // Use safe export
    $escapedValue = safe_var_export($value, true);

    // Validate the escaped value produces valid PHP
    $testAssignment = "\$$varName = $escapedValue;";
    $testCode = "<?php $testAssignment ?>";

    $oldLevel = error_reporting(0);
    $syntaxValid = @eval("return true; $testCode") !== false;
    error_reporting($oldLevel);

    if (!$syntaxValid) {
        return ["success" => false, "error" => "Generated PHP code would be invalid for variable $varName"];
    }

    // Update or add variable
    $pattern = '/\$' . preg_quote($varName, '/') . '\s*=\s*[^;]+;/';
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, '$' . $varName . '=' . $escapedValue . ';', $content);
    } else {
        // Add before closing
        $content = str_replace('?>', '$' . $varName . '=' . $escapedValue . ';' . PHP_EOL . '?>', $content);
    }

    // Write with atomic operation
    $tempFile = $filePath . '.tmp.' . uniqid();
    if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
        return ["success" => false, "error" => "Cannot write to temporary file"];
    }

    if (!rename($tempFile, $filePath)) {
        unlink($tempFile);
        return ["success" => false, "error" => "Cannot update file: " . basename($filePath)];
    }

    return ["success" => true, "message" => "Variable $varName updated successfully"];
}

/**
 * Retrieves base data for an NPC from the current NPC profile table.
 *
 * If the NPC name is empty or no matching profile row is found, the function returns null.
 *
 * @param string $npcname The name of the NPC to retrieve data for.
 * @return array|null An associative array containing 'gender', 'race', and 'refid' keys,
 *                    or null if no valid data is found.
 */
function getBaseDataForNpcFromLog($npcname) {
    if (empty($npcname)) {
        error_log("getBaseDataForNpcFromLog: NPC name is empty.");
        return null;
    }

    $npcNameEscaped = $GLOBALS["db"]->escape($npcname);
    $result = $GLOBALS["db"]->fetchOne("
        SELECT gender, race, refid
        FROM core_npc_master
        WHERE npc_name = '$npcNameEscaped'
        ORDER BY id DESC
        LIMIT 1
    ");

    if (!$result) {
        error_log("getBaseDataForNpcFromLog: No data found for NPC '$npcname'.");
        return null;
    }

    $currentNpcData = [
        "gender" => trim((string)($result["gender"] ?? "")),
        "race" => trim((string)($result["race"] ?? "")),
        "refid" => trim((string)($result["refid"] ?? ""))
    ];

    return $currentNpcData;
}

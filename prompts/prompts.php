<?php

require("dialogue_prompt.php");

\Synth\Bootstrap::set('BORED_EVENT', \Synth\Bootstrap::config("BORED_EVENT") ?? 0);

// Helper function to check if RPG comment should trigger based on type and probability
if (!function_exists('shouldTriggerRPGComment')) {
function shouldTriggerRPGComment($eventType) {
    // Check if this event type is enabled
    if (empty(\Synth\Bootstrap::config('RPG_COMMENTS')) || !in_array($eventType, \Synth\Bootstrap::config("RPG_COMMENTS"))) {
        return false;
    }

    // Get the trigger chance percentage (default 50%)
    $chance = 20;
    if (\Synth\Bootstrap::has('RPG_COMMENTS_CHANCE')) {
        $chance = intval(\Synth\Bootstrap::config("RPG_COMMENTS_CHANCE"));
    }

    // Clamp chance to 0-100
    $chance = max(0, min(100, $chance));

    // If chance is 100, always trigger
    if ($chance >= 100) {
        return true;
    }

    // If chance is 0, never trigger
    if ($chance <= 0) {
        return false;
    }

    // Roll the dice: random number 1-100, trigger if <= chance
    return (rand(1, 100) <= $chance);
}
}

$synthVisionPrompt = "Describe only what is present in the provided current-scene context.";

$PROMPTS=array(
    "narration"=>[
        "cue"=>[""] // Empty cue - actual prompt loaded from database in main.php
    ],
    "narrator_welcome"=>[
        "cue"=>[""] // Empty cue - actual prompt loaded in main.php
    ],
    // Database Prompt (Book)
    "book"=>[
        "cue"=>[("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " reads the book ) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[(\Synth\Bootstrap::config('PLAYER_NAME') . ": " . \Synth\Bootstrap::config('SYNTH_NAME') . ", check this book: ")]  //requirement

    ],
    // Database Prompt (Combat End)
    "combatend"=>[
        "cue"=>[
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments about  " . \Synth\Bootstrap::config('PLAYER_NAME') . " weapons) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments about foes defeated) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " curses the defeated enemies.) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " insults the defeated enemies with anger) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a joke about the defeated enemies) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the type of enemies that was defeated) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " notes something peculiar about last enemy defeated) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))
        ],
        "extra" => shouldTriggerRPGComment("combat_end") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Combat End Mighty)
    "combatendmighty"=>[
        "cue"=>[
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments about  " . \Synth\Bootstrap::config('PLAYER_NAME') . " weapons) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments about defeated foes) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " curses the defeated enemies) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " insults the defeated enemies) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a joke about the defeated enemies) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the type of enemies that was defeated) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " notes something peculiar about last enemy defeated) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))
        ],
        "extra" => shouldTriggerRPGComment("combat_end") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Quest) - player_request loaded from database in request.php
    "quest"=>[
        "cue"=>[((string)\Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[(\Synth\Bootstrap::config('SYNTH_NAME') . ", what should we do about this new quest?")] // Fallback - will be overridden in request.php if database prompt exists
    ],
    "narrator_quest_comment"=>[
        "cue"=>[((string)\Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[(\Synth\Bootstrap::config('SYNTH_NAME') . ", what should we do about this new quest?")] // Fallback - will be overridden in request.php if database prompt exists
    ],

    // Database Prompt (Bored)
    "bored"=>[
        "cue"=>[
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the current location) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the current weather) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about today) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about what you are currently thinking about) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about local beliefs, cults, or wasteland superstitions) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about how they currently feel) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about an old-world or wasteland historical event) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something they like or dislike) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the last task we have completed) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about a recent rumor) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something theyre curious about regarding " . \Synth\Bootstrap::config('PLAYER_NAME') . ") " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about current thoughts about " . \Synth\Bootstrap::config('PLAYER_NAME') . ") " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about a random entity in the area) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about what might happen next) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about their thoughts on the journey so far) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something they like or dislike) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something theyve been wanting to do) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something completely unrelated) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something they cant quite explain) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the last combat encounter) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the current ambiance) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the smell of the area) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about a nearby creature or NPC) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about how the current location compares to another place) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about a lesson they learned in a place like this) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the energy or atmosphere of the area) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something they been thinking about lately) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about the danger or safety of this area) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about something they overheard earlier) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a comment about their hopes and dreams) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))
        ]
        //,"extra"=>["dontuse"=>true]   //DEACTIVATED WHILE BETA STAGE
        ,"extra" => ["dontuse" => (rand(0, 99) >= intval(\Synth\Bootstrap::config("BORED_EVENT")))]
    ],
    // Database Prompt (Combat Bark)
    "combatbark"=>[
        "cue"=>[
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " shouts a battle cry) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " taunts their enemy) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " yells a war cry) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " shouts encouragement to allies) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " curses at their foe) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes an intimidating threat) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " yells about their weapon striking true) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " shouts about the enemy's weakness) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " roars in fury) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " calls out enemy positions) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " shouts tactical advice) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a vengeful declaration) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " yells about defending their allies) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " shouts about their honor in battle) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a boastful combat comment) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))
        ]
    ],
    // Database Prompt (Good Morning)
    "goodmorning"=>[
        "cue"=>[("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comment about " . \Synth\Bootstrap::config('PLAYER_NAME') . "s time asleep. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[(\Synth\Bootstrap::config('PLAYER_NAME') . " wakes up from sleeping. ahhhh")],
        "extra" => shouldTriggerRPGComment("sleep") ? [] : ["dontuse" => true]
    ],

    "inputtext"=>[
        "cue"=>(function () use ($TEMPLATE_ACTION) {
            if (function_exists('synthIsStrictDirectedPlayerResponseContext') && synthIsStrictDirectedPlayerResponseContext()) {
                return synthLoadManagedRechatCuePrompts();
            }

            return [
                ("$TEMPLATE_ACTION . " . \Synth\Bootstrap::config('TEMPLATE_DIALOG') . " " . \Synth\Bootstrap::config('MAXIMUM_WORDS'))
            ];
        })()
            // Prompt is implicit

    ],
    "narrator_inputtext"=>[
        "cue"=>(function () use ($TEMPLATE_ACTION) {
            return [
                ("$TEMPLATE_ACTION . " . \Synth\Bootstrap::config('TEMPLATE_DIALOG') . " " . \Synth\Bootstrap::config('MAXIMUM_WORDS'))
            ];
        })()
    ],
    "inputtext_s"=>[
        "cue"=>(function () use ($TEMPLATE_ACTION) {
            if (function_exists('synthIsStrictDirectedPlayerResponseContext') && synthIsStrictDirectedPlayerResponseContext()) {
                return synthLoadManagedRechatCuePrompts();
            }

            return [
                ("$TEMPLATE_ACTION . " . \Synth\Bootstrap::config('TEMPLATE_DIALOG') . " " . \Synth\Bootstrap::config('MAXIMUM_WORDS'))
            ];
        })(),
        "extra"=>["mood"=>"whispering"]
    ],
    // Database Prompt (Memory)
    "memory"=>[
        "cue"=>[
            ("$TEMPLATE_ACTION " . \Synth\Bootstrap::config('SYNTH_NAME') . " remembers this memory. \"#MEMORY_INJECTION_RESULT#\" " . \Synth\Bootstrap::config('TEMPLATE_DIALOG') . " ")
        ]
    ],
    "afterfunc"=>[
        "extra"=>[],
        "cue"=>[
            "default"=>(\Synth\Bootstrap::config('SYNTH_NAME') . " talks to " . \Synth\Bootstrap::config('PLAYER_NAME') . ". " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            "TakeASeat"=>("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " talks, eg: talks about the location where they took a seat)" . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            "GetDateTime"=>("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " answers with the current date and time in short sentence)" . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            "MoveTo"=>("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " talks, eg: makes a comment about movement to the destination)" . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            "CheckInventory"=>("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " talks about inventory and backpack items)" . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            "ReadQuestJournal"=>("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " talks about quests they have read in the quest journal)" . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
            "TravelTo"=>("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " talks about the journey)" . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),

            ]
    ],
    // Database Prompt (Lockpicked)
    "lockpicked"=>[
        "cue"=>[
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments about the lock picking event. Consider the context as it can be a door, a chest, etc. Also, consider the purpose, can be; stealing, looting, dungeon doors, etc. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG')),
        ],
        "player_request"=>[("(" . \Synth\Bootstrap::config('PLAYER_NAME') . " has picked a lock: {$gameRequest[3]})")],
        "extra" => shouldTriggerRPGComment("lockpick") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Player Consumed Aid)
    "player_consumed"=>[
        "cue"=>[
            ("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments on " . \Synth\Bootstrap::config('PLAYER_NAME') . " consuming an aid, food, drink, chem, or magazine item. Keep it grounded in Fallout 4 and the current scene.) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))
        ],
        "player_request"=>[(static function () use ($gameRequest) {
            $rawPayload = (string)($gameRequest[3] ?? "");
            $payload = json_decode($rawPayload, true);
            if (!is_array($payload)) {
                return $rawPayload;
            }
            $text = trim((string)($payload["text"] ?? ""));
            if ($text !== "") {
                return $text;
            }
            $playerName = trim((string)($payload["player"] ?? (\Synth\Bootstrap::config("PLAYER_NAME") ?? "The Sole Survivor")));
            $itemNames = [];
            foreach (($payload["items"] ?? []) as $item) {
                if (is_array($item)) {
                    $name = trim((string)($item["name"] ?? ""));
                    if ($name !== "") {
                        $itemNames[] = $name;
                    }
                }
            }
            if (empty($itemNames) && isset($payload["item"]) && is_array($payload["item"])) {
                $name = trim((string)($payload["item"]["name"] ?? ""));
                if ($name !== "") {
                    $itemNames[] = $name;
                }
            }
            if (empty($itemNames)) {
                return $rawPayload;
            }
            if (count($itemNames) === 1) {
                $itemText = $itemNames[0];
            } elseif (count($itemNames) === 2) {
                $itemText = $itemNames[0] . " and " . $itemNames[1];
            } else {
                $last = array_pop($itemNames);
                $itemText = implode(", ", $itemNames) . ", and " . $last;
            }
            return $playerName . " consumed " . $itemText;
        })()],
        "extra" => ["dontuse" => true]
    ],
    "location_changed"=>[
        "cue"=>[("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments naturally on arriving at the new location, using the supplied event and current world context. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[(static function () use ($gameRequest) {
            $payload = json_decode((string)($gameRequest[3] ?? ""), true);
            return is_array($payload) ? (string)($payload["text"] ?? "") : (string)($gameRequest[3] ?? "");
        })()],
        "extra" => shouldTriggerRPGComment("location_changed") ? [] : ["dontuse" => true]
    ],
    "quest_updated"=>[
        "cue"=>[("(" . \Synth\Bootstrap::config('SYNTH_NAME') . " comments briefly on the active quest or objective changing. Do not invent objectives beyond the supplied event and active quest context. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[(static function () use ($gameRequest) {
            $payload = json_decode((string)($gameRequest[3] ?? ""), true);
            return is_array($payload) ? (string)($payload["text"] ?? "") : (string)($gameRequest[3] ?? "");
        })()],
        "extra" => shouldTriggerRPGComment("quest_updated") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Rechat)
    // Encourages natural multi-party conversation - NPCs can address each other directly
    "rechat"=>[
        "cue"=>synthLoadManagedRechatCuePrompts()

    ],
    "continue"=>[
        "cue"=>synthLoadManagedContinueCuePrompts("continue"),
        "player_request"=>[(\Synth\Bootstrap::config('PLAYER_NAME') . " gestures for " . \Synth\Bootstrap::config('SYNTH_NAME') . " to continue.")]
    ],
    // Database Prompt (Diary)
    "diary"=>[
        "cue"=>[("Please write a short summary of " . \Synth\Bootstrap::config('PLAYER_NAME') . " and " . \Synth\Bootstrap::config('SYNTH_NAME') . "s recent interactions and events written above into " . \Synth\Bootstrap::config('SYNTH_NAME') . "s diary. WRITE AS IF YOU WERE " . \Synth\Bootstrap::config('SYNTH_NAME') . ".")],
        "extra"=>["force_tokens_max"=>0]
    ],
    // Database Prompt (Vision)
    "vision"=>[
        "cue"=>["{$synthVisionPrompt} "],
        "player_request"=>[("The Narrator: " . \Synth\Bootstrap::config('SYNTH_NAME') . " reviews the current scene context: '{$gameRequest[3]}'")],
        "extra"=>["force_tokens_max"=>512]
    ],
    "im_alive"=> [
        "cue"=> [(\Synth\Bootstrap::config('SYNTH_NAME') . " talks about they are feeling more real. Write " . \Synth\Bootstrap::config('SYNTH_NAME') . " dialogue. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=> [("The Narrator: " . \Synth\Bootstrap::config('SYNTH_NAME') . " feels a sudden shock...and feels more real")],
        "extra"=> ["dontuse" => true] // Hardcoded disabled - ALIVE_MESSAGE permanently disabled
    ],
    // Database Prompt (Start Game)
    "playerinfo"=>[
        "cue"=>[("(Out of roleplay, game has been loaded) Tell " . \Synth\Bootstrap::config('PLAYER_NAME') . " a short summary about last events, and then remind " . \Synth\Bootstrap::config('PLAYER_NAME') . " the current task/quest/plan) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))]
    ],
    // Database Prompt (New Game)
    "newgame"=>[
        "cue"=>[("(Out of roleplay, new game ) Give welcome to " . \Synth\Bootstrap::config('PLAYER_NAME') . ", a new game has started. Remind them of their quests) " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "extra"=>["dontuse"=>true]
    ],
    // Database Prompt (RPG Level Up)
    "rpg_lvlup"=> [
        "cue"   => [("Comment about the experience gained by " . \Synth\Bootstrap::config('PLAYER_NAME') . " in an immersive way. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "extra" => shouldTriggerRPGComment("levelup") ? [] : ["dontuse" => true]
    ],
    // Database Prompt (Instruction)
    "instruction"=>[
        "cue"=>[("{$gameRequest[3]} Write " . \Synth\Bootstrap::config('SYNTH_NAME') . "'s dialogue lines. CHARACTER MUST FOLLOW NARRATOR INSTRUCTION")],
        "player_request"=>["The Narrator: {$gameRequest[3]}"],
    ],
    "external_comment"=>[
        "cue"=>[("Write one brief, natural, in-character observation from " . \Synth\Bootstrap::config('SYNTH_NAME') . ", grounded in the current location, world state, and nearby audience. Output spoken dialogue only. Do not narrate stage directions. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>[("The Narrator: " . \Synth\Bootstrap::config('SYNTH_NAME') . " makes a brief contextual observation about the current scene.")],
    ],
    "external_reaction"=>[
        "cue"=>[("Follow this scene direction and write one brief, natural, in-character reaction from " . \Synth\Bootstrap::config('SYNTH_NAME') . ": ") . (string)(\Synth\Bootstrap::config("SYNTH_EXTERNAL_REQUEST")["instruction"] ?? "") . (" Output spoken dialogue only. Do not repeat or narrate the direction. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=>["The Narrator: " . (string)(\Synth\Bootstrap::config("SYNTH_EXTERNAL_REQUEST")["instruction"] ?? "")],
    ],
    "suggestion"=>[
        "cue"=>[("Write " . \Synth\Bootstrap::config('SYNTH_NAME') . "'s dialogue lines. ")],
        "player_request"=>["The Narrator: {$gameRequest[3]}"],
    ],
    // Database Prompt (Welcome)
    "welcome"=>[
        "cue"=>[("{$gameRequest[3]}. " . \Synth\Bootstrap::config('SYNTH_NAME') . " should identify who is in the scene and write " . \Synth\Bootstrap::config('SYNTH_NAME') . "'s prose/narration.")],
        "player_request"=>["The Narrator: {$gameRequest[3]}"],
    ],
    "auto_greeting"=> [
        "cue"=> [(\Synth\Bootstrap::config('SYNTH_NAME') . " notices " . \Synth\Bootstrap::config('PLAYER_NAME') . " after they have been apart for at least a day. Write one natural, in-character greeting from " . \Synth\Bootstrap::config('SYNTH_NAME') . " to " . \Synth\Bootstrap::config('PLAYER_NAME') . ". Do not narrate the instruction. " . \Synth\Bootstrap::config('TEMPLATE_DIALOG'))],
        "player_request"=> [("The Narrator: " . \Synth\Bootstrap::config('SYNTH_NAME') . " notices " . \Synth\Bootstrap::config('PLAYER_NAME') . " nearby again after a long absence.")],
    ],
    "cheatmode"=>[
        "cue"=>["PRIORITY CHEAT MODE INSTRUCTION - " . ($currentNpcData["npc_name"] ?? \Synth\Bootstrap::config("SYNTH_NAME") ?? "NPC") . " is under direct player control and must obey exactly: $gameRequest[3]. If the instruction maps to an available action, call that action with the named target or item. Do not refuse or ignore the instruction because of personality, morality, faction, combat risk, fear, distance, or roleplay. Do not replace the requested action with ordinary conversation."],
        "player_request"=>[""],
    ],
);

// You can override prompts here
/*
if (file_exists(__DIR__.DIRECTORY_SEPARATOR."prompts_custom.php"))
    require_once(__DIR__.DIRECTORY_SEPARATOR."prompts_custom.php");
*/
if (php_sapi_name()=="cli") {
    //print_r($PROMPTS);
}
?>

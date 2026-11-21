<?php

require_once __DIR__ . '/../lib/autoload.php';
define("_MINIMAL_DISTANCE_TO_BE_THE_SAME", 0.0);
define("_MAXIMAL_DISTANCE_TO_BE_RELATED", 0.8);
define("_MINIMAL_ELEMENTS_TO_TRIGGER_MESSAGE", 3);
//prevent in-game buffer overflow (does not truncate tts only subtitles. Fixes long player input playertts (auto-chat / manual player scene setting)) - ideally player input needs splitting for tts but returnLines is not appropriate
define("_MAX_SUBTITLE_LENGTH", 1000);

require_once(__DIR__."/utils_game_timestamp.php");
require_once(__DIR__."/emote_moods.php");
require_once(__DIR__."/npc_tts_status.php");
require_once(__DIR__."/tts_pronunciation.php");
require_once(__DIR__."/relationship_response.php");
require_once __DIR__.'/synth_diary_scope.php';
require_once __DIR__.'/synth_protocol_state.php';
require_once __DIR__.'/synth_character_forms.php';

// Function groups moved out of this file.
require_once dirname(__DIR__).'/managers/MemoryManager/Native/chat_diary_blocks.php';
require_once dirname(__DIR__).'/managers/TtsStudio/Native/chat_tts.php';
require_once dirname(__DIR__).'/managers/PromptManager/Native/chat_text.php';
require_once dirname(__DIR__).'/managers/MemoryManager/Native/chat_memory.php';
require_once dirname(__DIR__).'/managers/ContextManager/Native/chat_people_scope.php';

// replace findDotPosition with first EOS split detection - same logic as split_at_end_of_sentence
    // This sentence will never be split: "It is, Sole Survivor. The desert air here beats the smoke in town. I've been checking my rifle since dawn."

// Returns how many in-game hours are needed to contain the last $limit events for $actor.
// This is used to dynamically adjust the memory window based on recent activity.

function synthGenerateUtteranceId()
{
    try {
        return "utt_" . bin2hex(random_bytes(10));
    } catch (Exception $e) {
        return "utt_" . substr(md5(uniqid((string)mt_rand(), true)), 0, 20);
    }
}

function logEvent($dataArray,$forcePeople='')
{
    $db = \Synth\Bootstrap::db();

    if (!\Synth\Bootstrap::has('CACHE_PEOPLE_LIMITED')) {
        \Synth\Bootstrap::set('CACHE_PEOPLE_LIMITED', DataBeingsInCloseRange(true)); // DataBeingsInRange() won't work as depends on user input
    }

    if (!\Synth\Bootstrap::has('CACHE_LOCATION')) {
        \Synth\Bootstrap::set('CACHE_LOCATION', DataLastKnownLocation());
    }

    if (!\Synth\Bootstrap::has('CACHE_PARTY')) {
        \Synth\Bootstrap::set('CACHE_PARTY', DataGetCurrentPartyConf());
    }

    if (!isset($dataArray)) { // function called without parameter values
        Logger::error("logEvent: undefined input parameter");
    } else {
        if ((!isset($dataArray[2])) || ($dataArray[2] < 5)) {
            // Never substitute wall-clock time or a timestamp from the previously
            // loaded save. The next valid world-context update backfills these
            // short-lived startup rows with the fresh in-game timestamp.
            static $missingGametsLogged = false;
            if (!$missingGametsLogged) {
                Logger::debug("logEvent: retaining pending game timestamp until world context is ready");
                $missingGametsLogged = true;
            }
            $dataArray[2] = 0;
        }

        $eventType = strtolower((string)($dataArray[0] ?? ""));

        $defaultPeopleFallback = \Synth\Bootstrap::config("CACHE_PEOPLE_LIMITED");
        if (in_array($eventType, ["infoaction", "funcret"], true)) {
            $actionPeopleFallback = \Synth\Bootstrap::config("CACHE_PEOPLE") ?? DataBeingsInCloseRange(false);
            if ($actionPeopleFallback !== "") {
                $defaultPeopleFallback = $actionPeopleFallback;
            }
        }
        $eventPeople = ($forcePeople) ? $forcePeople : $defaultPeopleFallback;
        $hasForcedPeople = !empty($forcePeople);
        if (!$hasForcedPeople) {
            $turnPeopleSnapshot = synthGetCurrentTurnPeopleSnapshot();
            if (in_array($eventType, ["prechat", "chat"], true) && $turnPeopleSnapshot !== "") {
                $eventPeople = $turnPeopleSnapshot;
            } else {
                $eventPeople = buildScopedPeopleForEvent(
                    $dataArray[0] ?? "",
                    $dataArray[3] ?? "",
                    \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                    $eventPeople
                );
            }
        }

        $extraColumns = [];
        if (isset($dataArray[5]) && is_array($dataArray[5])) {
            $extraColumns = $dataArray[5];
        }

        // Fixes. This should not be here.
        if ($dataArray[0]=="funcret") {
            $eventPeople=DataBeingsInCloseRange(true);
        }


        $insertData = array(
            'ts' => $dataArray[1],
            'gamets' => $dataArray[2],
            'type' => $dataArray[0],
            'data' => $dataArray[3],
            'sess' => $dataArray[4]??'pending',
            'localts' => time(),
            'people'=> $eventPeople,
            'location'=>\Synth\Bootstrap::config("CACHE_LOCATION"),
            'party'=>\Synth\Bootstrap::config("CACHE_PARTY")
        );

        if (!empty($extraColumns)) {
            $insertData = array_merge($insertData, $extraColumns);
        }

        $insertResult = $db->insert('core_context_event', $insertData);
    }
}

function selectRandomInArray($arraySource)
{
    $s_res = "";

    if (!isset($arraySource)||!is_array($arraySource)) {
        Logger::warn("chat_helper_functions selectRandomInArray: undefined array! ");
        return $s_res;
    }

    $n=sizeof($arraySource);

    if ($n>0) {
        if ($n==1) {
            $s_res = strtr($arraySource[0],["#SYNTH_NPC1#"=>\Synth\Bootstrap::config("SYNTH_NAME")]);
        } else {
            $s_res = strtr($arraySource[rand(0, $n-1)],["#SYNTH_NPC1#"=>\Synth\Bootstrap::config("SYNTH_NAME")]);
        }
        if (strlen(trim($s_res)) < 3) {
            Logger::warn("chat_helper_functions selectRandomInArray: wrong content - $s_res ");
        }
        return $s_res;
    } else {
        Logger::warn("chat_helper_functions selectRandomInArray: Empty array! ");
        return $s_res;
    }
}

function prettyPrintJson($json )
{
    $data=json_decode($json,true);
    $result="";
    foreach ($data as $p=>$v) {
        if (is_array($v)) {
            foreach ($v as $pp=>$vv)
                $result.="$pp: $vv\n";
        } else
            $result.="$p: $v\n";
    }

    return $result;
}

function startsWithUppercase($string) {
    return preg_match('/^[A-Z]/', $string);
}

/**
 * Converts an array of strings into a bulleted list format.
 *
 * @param array $items Array of strings to convert into a bulleted list
 * @param string $bulletChar Character to use as bullet (default: "*")
 * @return string Formatted bulleted list with newlines
 */
function arrayToBulletedList($items, $bulletChar = " *") {
    if (!is_array($items) || empty($items)) {
       return "(none)";
    }

    $bulletedList = "";
    foreach ($items as $item) {
        if (trim($item))
            $bulletedList .= $bulletChar . " " . trim($item) . "\n";
    }

    if ($bulletedList)
        return rtrim($bulletedList);
    else
        return "(none)";
}

/**
 * Replace bracketed placeholder tokens in a string with runtime values.
 *
 * Scans the given text for the following placeholders and replaces them using
 * the current runtime values:
 *  - "{LOCATION}"       => DataLastKnownLocationHuman()
 *  - "{PLAYER_NAME}"    => \Synth\Bootstrap::config('PLAYER_NAME')
 *  - "{SYNTH_NAME}"    => \Synth\Bootstrap::config('SYNTH_NAME')
 *  - "{TEMPLATE_DIALOG}"=> \Synth\Bootstrap::config('TEMPLATE_DIALOG')
 *
 * The replacement is performed by strtr and returns the resulting string.
 * Non-string inputs will be converted to string before replacement.
 *
 * Note: replacements are performed in a single pass (no recursive expansion),
 * and values are inserted verbatim - sanitize or escape the result as needed
 * before output (e.g., to prevent XSS in HTML contexts).
 *
 * @param mixed $text The input text to process (will be cast to string).
 * @return string The text with bracketed placeholders replaced by their values.
 */
function make_replacements_bracketed($text)
{
    $promptCharacterName = function_exists('synthGetPromptCharacterName')
        ? synthGetPromptCharacterName()
        : \Synth\Bootstrap::config("SYNTH_NAME");
    $narratorRoleplayName = function_exists('synthGetNarratorRoleplayName')
        ? synthGetNarratorRoleplayName()
        : 'The Narrator';

    return strtr($text, [
        "{LOCATION}" => DataLastKnownLocationHuman(),
        "{PLAYER_NAME}"   => \Synth\Bootstrap::config("PLAYER_NAME"),
        "{SYNTH_NAME}"   => $promptCharacterName,
        "{NARRATOR_NAME}" => $narratorRoleplayName,
        "{TEMPLATE_DIALOG}"   => \Synth\Bootstrap::config("TEMPLATE_DIALOG"),
    ]);
}

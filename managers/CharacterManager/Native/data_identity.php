<?php

// Moved from lib/data_functions.php: SYNTH name and player placeholder helpers
// The original file requires this one, so every caller is unchanged.

function ChangeSYNTHName($new_name="") {
    if ($new_name > "") {
        SaveOriginalSYNTHName();
        $GLOBALS["SYNTH_NAME"] = $new_name;
    }
}

function SaveOriginalSYNTHName() {
    $b_already_saved = (\Synth\Bootstrap::config("ORIGINAL_SYNTH_NAME_SAVED") ?? false);
    if (!$b_already_saved) {
        $synth = (\Synth\Bootstrap::config("SYNTH_NAME") ?? "");
                if ((strlen($synth) > 0) && ($synth !== "The Narrator") && ($synth !== "Player") && ($synth !== "LLMFallback") && (stripos($synth, "Narrator") === false) && (stripos($synth, "actor") === false) && (stripos($synth, "everyone") === false) && (stripos($synth, "*") === false) && (stripos($synth, "none") === false) ) {
            $GLOBALS["ORIGINAL_SYNTH_NAME"] = $synth;
            $GLOBALS["ORIGINAL_SYNTH_NAME_SAVED"] = true;
        }
    }
}

function GetOriginalSYNTHName() {
    $b_already_saved = (\Synth\Bootstrap::config("ORIGINAL_SYNTH_NAME_SAVED") ?? false);
    if ($b_already_saved) {
        $synth = \Synth\Bootstrap::config("ORIGINAL_SYNTH_NAME") ?? '';
    } else {
        $synth = \Synth\Bootstrap::config("SYNTH_NAME");
    }
    return $synth;
}

function get_connector_id($s_driver='', $s_model='', $s_url='') {
    $i_res = -1;
    if (isset($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"])) {
        $i_res = \Synth\Bootstrap::config("SYNTH_CORE_CURRENT_CONNECTOR_DATA")["id"] ?? -1;
    }
    if ($i_res < 0) {
        if ((strlen($s_model) > 0) && (strlen($s_url) > 0)  && (strlen($s_driver) > 0)) {
            $query = "SELECT id FROM public.core_llm_connector WHERE (url='{$s_url}') AND (model='{$s_model}') AND (driver='{$s_driver}') LIMIT 1 ";
            $ret = \Synth\Bootstrap::db()->fetchAll($query);
            if ($ret) {
                $i_res = intval($ret[0]['id'] ?? -1);
            }
        }
    }
    return $i_res;
}

function ReplacePlayerNamePlaceholder($s_input) {
    //replace #PLAYER_NAME# with player name
    $s_res = $s_input;
    if ((strlen(trim($s_input))) > 12) {
        $promptCharacterName = function_exists('synthGetPromptCharacterName')
            ? synthGetPromptCharacterName()
            : \Synth\Bootstrap::config("SYNTH_NAME");
        $narratorRoleplayName = function_exists('synthGetNarratorRoleplayName')
            ? synthGetNarratorRoleplayName()
            : 'The Narrator';
        $s_res = strtr($s_input, [
            "{SYNTH_NAME}" =>$promptCharacterName,
            "{NARRATOR_NAME}" =>$narratorRoleplayName,
            "{PLAYER_NAME}"=>\Synth\Bootstrap::config("PLAYER_NAME"),
            "#SYNTH_NAME#" =>$promptCharacterName,
            "#NARRATOR_NAME#" =>$narratorRoleplayName,
            "#PLAYER_NAME#"=>\Synth\Bootstrap::config("PLAYER_NAME")
        ]);
    }
    return $s_res;
}

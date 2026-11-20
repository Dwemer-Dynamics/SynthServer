<?php

/*
foreach ($normalizedContext as $n=>$s_msg) {
    if ($n==(sizeof($normalizedContext)-1)) {   // Last prompt line
        $context.="### Instruction: ".$s_msg.". Write a single reply only.\n";
        \Synth\Bootstrap::config("DEBUG_DATA")[]="### Instruction: ".$s_msg."";

    } else {
        $narratorContextLabel = preg_quote(synthGetNarratorRoleplayName(), '/');
        $s_msg_p = preg_replace('/^(' . $narratorContextLabel . ':)(.*)/m', '[Author\'s notes: $2 ]', $s_msg);
        $context.="$s_msg_p\n";
        \Synth\Bootstrap::config("DEBUG_DATA")[]=$s_msg_p;
    }

}

$context.="### Response:";
\Synth\Bootstrap::config("DEBUG_DATA")[]="### Response:";
\Synth\Bootstrap::config("DEBUG_DATA")["prompt"]=$context;

*/


$context=(\Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
$context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
$context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n");

$context.=("#EXAMPLE\n" . \Synth\Bootstrap::config('PLAYER_NAME') . ": What is Trancos wearing?\n").json_encode([
    'character' => \Synth\Bootstrap::config('SYNTH_NAME'), 'listener' => \Synth\Bootstrap::config('PLAYER_NAME'),
    'mood' => 'sardonic', 'action' => 'Inspect', 'target' => 'Trancos', 'message' => 'Let me check ',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

$context.="\n#CONTEXT\n";

\Synth\Bootstrap::append('DEBUG_DATA', $context);
$contextHistory="";
$n=0;

foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

    if ($n==(sizeof($contextData)-1)) {   // Last prompt line

        $instruction.=$s_msg["content"]."";

    } else if ($n==(sizeof($contextData)-2)) {   // Last prompt line

        $instruction="\n### Instruction:\n".$s_msg["content"];

    }  else {
        if ($s_msg["role"]=="user") {
            $contextHistory.=$s_msg["content"]."\n";
            \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]);


        } else if ($s_msg["role"]=="assistant") {

             if (isset($s_msg["tool_calls"])) {
                    $pb["system"].=(\Synth\Bootstrap::config('SYNTH_NAME') . " issued ACTION {$s_msg["tool_calls"][0]["function"]["name"]}");
                    $lastAction=(\Synth\Bootstrap::config('SYNTH_NAME') . " issued ACTION {$s_msg["tool_calls"][0]["function"]["name"]} {$s_msg["tool_calls"][0]["function"]["arguments"]}");

                    $localFuncCodeName=getFunctionCodeName($s_msg["tool_calls"][0]["function"]["name"]);
                    $localArguments=json_decode($s_msg["tool_calls"][0]["function"]["arguments"],true);
                    if (!is_array($localArguments)) {
                        $localArguments = [];
                    }
                    $lastAction=synthFormatReturnMessageTemplate($localFuncCodeName, $localArguments);


                } else {
                    \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]);
                    //$contextHistory.=$s_msg["content"]."\n";

                    $dialogueTarget=extractDialogueTarget($s_msg["content"]);
                    // Trying to provide examples
                    $contextHistory.="".synth_provider_dialogue_history(strval(\Synth\Bootstrap::config("SYNTH_NAME")),
                        strval($dialogueTarget["target"] ?? ""), strval($dialogueTarget["cleanedString"]), "")."";
                }

        } else if ($s_msg["role"]=="tool") {
            $pb["system"].=$element["content"]."\n";
            $contextHistory.=synthBuildNarratorContextLine(strtr($lastAction,["#RESULT#"=>$s_msg["content"]]));
            \Synth\Bootstrap::set('PATCH_STORE_FUNC_RES', strtr($lastAction,["#RESULT#"=>$s_msg["content"]]));

        } elseif ($s_msg["role"]=="system") {


        }  // Must rebuild this


    }

    $n++;
}

$context.="$contextHistory  $instruction \n### Response:";
\Synth\Bootstrap::append('DEBUG_DATA', "$instruction");
\Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);

?>

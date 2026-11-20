<?php

$context=("<setting>\n" . \Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
$context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
$context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n");

\Synth\Bootstrap::append('DEBUG_DATA', $context);

$contextHistory="";
$n=0;
foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

    if ($n==(sizeof($contextData)-1)) {   // Last prompt line

        $instruction="<instruction>\n".$s_msg["content"]."</instruction>\n";

    } else {
        if ($s_msg["role"]=="user") {
            $contextHistory.=$s_msg["content"]."\n";
            \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]);
        } elseif ($s_msg["role"]=="assistant") {
            \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]);
            $contextHistory.=$s_msg["content"]."\n";
        } elseif ($s_msg["role"]=="system") {
        }  // Must rebuild this


    }

    $n++;
}

$context.="$contextHistory  $instruction \n";
\Synth\Bootstrap::append('DEBUG_DATA', "$instruction");
\Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);

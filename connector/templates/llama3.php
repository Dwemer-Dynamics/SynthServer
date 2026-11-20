<?php

\Synth\Bootstrap::append('more_stopseq', "<|end_of_text|>");
\Synth\Bootstrap::append('more_stopseq', "<end_of_turn>"); // Meta-Llama-3.1-8B-Instruct-GGUF

$context=("<|begin_of_text|><|start_header_id|>system<|end_header_id|>\n" . \Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
$context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
$context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n#CONTEXT\n");
\Synth\Bootstrap::append('DEBUG_DATA', $context);

$contextHistory="";
$n=0;
foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

    if ($n==(sizeof($contextData)-2)) {   // Last prompt line

        $instruction="<|eot_id|><|start_header_id|>user<|end_header_id|>".$s_msg["content"];

    } else if ($n==(sizeof($contextData)-1)) {   // Last prompt line

        $instruction.=$s_msg["content"]."<|eot_id|>";

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

$context.="$contextHistory  $instruction <|start_header_id|>assistant<|end_header_id|>";
\Synth\Bootstrap::append('DEBUG_DATA', "$instruction");
\Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);

?>

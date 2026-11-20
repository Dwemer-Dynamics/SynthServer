<?php

$context=(\Synth\Bootstrap::config('SYNTH_NAME') . "'s Persona: " . \Synth\Bootstrap::config('SYNTH_PERS') . "\n");
            $context.=(\Synth\Bootstrap::config('PLAYER_NAME') . "'s Persona: " . \Synth\Bootstrap::config('PLAYER_NAME') . "\n");
            $context.=("Scenario: " . \Synth\Bootstrap::config('PROMPT_HEAD') . " \n");
            $context.=("Play the role of " . \Synth\Bootstrap::config('SYNTH_NAME') . ". You must engage in a roleplaying chat with " . \Synth\Bootstrap::config('PLAYER_NAME') . " below this line.Do not write dialogues for " . \Synth\Bootstrap::config('PLAYER_NAME') . " and don't write narration.\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n");

            $contextHistory="";
            $n=0;
            foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-1)) {   // Last prompt line
                    $instruction="### Input:\n".$s_msg["content"]."\n";
                    \Synth\Bootstrap::append('DEBUG_DATA', $instruction);

                } else {
                    if ($s_msg["role"]=="user") {
                        $contextHistory.="### Input:\n".$s_msg["content"];
                        \Synth\Bootstrap::append('DEBUG_DATA', "### Input:\n".$s_msg["content"]);
                    } elseif ($s_msg["role"]=="assistant") {
                        $contextHistory.="### Response:\n".$s_msg["content"];
                        \Synth\Bootstrap::append('DEBUG_DATA', "### Response:\n".$s_msg["content"]);

                    } elseif ($s_msg["role"]=="system") {
                    }  // Must rebuild this
                }

                $n++;
            }

            $context.=("$contextHistory $instruction ### Response\n" . \Synth\Bootstrap::config('SYNTH_NAME') . ":");
            \Synth\Bootstrap::append('DEBUG_DATA', "ASSISTANT:");
            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);
            
            ?>

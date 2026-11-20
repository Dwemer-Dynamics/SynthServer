<?php

 $context=(\Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
            $context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n");
            $context.="Dialogue history:\n";

            $contextHistory="";
            $n=0;
            foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-1)) {   // Last prompt line

                    $instruction="USER: ".$s_msg["content"]."\n";

                } else {
                    if ($s_msg["role"]=="user") {
                        $contextHistory.="".$s_msg["content"]."\n";
                        \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]."\n");

                    } elseif ($s_msg["role"]=="assistant") {
                        $contextHistory.="".$s_msg["content"]."\n";
                        \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]."\n");
                    } elseif ($s_msg["role"]=="system") {
                    }  // Must rebuild this
                }

                $n++;
            }

            $context.="$contextHistory $instruction ASSISTANT: ";
            \Synth\Bootstrap::append('DEBUG_DATA', " $instruction ASSISTANT:");
            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);
            
            ?>

<?php

\Synth\Bootstrap::append('more_stopseq', "USER:");
            $context=(\Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
            $context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n");
            $context.=(\Synth\Bootstrap::config('SYNTH_NAME') . " IS THE ASSISTANT, " . \Synth\Bootstrap::config('PLAYER_NAME') . " IS THE USER\n");


            \Synth\Bootstrap::append('DEBUG_DATA', $context);

            $contextHistory="";
            $n=0;
            foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-1)) {   // Last prompt line

                    $instruction="USER: ".$s_msg["content"]."\n";
                    \Synth\Bootstrap::append('DEBUG_DATA', $instruction);
                } else {
                    if ($s_msg["role"]=="user") {
                        $contextHistory.="USER:".$s_msg["content"]."\n";
                          \Synth\Bootstrap::append('DEBUG_DATA', "USER:".$s_msg["content"]."\n");
                    } elseif ($s_msg["role"]=="assistant") {
                         \Synth\Bootstrap::append('DEBUG_DATA', "ASSISTANT:".$s_msg["content"]."\n");
                        $contextHistory.="ASSISTANT:".$s_msg["content"]."\n";
                    } elseif ($s_msg["role"]=="system") {
                    }  // Must rebuild this


                }

                $n++;
            }

            $context.=("$contextHistory  $instruction ASSISTANT:" . \Synth\Bootstrap::config('SYNTH_NAME') . ":");
            \Synth\Bootstrap::append('DEBUG_DATA', "ASSISTANT:");
            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);
            
            ?>

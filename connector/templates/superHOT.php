<?php

 $context="---\nstyle: roleplay\n";
            $context.=("characters:\n   " . \Synth\Bootstrap::config('SYNTH_NAME') . ":" . \Synth\Bootstrap::config('SYNTH_PERS') . "\n   " . \Synth\Bootstrap::config('PLAYER_NAME') . ":Human\n");
            $context.=("summary: " . \Synth\Bootstrap::config('PROMPT_HEAD') . " \n---\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n");
            $contextHistory="";
            $n=0;
            foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-1)) {   // Last prompt line

                    $instruction=$s_msg["content"];


                } else {
                    if ($s_msg["role"]=="user") {
                        $contextHistory.=$s_msg["content"]."\n";
                    } elseif ($s_msg["role"]=="assistant") {
                        $contextHistory.=$s_msg["content"]."\n";
                    } elseif ($s_msg["role"]=="system") {
                    }  // Must rebuild this
                }

                \Synth\Bootstrap::append('DEBUG_DATA', $s_msg["content"]);

                $n++;
            }

            $context.=("$contextHistory Human: $instruction\n" . \Synth\Bootstrap::config('SYNTH_NAME') . ":");

            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);
            
            ?>

<?php

\Synth\Bootstrap::append('more_stopseq', "<|im_start|>");
            $context=("<|im_start|>system\n" . \Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
            $context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "<|im_end|>\n");

            \Synth\Bootstrap::append('DEBUG_DATA', $context);

            $contextHistory="";
            $n=0;
            foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-2)) {   // Last prompt line

                    $instruction="<|im_start|>user\n".$s_msg["content"];

                }else if ($n==(sizeof($contextData)-1)) {   // Last prompt line

                    $instruction.=$s_msg["content"]."<|im_end|>\n";

                }  else {
                    if ($s_msg["role"]=="user") {
                        $contextHistory.="<|im_start|>user\n".$s_msg["content"]."<|im_end|>\n";
                          \Synth\Bootstrap::append('DEBUG_DATA', "<|im_start|>user\n".$s_msg["content"]."<|im_end|>\n");
                    } elseif ($s_msg["role"]=="assistant") {
                         \Synth\Bootstrap::append('DEBUG_DATA', "<|im_start|>assistant\n".$s_msg["content"]."<|im_end|>\n");
                        $contextHistory.="<|im_start|>assistant\n".$s_msg["content"]."<|im_end|>\n";
                    } elseif ($s_msg["role"]=="system") {
                    }  // Must rebuild this


                }

                $n++;
            }

            $context.="$contextHistory  $instruction <|im_start|>assistant";
            \Synth\Bootstrap::append('DEBUG_DATA', "$instruction");
            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);
            
            ?>

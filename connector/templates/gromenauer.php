<?php


            $context=("<|system|>\n" . \Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
            $context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n#CONTEXT\n");
            \Synth\Bootstrap::append('DEBUG_DATA', $context);

            $contextHistory="";
            $n=0;


             foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-1)) {   // Last prompt line

                    $instruction.=$s_msg["content"]."</s>\n";

                } else if ($n==(sizeof($contextData)-2)) {   // Last prompt line

                    $instruction="</s>\n<|user|>\n".$s_msg["content"];

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
                                    $contextHistory.="</s>\n <|assistant|>".synth_provider_dialogue_history(strval(\Synth\Bootstrap::config("SYNTH_NAME")),
                                        strval($dialogueTarget["target"] ?? ""), strval($dialogueTarget["cleanedString"]), "")."</s>\n <|user|>\n";
                                }

                    } else if ($s_msg["role"]=="tool") {
                        $pb["system"].=$element["content"]."\n";
                        $contextHistory.=synthBuildNarratorContextLine(strtr($lastAction,["#RESULT#"=>$s_msg["content"]]));
                        \Synth\Bootstrap::set('PATCH_STORE_FUNC_RES', strtr($lastAction,["#RESULT#"=>$s_msg["content"]]));

                    } else if ($s_msg["role"]=="system") {
                        }  // Must rebuild this


                }

                $n++;
            }


            $context.="$contextHistory  $instruction <|assistant|>";
            \Synth\Bootstrap::append('DEBUG_DATA', "$instruction");
            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);



            ?>

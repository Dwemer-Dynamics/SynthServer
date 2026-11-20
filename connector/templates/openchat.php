<?php
 //\Synth\Bootstrap::config("more_stopseq")[]="USER:";
            $context=("GPT4 User: " . \Synth\Bootstrap::config('PROMPT_HEAD') . "\n");
            $context.=(\Synth\Bootstrap::config('SYNTH_PERS') . "\n");
            $context.=(\Synth\Bootstrap::config('COMMAND_PROMPT') . "\n#CONTEXT\n");
            //$context.="{\Synth\Bootstrap::config("SYNTH_NAME")} IS THE ASSISTANT, {\Synth\Bootstrap::config("PLAYER_NAME")} IS THE USER\n";


            \Synth\Bootstrap::append('DEBUG_DATA', $context);

            $contextHistory="";
            $n=0;
            foreach ($contextData as $s_role=>$s_msg) {	// Have to mangle context format

                if ($n==(sizeof($contextData)-1)) {   // Last prompt line

                    $instruction="#INSTRUCTION ".$s_msg["content"]."<|end_of_turn|>\n";
                    \Synth\Bootstrap::append('DEBUG_DATA', $instruction);
                } else {
                    if ($s_msg["role"]=="user") {
                        $contextHistory.="".$s_msg["content"]."\n";
                          \Synth\Bootstrap::append('DEBUG_DATA', "".$s_msg["content"]."\n");
                    } elseif ($s_msg["role"]=="assistant") {
                         $contextHistory.="".$s_msg["content"]."\n";
                         \Synth\Bootstrap::append('DEBUG_DATA', "".$s_msg["content"]."\n");
                        
                    } elseif ($s_msg["role"]=="system") {
                    }  // Must rebuild this


                }

                $n++;
            }

            $context.="$contextHistory  $instruction GPT4 Assistant:";
            \Synth\Bootstrap::append('DEBUG_DATA', " GPT4 Assistant:");
            \Synth\Bootstrap::setAt('DEBUG_DATA', "prompt", $context);
            
            ?>

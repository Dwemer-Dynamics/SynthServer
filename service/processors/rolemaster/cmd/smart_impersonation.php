<?php 
require_once(__DIR__ . '/../../../../lib/logger.php');
require_once(__DIR__ . '/../../../../lib/synth_command_payload.php');

\Synth\Bootstrap::set('CURRENT_CONNECTOR', DMgetCurrentModel());
\Synth\Bootstrap::set('SYNTH_NO_EXAMPLES', true); // When no assistant entry in history, will try ti provide a bogus example.


if (!\Synth\Bootstrap::has('CURRENT_CONNECTOR') || (!file_exists($enginePath."connector".DIRECTORY_SEPARATOR.(\Synth\Bootstrap::config('CURRENT_CONNECTOR') . ".php")))) {
        logMsg(("Choose a LLM model and connector. Used '" . \Synth\Bootstrap::config('CURRENT_CONNECTOR') . "'"),S_LOG_CRITICAL);

    } else {
        logMsg(("Using " . \Synth\Bootstrap::config('CURRENT_CONNECTOR')));
        require($enginePath."connector".DIRECTORY_SEPARATOR.(\Synth\Bootstrap::config('CURRENT_CONNECTOR') . ".php"));

        $contextDataHistoric = DataLastDataExpandedFor("", -15);    // Full context
        
        $contextDataHistoric =array_merge([["role"=>"user","content"=>"# HISTORIC DIALOGUE AND EVENTS IN CHRONOLOGICAL ORDER"]], $contextDataHistoric);

        $contextDataWorld = DataLastInfoFor("", -2,$includeActorDescriptions=false,$excludeBusy=true);
        $contextDataFull = array_merge($contextDataWorld, $contextDataHistoric);
        $historyData="";

            
        foreach ($contextDataFull as $element) {
        
            $historyData.=trim("{$element["content"]}").PHP_EOL.PHP_EOL;
            
        }

        
       
    // Database Prompt (Smart Impersonation)
$commonprompt='';
        if (!\Synth\Bootstrap::config("argv")[3]) {
            $sysprompt=("Write dialogue for " . \Synth\Bootstrap::config('PLAYER_NAME'));
            $userprompt="";
        } else {
            $sysprompt=("Rewrite dialogue for " . \Synth\Bootstrap::config('PLAYER_NAME') . ", using this text as source \"" . \Synth\Bootstrap::config('PLAYER_NAME') . ":" . \Synth\Bootstrap::config('argv')[3] . "\". Pay attention to comments between brackets, that can guide you in length and verbosity.");
            $userprompt="";
        }
        
        $prompt[] = array('role' => 'system', 'content' => ("You are an actor/actress roleplaying as " . \Synth\Bootstrap::config('PLAYER_NAME') . ", and we are roleplaying Fallout in the wasteland. "));
        $prompt[] = array('role' => 'user', 'content' => "# Contextual data\n$historyData");
        $prompt[] = array('role' => 'user', 'content' =>"
$sysprompt
");
        
        
        
        $customParm["response_format"]=["type"=>"json_object"];
        $customParm["MAX_TOKENS"]=4000;
        
        \Synth\Bootstrap::appendPath('HOOKS', ["JSON_TEMPLATE"], function() {
            \Synth\Bootstrap::set('responseTemplate', [
                "character"=>((string)\Synth\Bootstrap::config('PLAYER_NAME')),
                "dialogue"=>"Dialogue for character",
                "scene_note"=>"Something other actors should know about the instruction, if the instruction also involves another actors"
            ]);
        });
        \Synth\Bootstrap::setPath('CONNECTOR', [\Synth\Bootstrap::config("CURRENT_CONNECTOR"), "json_schema"], false);

        $connectionHandler = new (\Synth\Bootstrap::config('CURRENT_CONNECTOR'));
        $connectionHandler->open($prompt,$customParm);

        $buffer="";
        $totalBuffer="";
        $breakFlag=false;
        
        while (true) {

            if ($breakFlag) {
                break;
            }

            $buffer=$connectionHandler->process();
            $totalBuffer.=$buffer;

            if ($connectionHandler->isDone()) {
                $breakFlag=true;
            }
            
        }
        
        $rawbuffer=$connectionHandler->close();
        
        function parseInstruction($response) {
            // Extract the character name and the instruction line
            
            $characterName = trim($response["character"] ?? 'Unknown');
            $instructionText = trim($response["dialogue"] ?? 'No instruction text');
        
        
            // Generate unique task ID
            $taskId = uniqid();
        
            synthQueueCommandResponse(
                "rolemaster",
                "ImpersonatePlayer",
                [
                    "speech" => make_replacements($instructionText),
                    "request_type" => "inputtext",
                ]
            );
        }

        function parseSceneNote($response) {
            // Extract scene note after "Scene Note:"
            $characterName = trim($response["character"] ?? 'Unknown');
            $noteContent = trim($response["scene_note"] ?? 'No instruction text');
            
        
            // Generate unique task ID
            $taskId = uniqid();
        
            // Format action string
            $action = make_replacements("$noteContent");
        
            // Insert into database
            \Synth\Bootstrap::db()->insert(
                'core_director_note',
                array(
                    'localts' => time(),
                    'ttl' => 60,
                    'type' => "scenenote",
                    'data' => $action
                )
            );
        }
        
        

        
        $response=__jpd_decode_lazy($rawbuffer);
        
        if (isset($response[0]) && is_array($response[0])) {
            $response=$response[0];
        }
        //print_r($response);
        parseInstruction($response);
        parseSceneNote($response);
        
    }
    

    Logger::info("Successfully logged instruction command to responselog");

    
   
?>


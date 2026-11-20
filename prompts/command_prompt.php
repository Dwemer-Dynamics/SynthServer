<?php

$rootPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

// Ensure PLAYER_NAME is defined before using in prompt strings
if (!\Synth\Bootstrap::has('PLAYER_NAME') || \Synth\Bootstrap::config("PLAYER_NAME") === '') {
    $safePlayerName = 'Player';
    try {
        require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
        synthRuntimeBootstrapIfNeeded($rootPath, [
            'run_db_updates' => false,
            'load_general_settings' => false,
            'load_stt_connector' => false,
            'load_player_name' => true,
        ]);
        if (\Synth\Bootstrap::has('PLAYER_NAME') && \Synth\Bootstrap::config("PLAYER_NAME") !== '') {
            $safePlayerName = (string)\Synth\Bootstrap::config("PLAYER_NAME");
        }
    } catch (Throwable $_) {
        // Ignore and use fallback
    }
    \Synth\Bootstrap::set('PLAYER_NAME', $safePlayerName);
}

$COMMAND_PROMPT = "";

// Database Prompt (Command Prompt)
$COMMAND_PROMPT_FUNCTIONS="\n\n#Available Actions\nUse if your character needs to perform an action:";
// Database Prompt (Command Coherent Prompt)
$COMMAND_PROMPT_ENFORCE_ACTIONS=("Choose coherent ACTION to obey " . \Synth\Bootstrap::config('PLAYER_NAME') . ".");

if (!function_exists('synthGetDialogueTargetVerb')) {
    function synthGetDialogueTargetVerb()
    {
        $mode = strtoupper((string)(\Synth\Bootstrap::config("SYNTH_EXECUTION_MODE") ?? ""));
        if ($mode === "WHISPER") {
            return "Whispering to";
        }
        if ($mode === "CLOSE") {
            return "Speaking privately to";
        }
        if ($mode === "SHOUT") {
            return "Shouting to";
        }

        return "Talking to";
    }
}

$dialogueTargetVerb = synthGetDialogueTargetVerb();
$DIALOGUE_TARGET=("({$dialogueTargetVerb} " . \Synth\Bootstrap::config('SYNTH_NAME') . ")");
$MEMORY_OFFERING="";

$RESPONSE_OK_NOTED="Okay, noted.";

$ERROR_OPENAI="Didn't hear you, can you repeat?";								// Say something logical, as this response will be pushed in next call.
$ERROR_OPENAI_REQLIMIT="Be quiet, I'm having a flashback, give me a minute";	// Say something logical, as this response will be pushed in next call.
$ERROR_OPENAI_POLICY="I can't think clearly now...";							// Say something logical, as this response will be pushed in next call. 


// You can override prompts here

if (file_exists(__DIR__.DIRECTORY_SEPARATOR."command_prompt_custom.php"))
    require_once(__DIR__.DIRECTORY_SEPARATOR."command_prompt_custom.php");

?>

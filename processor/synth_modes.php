<?php 

// Check modes should be here
// * Standard (STANDARD)
//      - when using text input, Easy Roleplay can be done just by prepending ** to the text)
//      Example:**(create a long speech about being the Sole Survivor) => I am no mere drifter on these cracked roads. I carried a bullet and walked back from the grave...
//      Example:**you are like a feral ghoul => You move like something the wastes forgot to finish killing.
//      - when using text input, you can achieve Event Injection With Response just putting text bewteen parenthesys
//      Example:(Volkur falls to the ground wounded)
//
// * Whisper (WHISPER)
//      Routes the turn privately to the selected listener using a quiet request-local range.
//
// * Close (CLOSE)
//      Routes the turn privately to one nearby listener within the plugin-owned 200-unit close radius.
//
// * Shout (SHOUT)
//      Expands local hearing/activation range and marks dialogue as shouted.
//
// * Narrator (NARRATOR)
//      Routes player speech privately to The Narrator only, using narrator_inputtext semantics.
//
// * Director. (DIRECTOR)
//      Call instruction directly.
//
//
// * Cheat Mode (CHEATMODE)
//      Processes ALL user input through cheatmode function (no # prefix required).
//      Sends input wrapped in <> brackets directly to LLM with functions enabled.
//      NPCs will execute whatever action/command is requested.
//      Example: "give me 1000 caps" => <give me 1000 caps>
//
// * Auto Chat (AUTOCHAT)
//      Generates clean text following player instructions using Fallout lore language.
//      Wraps input with **() to generate contextual text without stage directions.
//      Example: "Speech about being the Sole Survivor" => **(Generate text employing Fallout lore language and drawing upon the context, following the next instruction:Speech about being the Sole Survivor)
//      Example: "Hello" => **(Hello) 
//
// * Event Injection (INJECTION_LOG)
//      (Whatever is typed/said is injected into event log as an roleplay instruction)
//      Just store player speech on eventlog and die.
//
// * Event Injection With Response  (INJECTION_CHAT)
//      (Whatever is typed/said is injected into event log as an roleplay instruction expecting response)
//      Just store player speech on eventlog and follow the standard flow.

if (!isset($db)) $db = new sql();

function synthRunServiceManager(array $args, array &$output = null, int &$returnCode = null): void
{
    $managerPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "service" . DIRECTORY_SEPARATOR . "manager.php");
    if ($managerPath === false) {
        $managerPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "service" . DIRECTORY_SEPARATOR . "manager.php";
    }

    $cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($managerPath);
    foreach ($args as $arg) {
        $cmd .= " " . escapeshellarg((string)$arg);
    }

    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
}

function synthModePayloadToArray($payload): array
{
    if (is_array($payload)) {
        return $payload;
    }

    $payload = trim((string)$payload);
    if ($payload === '') {
        return [];
    }

    $json = json_decode($payload, true);
    if (is_array($json)) {
        return $json;
    }

    return [];
}

function synthModeTextFromPayload($payload): string
{
    $payloadText = is_string($payload) ? trim($payload) : '';
    $parsed = synthModePayloadToArray($payload);

    foreach (["text", "speech", "message", "instruction", "prompt"] as $key) {
        if (isset($parsed[$key]) && trim((string)$parsed[$key]) !== '') {
            return trim((string)$parsed[$key]);
        }
    }

    if (isset($parsed["payload"])) {
        $nested = synthModeTextFromPayload($parsed["payload"]);
        if ($nested !== '') {
            return $nested;
        }
    }

    if (isset($parsed["json"]) && is_array($parsed["json"])) {
        $nested = synthModeTextFromPayload($parsed["json"]);
        if ($nested !== '') {
            return $nested;
        }
    }

    if ($payloadText === '') {
        return '';
    }

    return trim(preg_replace('/^[^:]+:\s*/', '', $payloadText));
}

function synthModeExtractPlayerText(array $gameRequest, string $receivedData): string
{
    $text = synthModeTextFromPayload($gameRequest[3] ?? '');
    if ($text === '') {
        $decoded = json_decode($receivedData, true);
        if (is_array($decoded)) {
            $text = synthModeTextFromPayload($decoded);
        }
    }

    return trim(preg_replace('/^[^:]+:\s*/', '', $text));
}

function synthModeTargetFromPayload(array $gameRequest): string
{
    $payload = (string)($gameRequest[3] ?? '');

    if (strpos($payload, '=') !== false) {
        $parsed = synthModePayloadToArray($payload);
        foreach (["npc", "target", "listener", "actor_name"] as $key) {
            if (isset($parsed[$key]) && is_scalar($parsed[$key])) {
                $target = trim((string)$parsed[$key]);
                if ($target !== '') {
                    return $target;
                }
            }
        }
    }

    if (function_exists('synth_extract_conversation_target')) {
        $target = synth_extract_conversation_target($payload);
        if ($target !== '' && strcasecmp($target, 'The Narrator') !== 0) {
            return $target;
        }
    }

    return '';
}

function synthModeReadablePayloadWithTarget(array $gameRequest, string $text): string
{
    $display = "(" . trim($text) . ")";
    $target = synthModeTargetFromPayload($gameRequest);
    if ($target === '') {
        return $display;
    }

    $target = trim(str_replace(["\r", "\n", "|", "(", ")"], " ", $target));
    $target = preg_replace('/\s+/', ' ', $target);
    return $target !== '' ? "{$display} (Talking to {$target})" : $display;
}

function synthModeValueFromArray(array $data): string
{
    foreach (["synth_mode", "mode", "execution_mode"] as $key) {
        if (isset($data[$key]) && trim((string)$data[$key]) !== '') {
            return strtoupper(trim((string)$data[$key]));
        }
    }

    if (isset($data["payload"])) {
        $nested = synthModePayloadToArray($data["payload"]);
        if (!empty($nested)) {
            $mode = synthModeValueFromArray($nested);
            if ($mode !== '') {
                return $mode;
            }
        }
    }

    if (isset($data["json"]) && is_array($data["json"])) {
        $mode = synthModeValueFromArray($data["json"]);
        if ($mode !== '') {
            return $mode;
        }
    }

    return '';
}

function synthModeExtractRequestedMode(array $gameRequest, string $receivedData): string
{
    $allowedModes = [
        "STANDARD",
        "WHISPER",
        "CLOSE",
        "SHOUT",
        "NARRATOR",
        "DIRECTOR",
        "INJECTION_LOG",
        "INJECTION_CHAT",
        "CHEATMODE",
    ];

    $mode = synthModeValueFromArray(synthModePayloadToArray($gameRequest[3] ?? ''));
    if ($mode === '') {
        $decoded = json_decode($receivedData, true);
        if (is_array($decoded)) {
            $mode = synthModeValueFromArray($decoded);
        }
    }

    return in_array($mode, $allowedModes, true) ? $mode : '';
}

function synthModeResetToStandard($db): void
{
    $db->upsertRow(
        'conf_opts',
        array(
            'id' => 'synth_mode',
            'value' => 'STANDARD'
        ),
        "id='synth_mode'"
    );
}

function synthModeNotify(string $message): void
{
    $message = trim(str_replace(["\r", "\n", "@"], [" ", " ", " at "], $message));
    if ($message === '') {
        return;
    }

    if (function_exists('synth_buffer_command_response_line')) {
        synth_buffer_command_response_line("rolemaster", "DebugNotification", ["message" => $message]);
        return;
    }

    if (function_exists('synthQueueCommandResponse')) {
        synthQueueCommandResponse("rolemaster", "DebugNotification", ["message" => $message]);
    }
}

function synthModeFlushQueuedRolecommands(): void
{
    if (!function_exists('DataDequeue') || !function_exists('synth_buffer_command_response_line')) {
        return;
    }

    $rows = DataDequeue(time() + 1);
    foreach ($rows as $row) {
        $command = trim((string)($row["action"] ?? ""));
        if ($command === '') {
            continue;
        }
        $speaker = trim((string)($row["actor"] ?? ""));
        if ($speaker === '') {
            $speaker = "rolemaster";
        }
        synth_buffer_command_response_line($speaker, $command, [
            "text" => (string)($row["text"] ?? ""),
            "rowid" => (string)($row["rowid"] ?? ""),
        ]);
    }
}

$EXECUTION_MODE_=$db->fetchOne("SELECT value FROM conf_opts WHERE id='synth_mode'");
$EXECUTION_MODE=isset($EXECUTION_MODE_["value"])?$EXECUTION_MODE_["value"]:"STANDARD";

$EXECUTION_MODE=strtoupper($EXECUTION_MODE);

// Native turns carry their admission-time selection. Never re-read mutable UI state mid-response.
$nativeControl = $GLOBALS['SYNTH_REQUEST_EVENT']['json']['payload']['control'] ?? null;
$nativeInput = ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? null) === 2
    && in_array($GLOBALS['SYNTH_REQUEST_EVENT']['json']['type'] ?? '', ['input_text','input_audio'], true);
if ($nativeInput) $EXECUTION_MODE = is_array($nativeControl) ? $nativeControl['mode'] : 'STANDARD';

if (!in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"])) {
    $EXECUTION_MODE="STANDARD";
} else {
    $REQUESTED_EXECUTION_MODE = synthModeExtractRequestedMode($gameRequest, $receivedData ?? '');
    if ($REQUESTED_EXECUTION_MODE !== '') {
        $EXECUTION_MODE = $REQUESTED_EXECUTION_MODE;
        if (!$nativeInput) $db->upsertRow(
            'conf_opts',
            array(
                'id' => 'synth_mode',
                'value' => $EXECUTION_MODE
            ),
            "id='synth_mode'"
        );
    }
}

// Store globally for later use (e.g., updating speech table after LLM response)
$GLOBALS["SYNTH_EXECUTION_MODE"] = $EXECUTION_MODE;

if ($EXECUTION_MODE=="STANDARD") {


} else if ($EXECUTION_MODE=="WHISPER") {
    // The game plugin owns request-local whisper distance and audience routing.

} else if ($EXECUTION_MODE=="CLOSE") {
    // The game plugin owns the compact 200-unit close radius and target-only audience.

} else if ($EXECUTION_MODE=="SHOUT") {
    // The game plugin owns request-local shout distance and audience routing.

} else if ($EXECUTION_MODE=="NARRATOR") {
    if (in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"], true)) {
        $gameRequest[0] = "narrator_inputtext";
    }
    
} else if ($EXECUTION_MODE=="DIRECTOR") {

    if ($nativeInput) {
        // Reuse the owned structured response/action pipeline, not the legacy process/global command queue.
        $instruction = synthModeExtractPlayerText($gameRequest, $receivedData ?? '');
        $gameRequest[0] = 'cheatmode';
        $gameRequest[3] = '<Director instruction: '.$instruction.'. Respond as the selected character and use only supported structured actions; do not claim unsupported game changes.>';
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
    } else {
    
    ignore_user_abort(true);

    $instruction = synthModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $output=[];
    $returnCode=0;
    synthModeResetToStandard($db);

    if ($instruction === '') {
        synthModeNotify("Director mode failed: no instruction text was received.");
        terminate();
    }

    synthModeNotify("Director mode instruction received.");
    synthRunServiceManager(["rolemaster", "instruction", $instruction, "notify"], $output, $returnCode);
    synthModeFlushQueuedRolecommands();
    if (intval($returnCode ?? 0) !== 0) {
        synthModeNotify("Director mode instruction failed.");
    }
    terminate();

    }

} else if ($EXECUTION_MODE=="CHEATMODE") {
    // Process all input as cheat commands
    $cleaned_player_dialogue = synthModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $newSpeech = strtr($cleaned_player_dialogue, ["#"=>""]);
    $target = synthModeTargetFromPayload($gameRequest);
    if ($target !== '' && strcasecmp($target, 'The Narrator') !== 0) {
        $target = trim(str_replace(["\r", "\n", "|", "(", ")"], " ", $target));
        $target = preg_replace('/\s+/', ' ', $target);
        if ($target !== '') {
            $GLOBALS["SYNTH_CHEATMODE_TARGET"] = $target;
        }
    }
    error_log("[SYNTH_MODE] Cheat Mode request: " . $newSpeech);
    $gameRequest[0] = "cheatmode";
    $gameRequest[3] = "<$newSpeech>";
    $GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
    
} else if ($EXECUTION_MODE=="AUTOCHAT") {
    
    $cleaned_player_dialogue = synthModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $gameRequest[3]="**(".trim($cleaned_player_dialogue).")";
    $GLOBALS["PLAYER_RESPEECH"] = true; // Route through player_rewrite.php for bio/speech style context
    
} else if ($EXECUTION_MODE=="INJECTION_LOG") {
    $cleaned_player_dialogue = synthModeExtractPlayerText($gameRequest, $receivedData ?? '');
    $gameRequest[3] = synthModeReadablePayloadWithTarget($gameRequest, $cleaned_player_dialogue);
    logEvent($gameRequest);
    terminate();
    
} else if ($EXECUTION_MODE=="INJECTION_CHAT") {
    $cleaned_player_dialogue = synthModeExtractPlayerText($gameRequest, $receivedData ?? '');

    $gameRequest[3] = synthModeReadablePayloadWithTarget($gameRequest, $cleaned_player_dialogue);

    
}

$CONTEXT_MODE=$db->fetchOne("SELECT value FROM conf_opts WHERE id='synth_context_mode'");
if (isset($CONTEXT_MODE["value"]) && $CONTEXT_MODE["value"]==1) 
    $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]=true;
else
    $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]=false;


?>

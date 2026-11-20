<?php

if (PHP_SAPI !== "cli") {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(405);
    echo json_encode([
        'schema' => 'synth.player_rewrite.response.v1',
        'ok' => false,
        'error' => 'player_rewrite.php is an internal CLI worker',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

\Synth\Bootstrap::set('ENGINE_ROOT', __DIR__ . DIRECTORY_SEPARATOR);
\Synth\Bootstrap::set('ENGINE_PATH', \Synth\Bootstrap::engineRoot());
$enginePath             = \Synth\Bootstrap::engineRoot();

require_once $enginePath . "lib/runtime_bootstrap.php";
synthRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_player_name' => true,
    'run_db_updates' => false,
]);
require_once $enginePath . "lib/logger.php";
require_once $enginePath . "lib/model_dynmodel.php";
require_once $enginePath . "prompts/command_prompt.php";
require_once $enginePath . "lib/chat_helper_functions.php";
require_once $enginePath . "lib/data_functions.php";

\Synth\Bootstrap::set('db', \Synth\Bootstrap::db() ?? new sql());

function synthPlayerRewritePromptFromManager(string $promptKey, string $fallback, array $replacements): string
{
    try {
        $db = \Synth\Bootstrap::db() ?? null;
        if (!$db) {
            return strtr($fallback, $replacements);
        }

        $escapedPromptKey = $db->escape($promptKey);
        $row = $db->fetchOne("SELECT COALESCE(custom_prompt, default_prompt) AS prompt FROM core_prompt WHERE prompt_key='{$escapedPromptKey}' LIMIT 1");
        $prompt = trim((string)($row['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $fallback;
        }

        return strtr($prompt, $replacements);
    } catch (Throwable $e) {
        Logger::warn("Player rewrite prompt lookup failed for {$promptKey}: " . $e->getMessage());
        return strtr($fallback, $replacements);
    }
}

// New profile system
require_once $enginePath . "lib/core/api_badge.class.php";
require_once $enginePath . "lib/core/llm_connector.class.php";
require_once $enginePath . "lib/core/tts_connector.class.php";
require_once $enginePath . "lib/core/npc_master.class.php";
require_once $enginePath . "lib/core/core_profiles.class.php";

SaveOriginalSYNTHName();
\Synth\Bootstrap::set('SYNTH_NAME', "(actor)");

// Initialize function parameters before requiring functions.php
\Synth\Bootstrap::set('FUNCTION_PARM_INSPECT', []);
\Synth\Bootstrap::set('FUNCTION_PARM_MOVETO', []);
\Synth\Bootstrap::set('F_NAMES', []);

require $enginePath . "functions/functions.php";

$connector            = new LLMConnector();
$currentConnectorData = $connector->getById(\Synth\Bootstrap::config("CORE_CONNECTOR_PLAYER"));
$connectionHandler    = $connector->getConnector($currentConnectorData);

\Synth\Bootstrap::set('SYNTH_CORE_CURRENT_CONNECTOR_DATA', $currentConnectorData);
\Synth\Bootstrap::set('CURRENT_CONNECTOR', $currentConnectorData["driver"]);

$connector->setOldGlobals($currentConnectorData);

// Make functions.php data global

\Synth\Bootstrap::set('FUNCTIONS_ARE_ENABLED', false);

// Some functions need this setted */
$res                       = \Synth\Bootstrap::db()->fetchAll("select max(gamets)+1 as gamets,max(ts)+1 as ts  from core_context_event order by gamets desc limit 1 offset 0");
\Synth\Bootstrap::set('gameRequest', ["inputtext"]);
\Synth\Bootstrap::setAt('gameRequest', 2, $res[0]["gamets"] + 1);

\Synth\Bootstrap::set('SYNTH_NO_EXAMPLES', true); // When no assistant entry in history, will try ti provide a bogus example.

$sourceSpeech = trim((string)($argv[1] ?? ''));
$targetNpc = isset($argv[2]) && $argv[2] !== '' ? trim($argv[2]) : '';

if (! \Synth\Bootstrap::has('SYNTH_CORE_CURRENT_CONNECTOR_DATA')) {
    error_log(("Choose a LLM model and connector. Used connector: '" . \Synth\Bootstrap::config('CORE_CONNECTOR_DIRECTOR') . "'"), S_LOG_CRITICAL);

} else {

    error_log(("Using " . \Synth\Bootstrap::config('CURRENT_CONNECTOR') . " <{$sourceSpeech}>"));

    $contextDataHistoric = DataLastDataExpandedFor("", -15);
    $contextDataHistoric = array_merge([["role" => "user", "content" => "# HISTORIC DIALOGUE AND EVENTS IN CHRONOLOGICAL ORDER"]], $contextDataHistoric);

    // The player rewrite receives a dedicated survival block below. Suppress the
    // nearby-player copy here so AUTOCHAT does not see the same state twice.
    \Synth\Bootstrap::set('SYNTH_SUPPRESS_PLAYER_SURVIVAL_NEARBY', true);
    try {
        $contextDataWorld = DataLastInfoFor("", -2, $includeActorDescriptions = true, $excludeBusy = true);
    } finally {
        \Synth\Bootstrap::forget('SYNTH_SUPPRESS_PLAYER_SURVIVAL_NEARBY');
    }
    $contextDataFull  = array_merge($contextDataWorld??[], $contextDataHistoric??[]);
    $historyData      = "";
    foreach ($contextDataFull as $element) {
        $historyData .= trim("{$element["content"]}") . PHP_EOL . PHP_EOL;
    }

    // Load player data from core_player table
    $playerAppearance = '';
    $playerBio = '';
    $playerSpeechStyle = '';
    try {
        require_once(__DIR__ . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "player.class.php");
        $player = new Player();
        $playerAppearance = trim((string)($player->get('appearance') ?? ''));
        $playerBio = ResolvePlayerBackstory($player);
        $playerSpeechStyle = trim((string)($player->get('speech_style') ?? ''));
    } catch (Exception $e) {
        error_log("Could not load player data from core_player: " . $e->getMessage());
    }
    if (empty($playerSpeechStyle) && \Synth\Bootstrap::has('PLAYER_SPEECH_STYLE') && !empty(\Synth\Bootstrap::config('PLAYER_SPEECH_STYLE'))) {
        $playerSpeechStyle = \Synth\Bootstrap::config("PLAYER_SPEECH_STYLE");
    }

    if (empty($playerBio) && !empty($playerAppearance)) {
        $playerBio = $playerAppearance;
    }

    // Build player character context block
    $playerContext = "";
    if (!empty($playerBio)) {
        $resolvedBio = strtr($playerBio, ["#PLAYER_NAME#" => \Synth\Bootstrap::config("PLAYER_NAME")]);
        $playerContext .= "Player Backstory: " . $resolvedBio . "\n";
    }
    if (!empty($playerAppearance) && trim($playerAppearance) !== trim($playerBio)) {
        $resolvedAppearance = strtr($playerAppearance, ["#PLAYER_NAME#" => \Synth\Bootstrap::config("PLAYER_NAME")]);
        $playerContext .= "Player Appearance: " . $resolvedAppearance . "\n";
    }
    if (!empty($playerSpeechStyle)) {
        $playerContext .= "Player Speech Style: " . $playerSpeechStyle . "\n";
    }
    $playerSurvivalBlock = trim(synthBuildPlayerSurvivalConditionBlock());
    if ($playerSurvivalBlock !== '') {
        $playerContext .= $playerSurvivalBlock . "\n";
    }

    // Build system message: simple identity + PROMPT_HEAD as roleplay ruleset
    $npcLine = (!empty($targetNpc) && $targetNpc !== '(actor)') ? " You are currently speaking to {$targetNpc}." : '';
    $promptHead = '';
    if (!empty(\Synth\Bootstrap::config('PROMPT_HEAD'))) {
        $promptHead = strtr(\Synth\Bootstrap::config("PROMPT_HEAD"), ["#SYNTH_NAME#" => \Synth\Bootstrap::config("PLAYER_NAME")]);
    }
    $systemContent = ("You are roleplaying as " . \Synth\Bootstrap::config('PLAYER_NAME') . ".{$npcLine}");
    if (!empty($promptHead)) {
        $systemContent .= "\n\n" . $promptHead;
    }
    if (!empty($playerContext)) {
        $systemContent .= "\n\n# Character Context\n" . $playerContext;
    }

    $removePlayerAutochatAsterisks = shouldRemovePlayerAutochatAsterisks();

    // Build instruction
    if ($sourceSpeech === "") {
        $instruction = ("Write dialogue for " . \Synth\Bootstrap::config('PLAYER_NAME') . ".");
        $outputInstruction = "Output only the final spoken dialogue line. No narration. No stage directions. No speaker names. No bracketed comments.";
    } else {
        $promptReplacements = [
            '{PLAYER_NAME}' => \Synth\Bootstrap::config("PLAYER_NAME"),
            '{SPEECH}' => $sourceSpeech,
        ];

        if ($removePlayerAutochatAsterisks) {
            $instruction = synthPlayerRewritePromptFromManager(
                'player_respeech_rewrite_strip_prompt',
                "Rewrite dialogue for {PLAYER_NAME}, using this text as source \"{PLAYER_NAME}:{SPEECH}\". Use comments between brackets only as guidance for tone, target, length, and verbosity. Do not repeat bracketed comments, stage directions, narration, asterisked narration, or speaker names in the output.",
                $promptReplacements
            );
            $outputInstruction = synthPlayerRewritePromptFromManager(
                'player_respeech_output_strip_prompt',
                "Output only the final spoken dialogue line. No narration. No stage directions. No asterisked narration. No speaker names. No bracketed comments.",
                $promptReplacements
            );
        } else {
            $instruction = synthPlayerRewritePromptFromManager(
                'player_respeech_rewrite_prompt',
                "Rewrite dialogue for {PLAYER_NAME}, using this text as source \"{PLAYER_NAME}:{SPEECH}\". Use comments between brackets only as guidance for tone, target, length, and verbosity. If the source includes brief narration or stage business before the spoken line, preserve it as one short third-person narration block in single asterisks before the dialogue. Do not repeat bracketed comments or speaker names in the output.",
                $promptReplacements
            );
            $outputInstruction = synthPlayerRewritePromptFromManager(
                'player_respeech_output_prompt',
                "Output only the rewritten line. If the source includes brief leading narration, keep at most one short leading narration block in single asterisks before the spoken dialogue. Keep spoken dialogue outside the asterisks. No speaker names. No bracketed comments.",
                $promptReplacements
            );
        }
    }

    $prompt[] = ['role' => 'system', 'content' => $systemContent];
    $prompt[] = ['role' => 'user',   'content' => "# Contextual data\n$historyData"];
    $prompt[] = ['role' => 'user',   'content' => $instruction];
    $prompt[] = ['role' => 'user',   'content' => $outputInstruction];
    $customParm["MAX_TOKENS"] = 4000;

    $buffer = $connectionHandler->fast_request($prompt, ["MAX_TOKENS" => 4000]);
    $buffer = str_replace((\Synth\Bootstrap::config('PLAYER_NAME') . ":}"), "", $buffer);
    echo trim($buffer) . PHP_EOL;

}

Logger::info("Successfully logged instruction command to responselog");


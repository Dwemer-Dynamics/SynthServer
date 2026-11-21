<?php

// Thin hook: resolves the Gemini key and keyword hints, then the SttWrapper "gemini" driver does the call.
// The vocal tone it reports is cached here for the context builders (getPlayerTone) and put in front of the transcript.
require_once __DIR__ . '/../lib/classes/Logger.php';
require_once dirname(__DIR__) . '/drivers/stt/SttWrapper.php';

$localPath = dirname(__FILE__) . '/../';
require_once($localPath . "lib/runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($localPath, [
    'load_general_settings' => true,
    'load_stt_connector' => true,
]);
require_once($localPath . "lib/chat_helper_functions.php");

// Cache file for player tone - read by context builders
define('PLAYER_TONE_CACHE', dirname(__FILE__) . '/../soundcache/_player_tone.json');

function stt($filePath)
{
    if (!\Synth\Bootstrap::has('db') || !\Synth\Bootstrap::db())
        \Synth\Bootstrap::set('db', new sql());

    // Resolve API key: prefer STT conf, fallback to API Badge 'gemini' or 'google'
    $apiKey = trim(\Synth\Bootstrap::config('STT')['GEMINI']['API_KEY'] ?? '');
    foreach (['gemini', 'google'] as $label) {
        if ($apiKey !== '') break;
        try {
            require_once __DIR__ . '/../core/dbmanager/repositories/ApiBadgeRepository.php';
            $row = (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->keyByLowerLabel($label);
            if (is_array($row) && !empty($row['api_key'])) $apiKey = trim($row['api_key']);
        } catch (Throwable $_e) {
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'stt-gemini', "STT Gemini: DB error looking up {$label} badge: " . $_e->getMessage());
        }
    }

    // Keyword hints from recent context (NPC names, locations, etc.)
    $keywords = [];
    try {
        $keywords = array_values(array_filter(array_map('strval', lastKeyWordsNew(30))));
    } catch (Throwable $_e) {}

    $diagnostic = [];
    $transcript = \Synth\Drivers\Stt\SttWrapper::transcribeOrEmpty('gemini', [
        'api_key' => $apiKey,
        'model' => \Synth\Bootstrap::config('STT')['GEMINI']['MODEL'] ?? '',
        'language' => \Synth\Bootstrap::config('STT')['GEMINI']['LANG'] ?? '',
    ], new \Synth\Drivers\Stt\SttRequest($filePath, $keywords), $diagnostic);

    $tone = trim(strval($diagnostic['tone'] ?? ''));
    if ($tone === '') {
        return $transcript; // the provider call failed or answered plain text
    }
    // Cache the player's vocal tone for context injection
    if ($tone !== 'neutral') {
        @file_put_contents(PLAYER_TONE_CACHE, json_encode([
            'tone' => $tone,
            'timestamp' => time(),
            'transcript_preview' => substr($transcript, 0, 100)
        ]));
    } else {
        // Clear stale tone cache
        @unlink(PLAYER_TONE_CACHE);
    }

    // Inject tone directly into transcript so the LLM sees it in conversation history
    if ($tone !== 'neutral' && $transcript !== '') {
        return "({$tone}) {$transcript}";
    }
    return $transcript;
}

/**
 * Read the cached player tone (called by context builders)
 * Returns the tone string if fresh (within $maxAge seconds), null otherwise.
 *
 * @param int $maxAge Maximum age in seconds (default 60)
 * @return string|null
 */
function getPlayerTone($maxAge = 60) {
    if (!file_exists(PLAYER_TONE_CACHE)) {
        return null;
    }
    $data = json_decode(@file_get_contents(PLAYER_TONE_CACHE), true);
    if (!is_array($data) || empty($data['tone'])) {
        return null;
    }
    if ((time() - ($data['timestamp'] ?? 0)) > $maxAge) {
        return null;
    }
    return $data['tone'];
}

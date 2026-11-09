<?php

namespace Synth\Drivers\Tts;

use Synth\Core\SpeechDriver;

require_once dirname(__DIR__, 2).'/core/dialogue/contracts.php';

// Explicit profile speech planning and the durable TTS queue edge. Plans carry only the profile connector's
// own provider block and the resolved NPC voice, never another provider or per-turn prompt globals.
final class ProfileSpeechDriver implements SpeechDriver
{
    // Drivers whose existing speech plan is portable to the durable TTS worker.
    private const PORTABLE = ['xtts-fastapi', 'chatterbox', 'pockettts', 'cartesia', 'inworld'];

    /** @param array $serverConfig server-wide LANG_LLM_XTTS / TTS_FFMPEG_FILTERS values only */
    public function __construct(private $db, private string $root, private array $serverConfig) {}

    public function settings(array $npcRow, array $profile): ?array
    {
        require_once dirname(__DIR__, 2).'/core/tts_connector.class.php';
        require_once dirname(__DIR__, 2).'/core/npc_master.class.php';
        $tts = new \TTSConnector();
        $connectorId = intval($profile['tts_connector_id'] ?? 0);
        $connector = $connectorId > 0 ? $tts->getById($connectorId) : null;
        if (!is_array($connector)) return null;
        $runtime = $tts->runtimeSettings($connector);
        if (!in_array($runtime['TTSFUNCTION'], self::PORTABLE, true) || $runtime['provider_key'] === '') return null;
        $voice = (new \NpcMaster())->resolveNpcTtsVoiceForProfile($npcRow, $profile);
        $reference = trim(strval($voice['resolved_voice_reference'] ?? $voice['resolved_voice'] ?? ''));
        $resolved = trim(strval($voice['resolved_voice'] ?? ''));
        $inputs = ['TTSFUNCTION' => $runtime['TTSFUNCTION'], 'TTS_FUNCTION' => $runtime['TTS_FUNCTION'], 'TTS' => $runtime['TTS'],
            'SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID' => $runtime['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'],
            'SYNTH_CORE_CURRENT_NPC_DATA' => $npcRow, 'SYNTH_CORE_CURRENT_PROFILE_DATA' => $profile, 'SYNTH_SPEECH_ROLE' => 'npc'];
        if ($reference !== '') {
            $inputs['PATCH_OVERRIDE_VOICE'] = $reference;
            $inputs['TTS'] = array_replace_recursive($inputs['TTS'], array_intersect_key(
                \NpcMaster::ttsVoiceSettings($reference, $resolved), [$runtime['provider_key'] => true]));
        }
        foreach (['original_voice' => 'TTS_NPC_ORIGINAL_VOICE', 'fallback_voice' => 'TTS_NPC_FALLBACK_VOICE',
            'resolved_voice' => 'TTS_NPC_RESOLVED_VOICE', 'resolved_voice_sample' => 'TTS_NPC_RESOLVED_VOICE_SAMPLE'] as $from => $to)
            if (trim(strval($voice[$from] ?? '')) !== '') $inputs[$to] = strval($voice[$from]);
        $language = $inputs['TTS'][$runtime['provider_key']]['language'] ?? '';
        foreach (['LANG_LLM_XTTS', 'TTS_FFMPEG_FILTERS'] as $key)
            if (array_key_exists($key, $this->serverConfig)) $inputs[$key] = $this->serverConfig[$key];
        if (!empty($inputs['LANG_LLM_XTTS']) && is_string($language) && trim($language) !== '')
            $inputs['LLM_LANG'] = preg_replace('/[^a-z\-]/i', '', strtolower(trim($language)));
        return $inputs;
    }

    public function queue(array $speaker, string $lineId, string $text, array $settings): string
    {
        require_once dirname(__DIR__, 2).'/synth_tts_plan.php';
        require_once dirname(__DIR__, 2).'/synth_tts_jobs.php';
        require_once dirname(__DIR__, 2).'/synth_tts_worker.php';
        $name = strval($speaker['display_name']);
        $plan = synth_capture_tts_plan($this->root, $name, $text, 'default', $text, $settings);
        if (empty($plan['portable'])) throw new \RuntimeException('speech_unavailable');
        $entry = ['speaker' => $name, 'speaker_identity' => $speaker, 'text' => $plan['text'], 'mood' => 'default',
            'cache_seed' => $plan['cache_seed'], 'cache_key' => md5($plan['cache_seed']), 'tts_plan' => $plan, 'utterance_id' => $lineId];
        synth_tts_launch_job(synth_tts_enqueue_job($this->db, $entry, $this->root), $this->root);
        return $entry['cache_key'];
    }
}

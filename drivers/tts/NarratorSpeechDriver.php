<?php

namespace Synth\Drivers\Tts;

// Private narrator speech: the narrator profile's own TTS connector and the definition's voice, synthesized once per
// reply outside any database transaction and published as immutable media. There is no server-wide or NPC fallback;
// a reply that cannot be voiced is a terminal failure, because only completed audio can enter the transcript.
final class NarratorSpeechDriver
{
    private const PORTABLE = ['xtts-fastapi', 'chatterbox', 'pockettts', 'cartesia', 'inworld'];
    public const DEADLINE_MS = 30000;

    /**
     * @param array $serverConfig server-wide LANG_LLM_XTTS / TTS_FFMPEG_FILTERS values only
     * @param \Closure $legacy runs one legacy connector/plan/synthesis call with only its named configuration in scope
     */
    public function __construct(private $db, private string $root, private array $serverConfig, private \Closure $legacy) {}

    /** Explicit TTS plan inputs for one narrator definition revision; null when the profile has no portable speech. */
    public function settings(array $profile, string $name, string $voice): ?array
    {
        return ($this->legacy)(function () use ($profile, $name, $voice): ?array {
            require_once dirname(__DIR__, 2).'/lib/core/tts_connector.class.php';
            require_once dirname(__DIR__, 2).'/lib/core/npc_master.class.php';
            $tts = new \TTSConnector();
            $connectorId = intval($profile['tts_connector_id'] ?? 0);
            $connector = $connectorId > 0 ? $tts->getById($connectorId) : null;
            if (!is_array($connector)) return null;
            $runtime = $tts->runtimeSettings($connector);
            if (!in_array($runtime['TTSFUNCTION'], self::PORTABLE, true) || $runtime['provider_key'] === '') return null;
            $inputs = ['TTSFUNCTION' => $runtime['TTSFUNCTION'], 'TTS_FUNCTION' => $runtime['TTS_FUNCTION'], 'TTS' => $runtime['TTS'],
                'SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID' => $runtime['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'],
                'SYNTH_CORE_CURRENT_NPC_DATA' => ['npc_name' => $name, 'voiceid' => $voice],
                'SYNTH_CORE_CURRENT_PROFILE_DATA' => $profile, 'SYNTH_SPEECH_ROLE' => 'narrator'];
            if ($voice !== '') {
                $inputs['PATCH_OVERRIDE_VOICE'] = $voice;
                $inputs['TTS'] = array_replace_recursive($inputs['TTS'], array_intersect_key(
                    \NpcMaster::ttsVoiceSettings($voice, $voice), [$runtime['provider_key'] => true]));
            }
            $language = $inputs['TTS'][$runtime['provider_key']]['language'] ?? '';
            foreach (['LANG_LLM_XTTS', 'TTS_FFMPEG_FILTERS'] as $key)
                if (array_key_exists($key, $this->serverConfig)) $inputs[$key] = $this->serverConfig[$key];
            if (!empty($inputs['LANG_LLM_XTTS']) && is_string($language) && trim($language) !== '')
                $inputs['LLM_LANG'] = preg_replace('/[^a-z\-]/i', '', strtolower(trim($language)));
            return $inputs;
        });
    }

    /** Synthesizes and publishes one reply; returns a ready synth.speech.v1 payload or throws speech_unavailable. */
    public function synthesize(string $speaker, string $lineId, string $text, array $settings, array $correlation): array
    {
        try {
            $media = ($this->legacy)(function () use ($speaker, $text, $settings, $correlation): array {
                require_once dirname(__DIR__, 2).'/lib/synth_tts_plan.php';
                require_once dirname(__DIR__, 2).'/lib/chat_helper_functions.php';
                require_once dirname(__DIR__, 2).'/lib/npc_tts_status.php';
                require_once dirname(__DIR__, 2).'/lib/media_store.php';
                $plan = synth_capture_tts_plan($this->root, $speaker, $text, 'default', $text, $settings);
                if (empty($plan['portable'])) throw new \RuntimeException('speech_unavailable');
                $key = md5($plan['cache_seed']);
                if (!synth_call_planned_npc_tts($plan, $text, 'default', $plan['cache_seed'])) throw new \RuntimeException('speech_unavailable');
                $media = synth_resolve_npc_tts_media($key, $this->root, true);
                if ($media === null) throw new \RuntimeException('speech_unavailable');
                synth_media_register($this->db, $media, $correlation);
                synth_media_require_ready($this->db, $media);
                return ['cache_key' => $key, 'media' => $media];
            });
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable) { throw new \RuntimeException('speech_unavailable'); }
        return ['schema' => 'synth.speech.v1', 'utterance_id' => $lineId, 'cache_key' => $media['cache_key'],
            'status' => 'ready', 'deadline_ms' => self::DEADLINE_MS, 'media' => $media['media']];
    }
}

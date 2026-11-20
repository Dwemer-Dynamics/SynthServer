<?php

declare(strict_types=1);

namespace Synth\Drivers\Tts;


/**
 * A speech driver that voices a line before it returns (the private narrator), instead of queueing it for the
 * durable TTS worker. synthesize() returns the ready synth.speech.v1 payload or throws "speech_unavailable".
 */
interface TtsSynthesisInterface extends TtsDriverInterface
{
    /**
     * @param array $correlation request_id / turn_id / utterance_id / generation the published media is registered under
     * @return array synth.speech.v1 payload
     */
    public function synthesize(string $speaker, string $lineId, string $text, array $settings, array $correlation): array;
}

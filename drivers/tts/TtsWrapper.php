<?php

declare(strict_types=1);

namespace Synth\Drivers\Tts;

use Synth\Core\SpeechDriver;

require_once dirname(__DIR__, 2) . '/core/contracts/dialogue.php';

/**
 * The single entry point application code uses for speech.
 *
 * It is the Synth\Core\SpeechDriver the managers receive and forwards to the driver it was built with, so a
 * manager never names a concrete provider class. Optional speech stays optional: a driver that cannot voice a
 * profile returns null settings, and queue() failures reach the caller unchanged (the line is then text only).
 *
 * The provider scripts in tts/ (tts-*.php) are thin hooks that register the provider drivers in Drivers/; they still
 * run behind the speech plan. A driver that voices a line inline (the private narrator) also implements
 * TtsSynthesisInterface and is reached through synthesize().
 */
final class TtsWrapper implements SpeechDriver
{
    public function __construct(private readonly TtsDriverInterface $driver)
    {
    }

    public function getName(): string
    {
        return $this->driver->getName();
    }

    public function settings(array $npcRow, array $profile): ?array
    {
        return $this->driver->settings($npcRow, $profile);
    }

    /** Voices a line before returning (private narrator). Throws speech_unavailable when the driver cannot. */
    public function synthesize(string $speaker, string $lineId, string $text, array $settings, array $correlation): array
    {
        if (!$this->driver instanceof TtsSynthesisInterface) throw new \RuntimeException('speech_unavailable');
        return $this->driver->synthesize($speaker, $lineId, $text, $settings, $correlation);
    }

    public function queue(array $speaker, string $lineId, string $text, array $settings): string
    {
        return $this->driver->queue($speaker, $lineId, $text, $settings);
    }
}

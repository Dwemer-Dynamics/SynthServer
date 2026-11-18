<?php

declare(strict_types=1);

namespace Synth\Drivers\Tts;

use Synth\Core\SpeechDriver;

require_once dirname(__DIR__, 2) . '/core/contracts/dialogue.php';
require_once __DIR__ . '/TtsDriverInterface.php';

/**
 * The single entry point application code uses for speech.
 *
 * It is the Synth\Core\SpeechDriver the managers receive and forwards to the driver it was built with, so a
 * manager never names a concrete provider class. Optional speech stays optional: a driver that cannot voice a
 * profile returns null settings, and queue() failures reach the caller unchanged (the line is then text only).
 *
 * The provider scripts in tts/ (tts-*.php) still run behind the legacy speech plan; moving each one into
 * Drivers/ is tracked in docs/CHEVO-PARITY.md.
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

    public function queue(array $speaker, string $lineId, string $text, array $settings): string
    {
        return $this->driver->queue($speaker, $lineId, $text, $settings);
    }
}

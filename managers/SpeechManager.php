<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;
use Synth\Core\SpeechDriver;

require_once dirname(__DIR__).'/core/contracts/dialogue.php';
require_once dirname(__DIR__).'/core/entities/dialogue.php';

// Speech comes only from the NPC profile's own TTS connector and resolved voice; there is no server-wide
// fallback. Voice is optional: a profile without a portable connector, or a line that cannot be queued, is
// delivered as text only (no `speech` block) and the turn continues.
final class SpeechManager
{
    public const DEADLINE_MS = 30000;

    public function __construct(private SpeechDriver $driver) {}

    public function settings(PlacedCharacter $character): ?array
    {
        return $this->driver->settings($character->npc, $character->profile);
    }

    // The pending speech envelope for one line, or null when it cannot be queued. Cancellation still propagates.
    public function speak(PlacedCharacter $character, ?array $settings, string $lineId, string $text): ?array
    {
        if ($settings === null) return null;
        try { $cacheKey = $this->driver->queue($character->identity, $lineId, $text, $settings); }
        catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable) { return null; }
        return ['schema' => 'synth.speech.v1', 'utterance_id' => $lineId, 'cache_key' => $cacheKey,
            'status' => 'pending', 'deadline_ms' => self::DEADLINE_MS];
    }
}

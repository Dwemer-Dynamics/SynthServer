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

    // The message of the last queue failure, for the caller's one diagnostic line (never the text or a secret).
    private ?string $failure = null;

    public function __construct(private SpeechDriver $driver) {}

    // The last queue failure message, or null when the last line queued.
    public function lastFailure(): ?string
    {
        return $this->failure;
    }

    public function settings(PlacedCharacter $character): ?array
    {
        return $this->driver->settings($character->npc, $character->profile);
    }

    // The pending speech envelope for one line, or null when it cannot be queued. Cancellation still propagates.
    public function speak(PlacedCharacter $character, ?array $settings, string $lineId, string $text): ?array
    {
        $this->failure = null;
        if ($settings === null) return null;
        try { $cacheKey = $this->driver->queue($character->identity, $lineId, $text, $settings); }
        catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable $error) { $this->failure = $error->getMessage(); return null; }
        $speech = ['schema' => 'synth.speech.v1', 'utterance_id' => $lineId, 'cache_key' => $cacheKey,
            'status' => 'pending', 'deadline_ms' => self::DEADLINE_MS];
        // A client that negotiated the queue (capability message.queue) also gets the id to fetch the result by.
        $messageId = method_exists($this->driver, 'messageId') ? $this->driver->messageId() : null;
        if ($messageId !== null) $speech['message_id'] = $messageId;
        return $speech;
    }
}

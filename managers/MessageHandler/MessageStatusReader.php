<?php

declare(strict_types=1);

namespace Synth\Managers\MessageHandler;

use Synth\Drivers\Tts\SpeechStorage;


/**
 * Delivery state of one outbound NPC line. SYNTH's durable outbound queue is the TTS job (`core_worker_job`, run by
 * `workers/Speech`) plus the status file and media registry behind `SpeechStorage`; the message id is the TTS cache
 * key. This reads that state for `GET /api/v1/messages/<id>` and exposes nothing else: it creates, completes and
 * cancels nothing, and needs no table beyond the existing ones.
 */
final class MessageStatusReader
{
    public const PENDING = 'pending';
    public const READY = 'ready';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';
    public const INVALID = 'invalid';

    public function __construct(private ?string $root = null)
    {
    }

    /** @return array<string,mixed> the `synth.tts_status.v1` document for the id */
    public function status(string $messageId): array
    {
        return SpeechStorage::readStatus($messageId, $this->root);
    }

    /** Whether the id is syntactically a message id. */
    public function isValidId(string $messageId): bool
    {
        return ($this->status($messageId)['status'] ?? self::INVALID) !== self::INVALID;
    }
}

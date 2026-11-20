<?php

declare(strict_types=1);

namespace Synth\Managers\Notification;

/** The closed frames of protocol/v2/schemas/notify-hint.schema.json. They never carry message content: a message is only named by its id. */
final class NotifyHint
{
    public const SCHEMA = 'synth.notify.hint.v1';
    public const TOPICS = ['speech'];

    public static function welcome(): string
    {
        return json_encode(['schema' => self::SCHEMA, 'type' => 'welcome'], JSON_THROW_ON_ERROR);
    }

    public static function wake(string $topic): string
    {
        if (!in_array($topic, self::TOPICS, true)) {
            throw new \InvalidArgumentException('Unknown wake topic.');
        }
        return json_encode(['schema' => self::SCHEMA, 'type' => 'wake', 'topic' => $topic], JSON_THROW_ON_ERROR);
    }

    /** Tells the client that the queued message with this id is complete and can be fetched with GET /api/v1/messages/<id>. */
    public static function messageAvailable(int $messageId): string
    {
        if ($messageId < 1) {
            throw new \InvalidArgumentException('Unknown message id.');
        }
        return json_encode(['schema' => self::SCHEMA, 'type' => 'message_available', 'message_id' => $messageId], JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Synth\Managers\Notification;

/** The closed wake-up frames of protocol/v2/schemas/notify-hint.schema.json. They never carry message content. */
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
}

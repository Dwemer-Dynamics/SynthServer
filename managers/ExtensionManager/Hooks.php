<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// The hook names of extension API 1.0. A filter receives a payload array and returns one; an event only
// observes. Names follow chevo where SYNTH has the same moment (see README.md for where each one fires).
final class Hooks
{
    public const API_VERSION = '1.0';

    // Filters: run while the dialogue turn waits.
    public const DIALOGUE_CONTEXT_SECTIONS = 'dialogue.context.sections';
    public const DIALOGUE_PROMPT_MESSAGES = 'dialogue.prompt.messages';

    // Events: observe only; their result and failures never reach the caller. Deferrable (see DeferredEventSink).
    public const GAME_CONTEXT_RECEIVED = 'game.context.received';
    public const DIALOGUE_REPLY_COMPLETED = 'dialogue.reply.completed';

    public const FILTERS = [self::DIALOGUE_CONTEXT_SECTIONS, self::DIALOGUE_PROMPT_MESSAGES];
    public const EVENTS = [self::GAME_CONTEXT_RECEIVED, self::DIALOGUE_REPLY_COMPLETED];

    public static function all(): array
    {
        return [...self::FILTERS, ...self::EVENTS];
    }
}

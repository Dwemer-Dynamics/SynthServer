<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// The hook names of the extension API. A filter receives a payload array and returns one; an event only
// observes. Names follow chevo where SYNTH has the same moment (README.md lists where each one fires).
//
// API 1.1 added the hooks marked below to the four of 1.0 (the 1.0 names and payloads are unchanged), so every
// extension written for 1.0 still loads: a manifest asking for ^1.0 is accepted by a 1.1 host.
final class Hooks
{
    public const API_VERSION = '1.1';

    // ---- Filters: run while the game or the dashboard waits. ----

    // 1.0
    public const DIALOGUE_CONTEXT_SECTIONS = 'dialogue.context.sections';
    public const DIALOGUE_PROMPT_MESSAGES = 'dialogue.prompt.messages';
    // 1.1
    public const API_REQUEST = 'api.request';
    public const API_RESPONSE = 'api.response';
    public const GAME_EVENT_RECEIVED = 'game.event.received';
    public const DIALOGUE_REQUEST_RECEIVED = 'dialogue.request.received';
    public const DIALOGUE_LISTENER_RESOLVED = 'dialogue.listener.resolved';
    public const DIALOGUE_RESPONSE_SCHEMA = 'dialogue.response.schema';
    public const DIALOGUE_REPLY_PARSED = 'dialogue.reply.parsed';
    public const TTS_TEXT = 'tts.text';

    // ---- Events: observe only; their result and failures never reach the caller. ----

    // 1.0 (deferrable, see DEFERRABLE)
    public const GAME_CONTEXT_RECEIVED = 'game.context.received';
    public const DIALOGUE_REPLY_COMPLETED = 'dialogue.reply.completed';
    // 1.1 (always inline: they mark a moment that is over before a worker could run)
    public const APP_BOOT = 'app.boot';
    public const SAVE_CREATED = 'save.created';
    public const SAVE_LOADED = 'save.loaded';

    public const FILTERS = [
        self::DIALOGUE_CONTEXT_SECTIONS, self::DIALOGUE_PROMPT_MESSAGES, self::API_REQUEST, self::API_RESPONSE,
        self::GAME_EVENT_RECEIVED, self::DIALOGUE_REQUEST_RECEIVED, self::DIALOGUE_LISTENER_RESOLVED,
        self::DIALOGUE_RESPONSE_SCHEMA, self::DIALOGUE_REPLY_PARSED, self::TTS_TEXT,
    ];
    public const EVENTS = [
        self::GAME_CONTEXT_RECEIVED, self::DIALOGUE_REPLY_COMPLETED, self::APP_BOOT, self::SAVE_CREATED, self::SAVE_LOADED,
    ];

    /** Events a DeferredEventSink may take; every other event runs inline. */
    public const DEFERRABLE = [self::GAME_CONTEXT_RECEIVED, self::DIALOGUE_REPLY_COMPLETED];

    public static function all(): array
    {
        return [...self::FILTERS, ...self::EVENTS];
    }
}

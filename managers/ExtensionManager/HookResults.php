<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// What a filter may change. Every filter result is checked against its hook's rule here; a result that breaks the rule is
// discarded, counted as a failure of the extension, and the value continues unchanged (ExtensionManager::filter). The rules
// keep protocol identity fixed (ids, routes, methods) and bound everything an extension can add, so a filter can shape
// a turn but never break the contract the game client depends on.
final class HookResults
{
    public const MAX_EVENT_BYTES = 262144;
    public const MAX_BODY_BYTES = 1048576;
    public const MAX_REQUEST_TEXT = 2000;
    public const MAX_LINE_TEXT = 1000;
    public const MAX_LISTENERS = 12;
    public const MAX_LISTENER_CHARS = 100;
    public const MAX_SCHEMA_FIELDS = 8;
    public const MAX_SCHEMA_DESCRIPTION = 300;

    /** The rule for one filter hook as a callable ($result, $input): bool, or null for a hook with the built-in rule. */
    public static function ruleFor(string $hook): ?callable
    {
        return match ($hook) {
            Hooks::API_REQUEST => self::apiRequest(...),
            Hooks::API_RESPONSE => self::apiResponse(...),
            Hooks::GAME_EVENT_RECEIVED => self::gameEvent(...),
            Hooks::DIALOGUE_REQUEST_RECEIVED => static fn(array $r, array $i): bool => self::onlyText($r, $i, self::MAX_REQUEST_TEXT, false),
            Hooks::DIALOGUE_LISTENER_RESOLVED => self::listeners(...),
            Hooks::DIALOGUE_RESPONSE_SCHEMA => self::schema(...),
            Hooks::DIALOGUE_REPLY_PARSED => static fn(array $r, array $i): bool => self::onlyText($r, $i, self::MAX_LINE_TEXT, true),
            Hooks::TTS_TEXT => static fn(array $r, array $i): bool => self::onlyText($r, $i, self::MAX_LINE_TEXT, false),
            default => null,
        };
    }

    /** api.request may only add `respond`: {status: 100-599, body: object}; the method and route cannot change. */
    public static function apiRequest(array $result, array $input): bool
    {
        if (!self::sameExcept($result, $input, ['respond'])) return false;
        $respond = $result['respond'] ?? null;
        return $respond === null || self::validResponse($respond);
    }

    /** api.response may change `status` (200-599) and `body` (an object that encodes within bounds). */
    public static function apiResponse(array $result, array $input): bool
    {
        if (!self::sameExcept($result, $input, ['status', 'body'])) return false;
        return is_int($result['status'] ?? null) && $result['status'] >= 200 && $result['status'] <= 599
            && self::validBody($result['body'] ?? null);
    }

    /** game.event.received keeps the identity of the event; the rest goes through the closed contract again. */
    public static function gameEvent(array $result, array $input): bool
    {
        if (!self::sameExcept($result, $input, ['event'])) return false;
        $event = $result['event'] ?? null;
        $original = $input['event'] ?? [];
        if (!is_array($event) || array_is_list($event)) return false;
        foreach (['request_id', 'session_id', 'turn_id', 'type', 'schema', 'protocol_version', 'generation'] as $key) {
            if (($event[$key] ?? null) !== ($original[$key] ?? null)) return false;
        }
        $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) && strlen($json) <= self::MAX_EVENT_BYTES;
    }

    /** dialogue.listener.resolved may change `listeners` only: a short list of short names. */
    public static function listeners(array $result, array $input): bool
    {
        if (!self::sameExcept($result, $input, ['listeners'])) return false;
        $listeners = $result['listeners'] ?? null;
        if (!is_array($listeners) || !array_is_list($listeners) || count($listeners) > self::MAX_LISTENERS) return false;
        foreach ($listeners as $name) {
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_LISTENER_CHARS) return false;
        }
        return true;
    }

    /** dialogue.response.schema may add fields (snake_case name => short description); existing fields are fixed. */
    public static function schema(array $result, array $input): bool
    {
        if (!self::sameExcept($result, $input, ['fields'])) return false;
        $fields = $result['fields'] ?? null;
        $original = $input['fields'] ?? [];
        if (!is_array($fields) || !is_array($original)) return false;
        foreach ($original as $name => $value) {
            if (!array_key_exists($name, $fields) || $fields[$name] !== $value) return false;
        }
        $added = array_diff_key($fields, $original);
        if (count($added) > self::MAX_SCHEMA_FIELDS) return false;
        foreach ($added as $name => $description) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name) !== 1
                || !is_string($description) || mb_strlen($description) > self::MAX_SCHEMA_DESCRIPTION) return false;
        }
        return true;
    }

    // Only `text` may change: a string within the limit (empty only where $mayBeEmpty, which drops the line).
    private static function onlyText(array $result, array $input, int $limit, bool $mayBeEmpty): bool
    {
        if (!self::sameExcept($result, $input, ['text'])) return false;
        $text = $result['text'] ?? null;
        return is_string($text) && mb_strlen($text) <= $limit && ($mayBeEmpty || trim($text) !== '');
    }

    // The result has exactly the input's keys, and every key outside $mutable keeps its value.
    private static function sameExcept(array $result, array $input, array $mutable): bool
    {
        if (array_diff(array_keys($result), array_keys($input), $mutable) !== []) return false;
        foreach ($input as $key => $value) {
            if (in_array($key, $mutable, true)) continue;
            if (!array_key_exists($key, $result) || $result[$key] !== $value) return false;
        }
        return true;
    }

    private static function validResponse(mixed $respond): bool
    {
        return is_array($respond) && array_diff(array_keys($respond), ['status', 'body']) === []
            && is_int($respond['status'] ?? null) && $respond['status'] >= 100 && $respond['status'] <= 599
            && self::validBody($respond['body'] ?? null);
    }

    private static function validBody(mixed $body): bool
    {
        if (!is_array($body)) return false;
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) && strlen($json) <= self::MAX_BODY_BYTES;
    }
}

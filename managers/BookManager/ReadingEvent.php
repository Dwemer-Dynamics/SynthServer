<?php

/**
 * One thing the player read, as the client sends it in context.reading_event: a book, note, magazine,
 * terminal entry or holotape title with its bounded text.
 *
 * The text is copied game content. Nothing here executes or interprets it; it is only checked against
 * the closed contract and quoted for the prompt.
 */
declare(strict_types=1);

namespace Synth\Managers\BookManager;

use DomainException;

final class ReadingEvent
{
    // The kinds of reading the contract allows. Paper notes are private and have no kind here.
    public const KINDS = ['book', 'magazine', 'holotape', 'terminal'];
    // Longest title and text kept, in characters; the client bounds them the same way.
    public const TITLE_LIMIT = 128;
    public const TEXT_LIMIT = 4000;
    // Largest integer a wire ID may carry.
    private const MAX_WIRE_INTEGER = 9007199254740991;

    /**
     * Checks a reading against the closed contract and returns it unchanged.
     *
     * @param mixed $value The decoded payload.reading_event.
     * @return array<string, mixed> The same reading.
     * @throws DomainException When the shape, a bound or a character class is wrong.
     */
    public static function validate(mixed $value): array
    {
        $required = ['event_id', 'kind', 'title', 'source', 'text_truncated', 'age_ms'];
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), [...$required, 'text']) !== []
            || array_diff($required, array_keys($value)) !== []
            || !self::validId($value['event_id'])
            || !in_array($value['kind'], self::KINDS, true)
            || !self::validTitle($value['title'])
            || !self::validSource($value['source'])
            || !is_bool($value['text_truncated'])
            || !is_int($value['age_ms']) || $value['age_ms'] < 0 || $value['age_ms'] > self::MAX_WIRE_INTEGER) {
            throw new DomainException('invalid_reading_event');
        }
        if (array_key_exists('text', $value)) {
            if (!self::validText($value['text'])) {
                throw new DomainException('invalid_reading_event');
            }
        } elseif ($value['text_truncated']) {
            // A truncation flag with nothing kept is a contradiction.
            throw new DomainException('invalid_reading_event');
        }
        return $value;
    }

    /**
     * Tells whether an init event asks for reading events on a lane that may have them.
     *
     * @param array<string, mixed> $event The decoded wire event.
     */
    public static function isOffered(array $event): bool
    {
        return ($event['type'] ?? '') === 'init' && self::isAllowedOn($event);
    }

    /**
     * Tells whether an event may use readings: v2, flat and negotiated.
     *
     * @param array<string, mixed> $event The decoded wire event.
     */
    public static function isAllowedOn(array $event): bool
    {
        return ($event['protocol_version'] ?? 1) === 2 && ($event['runtime_variant'] ?? '') === 'flat'
            && in_array('context.reading_events', $event['capabilities'] ?? [], true);
    }

    /**
     * Writes the sentence the prompt history shows, quoting the game text as content.
     *
     * @param array<string, mixed> $reading A validated reading.
     * @param string $playerName The player's display name, already stripped of the people separator.
     */
    public static function describe(array $reading, string $playerName): string
    {
        $text = $playerName . ' read the ' . $reading['kind'] . ' "' . self::quote($reading['title']) . '".';
        if (array_key_exists('text', $reading)) {
            return $text . ' It reads: "' . self::quote($reading['text']) . '"'
                . ($reading['text_truncated'] ? ' [text truncated]' : '');
        }
        return $text . ' Only its title is known; no text was captured.';
    }

    // Makes untrusted game text safe to sit inside the quoted framing: straight quotes become typographic
    // ones, line breaks become a visible separator (so a line cannot pose as another speaker's line), and
    // Unicode line separators, bidi and zero-width controls are removed.
    private static function quote(string $text): string
    {
        $text = preg_replace('/[\x{0085}\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $text) ?? '';
        $text = preg_replace('/\s*[\r\n]+\s*/', ' / ', $text) ?? '';
        return str_replace(['"', "\u{201C}"], "\u{201D}", $text);
    }

    // Checks the reading:<n> event ID and its wire-safe range.
    private static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^reading:([1-9][0-9]{0,15})$/D', $id, $match) === 1
            && (int) $match[1] <= self::MAX_WIRE_INTEGER;
    }

    // Checks the title: UTF-8, non-blank, bounded, no control characters.
    private static function validTitle(mixed $title): bool
    {
        return is_string($title) && mb_check_encoding($title, 'UTF-8') && trim($title) !== ''
            && mb_strlen($title, 'UTF-8') <= self::TITLE_LIMIT && preg_match('/[\x00-\x1F\x7F]/', $title) !== 1;
    }

    // Checks the text: UTF-8, non-empty, bounded, newline allowed, no other control characters.
    private static function validText(mixed $text): bool
    {
        return is_string($text) && $text !== '' && mb_check_encoding($text, 'UTF-8')
            && mb_strlen($text, 'UTF-8') <= self::TEXT_LIMIT && preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text) !== 1;
    }

    // Checks the source: a real form ID and a plain plugin file name, nothing else.
    private static function validSource(mixed $source): bool
    {
        return is_array($source) && !array_is_list($source) && count($source) === 2
            && is_string($source['form_id'] ?? null) && preg_match('/^0x[0-9A-Fa-f]{8}$/D', $source['form_id']) === 1
            && preg_match('/^0x(?:00000000|[Ff]{8})$/D', $source['form_id']) !== 1
            && is_string($source['origin_plugin'] ?? null) && strlen($source['origin_plugin']) <= 255
            && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#D', $source['origin_plugin']) === 1;
    }
}

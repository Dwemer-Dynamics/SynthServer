<?php

/**
 * Rewrites the words a TTS voice says badly (for example "radroach") into spellings it says well ("rad roach").
 *
 * Only the text sent to the TTS provider changes: subtitles, the dialogue history and the queued payloads keep the line
 * as written. This class is the pure part of the dictionary: it resolves a list of rules (rows) for one speaker and
 * applies them to a text, with no database access. The rules come from the `core_tts_pronunciation` table through
 * `SynthTtsPronunciationDictionary` in `lib/tts_pronunciation.php`, whose procedural functions are thin hooks over this
 * class.
 *
 * Matching: a rule matches whole words only, ignoring case (letters, digits and "_" make up words, in any script); the
 * longest rule wins where several could match; the text is read once from left to right and replaced text is never read
 * again; the spoken text is inserted exactly as written. A custom rule beats a built-in one for the same word, and a
 * more specific rule (NPC names, races, knowledge tags) beats a less specific one.
 */
declare(strict_types=1);

namespace Synth\Lib;

final class PronunciationDictionary
{
    // Most rules a resolved list holds, rows read, and longest source and spoken texts, in bytes.
    public const MAX_RULES = 256;
    public const MAX_ROWS = 1024;
    public const MAX_SOURCE_BYTES = 120;
    public const MAX_SPOKEN_BYTES = 240;

    /** Reads a stored boolean ("1", "t", "true", "yes", "on" are true). */
    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /**
     * A comma separated tag list as unique lower-case tags of at most 64 bytes.
     *
     * @return list<string>
     */
    public static function normalizeTags(mixed $tags): array
    {
        $normalized = [];
        foreach (explode(',', strval($tags)) as $tag) {
            $tag = strtolower(trim($tag));
            if ($tag === '' || strlen($tag) > 64) {
                continue;
            }
            $normalized[$tag] = $tag;
        }

        return array_values($normalized);
    }

    /**
     * A comma separated list of names or races as unique values (case-insensitive) of at most 120 bytes.
     *
     * @return list<string>
     */
    public static function normalizeScopeValues(mixed $values): array
    {
        $normalized = [];
        foreach (explode(',', strval($values)) as $value) {
            $value = trim($value);
            if ($value === '' || strlen($value) > 120) {
                continue;
            }
            $normalized[self::lower($value)] = $value;
        }

        return array_values($normalized);
    }

    /** Whether a trimmed, case-insensitive value is one of the allowed values; an empty value never matches. */
    public static function valueMatches(string $value, array $allowedValues): bool
    {
        $value = self::lower(trim($value));
        if ($value === '') {
            return false;
        }
        foreach ($allowedValues as $allowedValue) {
            if ($value === self::lower(trim(strval($allowedValue)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a rule applies to this speaker: every populated filter (NPC names, races, knowledge tags) must match, while
     * alternatives within one filter may. A rule with tags also applies to a speaker whose tags include "knowall".
     *
     * @param array<string, mixed> $entry
     * @param list<string>|null $knowledgeTags
     */
    public static function entryAllows(array $entry, ?array $knowledgeTags = null, string $npcName = '', string $race = ''): bool
    {
        $entryNames = self::normalizeScopeValues($entry['npc_names'] ?? '');
        if ($entryNames !== [] && !self::valueMatches($npcName, $entryNames)) {
            return false;
        }
        $entryRaces = self::normalizeScopeValues($entry['races'] ?? '');
        if ($entryRaces !== [] && !self::valueMatches($race, $entryRaces)) {
            return false;
        }
        $entryTags = self::normalizeTags($entry['oghma_tags'] ?? '');
        if ($entryTags === []) {
            return true;
        }
        $knowledgeTags = array_values(array_unique(array_map(
            static fn($tag): string => strtolower(trim(strval($tag))),
            $knowledgeTags ?? []
        )));

        return in_array('knowall', $knowledgeTags, true) || array_intersect($entryTags, $knowledgeTags) !== [];
    }

    /**
     * The rules in force for one speaker, longest source first. Rows past MAX_ROWS, disabled rows, rows that do not apply
     * to the speaker and rows with an empty or over-long source or spoken text are skipped; for one source a custom rule
     * beats a built-in one and a more specific rule beats a less specific one.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $knowledgeTags
     * @return list<array{source: string, spoken: string, priority: int}>
     */
    public static function resolve(array $rows, ?array $knowledgeTags = null, string $npcName = '', string $race = ''): array
    {
        $resolved = [];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            if (!self::truthy($row['enabled'] ?? true) || !self::entryAllows($row, $knowledgeTags, $npcName, $race)) {
                continue;
            }
            $source = trim(strval($row['source_text'] ?? $row['source'] ?? ''));
            $spoken = trim(strval($row['spoken_text'] ?? $row['spoken'] ?? ''));
            if ($source === '' || $spoken === '' || strlen($source) > self::MAX_SOURCE_BYTES || strlen($spoken) > self::MAX_SPOKEN_BYTES) {
                continue;
            }
            $key = self::lower($source);
            $specificity = (self::normalizeScopeValues($row['npc_names'] ?? '') !== [] ? 1 : 0)
                + (self::normalizeScopeValues($row['races'] ?? '') !== [] ? 1 : 0)
                + (self::normalizeTags($row['oghma_tags'] ?? '') !== [] ? 1 : 0);
            $priority = (self::truthy($row['is_builtin'] ?? false) ? 0 : 10) + $specificity;
            if (isset($resolved[$key]) && $resolved[$key]['priority'] > $priority) {
                continue;
            }
            $resolved[$key] = ['source' => $source, 'spoken' => $spoken, 'priority' => $priority];
        }
        $entries = array_values($resolved);
        usort($entries, static fn(array $left, array $right): int => strlen($right['source']) <=> strlen($left['source']));

        return array_slice($entries, 0, self::MAX_RULES);
    }

    /**
     * Applies resolved rules to a text: whole words only, ignoring case, in one pass, the spoken text inserted as written.
     *
     * @param list<array{source: string, spoken: string}> $entries
     */
    public static function apply(string $text, array $entries): string
    {
        if ($text === '' || $entries === []) {
            return $text;
        }
        $replacements = [];
        $patterns = [];
        foreach ($entries as $entry) {
            $source = strval($entry['source'] ?? '');
            if ($source === '') {
                continue;
            }
            $replacements[self::lower($source)] = strval($entry['spoken'] ?? '');
            $patterns[] = preg_quote($source, '~');
        }
        if ($patterns === []) {
            return $text;
        }
        $pattern = '~(?<![\p{L}\p{N}_])(?:' . implode('|', $patterns) . ')(?![\p{L}\p{N}_])~iu';
        $replaced = preg_replace_callback(
            $pattern,
            static fn(array $match): string => $replacements[self::lower(strval($match[0] ?? ''))] ?? strval($match[0] ?? ''),
            $text
        );

        return is_string($replaced) ? $replaced : $text;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}

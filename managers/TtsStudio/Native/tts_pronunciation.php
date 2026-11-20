<?php

require_once dirname(__DIR__, 3).'/lib' . '/PronunciationDictionary.php';

// Provide Fallout pronunciations when the database has not been migrated yet.
function synthDefaultTtsPronunciationEntries(): array
{
    // Split this Fallout compound only in spoken text. This grants no lore
    // knowledge and leaves subtitles/history in their canonical spelling.
    return [
        ['source_text' => 'radroach', 'spoken_text' => 'rad roach', 'oghma_tags' => ''],
        ['source_text' => 'radroaches', 'spoken_text' => 'rad roaches', 'oghma_tags' => ''],
    ];
}

// Create the dictionary table and add missing built-in entries without changing user data.
function synthEnsureTtsPronunciationDictionary(): bool
{
    if (!isset($GLOBALS['db']) || !\Synth\Bootstrap::db()) {
        return false;
    }

    $schemaPath = dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR
        . 'database_schema' . DIRECTORY_SEPARATOR . 'core_tts_pronunciation.sql';
    if (!is_readable($schemaPath) || \Synth\Bootstrap::db()->execQuery(file_get_contents($schemaPath)) === false) {
        return false;
    }

    foreach (synthDefaultTtsPronunciationEntries() as $entry) {
        $source = \Synth\Bootstrap::db()->escapeLiteral($entry['source_text']);
        $spoken = \Synth\Bootstrap::db()->escapeLiteral($entry['spoken_text']);
        $tags = \Synth\Bootstrap::db()->escapeLiteral($entry['oghma_tags']);
        $inserted = \Synth\Bootstrap::db()->execQuery(
            "INSERT INTO public.core_tts_pronunciation
                (source_text, spoken_text, npc_names, races, oghma_tags, is_builtin, enabled, updated_at)
             SELECT {$source}, {$spoken}, '', '', {$tags}, TRUE,
                 NOT EXISTS (
                     SELECT 1 FROM public.core_tts_pronunciation existing
                     WHERE LOWER(existing.source_text) = LOWER({$source})
                       AND existing.is_builtin = FALSE AND existing.enabled = FALSE
                       AND BTRIM(existing.npc_names) = '' AND BTRIM(existing.races) = ''
                       AND BTRIM(existing.oghma_tags) = ''
                 ), CURRENT_TIMESTAMP
             ON CONFLICT DO NOTHING"
        );
        if ($inserted === false) {
            return false;
        }
    }

    return true;
}

final class SynthTtsPronunciationDictionary
{
    private const TABLE = 'core_tts_pronunciation';
    private ?bool $available = null;

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        if (!isset($GLOBALS['db']) || !\Synth\Bootstrap::db()
            || !method_exists(\Synth\Bootstrap::db(), 'escapeLiteral')
            || !method_exists(\Synth\Bootstrap::db(), 'fetchOne')) {
            return $this->available = false;
        }

        $table = \Synth\Bootstrap::db()->escapeLiteral(self::TABLE);
        $row = \Synth\Bootstrap::db()->fetchOne(
            "SELECT 1 AS present
             FROM information_schema.tables
             WHERE table_schema = 'public' AND table_name = {$table}
             LIMIT 1"
        );

        return $this->available = is_array($row) && intval($row['present'] ?? 0) === 1;
    }

    public function getRows(string $tagFilter = ''): array
    {
        if (!$this->isAvailable()) {
            return array_map(static function (array $entry): array {
                return $entry + [
                    'id' => 0,
                    'npc_names' => '',
                    'races' => '',
                    'is_builtin' => true,
                    'enabled' => true,
                ];
            }, synthDefaultTtsPronunciationEntries());
        }

        $rows = \Synth\Bootstrap::db()->fetchAll(
            'SELECT id, source_text, spoken_text, npc_names, races, oghma_tags,
                    is_builtin, enabled, created_at, updated_at
             FROM public.' . self::TABLE . '
             ORDER BY is_builtin DESC, LOWER(source_text), id
             LIMIT 1024'
        );
        $rows = is_array($rows) ? $rows : [];
        $tagFilter = strtolower(trim($tagFilter));
        if ($tagFilter === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($tagFilter): bool {
            if (synthTtsPronunciationBoolean($row['is_builtin'] ?? false)) {
                return true;
            }
            return in_array($tagFilter, synthTtsPronunciationNormalizeTags($row['oghma_tags'] ?? ''), true);
        }));
    }

    public function getAvailableTags(): array
    {
        $tags = [];
        foreach ($this->getRows() as $row) {
            if (synthTtsPronunciationBoolean($row['is_builtin'] ?? false)) {
                continue;
            }
            foreach (synthTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '') as $tag) {
                $tags[$tag] = $tag;
            }
        }
        natcasesort($tags);
        return array_values($tags);
    }

    public function saveCustom(
        ?int $id,
        string $source,
        string $spoken,
        string $npcNames,
        string $races,
        string $oghmaTags,
        bool $enabled
    ): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $source = trim($source);
        $spoken = trim($spoken);
        if ($source === '' || $spoken === '' || strlen($source) > 120 || strlen($spoken) > 240) {
            return false;
        }

        $normalizedNames = implode(', ', array_slice(synthTtsPronunciationNormalizeScopeValues($npcNames), 0, 32));
        $normalizedRaces = implode(', ', array_slice(synthTtsPronunciationNormalizeScopeValues($races), 0, 32));
        $normalizedTags = implode(', ', array_slice(synthTtsPronunciationNormalizeTags($oghmaTags), 0, 32));
        $sourceValue = \Synth\Bootstrap::db()->escapeLiteral($source);
        $spokenValue = \Synth\Bootstrap::db()->escapeLiteral($spoken);
        $namesValue = \Synth\Bootstrap::db()->escapeLiteral(substr($normalizedNames, 0, 512));
        $racesValue = \Synth\Bootstrap::db()->escapeLiteral(substr($normalizedRaces, 0, 512));
        $tagsValue = \Synth\Bootstrap::db()->escapeLiteral(substr($normalizedTags, 0, 512));
        $enabledValue = $enabled ? 'TRUE' : 'FALSE';

        if ($id !== null && $id > 0) {
            return \Synth\Bootstrap::db()->execQuery(
                "UPDATE synth." . self::TABLE . "
                 SET source_text = {$sourceValue}, spoken_text = {$spokenValue},
                     npc_names = {$namesValue}, races = {$racesValue}, oghma_tags = {$tagsValue},
                     enabled = {$enabledValue}, updated_at = CURRENT_TIMESTAMP
                 WHERE id = " . intval($id) . " AND is_builtin = FALSE"
            ) !== false;
        }

        return \Synth\Bootstrap::db()->execQuery(
            "INSERT INTO synth." . self::TABLE . "
                (source_text, spoken_text, npc_names, races, oghma_tags, is_builtin, enabled, updated_at)
             VALUES ({$sourceValue}, {$spokenValue}, {$namesValue}, {$racesValue}, {$tagsValue},
                     FALSE, {$enabledValue}, CURRENT_TIMESTAMP)"
        ) !== false;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        if ($id <= 0 || !$this->isAvailable()) {
            return false;
        }

        return \Synth\Bootstrap::db()->execQuery(
            'UPDATE public.' . self::TABLE . '
             SET enabled = ' . ($enabled ? 'TRUE' : 'FALSE') . ', updated_at = CURRENT_TIMESTAMP
             WHERE id = ' . intval($id)
        ) !== false;
    }

    public function deleteCustom(int $id): bool
    {
        if ($id <= 0 || !$this->isAvailable()) {
            return false;
        }

        return \Synth\Bootstrap::db()->execQuery(
            'DELETE FROM public.' . self::TABLE . '
             WHERE id = ' . intval($id) . ' AND is_builtin = FALSE'
        ) !== false;
    }
}

// The rule logic lives in Synth\Lib\PronunciationDictionary; these functions are the thin hooks the legacy callers use.
function synthTtsPronunciationBoolean($value): bool
{
    return \Synth\Lib\PronunciationDictionary::truthy($value);
}

function synthTtsPronunciationNormalizeTags($tags): array
{
    return \Synth\Lib\PronunciationDictionary::normalizeTags($tags);
}

function synthTtsPronunciationNormalizeScopeValues($values): array
{
    return \Synth\Lib\PronunciationDictionary::normalizeScopeValues($values);
}

function synthTtsPronunciationValueMatches(string $value, array $allowedValues): bool
{
    return \Synth\Lib\PronunciationDictionary::valueMatches($value, $allowedValues);
}

// Require every populated speaker filter while allowing alternatives within each filter.
function synthTtsPronunciationEntryAllows(
    array $entry,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): bool
{
    return \Synth\Lib\PronunciationDictionary::entryAllows($entry, $knowledgeTags, $npcName, $race);
}

// Resolve active rows with custom scoped entries taking priority over global defaults.
function synthTtsPronunciationEntries(
    ?array $rows = null,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): array
{
    if ($rows === null) {
        static $cachedRows = null;
        if ($cachedRows === null) {
            $cachedRows = (new SynthTtsPronunciationDictionary())->getRows();
        }
        $rows = $cachedRows;
    }

    return \Synth\Lib\PronunciationDictionary::resolve($rows, $knowledgeTags, $npcName, $race);
}

function synthApplyTtsPronunciationDictionary(
    string $text,
    ?array $rows = null,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): string {
    if ($text === '' || !empty($GLOBALS['SYNTH_TTS_PRONUNCIATION_BYPASS'])) {
        return $text;
    }

    return \Synth\Lib\PronunciationDictionary::apply($text, synthTtsPronunciationEntries($rows, $knowledgeTags, $npcName, $race));
}

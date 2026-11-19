<?php

// Reads and writes core_relationship_config: the authored relationship of one directed pair in one playthrough
// (docs/AUTHORED-RELATIONSHIPS.md). There are no foreign keys, so a save, a load or an NPC deletion never blocks on it, and a
// row survives its characters. Every write is one parameterized statement guarded by the revision, so it is safe inside the
// caller's legacy transaction. Prompt-safe reads never select the private notes or the lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractRelationshipConfig;

require_once __DIR__.'/UsesEntityManager.php';
require_once dirname(__DIR__, 2).'/entities/base/AbstractRelationshipConfig.php';

/** Finds, creates and edits authored relationships. */
final class RelationshipConfigDao
{
    use UsesEntityManager;

    public const ACCEPTED = 'accepted';
    public const STALE_REVISION = 'stale_revision';
    public const NOT_FOUND = 'not_found';
    public const REVISION_EXHAUSTED = 'revision_exhausted';
    public const BUSY = 'busy';

    /** At most this many target characters per prompt read, and this many pairs per prompt. */
    public const MAX_TARGETS = 8;

    private const MAX_REVISION = PHP_INT_MAX;
    private const EDITABLE = ['relation_detail', 'custom_info', 'private_notes', 'automatic_updates_locked', 'baseline_affinity', 'baseline_type'];

    /** The full row of one directed pair, or null. Includes the private notes: for the editor, never for a prompt. */
    public function read(string $playthroughId, int $sourceCharacterId, int $targetCharacterId): ?AbstractRelationshipConfig
    {
        return $this->fresh()->find(AbstractRelationshipConfig::class,
            ['playthroughId' => $playthroughId, 'sourceCharacterId' => $sourceCharacterId, 'targetCharacterId' => $targetCharacterId]);
    }

    /**
     * The prompt-safe fields of one pair (never private_notes or the lock), or null.
     *
     * @return array{relation_detail: ?string, custom_info: ?string, baseline_affinity: ?int, baseline_type: ?string, revision: int,
     *     affinity_baseline_revision: int, type_baseline_revision: int}|null
     */
    public function readPromptSafe(string $playthroughId, int $sourceCharacterId, int $targetCharacterId): ?array
    {
        $rows = $this->readForPrompt($playthroughId, $sourceCharacterId, [$targetCharacterId]);

        return $rows[$targetCharacterId] ?? null;
    }

    /**
     * Prompt-safe rows of one speaker toward up to MAX_TARGETS targets, keyed by target character id; targets without a row are absent.
     *
     * @param list<int> $targetCharacterIds
     * @return array<int, array<string, mixed>>
     */
    public function readForPrompt(string $playthroughId, int $sourceCharacterId, array $targetCharacterIds): array
    {
        $targets = array_values(array_unique(array_map('intval', $targetCharacterIds)));
        if (count($targets) > self::MAX_TARGETS) {
            throw new InvalidArgumentException('A prompt read covers at most '.self::MAX_TARGETS.' targets.');
        }
        if ($targets === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT target_character_id, relation_detail, custom_info, baseline_affinity, baseline_type, revision,
                    affinity_baseline_revision, type_baseline_revision
               FROM public.core_relationship_config
              WHERE playthrough_id = :playthrough_id AND source_character_id = :source AND target_character_id = ANY(CAST(:targets AS integer[]))',
            ['playthrough_id' => $playthroughId, 'source' => $sourceCharacterId, 'targets' => '{'.implode(',', $targets).'}']
        );
        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[(int) $row['target_character_id']] = [
                'relation_detail' => $row['relation_detail'], 'custom_info' => $row['custom_info'],
                'baseline_affinity' => $row['baseline_affinity'] === null ? null : (int) $row['baseline_affinity'],
                'baseline_type' => $row['baseline_type'], 'revision' => (int) $row['revision'],
                'affinity_baseline_revision' => (int) $row['affinity_baseline_revision'], 'type_baseline_revision' => (int) $row['type_baseline_revision'],
            ];
        }

        return $byTarget;
    }

    /**
     * Creates the row of a pair at revision 1; false when it already exists.
     *
     * @param array<string, mixed> $fields any of the editable fields
     */
    public function create(string $playthroughId, int $sourceCharacterId, int $targetCharacterId, array $fields): bool
    {
        $fields = $this->validated($fields, $sourceCharacterId, $targetCharacterId);

        return $this->connection()->executeStatement(
            'INSERT INTO public.core_relationship_config (playthrough_id, source_character_id, target_character_id, relation_detail, custom_info,
               private_notes, automatic_updates_locked, baseline_affinity, baseline_type, affinity_baseline_revision, type_baseline_revision)
             VALUES (:playthrough_id, :source, :target, :relation_detail, :custom_info, :private_notes, :locked, CAST(:baseline_affinity AS smallint), CAST(:baseline_type AS varchar),
               CASE WHEN CAST(:baseline_affinity AS smallint) IS NULL THEN 0 ELSE 1 END, CASE WHEN CAST(:baseline_type AS varchar) IS NULL THEN 0 ELSE 1 END)
             ON CONFLICT DO NOTHING',
            ['playthrough_id' => $playthroughId, 'source' => $sourceCharacterId, 'target' => $targetCharacterId,
                'relation_detail' => $fields['relation_detail'] ?? null, 'custom_info' => $fields['custom_info'] ?? null,
                'private_notes' => $fields['private_notes'] ?? null, 'locked' => (bool) ($fields['automatic_updates_locked'] ?? false),
                'baseline_affinity' => $fields['baseline_affinity'] ?? null, 'baseline_type' => $fields['baseline_type'] ?? null],
            ['locked' => \Doctrine\DBAL\ParameterType::BOOLEAN]
        ) === 1;
    }

    /**
     * Applies the given editable fields when the stored revision is still the expected one, and raises the revision by one.
     * A changed baseline records the revision it was last set at, so evaluators can tell stale work.
     *
     * @param array<string, mixed> $fields only the keys to change; null clears a nullable field
     * @return string one of the class answer constants
     */
    public function update(string $playthroughId, int $sourceCharacterId, int $targetCharacterId, array $fields, int $expectedRevision): string
    {
        $fields = $this->validated($fields, $sourceCharacterId, $targetCharacterId);
        if ($expectedRevision >= self::MAX_REVISION) {
            return self::REVISION_EXHAUSTED;
        }
        $sets = ['revision = revision + 1', 'updated_at = now()'];
        $params = ['playthrough_id' => $playthroughId, 'source' => $sourceCharacterId, 'target' => $targetCharacterId, 'expected' => $expectedRevision];
        $types = [];
        foreach ($fields as $column => $value) {
            $sets[] = $column.' = :'.$column;
            $params[$column] = $value;
            if ($column === 'automatic_updates_locked') {
                $types[$column] = \Doctrine\DBAL\ParameterType::BOOLEAN;
            }
        }
        if (array_key_exists('baseline_affinity', $fields)) {
            $sets[] = 'affinity_baseline_revision = revision + 1';
        }
        if (array_key_exists('baseline_type', $fields)) {
            $sets[] = 'type_baseline_revision = revision + 1';
        }
        try {
            $changed = $this->connection()->executeStatement(
                'UPDATE public.core_relationship_config SET '.implode(', ', $sets).'
                  WHERE playthrough_id = :playthrough_id AND source_character_id = :source AND target_character_id = :target AND revision = :expected',
                $params,
                $types
            );
        } catch (DbalException $error) {
            // lock_not_available (55P03) and query_canceled by a lock timeout (57014): another writer holds the row.
            if (in_array((string) $error->getPrevious()?->getCode(), ['55P03', '57014'], true) || str_contains($error->getMessage(), 'lock timeout')) {
                return self::BUSY;
            }
            throw $error;
        }
        if ($changed === 1) {
            return self::ACCEPTED;
        }
        $exists = $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_relationship_config WHERE playthrough_id = :playthrough_id AND source_character_id = :source AND target_character_id = :target',
            ['playthrough_id' => $playthroughId, 'source' => $sourceCharacterId, 'target' => $targetCharacterId]
        );

        return $exists === false ? self::NOT_FOUND : self::STALE_REVISION;
    }

    /**
     * Keeps only editable keys and checks their bounds, so the database checks are a second line, not the first.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function validated(array $fields, int $source, int $target): array
    {
        if ($source < 1 || $target < 1 || $source === $target) {
            throw new InvalidArgumentException('A relationship needs two different characters.');
        }
        $fields = array_intersect_key($fields, array_flip(self::EDITABLE));
        $limits = ['relation_detail' => 512, 'custom_info' => 4096, 'private_notes' => 4096];
        foreach ($limits as $column => $limit) {
            if (isset($fields[$column]) && mb_strlen((string) $fields[$column]) > $limit) {
                throw new InvalidArgumentException($column.' is longer than '.$limit.' characters.');
            }
        }
        if (isset($fields['baseline_affinity']) && ((int) $fields['baseline_affinity'] < -100 || (int) $fields['baseline_affinity'] > 100)) {
            throw new InvalidArgumentException('baseline_affinity must be between -100 and 100.');
        }
        if (isset($fields['baseline_type']) && preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', (string) $fields['baseline_type']) !== 1) {
            throw new InvalidArgumentException('baseline_type is not a valid type name.');
        }

        return $fields;
    }
}

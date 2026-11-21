<?php

// Reads core_memory and runs the diary-page and memory-maintenance statements on it, one row or one filter at a time. No
// method here begins a transaction or takes a lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemory;

/** Finds rows of core_memory. */
class MemoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $rowid): ?AbstractMemory
    {
        return $this->fresh()->find(AbstractMemory::class, $rowid);
    }

    /** How many legacy summary memories there are. */
    public function countSummaries(): int
    {
        return (int) $this->connection()->fetchOne("SELECT count(*) AS n FROM public.core_memory WHERE source_type='summary'");
    }

    /** The newest legacy diary entry (topic, content) written for one companion name, or null. @return array<string, mixed>|null */
    public function latestDiaryEntry(string $companion): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT topic, summary AS content
                 FROM public.core_memory
                 WHERE source_type = 'diary' AND lower(trim(companions)) = lower(?)
                 ORDER BY gamets DESC, localts DESC, rowid DESC
                 LIMIT 1",
            [$companion]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemory::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    private const DIARY_COLUMNS = 'rowid, topic, summary AS content, tags, companions AS people, location, localts, gamets';
    private const DIARY_NOT_NOTES = "topic NOT IN ('Sent Letter', 'Journal Note')";

    /** People named in diary entries with their entry counts, most entries first. @return list<array<string, mixed>> */
    public function diaryPeopleCounts(): array
    {
        return $this->connection()->fetchAllAssociative("
            WITH split_people AS (
                SELECT d.rowid, trim(unnest(string_to_array(trim(d.companions, '|'), '|'))) AS person
                FROM public.core_memory d
                WHERE d.source_type = 'diary' AND d.companions IS NOT NULL AND d.companions != ''
                  AND d.topic NOT IN ('Sent Letter', 'Journal Note')
            )
            SELECT person, COUNT(DISTINCT rowid) AS entry_count
            FROM split_people WHERE person != ''
            GROUP BY person ORDER BY entry_count DESC, person ASC");
    }

    /** Diary entries whose companions contain $person, newest first. @return list<array<string, mixed>> */
    public function diaryEntriesForPerson(string $person): array
    {
        return $this->connection()->fetchAllAssociative('SELECT '.self::DIARY_COLUMNS." FROM public.core_memory
            WHERE source_type = 'diary' AND companions LIKE ? AND ".self::DIARY_NOT_NOTES.' ORDER BY localts DESC', ['%'.$person.'%']);
    }

    /** Diary entries written between two local timestamps (inclusive), oldest first. @return list<array<string, mixed>> */
    public function diaryEntriesBetween(int $start, int $end): array
    {
        return $this->connection()->fetchAllAssociative('SELECT '.self::DIARY_COLUMNS." FROM public.core_memory
            WHERE source_type = 'diary' AND localts >= ? AND localts <= ? AND ".self::DIARY_NOT_NOTES.' ORDER BY localts ASC', [$start, $end]);
    }

    /** Diary entries with a game time, in game-time order. @return list<array<string, mixed>> */
    public function diaryEntriesWithGameTime(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT '.self::DIARY_COLUMNS." FROM public.core_memory
            WHERE source_type = 'diary' AND gamets > 0 AND ".self::DIARY_NOT_NOTES.' ORDER BY gamets ASC');
    }

    /** Every diary entry, oldest first. @return list<array<string, mixed>> */
    public function diaryEntriesAll(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT '.self::DIARY_COLUMNS." FROM public.core_memory
            WHERE source_type = 'diary' AND ".self::DIARY_NOT_NOTES.' ORDER BY localts ASC');
    }

    /**
     * The entries the diary page lists for one selected day: every entry with a game time in Fallout mode (the page filters
     * by game date), else the entries inside the local timestamp window.
     *
     * @return list<array<string, mixed>>
     */
    public function diaryEntriesForDay(bool $falloutTime, int $start, int $end): array
    {
        $where = $falloutTime ? 'gamets > 0' : 'localts >= ? AND localts <= ?';

        return $this->connection()->fetchAllAssociative('SELECT '.self::DIARY_COLUMNS." FROM public.core_memory
            WHERE source_type = 'diary' AND (".$where.') AND '.self::DIARY_NOT_NOTES.' ORDER BY localts ASC',
            $falloutTime ? [] : [$start, $end]);
    }

    /** Diary entries with a game time, for the calendar highlights. @return list<array<string, mixed>> */
    public function diaryCalendarEntries(bool $falloutTime): array
    {
        $sort = $falloutTime ? 'gamets' : 'localts';

        return $this->connection()->fetchAllAssociative("SELECT DISTINCT rowid, gamets, localts, topic, summary AS content, tags,
                companions AS people, location, to_char(to_timestamp(CAST(localts AS bigint)), 'YYYY-MM-DD') AS date, {$sort} AS sort_field
            FROM public.core_memory
            WHERE source_type = 'diary' AND gamets > 0 AND ".self::DIARY_NOT_NOTES.' ORDER BY sort_field ASC');
    }

    /** The diary book of one person: their entries, unordered. @return list<array<string, mixed>> */
    public function diaryBookEntries(string $person): array
    {
        return $this->connection()->fetchAllAssociative("SELECT rowid, summary AS content, companions AS people, location, localts, gamets
            FROM public.core_memory WHERE source_type = 'diary' AND companions LIKE ?", ['%'.$person.'%']);
    }

    /** Deletes one diary entry. */
    public function deleteDiaryEntry(int $rowid): void
    {
        $this->connection()->executeStatement("DELETE FROM public.core_memory WHERE source_type = 'diary' AND rowid = ?", [$rowid]);
    }

    /** Deletes every diary entry and returns how many went. */
    public function deleteAllDiaryEntries(): int
    {
        return (int) $this->connection()->executeStatement("DELETE FROM public.core_memory WHERE source_type = 'diary'");
    }

    /** Rewrites the topic and text of one diary entry. */
    public function updateDiaryEntry(int $rowid, string $topic, string $content): void
    {
        $this->connection()->executeStatement("UPDATE public.core_memory SET topic = ?, summary = ? WHERE source_type = 'diary' AND rowid = ?",
            [$topic, $content, $rowid]);
    }

    private const SYNC_FILTER = " source_type='summary' AND summary IS NOT NULL";
    private const SYNC_MISSING = ' AND (embedding IS NULL OR native_vec IS NULL)';

    /** How many summaries a resync would touch: all of them, or those missing a vector. */
    public function summarySyncCount(bool $forceAll): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_memory WHERE'.self::SYNC_FILTER.($forceAll ? '' : self::SYNC_MISSING));
    }

    /** The summaries a resync would touch. @return list<array<string, mixed>> */
    public function summarySyncRows(bool $forceAll): array
    {
        return $this->connection()->fetchAllAssociative('SELECT summary AS content, uid, classifier, rowid, companions FROM core_memory WHERE'
            .self::SYNC_FILTER.($forceAll ? '' : self::SYNC_MISSING));
    }

    /** Saves the extracted tags, defaults a missing scope to global and rebuilds the full-text vector of one memory. */
    public function saveTagsAndVector(int $rowid, string $tags): void
    {
        $connection = $this->connection();
        $connection->executeStatement('UPDATE core_memory SET tags = ? WHERE rowid = ?', [$tags, $rowid]);
        $connection->executeStatement("UPDATE core_memory SET scope='global' WHERE rowid = ? AND scope IS NULL", [$rowid]);
        $this->refreshSearchVector($rowid);
    }

    /** Rebuilds the full-text vector (tags weigh A, summary B) of one memory. */
    public function refreshSearchVector(int $rowid): void
    {
        $this->connection()->executeStatement("UPDATE core_memory SET native_vec = setweight(to_tsvector(coalesce(tags, '')), 'A') || setweight(to_tsvector(coalesce(summary, '')), 'B') WHERE rowid = ?", [$rowid]);
    }

    /** Sets blank companions (only separators and spaces) to NULL. */
    public function clearBlankCompanions(): void
    {
        $this->connection()->executeStatement("UPDATE core_memory SET companions=NULL WHERE btrim(COALESCE(companions, ''), '|, ') = ''");
    }

    /** Clears the companions of every dialogue memory. */
    public function clearDialogueCompanions(): void
    {
        $this->connection()->executeStatement("UPDATE core_memory SET companions=NULL WHERE classifier='dialogue'");
    }

    /** Non-diary summaries with no companions, oldest first (whole rows). @return list<array<string, mixed>> */
    public function summariesMissingCompanions(): array
    {
        return $this->connection()->fetchAllAssociative("SELECT * FROM core_memory WHERE source_type='summary' AND (companions IS NULL OR companions = '') and classifier<>'diary' ORDER BY gamets_truncated ASC");
    }

    /** Sets the companions of one memory. */
    public function setCompanions(int $rowid, string $companions): void
    {
        $this->connection()->executeStatement('UPDATE core_memory SET companions = ? WHERE rowid = ?', [$companions, $rowid]);
    }

    /** The distinct speakers and listeners of memory_v within a game-time window, joined with '|' (null when none). */
    public function participantsInWindow(int $gametsFrom, int $gametsTo): ?string
    {
        $value = $this->connection()->fetchOne("SELECT STRING_AGG(DISTINCT person, '|') AS people FROM synth.memory_v
            CROSS JOIN LATERAL unnest(array[speaker, listener]) AS participant(person) WHERE gamets > ? AND gamets <= ?", [$gametsFrom, $gametsTo]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** The newest game time of the summaries scoped to one NPC (0 when there are none). */
    public function lastScopedGamets(string $scope): int
    {
        return (int) $this->connection()->fetchOne("SELECT COALESCE(MAX(gamets_truncated), 0) FROM core_memory WHERE source_type='summary' AND scope = ?", [$scope]);
    }

    /**
     * Global summaries newer than $afterGamets in whose game-time window ($window either side) the NPC took part in an event
     * or a dialogue, oldest first, at most 500.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingIndividualSummaries(string $npcName, int $afterGamets, int $window): array
    {
        return $this->connection()->fetchAllAssociative("
            SELECT ms.rowid, ms.uid, ms.gamets_truncated, ms.summary, ms.packed_message
            FROM core_memory ms
            WHERE source_type='summary' AND ms.summary IS NOT NULL
              AND (ms.scope IS NULL OR ms.scope='global')
              AND ms.gamets_truncated > ?
              AND (
                    EXISTS (
                        SELECT 1 FROM core_context_event ev
                        WHERE ev.gamets > ms.gamets_truncated - ? AND ev.gamets <= ms.gamets_truncated + ?
                          AND (
                                (CASE WHEN COALESCE(ev.party, '[]') = '[]' THEN COALESCE(ev.people, '') ELSE COALESCE(ev.people, ev.party) END) ILIKE ?
                                OR
                                (CASE WHEN COALESCE(ev.party, '[]') = '[]' THEN COALESCE(ev.people, '') ELSE COALESCE(ev.people, ev.party) END) ILIKE ?
                              )
                    )
                    OR EXISTS (
                        SELECT 1 FROM memory_v mv
                        WHERE mv.gamets > ms.gamets_truncated - ? AND mv.gamets <= ms.gamets_truncated + ?
                          AND (LOWER(COALESCE(mv.speaker, '')) = LOWER(?) OR LOWER(COALESCE(mv.listener, '')) = LOWER(?))
                    )
                  )
            ORDER BY ms.gamets_truncated ASC, ms.rowid ASC
            LIMIT 500", [$afterGamets, $window, $window, '%|'.$npcName.'|%', '%'.$npcName.'%', $window, $window, $npcName, $npcName]);
    }

    /** The text of the newest summary scoped to one NPC, or null. */
    public function latestScopedSummary(string $scope): ?string
    {
        $value = $this->connection()->fetchOne("SELECT summary FROM core_memory WHERE source_type='summary' AND scope = ? AND summary IS NOT NULL ORDER BY gamets_truncated DESC, rowid DESC LIMIT 1", [$scope]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** Adds one individual (NPC-scoped) summary. */
    public function insertIndividualSummary(int $gametsTruncated, int $n, string $packedMessage, string $summary, int $uid, string $npcName, string $tags): void
    {
        $this->connection()->insert('core_memory', [
            'gamets_truncated' => $gametsTruncated, 'n' => $n, 'packed_message' => $packedMessage, 'summary' => $summary,
            'classifier' => 'individual', 'uid' => $uid, 'companions' => '|'.$npcName.'|', 'scope' => $npcName, 'tags' => $tags,
        ]);
    }

    /** How many summaries a compaction has to write: newer than $maxRow, or with no summary yet. */
    public function compactionCount(int $maxRow): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM core_memory WHERE source_type='summary' AND (gamets_truncated > ? OR summary IS NULL)", [$maxRow]);
    }

    /** The summaries a compaction has to write, oldest first. @return list<array<string, mixed>> */
    public function compactionRows(int $maxRow): array
    {
        return $this->connection()->fetchAllAssociative("SELECT gamets_truncated, packed_message, uid, classifier, rowid, companions FROM core_memory WHERE source_type='summary' AND (gamets_truncated > ? OR summary IS NULL) ORDER BY gamets_truncated ASC", [$maxRow]);
    }

    /** The summary row just before a game time, or null. @return array<string, mixed>|null */
    public function previousSummaryBefore(int $gametsTruncated): ?array
    {
        $row = $this->connection()->fetchAssociative("SELECT gamets_truncated, packed_message, uid, classifier, rowid, companions, summary FROM core_memory WHERE source_type='summary' AND gamets_truncated < ? ORDER BY gamets_truncated DESC LIMIT 1", [$gametsTruncated]);

        return $row === false ? null : $row;
    }

    /** Stores a compacted summary with its tags (a missing scope becomes global) and rebuilds its full-text vector. */
    public function saveCompactedSummary(int $rowid, string $summary, string $tags): void
    {
        $this->connection()->executeStatement("UPDATE core_memory SET summary = ?, tags = ?, scope = COALESCE(scope,'global') WHERE rowid = ?", [$summary, $tags, $rowid]);
        $this->refreshSearchVector($rowid);
    }

    /** Deletes every summary memory. */
    public function deleteSummaries(): void
    {
        $this->connection()->executeStatement("DELETE FROM core_memory WHERE source_type='summary'");
    }

    /** Number of memory rows of one source type, such as diary or summary (home dashboard). */
    public function dashboardCountBySource(string $sourceType): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_memory WHERE source_type = ?', [$sourceType]);
    }

    /** The newest diary memory, as a list of at most one row (home dashboard). @return list<array<string, mixed>> */
    public function dashboardLatestDiary(): array
    {
        return $this->connection()->fetchAllAssociative("SELECT rowid, topic, summary AS content, companions AS people, location, localts, gamets FROM core_memory WHERE source_type='diary' ORDER BY gamets DESC, rowid DESC LIMIT 1");
    }

    /**
     * The newest sent letters and journal notes written for this actor after a game time (content, gamets, topic), at most $limit.
     *
     * @return list<array<string, mixed>>
     */
    public function recentLettersAndNotes(string $actor, int $afterGamets, int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT summary AS content, gamets, topic FROM public.core_memory WHERE source_type='diary' AND companions = ? AND gamets > ? AND (topic='Sent Letter' OR topic='Journal Note') ORDER BY gamets DESC, ts DESC LIMIT ".max(1, $limit),
            [$actor, $afterGamets]
        );
    }

    /** Deletes one memory row. */
    public function deleteRow(int $rowid): void
    {
        $this->connection()->executeStatement('DELETE FROM public.core_memory WHERE rowid = ?', [$rowid]);
    }

    /** Saves the three fields the memory editors change. */
    public function updateEditable(int $rowid, string $summary, string $tags, string $companions): void
    {
        $this->connection()->executeStatement(
            'UPDATE public.core_memory SET summary = ?, tags = ?, companions = ? WHERE rowid = ?',
            [$summary, $tags, $companions, $rowid]
        );
    }

    /** Topic and content of every diary entry, newest first. @return list<array<string,mixed>> */
    public function diaryExport(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT topic, summary AS content FROM public.core_memory WHERE source_type = 'diary' ORDER BY rowid DESC"
        );
    }

    /** The newest diary entries, by game time. @return list<array<string,mixed>> */
    public function recentDiary(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT a.topic, a.summary AS content, a.tags, a.companions AS people, a.location, a.localts, a.gamets, a.ts, a.sess, a.rowid'
            ." FROM public.core_memory a WHERE a.source_type = 'diary' ORDER BY gamets DESC, rowid DESC LIMIT ?",
            [$limit]
        );
    }

    /** One diary entry (rowid, content, people), or null. @return array<string,mixed>|null */
    public function diaryEntry(int $rowid): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT rowid, summary AS content, companions AS people FROM public.core_memory WHERE source_type = 'diary' AND rowid = ? LIMIT 1",
            [$rowid]
        );

        return is_array($row) ? $row : null;
    }

    /** The newest summaries with their uid, for the dashboard table. @return list<array<string,mixed>> */
    public function recentSummariesWithUid(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT gamets_truncated, n, summary, companions, tags, classifier, uid, rowid, packed_message, native_vec'
            ." FROM public.core_memory WHERE source_type = 'summary' ORDER BY gamets_truncated DESC, rowid DESC LIMIT ?",
            [$limit]
        );
    }

    /** The newest summaries with their scope, for the Events and Memories page. @return list<array<string,mixed>> */
    public function recentSummariesWithScope(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT gamets_truncated, n, summary, companions, tags, classifier, scope, rowid, packed_message, native_vec'
            ." FROM public.core_memory WHERE source_type = 'summary' ORDER BY gamets_truncated DESC, rowid DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Inserts one diary memory row (column => value, every column a plain scalar); returns false when the statement fails.
     *
     * @param array<string, scalar|null> $row
     */
    public function insertDiaryEntry(array $row): bool
    {
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string) $column)) {
                throw new \InvalidArgumentException('Invalid core_memory column.');
            }
        }
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_memory ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
                array_values($row)
            );
        } catch (\Throwable $error) {
            return false;
        }

        return true;
    }
}

<?php

// Reads core_memory. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemory;

/** Finds rows of core_memory. */
final class MemoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $rowid): ?AbstractMemory
    {
        return $this->fresh()->find(AbstractMemory::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemory::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
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
}

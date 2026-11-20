<?php

// Reads core_narrator_transcript_entry. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorTranscriptEntry;

/** Finds rows of core_narrator_transcript_entry. */
final class NarratorTranscriptEntryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $entryId): ?AbstractNarratorTranscriptEntry
    {
        return $this->fresh()->find(AbstractNarratorTranscriptEntry::class, $entryId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorTranscriptEntry> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorTranscriptEntry::class)->findBy([], ['entryId' => 'ASC'], max(1, $limit)));
    }
}

<?php

// Reads core_narrator_transcript_cursor. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorTranscriptCursor;

/** Finds rows of core_narrator_transcript_cursor. */
final class NarratorTranscriptCursorDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $cursorId): ?AbstractNarratorTranscriptCursor
    {
        return $this->fresh()->find(AbstractNarratorTranscriptCursor::class, $cursorId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorTranscriptCursor> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorTranscriptCursor::class)->findBy([], ['cursorId' => 'ASC'], max(1, $limit)));
    }
}

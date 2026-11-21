<?php

// Reads core_speech. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSpeech;

/** Finds rows of core_speech. */
final class SpeechDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractSpeech
    {
        return $this->fresh()->find(AbstractSpeech::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSpeech> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSpeech::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** The highest gamets of a line spoken by, to or in company of this actor, or null when there is none. */
    public function lastGametsFor(string $actor): ?string
    {
        $last = $this->connection()->fetchOne(
            'SELECT max(gamets) FROM core_speech WHERE speaker = :actor OR listener = :actor OR companions LIKE :company',
            ['actor' => $actor, 'company' => '%|'.$actor.'|%']
        );

        return $last === false || $last === null ? null : (string) $last;
    }
}

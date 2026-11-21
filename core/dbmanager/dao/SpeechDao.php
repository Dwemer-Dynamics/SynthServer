<?php

// Reads core_speech. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSpeech;
use Doctrine\DBAL\Exception as DbalException;

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

    /** The speaker of the newest core_speech row, or null when there is none. */
    public function latestSpeaker(): ?string
    {
        $speaker = $this->connection()->fetchOne('SELECT speaker FROM public.core_speech ORDER BY rowid DESC LIMIT 1');

        return $speaker === false ? null : (string) $speaker;
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

    private const COLUMNS = ['ts', 'gamets', 'listener', 'speaker', 'speech', 'location', 'companions', 'sess', 'audios', 'utterance_id', 'topic', 'localts'];

    /**
     * Appends one spoken line (column => value over the known columns; others are refused). False when the statement fails or
     * nothing valid was given.
     *
     * @param array<string, mixed> $row
     */
    public function record(array $row): bool
    {
        $row = array_intersect_key($row, array_flip(self::COLUMNS));
        if ($row === []) {
            return false;
        }
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_speech ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')',
                array_values($row)
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Removes every spoken line (the wipe command). */
    public function deleteAll(): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_speech');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** The speaker of the newest spoken line, or null when there is none. */
    public function lastSpeaker(): ?string
    {
        $speaker = $this->connection()->fetchOne('SELECT speaker FROM public.core_speech ORDER BY rowid DESC LIMIT 1');

        return $speaker === false || $speaker === null ? null : (string) $speaker;
    }

    /**
     * Replaces the text of the pending player lines (speaker matching $speakerPattern, within 100 gamets of $gamets) with
     * $speech (the Auto Chat rewrite). False when the statement fails.
     */
    public function rewritePendingPlayerSpeech(string $speakerPattern, string $speech, int $gamets): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_speech SET speech = ? WHERE speaker ILIKE ? AND gamets >= ? - 100 AND gamets <= ? + 100 AND sess = 'pending'",
                [$speech, $speakerPattern, $gamets, $gamets]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}

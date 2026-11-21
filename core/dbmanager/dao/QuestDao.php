<?php

// Reads core_quest. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractQuest;

/** Finds rows of core_quest. */
final class QuestDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractQuest
    {
        return $this->fresh()->find(AbstractQuest::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractQuest> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractQuest::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    private const COLUMNS = ['ts', 'gamets', 'name', 'editor_id', 'briefing', 'briefing2', 'data', 'stage', 'giver_actor_id', 'id_quest', 'sess', 'status', 'localts'];

    /**
     * Appends one quest row (column => value over the known columns; others are refused). False when the statement fails or
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
                'INSERT INTO public.core_quest ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')',
                array_values($row)
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Removes the quest rows of one projection session. False when the statement fails. */
    public function deleteBySession(string $sess): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_quest WHERE sess = ?', [$sess]);
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Removes every quest row (the wipe command). */
    public function deleteAll(): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_quest');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}

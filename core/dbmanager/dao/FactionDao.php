<?php

// Reads core_faction. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractFaction;

/** Finds rows of core_faction. */
class FactionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $formid): ?AbstractFaction
    {
        return $this->fresh()->find(AbstractFaction::class, $formid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractFaction> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractFaction::class)->findBy([], ['formid' => 'ASC'], max(1, $limit)));
    }

    /**
     * Faction names for the given (already upper-cased) form ids. Throws when the table is unreadable, as older
     * installs can reach this before the faction migration.
     *
     * @param list<string> $formIds
     * @return list<string>
     */
    public function namesByFormIds(array $formIds): array
    {
        $in = implode(',', array_fill(0, count($formIds), '?'));

        return array_map('strval', $this->connection()->fetchFirstColumn(
            'SELECT name FROM public.core_faction WHERE upper(formid) IN ('.$in.')',
            array_values($formIds)
        ));
    }
}

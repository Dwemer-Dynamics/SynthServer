<?php

// Reads core_faction. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractFaction;
use Doctrine\DBAL\Driver\Exception as DriverException;

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

    /** Empties the faction table (a full replace from the game). False when the statement fails. */
    public function truncate(): bool
    {
        try {
            $this->connection()->executeStatement('TRUNCATE TABLE public.core_faction');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Stores one faction by formid, replacing its name and vendor container. False when the statement fails. */
    public function upsert(string $name, string $formid, string $vendorCont): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_faction (name, formid, vendor_cont) VALUES (?, ?, ?) '
                .'ON CONFLICT (formid) DO UPDATE SET name = EXCLUDED.name, vendor_cont = EXCLUDED.vendor_cont',
                [$name, $formid, $vendorCont]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Replaces one faction's market stock (JSON text), caps and the player's rank. False when the statement fails. */
    public function updateStock(string $formid, string $stockJson, int $caps, int $playerRank): bool
    {
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_faction SET stock = CAST(? AS jsonb), caps = ?, player_rank = ? WHERE formid = ?',
                [$stockJson, $caps, $playerRank, $formid]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
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

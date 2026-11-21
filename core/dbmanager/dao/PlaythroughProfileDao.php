<?php

// Reads core_playthrough_profile. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractPlaythroughProfile;

/** Finds rows of core_playthrough_profile. */
final class PlaythroughProfileDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractPlaythroughProfile
    {
        return $this->fresh()->find(AbstractPlaythroughProfile::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractPlaythroughProfile> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractPlaythroughProfile::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** Rows for the playthrough manager, most recent game time first. @return list<array<string, mixed>> */
    public function managerRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, name, created_at, size_bytes, storage_type, schema_name, notes, is_active, player_name, game, eventlog_count, worldknowledge_count, last_gamets FROM public.core_playthrough_profile ORDER BY COALESCE(last_gamets,0) DESC, created_at DESC');
    }
}

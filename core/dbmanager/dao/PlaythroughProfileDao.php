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
}

<?php

// Reads core_profiles. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractProfile;


/** Finds rows of core_profiles. */
final class ProfileDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractProfile
    {
        return $this->fresh()->find(AbstractProfile::class, $id);
    }

    /** Every row, ordered by id. @return list<AbstractProfile> */
    public function findAll(): array
    {
        return array_values($this->fresh()->getRepository(AbstractProfile::class)->findBy([], ['id' => 'ASC']));
    }
}

<?php

// Reads core_media_object. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMediaObject;

/** Finds rows of core_media_object. */
final class MediaObjectDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $mediaId): ?AbstractMediaObject
    {
        return $this->fresh()->find(AbstractMediaObject::class, $mediaId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMediaObject> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMediaObject::class)->findBy([], ['mediaId' => 'ASC'], max(1, $limit)));
    }
}

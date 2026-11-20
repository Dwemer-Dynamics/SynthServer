<?php

// Reads core_relationship_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipRevision;

/** Finds rows of core_relationship_revision. */
final class RelationshipRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipRevision
    {
        return $this->fresh()->find(AbstractRelationshipRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}

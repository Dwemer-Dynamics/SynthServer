<?php

// Reads core_relationship_response_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipResponseInput;

/** Finds rows of core_relationship_response_input. */
final class RelationshipResponseInputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipResponseInput
    {
        return $this->fresh()->find(AbstractRelationshipResponseInput::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipResponseInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipResponseInput::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}

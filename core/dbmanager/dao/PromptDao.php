<?php

// Reads core_prompt. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractPrompt;

/** Finds rows of core_prompt. */
final class PromptDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $promptKey): ?AbstractPrompt
    {
        return $this->fresh()->find(AbstractPrompt::class, $promptKey);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractPrompt> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractPrompt::class)->findBy([], ['promptKey' => 'ASC'], max(1, $limit)));
    }
}

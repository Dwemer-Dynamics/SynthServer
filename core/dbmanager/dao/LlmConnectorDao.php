<?php

// Reads core_llm_connector. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractLlmConnector;


/** Finds rows of core_llm_connector. */
final class LlmConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractLlmConnector
    {
        return $this->entityManager->find(AbstractLlmConnector::class, $id);
    }

    /** The id of the connector whose label equals this one, ignoring case, or null. */
    public function findIdByLabel(string $label): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_llm_connector WHERE LOWER(COALESCE(label,'')) = LOWER(?) LIMIT 1",
            [$label]
        );

        return $id === false ? null : (int) $id;
    }

    /** Id and display label (the label, or the model when it is empty) of every connector, by display label. @return list<array<string, mixed>> */
    public function displayOptions(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT id, COALESCE(NULLIF(label,''), model) AS label FROM public.core_llm_connector ORDER BY LOWER(COALESCE(NULLIF(label,''), model)) ASC"
        );
    }

    /** Id and label of every connector, by label then id (the generic foreign-key picker). @return list<array<string, mixed>> */
    public function labelOptions(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, label FROM public.core_llm_connector ORDER BY LOWER(label) ASC, id ASC');
    }

    /** Every row, ordered by id. @return list<AbstractLlmConnector> */
    public function findAll(): array
    {
        return array_values($this->entityManager->getRepository(AbstractLlmConnector::class)->findBy([], ['id' => 'ASC']));
    }
}

<?php

// Reads core_rechat_selection. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatSelection;

/** Finds rows of core_rechat_selection. */
final class RechatSelectionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractRechatSelection
    {
        return $this->fresh()->find(AbstractRechatSelection::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatSelection> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatSelection::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The prepared npc/narrator selection of a request whose reserved continuation belongs to the chain at this generation,
     * context sequence, turn and runtime variant: role and identity JSON. Null when there is none or the statement failed.
     *
     * @return array<string, mixed>|null
     */
    public function preparedReservedSelection(string $sessionId, string $requestId, int $generation, int $contextSequence, string $turnId, string $runtimeVariant): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT selected.role, selected.identity
                 FROM public.core_rechat_selection selected
                 JOIN public.core_rechat_continuation continuation USING(session_id, request_id)
                 JOIN public.core_rechat_chain chain ON chain.id = continuation.chain_id AND chain.session_id = continuation.session_id
                 WHERE selected.session_id = :session AND selected.request_id = :request AND selected.prepared_at IS NOT NULL
                    AND selected.role IN ('npc','narrator') AND continuation.status = 'reserved'
                    AND chain.generation = :generation AND chain.context_sequence = :sequence AND chain.turn_id = :turn AND chain.runtime_variant = :variant",
                ['session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'sequence' => $contextSequence, 'turn' => $turnId, 'variant' => $runtimeVariant]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        return $row === false ? null : $row;
    }
}

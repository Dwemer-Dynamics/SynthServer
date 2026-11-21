<?php

// Reads core_action_intent. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionIntent;

/** Finds rows of core_action_intent. */
final class ActionIntentDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $actionId): ?AbstractActionIntent
    {
        return $this->fresh()->find(AbstractActionIntent::class, $actionId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActionIntent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActionIntent::class)->findBy([], ['actionId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The live intent of an action with its actor and target identities. Parameters, in order: action id, idempotency key,
     * session id, request id, generation, turn id, deadline in milliseconds. [] when none is live, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function liveForEmission(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT i.*,a.full_form_id,a.origin_plugin,a.playthrough_id,
                target.full_form_id AS target_form_id,target.origin_plugin AS target_plugin,target.playthrough_id AS target_playthrough
            FROM public.core_action_intent i JOIN public.core_actor_identity a ON a.id=i.actor_identity_id
            LEFT JOIN public.core_actor_identity target ON target.id=i.target_identity_id
            WHERE i.action_id=:p1 AND i.idempotency_key=:p2 AND i.session_id=:p3 AND i.request_id=:p4 AND i.generation=:p5 AND i.turn_id=:p6
                AND i.state IN ('pending','dispatched') AND i.deadline_at>clock_timestamp()
                AND EXTRACT(EPOCH FROM (i.deadline_at-i.created_at))*1000 >= :p7", $parameters);
    }
}

<?php

// Reads core_actor_identity. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActorIdentity;

/** Finds rows of core_actor_identity. */
final class ActorIdentityDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractActorIdentity
    {
        return $this->fresh()->find(AbstractActorIdentity::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActorIdentity> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActorIdentity::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** The id of the fo4 actor with this form id and origin plugin (compared without case) in this playthrough, or null. */
    public function idByIdentity(string $formId, string $originPlugin, string $playthroughId): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_actor_identity WHERE game='fo4'
                AND upper(full_form_id)=upper(:p1) AND lower(origin_plugin)=lower(:p2) AND playthrough_id=:p3",
            ['p1' => $formId, 'p2' => $originPlugin, 'p3' => $playthroughId]
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * The actors of the saved scene (player, target, audience) other than the reader, each with their latest saved relationship
     * state. $visibleCte is the reader's visibility CTE chain ending in `native_ancestry` and `native_relationships`; the statement
     * binds no parameters.
     *
     * @return list<array<string, string|null>>
     */
    public function relationshipPromptRows(string $visibleCte): array
    {
        return self::textRows($this->connection()->fetchAllAssociative(
            $visibleCte."
            SELECT actor.id,actor.full_form_id,actor.origin_plugin,actor.playthrough_id,
                state.history_event_id,state.affinity,state.relationship_type,state.details
            FROM native_ancestry current
            JOIN public.core_runtime_context c ON c.session_id=current.session_id AND c.generation=current.generation
                AND c.snapshot_sequence=current.snapshot_sequence
            JOIN public.core_actor_identity actor ON actor.game='fo4' AND actor.playthrough_id=c.playthrough_id
                AND (actor.id=c.player_identity_id OR actor.id=c.target_identity_id
                    OR c.audience_identity_ids @> jsonb_build_array(actor.id))
            LEFT JOIN LATERAL (SELECT r.* FROM native_relationships r WHERE r.subject_identity_id=actor.id
                ORDER BY r.history_event_id DESC LIMIT 1) state ON TRUE
            WHERE current.depth=1 AND actor.id<>current.reader_identity_id ORDER BY actor.id"
        ));
    }
}

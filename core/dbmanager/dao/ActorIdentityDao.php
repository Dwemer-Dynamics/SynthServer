<?php

// Reads core_actor_identity. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActorIdentity;

/** Finds rows of core_actor_identity. */
class ActorIdentityDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractActorIdentity
    {
        return $this->fresh()->find(AbstractActorIdentity::class, $id);
    }

    /** The id of the Fallout 4 actor identity with this form id (any case), origin plugin (any case) and playthrough, or null. */
    public function findFo4IdentityId(string $formId, string $originPlugin, string $playthroughId): ?string
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_actor_identity WHERE game='fo4'
            AND upper(full_form_id)=upper(?) AND lower(origin_plugin)=lower(?) AND playthrough_id=?",
            [$formId, $originPlugin, $playthroughId]
        );

        return $id === false ? null : (string) $id;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActorIdentity> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActorIdentity::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** Merges the faction snapshot (JSON object text) into the actor's metadata and stamps it as seen. False when the statement fails. */
    public function mergeFactionMetadata(int $id, string $snapshotJson): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_actor_identity SET metadata=COALESCE(metadata, '{}'::jsonb) || CAST(? AS jsonb), last_seen_at=NOW() WHERE id = ?",
            [$snapshotJson, $id]
        );
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
     * The id of the fo4 actor with this form id (compared without case), origin plugin and playthrough, locked FOR UPDATE until the
     * caller's open transaction ends; null when there is none.
     */
    public function lockFo4Identity(string $fullFormId, string $originPlugin, string $playthroughId): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_actor_identity
              WHERE game = 'fo4' AND upper(full_form_id) = upper(?) AND lower(origin_plugin) = lower(?) AND playthrough_id = ?
              FOR UPDATE",
            [$fullFormId, $originPlugin, $playthroughId]
        );

        return $id === false ? null : (int) $id;
    }

    /** Stamps the actor as seen now; a non-null display name replaces the stored one. */
    public function touch(int $id, ?string $displayName): bool
    {
        return $this->tryStatement(
            'UPDATE public.core_actor_identity SET last_seen_at = NOW(), display_name = COALESCE(?, display_name) WHERE id = ?',
            [$displayName, $id]
        );
    }

    /** Inserts a first-seen fo4 actor and returns its id, or null when the insert fails. */
    public function insertFo4(string $fullFormId, string $originPlugin, string $playthroughId, ?string $displayName): ?int
    {
        try {
            $id = $this->connection()->fetchOne(
                "INSERT INTO public.core_actor_identity (game, full_form_id, origin_plugin, playthrough_id, display_name)
                 VALUES ('fo4', ?, ?, ?, ?) RETURNING id",
                [$fullFormId, $originPlugin, $playthroughId, $displayName]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

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

    /**
     * Form id, origin plugin and playthrough of one identity (only of this game when $game is given); null when none.
     *
     * @return array{full_form_id: string, origin_plugin: string, playthrough_id: string}|null
     */
    public function identityRow(int $id, ?string $game = null): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT full_form_id, origin_plugin, playthrough_id FROM public.core_actor_identity WHERE id = ?'.($game !== null ? ' AND game = ?' : ''),
            $game !== null ? [$id, $game] : [$id]
        );

        return $row === false ? null : $row;
    }

    /** Id and playthrough of the identities with these ids. @param list<int> $ids @return list<array<string, mixed>> */
    public function playthroughsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->connection()->fetchAllAssociative(
            'SELECT id, playthrough_id FROM public.core_actor_identity WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
            array_map('intval', array_values($ids))
        );
    }

    /**
     * The id of the Fallout 4 identity for this form, plugin and playthrough (form and plugin compared case-insensitively) that
     * belongs to the playthrough of this runtime session and variant; null when none.
     */
    public function findIdInSession(string $sessionId, string $runtimeVariant, string $formId, string $originPlugin, string $playthroughId): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT a.id FROM public.core_actor_identity a JOIN public.core_runtime_session r ON r.playthrough_id = a.playthrough_id
             WHERE a.game = 'fo4' AND r.session_id = ? AND r.runtime_variant = ? AND upper(a.full_form_id) = upper(?)
             AND lower(a.origin_plugin) = lower(?) AND a.playthrough_id = ?",
            [$sessionId, $runtimeVariant, $formId, $originPlugin, $playthroughId]
        );

        return $id === false ? null : (int) $id;
    }
}

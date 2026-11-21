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

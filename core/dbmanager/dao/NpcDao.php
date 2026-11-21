<?php

// Reads core_npc. Writes stay with the statements that own them; the one exception is the default-profile repair below. No lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractNpc;

/** Finds rows of core_npc. */
final class NpcDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractNpc
    {
        return $this->fresh()->find(AbstractNpc::class, $id);
    }

    /**
     * True when this NPC still has this identity and profile for this runtime session (same game, plugin form id, origin plugin,
     * playthrough and variant); locks the NPC and profile rows FOR SHARE until the caller's open transaction ends.
     */
    public function lockRechatBinding(string $sessionId, int $npcId, int $actorIdentityId, int $profileId, string $formId, string $originPlugin, string $playthroughId, string $runtimeVariant): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_npc n
            JOIN public.core_profiles p ON p.id=n.profile_id
            JOIN public.core_actor_identity a ON a.id=n.actor_identity_id AND a.game='fo4'
            JOIN public.core_runtime_session r ON r.session_id=? AND r.playthrough_id=a.playthrough_id
            WHERE n.id=? AND n.actor_identity_id=? AND p.id=?
                AND upper(a.full_form_id)=upper(?) AND lower(a.origin_plugin)=lower(?) AND a.playthrough_id=?
                AND r.runtime_variant=? FOR SHARE OF n,p",
            [$sessionId, $npcId, $actorIdentityId, $profileId, $formId, $originPlugin, $playthroughId, $runtimeVariant]
        ) !== false;
    }

    /** Gives every non-Narrator NPC whose profile is missing or no longer exists this profile. */
    public function assignProfileWhereMissing(int $profileId): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_npc SET profile_id = ? WHERE npc_name <> 'The Narrator' "
            .'AND (profile_id IS NULL OR NOT EXISTS (SELECT 1 FROM public.core_profiles p WHERE p.id = public.core_npc.profile_id))',
            [$profileId]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpc> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpc::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * Creates the character of a playthrough-scoped (runtime) actor and returns its id. The legacy name index allows one
     * unbound character per name, so a second actor of the same name gets its form id appended until the identity redesign
     * replaces that index; the display name lives on core_npc_runtime_actor.
     */
    public function createScoped(string $name, string $playthroughId, string $disambiguator): int
    {
        $name = trim($name);
        if ($name === '' || $playthroughId === '') {
            throw new \InvalidArgumentException('A scoped character needs a name and a playthrough.');
        }
        foreach ([$name, $name.' ['.$disambiguator.']'] as $candidate) {
            $id = $this->connection()->fetchOne(
                'INSERT INTO public.core_npc (npc_name, scope_playthrough_id) VALUES (:name, :playthrough)
                 ON CONFLICT (npc_name) WHERE actor_identity_id IS NULL DO NOTHING RETURNING id',
                ['name' => $candidate, 'playthrough' => $playthroughId]
            );
            if ($id !== false && $id !== null) {
                return (int) $id;
            }
        }
        throw new \RuntimeException('The scoped character could not be created.');
    }

    /** The whole row of the profile bound to an actor identity, or null. @return array<string, mixed>|null */
    public function rowByActorIdentity(int $actorIdentityId): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_npc WHERE actor_identity_id = ? LIMIT 1', [$actorIdentityId]);

        return $row === false ? null : $row;
    }

    /** As rowByActorIdentity(), with the row locked FOR UPDATE until the caller's open transaction ends. @return array<string, mixed>|null */
    public function lockRowByActorIdentity(int $actorIdentityId): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_npc WHERE actor_identity_id = ? FOR UPDATE', [$actorIdentityId]);

        return $row === false ? null : $row;
    }

    /** The name of the profile with this md5, or null. */
    public function nameByMd5(string $md5): ?string
    {
        $name = $this->connection()->fetchOne('SELECT npc_name FROM public.core_npc WHERE md5 = ? LIMIT 1', [$md5]);

        return $name === false || $name === null ? null : (string) $name;
    }

    /** The whole row of the profile with this name, compared without case, or null. @return array<string, mixed>|null */
    public function rowByNameIgnoreCase(string $name): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_npc WHERE lower(npc_name) = lower(?) LIMIT 1', [$name]);

        return $row === false ? null : $row;
    }

    /** Fills the base id of the profile with this name that is not bound to an actor identity, when it has none yet. False when the statement fails. */
    public function fillBaseWhereEmpty(string $name, string $baseId): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_npc SET base = COALESCE(NULLIF(base, ''), ?) WHERE npc_name = ? AND actor_identity_id IS NULL",
                [$baseId, $name]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }

    /** Stores the last known coordinates (JSON text) in the metadata of the profile with this name that is not bound to an actor identity. False when the statement fails. */
    public function setLastCoordsWhereUnbound(string $name, string $coordsJson): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_npc SET metadata = jsonb_set(COALESCE(metadata, '{}'::jsonb), '{last_coords}', CAST(? AS jsonb), true) WHERE npc_name = ? AND actor_identity_id IS NULL",
                [$coordsJson, $name]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}

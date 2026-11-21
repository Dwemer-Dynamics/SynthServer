<?php

// Reads core_npc. Writes stay with the statements that own them; the one exception is the default-profile repair below. No lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpc;

/** Finds rows of core_npc. */
class NpcDao
{
    use FailSoftQueries;
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

    /** One NPC row locked FOR UPDATE until the caller's open transaction ends; null when it does not exist. @return array<string, mixed>|null */
    public function lockRowById(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_npc WHERE id=? FOR UPDATE', [$id]);

        return $row === false ? null : $row;
    }

    /** Named NPC rows with the columns the memory-bank tools read, by name. @return list<array<string, mixed>> */
    public function namedProfiles(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, npc_name, core, npc_static_bio, personality, goals, speechstyle, extended_data FROM core_npc WHERE npc_name IS NOT NULL ORDER BY npc_name ASC');
    }

    /** Number of characters (home dashboard). */
    public function dashboardCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_npc');
    }

    /** Gender and race of the character with this reference id, or null. @return array<string, mixed>|null */
    public function genderAndRaceByRefid(string $refid): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT gender, race FROM core_npc WHERE refid = ?', [$refid]);

        return $row === false ? null : $row;
    }

    /**
     * The rechat responder candidates among the captured actors of a scene, in capture order, with their NPC row and profile
     * metadata. The NPC and profile rows are locked FOR SHARE, as the legacy read did. Null when the read fails.
     *
     * @return list<array<string, mixed>>|null
     */
    public function rechatCandidates(string $capturedActorsJson, string $sessionId): ?array
    {
        return $this->rowsOrNull("SELECT a.id AS actor_identity_id,n.id AS npc_id,n.profile_id,
            to_jsonb(n) AS npc,captured.actor AS identity,p.metadata AS profile_metadata
        FROM jsonb_array_elements(:p1::jsonb) WITH ORDINALITY captured(actor,ordinal)
        JOIN public.core_actor_identity a ON a.game='fo4' AND upper(a.full_form_id)=upper(captured.actor->>'form_id')
            AND lower(a.origin_plugin)=lower(captured.actor->>'origin_plugin')
            AND a.playthrough_id=captured.actor->>'playthrough_id'
        JOIN public.core_runtime_session s ON s.session_id=:p2 AND s.playthrough_id=a.playthrough_id
        JOIN public.core_npc n ON n.actor_identity_id=a.id
        JOIN public.core_profiles p ON p.id=n.profile_id
        ORDER BY captured.ordinal FOR SHARE OF n,p", [$capturedActorsJson, $sessionId]);
    }

    /**
     * Id, actor identity and name of one character; with $lock the row is locked FOR UPDATE until the caller's open
     * transaction ends. Null when the character does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function linkRow(int $id, bool $lock = false): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT id, actor_identity_id, npc_name FROM public.core_npc WHERE id = ?'.($lock ? ' FOR UPDATE' : ''), [$id]);

        return $row === false ? null : $row;
    }

    /** The whole row of one character, or null. @return array<string, mixed>|null */
    public function row(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_npc WHERE id = ?', [$id]);

        return $row === false ? null : $row;
    }

    /**
     * Up to two characters linked to the Fallout 4 actor identity with this form id (case-insensitive), origin plugin
     * (case-insensitive) and playthrough, ordered by id; two rows mean the link is ambiguous.
     *
     * @return list<array<string, mixed>> id, actor_identity_id and npc_name
     */
    public function linkedToActor(string $formId, string $originPlugin, string $playthroughId): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT n.id, n.actor_identity_id, n.npc_name FROM public.core_npc n
             JOIN public.core_actor_identity a ON a.id = n.actor_identity_id
             WHERE a.game = 'fo4' AND upper(a.full_form_id) = upper(?) AND lower(a.origin_plugin) = lower(?) AND a.playthrough_id = ?
             ORDER BY n.id LIMIT 2",
            [$formId, $originPlugin, $playthroughId]
        );
    }
}

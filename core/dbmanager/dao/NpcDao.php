<?php

// Reads core_npc. Writes stay with the statements that own them; the one exception is the default-profile repair below. No lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpc;

/** Finds rows of core_npc. */
class NpcDao
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

    /** The metadata row of the character with this exact name (first match), or null. @return array<string, mixed>|null */
    public function metadataByName(string $name): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT metadata FROM core_npc WHERE npc_name = ? LIMIT 1', [$name]);

        return $row === false ? null : $row;
    }

    /** Voice id and extended data of the character with this exact name (first match), or null. @return array<string, mixed>|null */
    public function voiceByName(string $name): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT voiceid, extended_data FROM core_npc WHERE npc_name = ? LIMIT 1', [$name]);

        return $row === false ? null : $row;
    }

    /** Voice id and extended data of the character bound to this Fallout 4 actor identity, or null. @return array<string, mixed>|null */
    public function voiceByActor(string $formId, string $originPlugin, string $playthroughId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT n.voiceid, n.extended_data FROM public.core_npc n JOIN public.core_actor_identity a ON a.id = n.actor_identity_id
             WHERE a.game = 'fo4' AND upper(a.full_form_id) = upper(?) AND lower(a.origin_plugin) = lower(?) AND a.playthrough_id = ?",
            [$formId, $originPlugin, $playthroughId]
        );

        return $row === false ? null : $row;
    }

    /** Up to two profile rows of the characters bound to this actor identity (more than one means the binding is ambiguous). @return list<array<string, mixed>> */
    public function profileRowsByActor(int $actorIdentityId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id, npc_name, npc_static_bio, personality, occupation, skills, speechstyle, goals FROM public.core_npc WHERE actor_identity_id = ? LIMIT 2',
            [$actorIdentityId]
        );
    }

    /**
     * Seeds the profile row of a character from its captured actor or bio template. A new row is inserted; on conflict (the actor
     * identity, or the name when there is no identity) an unlocked profile fills only its empty or placeholder fields and a locked
     * profile keeps every authored field. Empty strings are stored as NULL. Throws when the statement fails.
     *
     * @param array<string, string> $fields core, npc_static_bio, worldknowledge_tags, personality, relationships, occupation, appearance,
     *                                       skills, speechstyle, goals, gender, race, voice, baseid (all trimmed)
     */
    public function seedProfile(?int $actorId, string $npcName, array $fields, string $refid, ?int $profileId, string $metadataJson, string $extendedJson,
        string $profileToken, bool $mappedCreature, string $actorProfileVoice): void
    {
        $conflict = $actorId === null ? '(npc_name) WHERE actor_identity_id IS NULL' : '(actor_identity_id) WHERE actor_identity_id IS NOT NULL';
        $nullable = static fn(string $value): ?string => trim($value) === '' ? null : trim($value);
        $parameters = [$actorId, $npcName];
        foreach (['core', 'npc_static_bio', 'worldknowledge_tags', 'personality', 'relationships', 'occupation', 'appearance', 'skills', 'speechstyle',
            'goals', 'gender', 'race', 'voice'] as $key) {
            $parameters[] = $nullable((string) ($fields[$key] ?? ''));
        }
        array_push($parameters, $refid !== '' ? $refid : null, $nullable((string) ($fields['baseid'] ?? '')), $profileId, $metadataJson, $extendedJson,
            $profileToken, $mappedCreature ? 'true' : 'false', $nullable($actorProfileVoice));
        $this->connection()->executeStatement(
            "INSERT INTO public.core_npc (actor_identity_id, npc_name, npc_favorite, lock_profile, core, npc_static_bio, worldknowledge_tags,
                personality, relationships, occupation, appearance, skills, speechstyle, goals, gender, race, voiceid, refid, base, profile_id,
                metadata, extended_data, gamets_last_updated, md5)
             VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), 0, ?)
        ON CONFLICT {$conflict} DO UPDATE SET
            npc_name = EXCLUDED.npc_name,
            md5 = COALESCE(NULLIF(public.core_npc.md5, ''), EXCLUDED.md5),
            core = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.core ELSE COALESCE(NULLIF(public.core_npc.core, ''), EXCLUDED.core) END,
            npc_static_bio = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.npc_static_bio ELSE COALESCE(NULLIF(public.core_npc.npc_static_bio, ''), EXCLUDED.npc_static_bio) END,
            worldknowledge_tags = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.worldknowledge_tags ELSE COALESCE(NULLIF(public.core_npc.worldknowledge_tags, ''), EXCLUDED.worldknowledge_tags) END,
            personality = CASE
                WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.personality
                WHEN public.core_npc.personality IN (
                    'A Fallout 4 wasteland resident. Replace this generated SYNTH seed profile with richer character data when available.',
                    'A Fallout 4 wasteland resident. Replace this generated SYNTH seed profile with richer character data when available.'
                )
                    THEN EXCLUDED.personality
                ELSE COALESCE(NULLIF(public.core_npc.personality, ''), EXCLUDED.personality)
            END,
            relationships = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.relationships ELSE COALESCE(NULLIF(public.core_npc.relationships, ''), EXCLUDED.relationships) END,
            occupation = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.occupation ELSE COALESCE(NULLIF(public.core_npc.occupation, ''), EXCLUDED.occupation) END,
            appearance = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.appearance ELSE COALESCE(NULLIF(public.core_npc.appearance, ''), EXCLUDED.appearance) END,
            skills = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.skills ELSE COALESCE(NULLIF(public.core_npc.skills, ''), EXCLUDED.skills) END,
            speechstyle = CASE
                WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.speechstyle
                WHEN public.core_npc.speechstyle = 'Speaks plainly and reacts to the Sole Survivor and the current situation.'
                    THEN EXCLUDED.speechstyle
                ELSE COALESCE(NULLIF(public.core_npc.speechstyle, ''), EXCLUDED.speechstyle)
            END,
            goals = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.goals ELSE COALESCE(NULLIF(public.core_npc.goals, ''), EXCLUDED.goals) END,
            gender = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.gender ELSE COALESCE(NULLIF(EXCLUDED.gender, ''), public.core_npc.gender) END,
            race = CASE WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.race ELSE COALESCE(NULLIF(EXCLUDED.race, ''), public.core_npc.race) END,
            voiceid = CASE
                WHEN public.core_npc.lock_profile = 1 THEN public.core_npc.voiceid
                WHEN CAST(? AS boolean)
                 AND (
                    NULLIF(public.core_npc.voiceid, '') IS NULL
                    OR lower(public.core_npc.voiceid) = lower(CAST(? AS text))
                 ) THEN EXCLUDED.voiceid
                ELSE COALESCE(NULLIF(public.core_npc.voiceid, ''), NULLIF(EXCLUDED.voiceid, ''))
            END,
            refid = COALESCE(NULLIF(EXCLUDED.refid, ''), public.core_npc.refid),
            base = COALESCE(NULLIF(EXCLUDED.base, ''), public.core_npc.base),
            profile_id = COALESCE(public.core_npc.profile_id, EXCLUDED.profile_id),
            metadata = COALESCE(public.core_npc.metadata, '{}'::jsonb) || EXCLUDED.metadata,
            extended_data = CASE
                WHEN public.core_npc.lock_profile = 1
                    THEN COALESCE(public.core_npc.extended_data, '{}'::jsonb)
                ELSE COALESCE(public.core_npc.extended_data, '{}'::jsonb) || EXCLUDED.extended_data
            END,
            gamets_last_updated = EXCLUDED.gamets_last_updated",
            $parameters
        );
    }
}

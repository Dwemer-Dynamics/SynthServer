<?php

// Reads core_npc. Writes stay with the statements that own them; the one exception is the default-profile repair below. No lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpc;
use Doctrine\DBAL\Exception as DbalException;

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

    /** The extended_data JSON text of the legacy NPC row with this name, or null when there is none. */
    public function legacyExtendedDataByName(string $npcName): ?string
    {
        $value = $this->connection()->fetchOne('SELECT extended_data FROM core_npc WHERE npc_name=? LIMIT 1', [$npcName]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * The legacy NPC rows (without a native actor) that have middle-term memory enabled: by their own extended_data key, else by their
     * profile's MIDDLE_TERM_MEMORY_ENABLED. @return list<array<string, mixed>>
     */
    public function legacyMiddletermNpcs(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT n.*
        FROM core_npc n
        LEFT JOIN core_profiles p ON p.id = n.profile_id
        WHERE n.actor_identity_id IS NULL AND (
            lower(COALESCE(n.extended_data->>'middle_term_enabled', '')) IN ('1', 'true', 't', 'yes', 'on')
            OR (
                NOT jsonb_exists(COALESCE(n.extended_data, '{}'::jsonb), 'middle_term_enabled')
                AND lower(COALESCE(p.metadata->>'MIDDLE_TERM_MEMORY_ENABLED', '')) IN ('1', 'true', 't', 'yes', 'on')
            )
        )"
        );
    }


    /** The raw core_npc row of one actor identity, or null (or when the statement fails). @return array<string, mixed>|null */
    public function rowByActor(int $actorId): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_npc WHERE actor_identity_id = ?', [$actorId]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** The NPC's extended data and its profile's metadata (NULL without a profile), or null when there is no NPC (or the statement fails). @return array<string, mixed>|null */
    public function extendedDataWithProfileMetadata(int $actorId): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT n.extended_data,p.metadata FROM public.core_npc n
                 LEFT JOIN public.core_profiles p ON p.id=n.profile_id WHERE n.actor_identity_id = ?',
                [$actorId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
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

    /**
     * Takes the session-level advisory lock that serializes relationship updates for one NPC (namespace 1001). It is held on the
     * shared session until unlockRelationshipUpdates() or the session ends; it does not start a transaction.
     */
    public function lockRelationshipUpdates(int $npcId): void
    {
        $this->connection()->executeQuery('SELECT pg_advisory_lock(:p1)', ['p1' => 1001000000 + $npcId]);
    }

    /** Releases the lock taken by lockRelationshipUpdates() for this NPC. */
    public function unlockRelationshipUpdates(int $npcId): void
    {
        $this->connection()->executeQuery('SELECT pg_advisory_unlock(:p1)', ['p1' => 1001000000 + $npcId]);
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

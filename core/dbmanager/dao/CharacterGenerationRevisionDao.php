<?php

// Reads core_character_generation_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCharacterGenerationRevision;

/** Finds rows of core_character_generation_revision. */
final class CharacterGenerationRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractCharacterGenerationRevision
    {
        return $this->fresh()->find(AbstractCharacterGenerationRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCharacterGenerationRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCharacterGenerationRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /**
     * Whether the accepted revision of this request, actor and role (when one exists) holds exactly this input and result;
     * null when the request has no accepted revision. Throws when the read fails.
     */
    public function replayIdentical(string $sessionId, string $requestId, int $actorIdentityId, string $role, string $inputJson, string $fieldsJson): ?bool
    {
        try {
            $identical = $this->connection()->fetchOne(
                "SELECT input_sha256 = encode(sha256(convert_to((CAST(? AS jsonb))::text, 'UTF8')), 'hex') AND result_fields = CAST(? AS jsonb) AS identical
                 FROM public.core_character_generation_revision WHERE session_id = ? AND request_id = ? AND actor_identity_id = ? AND profile_role = ?",
                [$inputJson, $fieldsJson, $sessionId, $requestId, $actorIdentityId, $role]
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to check native profile replay.', 0, $error);
        }

        return $identical === false ? null : ($identical === true || $identical === 't' || $identical === '1' || $identical === 1);
    }

    /**
     * Whether a revision of this actor and role was accepted after the history position of this context snapshot (one that
     * no acknowledged snapshot covers yet). Throws when the read fails.
     */
    public function newerThanContext(string $sessionId, int $generation, int $contextSequence, int $actorIdentityId, string $role): bool
    {
        try {
            return $this->connection()->fetchOne(
                'SELECT 1 FROM public.core_character_generation_revision v JOIN public.core_history_event h ON h.id = v.history_event_id
                 JOIN public.core_runtime_context c ON c.session_id = ? AND c.generation = ? AND c.snapshot_sequence = ?
                 WHERE v.session_id = c.session_id AND v.generation = c.generation AND v.actor_identity_id = ? AND v.profile_role = ? AND h.id > c.history_event_id',
                [$sessionId, $generation, $contextSequence, $actorIdentityId, $role]
            ) !== false;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to verify native profile frontier.', 0, $error);
        }
    }

    /**
     * Publishes one accepted profile result in one statement: the derived context event, its history event and the revision.
     * Throws when the statement fails.
     */
    public function publish(string $inputJson, string $fieldsJson, string $playthroughId, int $gameTime, int $now, string $sessionId, int $generation, int $contextSequence, string $requestId, int $actorIdentityId, string $role, ?int $priorRevision): void
    {
        try {
            $this->connection()->executeStatement(
                "WITH value AS (SELECT CAST(:input AS jsonb) AS input, CAST(:fields AS jsonb) AS fields), publication AS (
                    INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                    VALUES('profile_result','Accepted native profile development',:playthrough,:gametime,:now,:now,'','','','derived',:session,:generation,:sequence,:request) RETURNING *
                ), history AS (
                    INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                    SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:playthrough,rowid,to_jsonb(publication)
                        ||jsonb_build_object('profile_input_sha256',encode(sha256(convert_to(value.input::text,'UTF8')),'hex'),
                            'profile_result',value.fields,'profile_result_sha256',encode(sha256(convert_to(value.fields::text,'UTF8')),'hex'))
                    FROM publication CROSS JOIN value RETURNING id
                ) INSERT INTO public.core_character_generation_revision(history_event_id,session_id,request_id,generation,context_sequence,actor_identity_id,profile_role,
                    prior_history_event_id,input_payload,input_sha256,result_fields,result_sha256)
                    SELECT history.id,:session,:request,:generation,:sequence,:actor,:role,:prior,value.input,encode(sha256(convert_to(value.input::text,'UTF8')),'hex'),value.fields,
                        encode(sha256(convert_to(value.fields::text,'UTF8')),'hex') FROM history CROSS JOIN value",
                ['input' => $inputJson, 'fields' => $fieldsJson, 'playthrough' => $playthroughId, 'gametime' => $gameTime, 'now' => $now,
                    'session' => $sessionId, 'generation' => $generation, 'sequence' => $contextSequence, 'request' => $requestId,
                    'actor' => $actorIdentityId, 'role' => $role, 'prior' => $priorRevision]
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to publish native profile result.', 0, $error);
        }
    }
}

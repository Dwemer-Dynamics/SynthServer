<?php

// Reads core_diary_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryInput;

/** Finds rows of core_diary_input. */
final class DiaryInputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractDiaryInput
    {
        return $this->fresh()->find(AbstractDiaryInput::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryInput::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** The id of the diary input one request froze for one actor and role, or null. */
    public function idForRequest(string $sessionId, string $requestId, int $actorIdentityId, string $role): ?int
    {
        $id = $this->connection()->fetchOne(
            'SELECT id FROM public.core_diary_input WHERE session_id=? AND request_id=? AND actor_identity_id=? AND diary_role=?',
            [$sessionId, $requestId, $actorIdentityId, $role]
        );

        return $id === false ? null : (int) $id;
    }

    /** The frozen input of one request, actor and role with whether its hash still matches; null when none. @return array<string, mixed>|null */
    public function frozenForRequest(string $sessionId, string $requestId, int $actorIdentityId, string $role): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT id,input_payload,input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex') AS valid
             FROM public.core_diary_input WHERE session_id=? AND request_id=? AND actor_identity_id=? AND diary_role=?",
            [$sessionId, $requestId, $actorIdentityId, $role]
        );

        return $row === false ? null : $row;
    }

    /**
     * The saved evidence a new input freezes: up to $limit visible history events, newest first, each with its source hash.
     * $historyCte is the visible-history CTE prefix (ending in a space) that defines native_history.
     *
     * @return list<array<string, mixed>>
     */
    public function evidenceRows(string $historyCte, int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            $historyCte." SELECT n.history_event_id,n.type,left(n.data,4000) AS data,
                encode(sha256(convert_to(h.event_payload::text,'UTF8')),'hex') AS source_sha256 FROM native_history n
                JOIN public.core_history_event h ON h.id=n.history_event_id
                WHERE n.type NOT IN ('prechat','itemfound','infoaction') ORDER BY n.history_event_id DESC LIMIT ".max(0, $limit)
        );
    }

    /** Freezes one diary input and returns its id; null when the statement fails. */
    public function insertInput(string $sessionId, string $requestId, int $generation, int $contextSequence, int $actorIdentityId, string $role, ?int $priorHistoryEventId, string $inputJson, ?int $profileRevisionId): ?int
    {
        try {
            $id = $this->connection()->fetchOne(
                "INSERT INTO public.core_diary_input(session_id,request_id,generation,context_sequence,actor_identity_id,
                    diary_role,prior_history_event_id,input_payload,input_sha256,profile_revision_id) VALUES(?,?,?,?,?,?,?,CAST(? AS jsonb),
                    encode(sha256(convert_to((CAST(? AS jsonb))::text,'UTF8')),'hex'),?) RETURNING id",
                [$sessionId, $requestId, $generation, $contextSequence, $actorIdentityId, $role, $priorHistoryEventId, $inputJson, $inputJson, $profileRevisionId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $id === false ? null : (int) $id;
    }

    /** One frozen input of this exact request, generation, snapshot and role whose hash and fields are consistent; null when none. @return array<string, mixed>|null */
    public function consistentForPublication(int $inputId, string $sessionId, string $requestId, int $generation, int $contextSequence, string $role): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT * FROM public.core_diary_input WHERE id=? AND session_id=? AND request_id=? AND generation=? AND context_sequence=?
                AND diary_role=? AND input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')
                AND input_payload->>'actor_identity_id'=actor_identity_id::text AND input_payload->>'diary_role'=diary_role
                AND input_payload->'prior_revision'=COALESCE(to_jsonb(prior_history_event_id),'null'::jsonb)
                AND input_payload->'profile_revision'=COALESCE(to_jsonb(profile_revision_id),'null'::jsonb)",
            [$inputId, $sessionId, $requestId, $generation, $contextSequence, $role]
        );

        return $row === false ? null : $row;
    }
}

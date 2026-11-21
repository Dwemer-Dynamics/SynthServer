<?php

// Reads core_diary_job_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryJobResult;

/** Finds rows of core_diary_job_result. */
final class DiaryJobResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $jobId): ?AbstractDiaryJobResult
    {
        return $this->fresh()->find(AbstractDiaryJobResult::class, $jobId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryJobResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryJobResult::class)->findBy([], ['jobId' => 'ASC'], max(1, $limit)));
    }

    /** The checkpointed result of one job with whether its hashes match the given input hash; null when none. @return array<string, mixed>|null */
    public function checkpoint(int $jobId, string $inputSha256): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT result_payload,result_sha256=encode(sha256(convert_to(result_payload::text,'UTF8')),'hex')
                AND input_sha256=? AS valid
             FROM public.core_diary_job_result WHERE job_id=?",
            [$inputSha256, $jobId]
        );

        return $row === false ? null : $row;
    }

    /** Stores the accepted result of one job. False when the statement fails. */
    public function insertCheckpoint(int $jobId, string $inputSha256, string $resultJson): bool
    {
        try {
            $this->connection()->executeStatement(
                "INSERT INTO public.core_diary_job_result(job_id,input_sha256,result_payload,result_sha256)
                 VALUES(?,?,CAST(? AS jsonb),encode(sha256(convert_to((CAST(? AS jsonb))::text,'UTF8')),'hex'))",
                [$jobId, $inputSha256, $resultJson, $resultJson]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Whether the embedding was attempted and whether its stored hashes are consistent; null when there is no checkpoint. @return array<string, mixed>|null */
    public function embeddingState(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT embedding_attempted,
                (embedding IS NULL AND embedding_sha256 IS NULL AND embedding_text_sha256 IS NULL) OR
                    (embedding_attempted AND embedding_sha256=encode(sha256(convert_to(embedding::text,'UTF8')),'hex')
                        AND (embedding_text_sha256 IS NULL OR embedding_text_sha256=encode(sha256(convert_to(result_payload->>'content','UTF8')),'hex'))) AS valid
             FROM public.core_diary_job_result WHERE job_id=?",
            [$jobId]
        );

        return $row === false ? null : $row;
    }

    /** Records the optional embedding attempt (a vector JSON text or null) once. False when the statement fails. */
    public function recordEmbedding(int $jobId, ?string $embedding, ?string $textSha256): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_diary_job_result SET embedding=CAST(:embedding AS public.vector),
                    embedding_sha256=CASE WHEN CAST(:embedding AS public.vector) IS NULL THEN NULL ELSE encode(sha256(convert_to((CAST(:embedding AS public.vector))::text,'UTF8')),'hex') END,
                    embedding_text_sha256=:text,embedding_attempted=true WHERE job_id=:job AND NOT embedding_attempted",
                ['embedding' => $embedding, 'text' => $textSha256, 'job' => $jobId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }
}

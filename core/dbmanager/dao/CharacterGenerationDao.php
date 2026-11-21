<?php

// Reads core_character_generation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCharacterGeneration;

/** Finds rows of core_character_generation. */
final class CharacterGenerationDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $jobId): ?AbstractCharacterGeneration
    {
        return $this->fresh()->find(AbstractCharacterGeneration::class, $jobId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCharacterGeneration> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCharacterGeneration::class)->findBy([], ['jobId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The frozen input of a profile job and whether its stored hash still matches, or null when none is frozen.
     *
     * @return array{input_payload: string, valid: bool}|null
     */
    public function input(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT input_payload, input_sha256 = encode(sha256(convert_to(input_payload::text, 'UTF8')), 'hex') AS valid
             FROM public.core_character_generation WHERE job_id = ?",
            [$jobId]
        );

        return $row === false ? null : $row;
    }

    /** Freezes the input of a profile job (JSON text); throws when the statement fails. */
    public function freezeInput(int $jobId, string $json): void
    {
        try {
            $this->connection()->executeStatement(
                "INSERT INTO public.core_character_generation (job_id, input_payload, input_sha256)
                 VALUES (?, CAST(? AS jsonb), encode(sha256(convert_to((CAST(? AS jsonb))::text, 'UTF8')), 'hex'))",
                [$jobId, $json, $json]
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to freeze native profile job input.', 0, $error);
        }
    }
}

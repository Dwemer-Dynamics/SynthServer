<?php

// Reads core_character_generation_field. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractCharacterGenerationField;

/** Finds rows of core_character_generation_field. */
final class CharacterGenerationFieldDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $jobId, string $field): ?AbstractCharacterGenerationField
    {
        return $this->fresh()->find(AbstractCharacterGenerationField::class, ['jobId' => $jobId, 'field' => $field]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractCharacterGenerationField> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractCharacterGenerationField::class)->findBy([], ['jobId' => 'ASC', 'field' => 'ASC'], max(1, $limit)));
    }

    /**
     * The checkpointed text of one field of a profile job and whether its stored hash still matches, or null.
     *
     * @return array{result_text: string, valid: bool}|null
     */
    public function checkpoint(int $jobId, string $field): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT result_text, result_sha256 = encode(sha256(convert_to(result_text, 'UTF8')), 'hex') AS valid
             FROM public.core_character_generation_field WHERE job_id = ? AND field = ?",
            [$jobId, $field]
        );

        return $row === false ? null : $row;
    }

    /** Records one generated field with its hash; throws when the statement fails. */
    public function record(int $jobId, string $field, string $text): void
    {
        try {
            $this->connection()->executeStatement(
                "INSERT INTO public.core_character_generation_field (job_id, field, result_text, result_sha256)
                 VALUES (?, ?, ?, encode(sha256(convert_to(?, 'UTF8')), 'hex'))",
                [$jobId, $field, $text, $text]
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to checkpoint profile field.', 0, $error);
        }
    }
}

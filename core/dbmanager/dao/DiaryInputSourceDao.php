<?php

// Reads core_diary_input_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryInputSource;

/** Finds rows of core_diary_input_source. */
final class DiaryInputSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $inputId, int|string $historyEventId): ?AbstractDiaryInputSource
    {
        return $this->fresh()->find(AbstractDiaryInputSource::class, ['inputId' => $inputId, 'historyEventId' => $historyEventId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryInputSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryInputSource::class)->findBy([], ['inputId' => 'ASC', 'historyEventId' => 'ASC'], max(1, $limit)));
    }

    /** Records one source of a frozen input. False when the statement fails. */
    public function insertSource(int $inputId, int $historyEventId, string $sourceSha256): bool
    {
        try {
            $this->connection()->executeStatement('INSERT INTO public.core_diary_input_source VALUES(?,?,?)', [$inputId, $historyEventId, $sourceSha256]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * How many of one input's sources are visible with matching hash and evidence (n) against how many it recorded (total).
     * $historyCte is the visible-history CTE prefix (ending in a space) that defines native_history.
     *
     * @return array<string, mixed>|null
     */
    public function visibleSourceCounts(string $historyCte, int $inputId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            $historyCte.' SELECT count(*) AS n,
                (SELECT count(*) FROM public.core_diary_input_source WHERE input_id=:input) AS total
                FROM public.core_diary_input_source s JOIN native_history v ON v.history_event_id=s.history_event_id
                JOIN public.core_history_event h ON h.id=s.history_event_id JOIN public.core_diary_input i ON i.id=s.input_id
                WHERE s.input_id=:input AND s.source_sha256=encode(sha256(convert_to(h.event_payload::text,\'UTF8\')),\'hex\')
                    AND EXISTS(SELECT 1 FROM jsonb_array_elements(i.input_payload->\'history\') evidence
                        WHERE evidence->>\'history_event_id\'=s.history_event_id::text AND evidence->>\'source_sha256\'=s.source_sha256)',
            ['input' => $inputId]
        );

        return $row === false ? null : $row;
    }
}

<?php

// Reads core_profiles. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractProfile;


/** Finds rows of core_profiles. */
final class ProfileDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractProfile
    {
        return $this->fresh()->find(AbstractProfile::class, $id);
    }

    /** The id of the profile with this exact name, or null. */
    public function findIdByName(string $name): ?int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM public.core_profiles WHERE name = ? LIMIT 1', [$name]);

        return $id === false ? null : (int) $id;
    }

    /** Whether a profile with this id exists. */
    public function exists(int $id): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM public.core_profiles WHERE id = ?', [$id]) !== false;
    }

    /** Id and label of one profile, or null. @return array{id: string, label: string}|null */
    public function idAndLabel(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT id, label FROM public.core_profiles WHERE id = ? LIMIT 1', [$id]);

        return $row === false ? null : $row;
    }

    /** Id and label of every profile, ordered by id. @return list<array<string, mixed>> */
    public function idLabels(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, label FROM public.core_profiles ORDER BY id');
    }

    /** Id, label and metadata of every profile, ordered by label (the NPC page's filter list). @return list<array<string, mixed>> */
    public function filterRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, label, metadata FROM public.core_profiles ORDER BY label ASC');
    }

    /** The lowest id among profiles flagged as the default NPC profile, or null. */
    public function defaultNpcId(): ?int
    {
        $id = $this->connection()->fetchOne("SELECT id FROM public.core_profiles WHERE default_npc = '1' ORDER BY id ASC LIMIT 1");

        return $id === false ? null : (int) $id;
    }

    /** Every profile's connector ids and metadata, ordered by id. @return list<array<string, mixed>> */
    public function connectorMappings(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT id, llm_primary_id, llm_secondary_id, llm_tertiary_id, llm_quaternary_id, llm_formatter_id, diary_connector_id, metadata '
            .'FROM public.core_profiles ORDER BY id ASC'
        );
    }

    /** How many profiles use this TTS connector. */
    public function countUsingTtsConnector(int $ttsConnectorId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_profiles WHERE tts_connector_id = ?', [$ttsConnectorId]);
    }

    /** Profile counts per TTS connector id (connectors with no profile are absent). @return list<array<string, mixed>> */
    public function ttsConnectorUsage(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT tts_connector_id, COUNT(*) AS c FROM public.core_profiles WHERE tts_connector_id IS NOT NULL GROUP BY tts_connector_id'
        );
    }

    /** Every row, ordered by id. @return list<AbstractProfile> */
    public function findAll(): array
    {
        return array_values($this->fresh()->getRepository(AbstractProfile::class)->findBy([], ['id' => 'ASC']));
    }
}

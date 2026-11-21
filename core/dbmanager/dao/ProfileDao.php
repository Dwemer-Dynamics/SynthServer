<?php

// Reads core_profiles. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractProfile;


/** Finds rows of core_profiles. */
final class ProfileDao
{
    use FailSoftQueries;
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

    /**
     * The id of the profile the Narrator settings point to (optionally only when it is $profileId), with that profile row and
     * the setting row locked FOR SHARE until the caller's open transaction ends; null when nothing matches.
     */
    public function lockNarratorProfile(?int $profileId = null): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT p.id FROM public.core_profiles p JOIN public.core_config_setting n "
            ."ON n.scope = 'narrator' AND n.config_key = 'profile_id' AND n.config_value = p.id::text "
            .($profileId !== null ? 'WHERE p.id = ? ' : '').'FOR SHARE OF p, n',
            $profileId !== null ? [$profileId] : []
        );

        return $id === false ? null : (int) $id;
    }

    /** The lowest profile id, or null when there are no profiles. */
    public function firstId(): ?int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM public.core_profiles ORDER BY id ASC LIMIT 1');

        return $id === false ? null : (int) $id;
    }

    /**
     * Makes sure exactly one profile is the default NPC profile and exactly one the default Narrator profile: a missing default
     * falls back to the first profile, and extra flags are cleared (the lowest id wins). Returns the default NPC profile id, or
     * null (and changes nothing) when there are no profiles.
     */
    public function repairDefaults(): ?int
    {
        $firstId = $this->firstId();
        if ($firstId === null) {
            return null;
        }
        $connection = $this->connection();
        $defaultNpcId = $this->defaultNpcId();
        if ($defaultNpcId === null) {
            $connection->executeStatement("UPDATE public.core_profiles SET default_npc = '1' WHERE id = ?", [$firstId]);
            $defaultNpcId = $firstId;
        }
        if ($connection->fetchOne("SELECT id FROM public.core_profiles WHERE default_narrator = '1' ORDER BY id ASC LIMIT 1") === false) {
            $connection->executeStatement("UPDATE public.core_profiles SET default_narrator = '1' WHERE id = ?", [$firstId]);
        }
        foreach (['default_npc', 'default_narrator'] as $column) {
            $connection->executeStatement(
                "WITH chosen AS (SELECT id FROM public.core_profiles WHERE {$column} = '1' ORDER BY id ASC LIMIT 1) "
                ."UPDATE public.core_profiles p SET {$column} = CASE WHEN p.id = (SELECT id FROM chosen) THEN '1' ELSE '0' END "
                ."WHERE p.{$column} = '1' OR p.id = (SELECT id FROM chosen)"
            );
        }

        return $defaultNpcId;
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

    /**
     * The ids of the default NPC and default narrator profiles, with those rows locked FOR UPDATE until the caller's open
     * transaction ends.
     *
     * @return list<int>
     */
    public function lockDefaultIds(): array
    {
        $rows = $this->connection()->fetchFirstColumn("SELECT id FROM public.core_profiles WHERE default_npc = '1' OR default_narrator = '1' FOR UPDATE");

        return array_map('intval', $rows);
    }

    /**
     * Points the named connector columns of the given profiles at one connector. Only the known connector columns are accepted.
     * Returns false when the statement fails or a column is not a connector column.
     *
     * @param list<int> $profileIds
     * @param list<string> $fields
     */
    public function routeConnectors(array $profileIds, array $fields, int $connectorId): bool
    {
        $allowed = ['llm_primary_id', 'llm_secondary_id', 'llm_tertiary_id', 'llm_quaternary_id', 'llm_formatter_id', 'diary_connector_id'];
        if ($profileIds === [] || $fields === [] || array_diff($fields, $allowed) !== []) {
            return false;
        }
        $assignments = implode(', ', array_map(static fn(string $field): string => $field.' = ?', $fields));
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_profiles SET '.$assignments.' WHERE id IN ('.implode(', ', array_fill(0, count($profileIds), '?')).')',
                array_merge(array_fill(0, count($fields), $connectorId), array_map('intval', $profileIds))
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Inserts an empty profile with this label and metadata JSON and returns its id. */
    public function insertBlank(string $label, string $metadataJson): int
    {
        return (int) $this->connection()->fetchOne('INSERT INTO core_profiles (label, metadata) VALUES (?, ?) RETURNING id', [$label, $metadataJson]);
    }

    /** The metadata column of one profile ([] when it has no row, null when the read fails). @return array<string, mixed>|null */
    public function metadataRow(int $id): ?array
    {
        return $this->rowOrNull('SELECT metadata FROM core_profiles WHERE id=:p1 LIMIT 1', [$id]);
    }

    /**
     * Whether any profile has a connector in the given model slot column (llm_primary_id, llm_secondary_id, llm_tertiary_id or
     * llm_quaternary_id). Any other column name is refused.
     */
    public function hasConnectorInSlot(string $column): bool
    {
        if (!in_array($column, ['llm_primary_id', 'llm_secondary_id', 'llm_tertiary_id', 'llm_quaternary_id'], true)) {
            throw new \InvalidArgumentException('Unknown model slot column.');
        }

        return $this->connection()->fetchOne("SELECT id FROM core_profiles WHERE {$column} IS NOT NULL AND {$column} > 0 LIMIT 1") !== false;
    }
}

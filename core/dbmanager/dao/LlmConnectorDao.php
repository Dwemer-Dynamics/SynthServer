<?php

// Reads core_llm_connector. The edit API owns the key-store binding rules and the reference checks for user edits; the only writes
// here are the Player2 connector the randomizer creates and binds. They are parameterized statements, with no lock and no flush.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractLlmConnector;


/** Finds rows of core_llm_connector. */
final class LlmConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractLlmConnector
    {
        return $this->fresh()->find(AbstractLlmConnector::class, $id);
    }

    /** The id of the connector whose label equals this one, ignoring case, or null. */
    public function findIdByLabel(string $label): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_llm_connector WHERE LOWER(COALESCE(label,'')) = LOWER(?) LIMIT 1",
            [$label]
        );

        return $id === false ? null : (int) $id;
    }

    /** Id and display label (the label, or the model when it is empty) of every connector, by display label. @return list<array<string, mixed>> */
    public function displayOptions(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT id, COALESCE(NULLIF(label,''), model) AS label FROM public.core_llm_connector ORDER BY LOWER(COALESCE(NULLIF(label,''), model)) ASC"
        );
    }

    /** Id and label of every connector, by label then id (the generic foreign-key picker). @return list<array<string, mixed>> */
    public function labelOptions(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT id, label FROM public.core_llm_connector ORDER BY LOWER(label) ASC, id ASC');
    }

    /** The id of the first connector using this driver, one labelled $preferredLabel (ignoring case) first, or null. */
    public function findIdByDriver(string $driver, string $preferredLabel = ''): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_llm_connector WHERE driver = ? "
            ."ORDER BY CASE WHEN lower(coalesce(label,'')) = lower(?) THEN 0 ELSE 1 END, id ASC LIMIT 1",
            [$driver, $preferredLabel]
        );

        return $id === false ? null : (int) $id;
    }

    /** True when the connector exists and uses this driver. */
    public function existsWithDriver(int $id, string $driver): bool
    {
        return $this->connection()->fetchOne('SELECT id FROM public.core_llm_connector WHERE id = ? AND driver = ? LIMIT 1', [$id, $driver]) !== false;
    }

    /** The key-store reference of one connector, or null when it has none (or no row). */
    public function apiKeyId(int $id): ?int
    {
        $value = $this->connection()->fetchOne('SELECT api_key_id FROM public.core_llm_connector WHERE id = ? LIMIT 1', [$id]);

        return $value === false || $value === null ? null : (int) $value;
    }

    /** Binds one connector to a key-store entry. */
    public function setApiKeyId(int $id, int $apiKeyId): void
    {
        $this->connection()->executeStatement('UPDATE public.core_llm_connector SET api_key_id = ? WHERE id = ?', [$apiKeyId, $id]);
    }

    /**
     * Inserts a Player2 connector with the randomizer's fixed sampler defaults and returns its id, or null when the insert fails.
     * A null $apiKeyId stores no key reference.
     */
    public function insertPlayer2(string $label, string $metadata, string $url, ?int $apiKeyId): ?int
    {
        try {
            $id = $this->connection()->fetchOne(
                'INSERT INTO public.core_llm_connector (label, metadata, url, model, driver, service, max_tokens, enforce_json, prefill_json, json_schema, '
                .'api_key_id, temperature, presence_penalty, frequency_penalty, repetition_penalty, top_p, top_k, min_p, top_a) '
                ."VALUES (?, ?, ?, 'player2-app-selected', 'player2json', 'player2', 750, 1, 0, 1, ?, 1, 0, 0, 1, 1, 0, 0, 0) RETURNING id",
                [$label, $metadata, $url, $apiKeyId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $id === false ? null : (int) $id;
    }

    /** Every row, ordered by id. @return list<AbstractLlmConnector> */
    public function findAll(): array
    {
        return array_values($this->fresh()->getRepository(AbstractLlmConnector::class)->findBy([], ['id' => 'ASC']));
    }

    /** The driver and max_tokens of one connector; null when it does not exist. @return array<string, mixed>|null */
    public function driverAndMaxTokens(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT driver,max_tokens FROM public.core_llm_connector WHERE id=?', [$id]);

        return $row === false ? null : $row;
    }

    /** Takes the transaction-scoped advisory lock that serializes the local model setup wizard; false when the statement fails. */
    public function lockLocalSetup(): bool
    {
        try {
            $this->connection()->executeQuery("SELECT pg_advisory_xact_lock(hashtext('synth_local_llm_setup'))");
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Id and option_label (the label, else the model, else the id) of every connector, for a settings select list. @return list<array<string, mixed>> */
    public function settingOptions(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT id, COALESCE(NULLIF(label, ''), NULLIF(model, ''), CAST(id AS text)) AS option_label FROM public.core_llm_connector ORDER BY LOWER(COALESCE(NULLIF(label, ''), model)) ASC"
        );
    }

    /** Number of LLM connectors (home dashboard). */
    public function dashboardCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_llm_connector');
    }

    /** The label of one connector, or null (quickstart). */
    public function labelById(int $id): ?string
    {
        $label = $this->connection()->fetchOne('SELECT label FROM core_llm_connector WHERE id = ? LIMIT 1', [$id]);

        return $label === false ? null : (string) $label;
    }
}

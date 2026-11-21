<?php

// Reads core_config_preset. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractConfigPreset;

/** Finds rows of core_config_preset. */
final class ConfigPresetDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractConfigPreset
    {
        return $this->fresh()->find(AbstractConfigPreset::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractConfigPreset> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractConfigPreset::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** Whether the preset table exists (false means the migrations have not run). */
    public function tableExists(): bool
    {
        return $this->connection()->fetchOne("SELECT to_regclass('public.core_config_preset')") !== null;
    }

    /** Locks the whole table SHARE ROW \Doctrine\DBAL\ExceptionCLUSIVE until the caller's open transaction ends; false when the statement fails. */
    public function lockTable(): bool
    {
        try {
            $this->connection()->executeStatement('LOCK TABLE public.core_config_preset IN SHARE ROW \Doctrine\DBAL\ExceptionCLUSIVE MODE');
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** The name of one preset, or null. */
    public function nameOf(int $id): ?string
    {
        $name = $this->connection()->fetchOne('SELECT name FROM public.core_config_preset WHERE id = ?', [$id]);

        return $name === false || $name === null ? null : (string) $name;
    }

    /** Name and snapshot of one preset, or null. @return array{name: string, snapshot: string}|null */
    public function nameAndSnapshot(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT name, snapshot FROM public.core_config_preset WHERE id = ?', [$id]);

        return $row === false || !isset($row['name']) ? null : $row;
    }

    /** Replaces one preset's snapshot; false when the statement fails. */
    public function overwrite(int $id, string $snapshot, string $updatedAt): bool
    {
        try {
            $this->connection()->executeStatement('UPDATE public.core_config_preset SET snapshot = ?, updated_at = ? WHERE id = ?', [$snapshot, $updatedAt, $id]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** How many presets exist and how many of them carry this name (ignoring case). @return array{count: int, duplicate: int} */
    public function countsForName(string $name): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT count(*) AS count, count(*) FILTER (WHERE lower(name) = lower(?)) AS duplicate FROM public.core_config_preset',
            [$name]
        );

        return ['count' => (int) ($row['count'] ?? 0), 'duplicate' => (int) ($row['duplicate'] ?? 0)];
    }

    /** Adds a preset and returns its id, or 0 when the statement fails. */
    public function insert(string $name, string $snapshot): int
    {
        try {
            return (int) $this->connection()->fetchOne('INSERT INTO public.core_config_preset (name, snapshot) VALUES (?, ?) RETURNING id', [$name, $snapshot]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return 0;
        }
    }
}

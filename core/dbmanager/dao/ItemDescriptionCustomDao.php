<?php

// Reads and writes core_item_description_custom: reads by key, and the upsert, truncate and export the description manager page uses.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractItemDescriptionCustom;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;

/** Finds rows of core_item_description_custom. */
final class ItemDescriptionCustomDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $plugin, string $baseid): ?AbstractItemDescriptionCustom
    {
        return $this->fresh()->find(AbstractItemDescriptionCustom::class, ['plugin' => $plugin, 'baseid' => $baseid]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractItemDescriptionCustom> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractItemDescriptionCustom::class)->findBy([], ['plugin' => 'ASC', 'baseid' => 'ASC'], max(1, $limit)));
    }

    /** Inserts or updates one custom item description keyed by (plugin, baseid). Throws on a database error. */
    public function upsert(string $plugin, string $baseid, string $name, string $description): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_item_description_custom (plugin, baseid, name, description) VALUES (?, ?, ?, ?) '
            .'ON CONFLICT (plugin, baseid) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description',
            [$plugin, $baseid, $name, $description]
        );
    }

    /** Deletes every custom item description (TRUNCATE). Throws on a database error. */
    public function truncate(): void
    {
        $this->connection()->executeStatement('TRUNCATE TABLE public.core_item_description_custom');
    }

    /** Every custom description for the CSV export, ordered by plugin then baseid. @return list<array<string, mixed>> */
    public function exportRows(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT plugin, baseid, name, description FROM public.core_item_description_custom ORDER BY plugin ASC, baseid ASC');
    }

    /**
     * Plugin, base id, name and description of one item; null when absent or unreadable, so the prompt lookup can fall back
     * to the other catalogue as the legacy fetchOne() read did. @return array<string, mixed>|null
     */
    public function recordByKey(string $plugin, string $baseid): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative('SELECT plugin, baseid, name, description FROM public.core_item_description_custom WHERE plugin = ? AND baseid = ? LIMIT 1', [$plugin, $baseid]);
        } catch (DbalException|DriverException $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** The non-empty description of one item; null when absent or unreadable (the description is optional prompt text). */
    public function descriptionByKey(string $plugin, string $baseid): ?string
    {
        try {
            $value = $this->connection()->fetchOne("SELECT description FROM public.core_item_description_custom WHERE plugin = ? AND baseid = ? AND description <> '' LIMIT 1", [$plugin, $baseid]);
        } catch (DbalException|DriverException $error) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}

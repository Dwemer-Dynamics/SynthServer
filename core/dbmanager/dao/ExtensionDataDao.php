<?php

// The database side of the extension host: the per-extension key/value store (core_extension_store), the ledger of applied
// extension migrations (core_extension_migration), and a parameterized door to an extension's own ext_<id>_* tables.
// Statement-only (no mapped entity): rows are never hydrated as objects. Every statement is scoped to one extension id.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;

/** Store, migration ledger and own-table statements for extensions. */
final class ExtensionDataDao
{
    use UsesEntityManager;

    public const MAX_VALUE_BYTES = 1048576;
    public const MAX_KEYS = 1000;

    public function storeGet(string $id, string $key): mixed
    {
        $raw = $this->connection()->fetchOne('SELECT value::text FROM public.core_extension_store WHERE extension_id = :id AND store_key = :key',
            ['id' => $id, 'key' => $key]);

        return $raw === false ? null : json_decode((string) $raw, true);
    }

    public function storeHas(string $id, string $key): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM public.core_extension_store WHERE extension_id = :id AND store_key = :key',
            ['id' => $id, 'key' => $key]) !== false;
    }

    public function storeSet(string $id, string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_VALUE_BYTES) {
            throw new \LengthException('extension_store_too_large');
        }
        if ($key === '' || strlen($key) > 200) {
            throw new InvalidArgumentException('extension_store_key');
        }
        if (!$this->storeHas($id, $key) && $this->storeCount($id) >= self::MAX_KEYS) {
            throw new \LengthException('extension_store_full');
        }
        $this->connection()->executeStatement(
            'INSERT INTO public.core_extension_store (extension_id, store_key, value) VALUES (:id, :key, CAST(:value AS jsonb))
             ON CONFLICT (extension_id, store_key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
            ['id' => $id, 'key' => $key, 'value' => $json]
        );
    }

    public function storeDelete(string $id, string $key): void
    {
        $this->connection()->executeStatement('DELETE FROM public.core_extension_store WHERE extension_id = :id AND store_key = :key',
            ['id' => $id, 'key' => $key]);
    }

    public function storeCount(string $id): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM public.core_extension_store WHERE extension_id = :id', ['id' => $id]);
    }

    /** @return list<string> keys starting with $prefix, sorted */
    public function storeKeys(string $id, string $prefix = ''): array
    {
        $like = addcslashes($prefix, '\\%_') . '%';

        return array_map('strval', $this->connection()->fetchFirstColumn(
            'SELECT store_key FROM public.core_extension_store WHERE extension_id = :id AND store_key LIKE :like ORDER BY store_key LIMIT 1000',
            ['id' => $id, 'like' => $like]
        ));
    }

    /** @return array<string, string> applied migration name => SHA-256 */
    public function appliedMigrations(string $id): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT name, sha256 FROM public.core_extension_migration WHERE extension_id = :id', ['id' => $id]);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = trim((string) $row['sha256']);
        }

        return $out;
    }

    public function recordMigration(string $id, string $name, string $sha256): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_extension_migration (extension_id, name, sha256) VALUES (:id, :name, :sha)',
            ['id' => $id, 'name' => $name, 'sha' => $sha256]
        );
    }

    /** Runs one already-checked statement of an extension's own SQL, or a transaction control word. */
    public function run(string $sql, array $params = []): int
    {
        return $this->connection()->executeStatement($sql, $params);
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->connection()->fetchAllAssociative($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->connection()->fetchAssociative($sql, $params);

        return $row === false ? null : $row;
    }
}

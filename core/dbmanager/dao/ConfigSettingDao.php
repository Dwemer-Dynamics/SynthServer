<?php

// The one read point for core_config_setting. Reads are array or scalar hydrated, never the identity map, so each call sees
// the row as the shared session sees it now: a direct legacy write, even one still uncommitted in the caller's transaction,
// is visible to the next read. No read locks. The entity is mapped read-only, so the writes (upsert, delete) are
// parameterized DBAL statements on the same session; only ConfigManager calls them.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use RuntimeException;
use Synth\Core\Entities\Base\AbstractConfigSetting;


/** Reads stored settings by scope and key. */
final class ConfigSettingDao
{
    private static ?self $instance = null;

    private function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    private function fresh(): EntityManagerInterface
    {
        $this->entityManager->clear();

        return $this->entityManager;
    }

    /** Called once by Database::boot(): makes this DAO's static reads use the shared EntityManager. */
    public static function configure(EntityManagerInterface $entityManager): void
    {
        self::$instance = new self($entityManager);
    }

    /** A DAO over one EntityManager, for code (and tests) that owns its own legacy session. */
    public static function using(EntityManagerInterface $entityManager): self
    {
        return new self($entityManager);
    }

    /** The configured process-wide DAO. */
    public static function shared(): self
    {
        return self::$instance ?? throw new \LogicException('Database::boot() must be called before settings are read.');
    }

    /** The mapped row of one setting, or null when it has no row. Prefer value() for plain reads. */
    public function find(string $scope, string $key): ?AbstractConfigSetting
    {
        return $this->fresh()->find(AbstractConfigSetting::class, ['scope' => $scope, 'key' => $key]);
    }

    /** Raw stored value ('' for a NULL value), or null when the setting has no row or the table is unreadable. */
    public function value(string $scope, string $key): ?string
    {
        try {
            $rows = $this->entityManager->createQuery('SELECT s.key, s.value FROM '.AbstractConfigSetting::class
                .' s WHERE s.scope = :scope AND s.key = :key')
                ->setParameter('scope', $scope)->setParameter('key', $key)->setMaxResults(1)->getArrayResult();
        } catch (DbalException|DriverException $error) {
            // As the legacy read did: execQuery() has logged the failure, and an unreadable setting reads as absent.
            return null;
        }

        return $rows === [] ? null : strval($rows[0]['value'] ?? '');
    }

    /**
     * Raw stored values for the named keys of one scope that have a row, in one read; throws when unreadable.
     *
     * @param list<string|int> $keys
     * @return array<string|int, string>
     */
    public function values(string $scope, array $keys): array
    {
        // One statement with one text[] parameter: DQL IN (:keys) would bind a parameter per key and fail past
        // PostgreSQL's 65535-parameter limit. An empty list still runs, so an unreadable table still throws.
        $literal = '{'.implode(',', array_map(static fn($key) => '"'.addcslashes(strval($key), '"\\').'"', $keys)).'}';
        $mapping = (new ResultSetMapping())->addScalarResult('config_key', 'key')->addScalarResult('config_value', 'value');
        try {
            $rows = $this->entityManager->createNativeQuery(
                'SELECT config_key, config_value FROM public.core_config_setting WHERE scope = ? AND config_key = ANY(CAST(? AS text[]))',
                $mapping
            )->setParameter(1, $scope)->setParameter(2, $literal)->getScalarResult();
        } catch (DbalException|DriverException $error) {
            throw new RuntimeException('Unable to read '.$scope.' settings', 0, $error);
        }
        $values = [];
        foreach ($rows as $row) {
            $values[strval($row['key'])] = strval($row['value'] ?? '');
        }

        return $values;
    }

    /**
     * Rows of one scope (only the named keys when given), ordered by key, read FOR SHARE: the rows stay unchangeable until the
     * caller's open transaction ends, and the read joins that transaction because it runs on the same session. Returns [] when
     * the read fails, as the legacy read did.
     *
     * @param list<string>|null $keys
     * @return array<string, string> key => value ('' for NULL)
     */
    public function valuesForShare(string $scope, ?array $keys = null): array
    {
        $sql = 'SELECT config_key, config_value FROM public.core_config_setting WHERE scope = ?';
        $parameters = [$scope];
        if ($keys !== null) {
            if ($keys === []) {
                return [];
            }
            $sql .= ' AND config_key IN ('.implode(', ', array_fill(0, count($keys), '?')).')';
            array_push($parameters, ...array_map('strval', $keys));
        }
        try {
            $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql.' ORDER BY config_key FOR SHARE', $parameters);
        } catch (DbalException|DriverException $error) {
            return [];
        }
        $values = [];
        foreach ($rows as $row) {
            $values[strval($row['config_key'])] = strval($row['config_value'] ?? '');
        }

        return $values;
    }

    /**
     * Every stored row of one scope, ordered by key, as id, value ('' for NULL), description and updated_at; throws when
     * unreadable.
     *
     * @return list<array{id: string, value: string, description: string, updated_at: ?string}>
     */
    public function all(string $scope): array
    {
        $mapping = (new ResultSetMapping())->addScalarResult('config_key', 'id')->addScalarResult('config_value', 'value')
            ->addScalarResult('description', 'description')->addScalarResult('updated_at', 'updated_at');
        try {
            $rows = $this->entityManager->createNativeQuery(
                'SELECT config_key, config_value, description, updated_at FROM public.core_config_setting WHERE scope = ? ORDER BY config_key ASC',
                $mapping
            )->setParameter(1, $scope)->getScalarResult();
        } catch (DbalException|DriverException $error) {
            throw new RuntimeException('Unable to read '.$scope.' settings', 0, $error);
        }

        return array_map(static fn(array $row): array => [
            'id' => strval($row['id']),
            'value' => strval($row['value'] ?? ''),
            'description' => strval($row['description'] ?? ''),
            'updated_at' => isset($row['updated_at']) ? strval($row['updated_at']) : null,
        ], $rows);
    }

    /**
     * Stores one setting, replacing any previous value. A null description keeps the stored one (or '' for a new row).
     * Returns false when the statement fails, as the legacy writer did.
     */
    public function upsert(string $scope, string $key, string $value, ?string $description = null): bool
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO public.core_config_setting (scope, config_key, config_value, description, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP) '
                .'ON CONFLICT (scope, config_key) DO UPDATE SET config_value = EXCLUDED.config_value, '
                .'description = CASE WHEN CAST(? AS boolean) THEN EXCLUDED.description ELSE public.core_config_setting.description END, updated_at = CURRENT_TIMESTAMP',
                [$scope, $key, $value, $description ?? '', $description !== null ? 'true' : 'false']
            );
        } catch (DbalException|DriverException $error) {
            return false;
        }

        return true;
    }

    /**
     * Stores several settings of one scope in one statement (all or none), replacing previous values. Descriptions are kept.
     * Returns false when the statement fails, as the legacy writer did.
     *
     * @param array<string, string> $values config_key => config_value
     */
    public function upsertMany(string $scope, array $values): bool
    {
        if ($values === []) {
            return true;
        }
        $rows = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            $rows[] = '(?, ?, ?)';
            array_push($parameters, $scope, (string) $key, (string) $value);
        }
        try {
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO public.core_config_setting (scope, config_key, config_value) VALUES '.implode(',', $rows)
                .' ON CONFLICT (scope, config_key) DO UPDATE SET config_value = EXCLUDED.config_value',
                $parameters
            );
        } catch (DbalException|DriverException $error) {
            return false;
        }

        return true;
    }

    /** Removes one setting (back to its default); true when the statement ran, whether or not a row existed. */
    public function delete(string $scope, string $key): bool
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM public.core_config_setting WHERE scope = ? AND config_key = ?',
                [$scope, $key]
            );
        } catch (DbalException|DriverException $error) {
            return false;
        }

        return true;
    }

    /**
     * Rows of one scope whose key starts with the prefix (the prefix is literal: LIKE wildcards in it do not match),
     * ordered by key (the first $limit when given), as id and value ('' for NULL). Returns [] when the table is unreadable, as the legacy read did.
     *
     * @return list<array{id: string, value: string}>
     */
    public function withPrefix(string $scope, string $prefix, ?int $limit = null): array
    {
        $mapping = (new ResultSetMapping())->addScalarResult('config_key', 'id')->addScalarResult('config_value', 'value');
        try {
            $rows = $this->entityManager->createNativeQuery(
                "SELECT config_key, config_value FROM public.core_config_setting WHERE scope = ? AND config_key LIKE ? ESCAPE '!' ORDER BY config_key ASC"
                .($limit !== null ? ' LIMIT '.max(0, $limit) : ''),
                $mapping
            )->setParameter(1, $scope)->setParameter(2, self::likePrefix($prefix))->getScalarResult();
        } catch (DbalException|DriverException $error) {
            return [];
        }

        return array_map(static fn(array $row): array => ['id' => strval($row['id']), 'value' => strval($row['value'] ?? '')], $rows);
    }

    /** Renames one setting's key within its scope; true when the statement ran, whether or not a row matched. */
    public function rename(string $scope, string $key, string $newKey): bool
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE public.core_config_setting SET config_key = ? WHERE scope = ? AND config_key = ?',
                [$newKey, $scope, $key]
            );
        } catch (DbalException|DriverException $error) {
            return false;
        }

        return true;
    }

    /**
     * Removes every row of one scope whose key starts with any of the prefixes, in one statement. True when the statement
     * ran, whether or not a row matched; an empty list does nothing.
     *
     * @param list<string> $prefixes
     */
    public function deleteByPrefixes(string $scope, array $prefixes): bool
    {
        if ($prefixes === []) {
            return true;
        }
        $parameters = [$scope];
        $conditions = [];
        foreach ($prefixes as $prefix) {
            $conditions[] = "config_key LIKE ? ESCAPE '!'";
            $parameters[] = self::likePrefix(strval($prefix));
        }
        try {
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM public.core_config_setting WHERE scope = ? AND ('.implode(' OR ', $conditions).')',
                $parameters
            );
        } catch (DbalException|DriverException $error) {
            return false;
        }

        return true;
    }

    private static function likePrefix(string $prefix): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
    }
}

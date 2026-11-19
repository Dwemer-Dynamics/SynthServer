<?php

// The one read point for core_config_setting. Reads are array or scalar hydrated, never the identity map, so each call sees
// the row as the shared session sees it now: a direct legacy write, even one still uncommitted in the caller's transaction,
// is visible to the next read. No read locks. Writes stay in lib/settings.php for now; the entity is mapped read-only.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use RuntimeException;
use Synth\Core\Entities\Base\AbstractConfigSetting;

require_once dirname(__DIR__, 2).'/entities/base/AbstractConfigSetting.php';

/** Reads stored settings by scope and key. */
final class ConfigSettingDao
{
    private static ?self $instance = null;

    private function __construct(private readonly EntityManagerInterface $entityManager)
    {
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
        return $this->entityManager->find(AbstractConfigSetting::class, ['scope' => $scope, 'key' => $key]);
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
}

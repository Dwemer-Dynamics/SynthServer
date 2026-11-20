<?php

// The shared shape of every DAO: one instance per EntityManager, configured once by Database::boot(), with using() for a
// caller (or test) that owns its own legacy session. A DAO class using this trait is listed in Database::DAOS.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

trait UsesEntityManager
{
    private static ?self $instance = null;

    private function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** Called once by Database::boot(): makes the static shared() DAO use the shared EntityManager. */
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
        return self::$instance ?? throw new LogicException('Database::boot() must be called before '.static::class.' is used.');
    }

    /** @var array{0: object, 1: self}|null the legacy session the last current() DAO was built over, and that DAO */
    private static ?array $bound = null;

    /**
     * The DAO for request code that still owns a legacy session: the booted process-wide DAO when Database::boot() ran,
     * else one over $db (default $GLOBALS['db']), built once per session object.
     */
    public static function current(?object $db = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $db ??= is_object(\Synth\Bootstrap::db() ?? null) ? \Synth\Bootstrap::db() : null;
        if ($db === null) {
            throw new \RuntimeException('No database session is available for '.static::class.'.');
        }
        if (self::$bound === null || self::$bound[0] !== $db) {
            self::$bound = [$db, self::using(\Synth\Core\Doctrine\EntityManagerFactory::forSharedSession($db))];
        }

        return self::$bound[1];
    }

    /**
     * The EntityManager with an empty identity map. Rows are written by parameterized statements (and by legacy SQL), never by
     * Doctrine, so a managed copy would go stale; clearing first makes every find read the row as it is now.
     */
    private function fresh(): EntityManagerInterface
    {
        $this->entityManager->clear();

        return $this->entityManager;
    }

    /** The connection every statement of this DAO runs on (the legacy session: transactions belong to its owner). */
    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}

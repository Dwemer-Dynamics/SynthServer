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

<?php

/**
 * Sets up database access once per process: creates the shared Doctrine EntityManager and hands it to every DAO, so
 * application code only works with the system entity classes. Ported from the CHIM Evolution persistence model.
 *
 * SYNTH differs in one respect: the EntityManager rides the legacy PostgreSQL session (SharedSessionDriver), so a DAO
 * can run inside a legacy-owned transaction. That session is passed to boot() until the last legacy caller is gone.
 */
declare(strict_types=1);

namespace Synth\Core\DbManager;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Synth\Core\DbManager\Dao\ConfigSettingDao;
use Synth\Core\DbManager\Dao\IttConnectorDao;
use Synth\Core\DbManager\Dao\LlmConnectorDao;
use Synth\Core\DbManager\Dao\LocationDao;
use Synth\Core\DbManager\Dao\LocationStateDao;
use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateActorDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateCustomDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateDao;
use Synth\Core\DbManager\Dao\NpcDao;
use Synth\Core\DbManager\Dao\NpcHistoryDao;
use Synth\Core\DbManager\Dao\NpcProfileRuleDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\DbManager\Dao\PluginManifestRevisionDao;
use Synth\Core\DbManager\Dao\ProfileDao;
use Synth\Core\DbManager\Dao\RelationshipConfigDao;
use Synth\Core\DbManager\Dao\SttConnectorDao;
use Synth\Core\DbManager\Dao\TtsConnectorDao;
use Synth\Core\DbManager\Dao\WorldspaceDao;
use Synth\Core\DbManager\Types\PreciseDateTimeType;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once __DIR__.'/EntityManagerFactory.php';

final class Database
{
    /**
     * DAO classes to configure, each with a static configure(EntityManagerInterface) method. A new DAO is added here.
     *
     * @var list<class-string>
     */
    public const DAOS = [
        ConfigSettingDao::class, NpcActorDao::class, NpcRuntimeActorDao::class, PluginManifestRevisionDao::class,
        LlmConnectorDao::class,
        TtsConnectorDao::class,
        SttConnectorDao::class,
        IttConnectorDao::class,
        ProfileDao::class, LocationDao::class, LocationStateDao::class, WorldspaceDao::class, RelationshipConfigDao::class,
        NpcDao::class,
        NpcHistoryDao::class,
        NpcBiographyTemplateDao::class,
        NpcBiographyTemplateActorDao::class,
        NpcBiographyTemplateCustomDao::class,
        NpcProfileRuleDao::class,
    ];

    /** Column types the entities use beyond Doctrine's own, by name. */
    public const TYPES = [
        PreciseDateTimeType::NAME => PreciseDateTimeType::class,
    ];

    private static ?EntityManagerInterface $entityManager = null;

    /**
     * Creates the EntityManager on the legacy session and configures every DAO.
     *
     * Calling it again in the same process does nothing, so every entry point can call it without checking whether
     * another part already did.
     */
    public static function boot(object $legacySession): void
    {
        if (self::$entityManager !== null) {
            return;
        }

        self::bootWith(EntityManagerFactory::forSharedSession($legacySession));
    }

    /**
     * Configures every DAO with an EntityManager created elsewhere, such as the one a test builds. Replaces any
     * EntityManager set up before.
     */
    public static function bootWith(EntityManagerInterface $entityManager): void
    {
        self::registerTypes();
        self::$entityManager = $entityManager;

        foreach (self::DAOS as $daoClass) {
            $daoClass::configure($entityManager);
        }
    }

    /**
     * Returns the shared EntityManager, for tools that need Doctrine itself.
     *
     * @throws LogicException When boot() has not been called.
     */
    public static function getEntityManager(): EntityManagerInterface
    {
        return self::$entityManager ?? throw new LogicException('Database::boot() must be called before the database is used.');
    }

    /**
     * Makes the project's own column types known to Doctrine.
     *
     * Doctrine keeps types in one list for the whole process, so each type is added once.
     */
    public static function registerTypes(): void
    {
        foreach (self::TYPES as $name => $typeClass) {
            if (!Type::hasType($name)) {
                Type::addType($name, $typeClass);
            }
        }
    }

    /**
     * Forgets the EntityManager. For tests that boot more than once; production code never calls it.
     */
    public static function reset(): void
    {
        self::$entityManager = null;
    }
}

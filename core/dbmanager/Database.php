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
use Synth\Core\DbManager\Dao\ActorActivationDao;
use Synth\Core\DbManager\Dao\ActorEventBatchDao;
use Synth\Core\DbManager\Dao\ActorIdentityDao;
use Synth\Core\DbManager\Dao\CatalogVersionDao;
use Synth\Core\DbManager\Dao\CharacterGenerationDao;
use Synth\Core\DbManager\Dao\CharacterGenerationFieldDao;
use Synth\Core\DbManager\Dao\CharacterGenerationRevisionDao;
use Synth\Core\DbManager\Dao\ConfigSettingDao;
use Synth\Core\DbManager\Dao\DialogueOriginPolicyDao;
use Synth\Core\DbManager\Dao\DialogueRouteDao;
use Synth\Core\DbManager\Dao\DialogueUtteranceDao;
use Synth\Core\DbManager\Dao\DiaryControlRevisionDao;
use Synth\Core\DbManager\Dao\DiaryInputDao;
use Synth\Core\DbManager\Dao\DiaryInputSourceDao;
use Synth\Core\DbManager\Dao\DiaryJobResultDao;
use Synth\Core\DbManager\Dao\DiaryResultDao;
use Synth\Core\DbManager\Dao\HistoryEventDao;
use Synth\Core\DbManager\Dao\IttConnectorDao;
use Synth\Core\DbManager\Dao\LlmConnectorDao;
use Synth\Core\DbManager\Dao\LlmRequestLogDao;
use Synth\Core\DbManager\Dao\LocationDao;
use Synth\Core\DbManager\Dao\LocationStateDao;
use Synth\Core\DbManager\Dao\MessageQueueDao;
use Synth\Core\DbManager\Dao\MediaObjectDao;
use Synth\Core\DbManager\Dao\MemoryBatchControlDao;
use Synth\Core\DbManager\Dao\MemoryBatchControlEventDao;
use Synth\Core\DbManager\Dao\MemoryBatchDao;
use Synth\Core\DbManager\Dao\MemoryBatchRebuildDao;
use Synth\Core\DbManager\Dao\MemoryBatchSourceDao;
use Synth\Core\DbManager\Dao\MemoryBatchSummarySourceDao;
use Synth\Core\DbManager\Dao\MemorySummaryDao;
use Synth\Core\DbManager\Dao\MemorySummaryRevisionDao;
use Synth\Core\DbManager\Dao\MiddletermControlRevisionDao;
use Synth\Core\DbManager\Dao\MiddletermInputDao;
use Synth\Core\DbManager\Dao\MiddletermRegenerationApprovalDao;
use Synth\Core\DbManager\Dao\MiddletermReplacementDao;
use Synth\Core\DbManager\Dao\MiddletermResultDao;
use Synth\Core\DbManager\Dao\MiddletermSourceDao;
use Synth\Core\DbManager\Dao\NarratorContextStateDao;
use Synth\Core\DbManager\Dao\NarratorDefinitionDao;
use Synth\Core\DbManager\Dao\NarratorDefinitionRevisionDao;
use Synth\Core\DbManager\Dao\NarratorLaneHeadDao;
use Synth\Core\DbManager\Dao\NarratorPendingOutputDao;
use Synth\Core\DbManager\Dao\NarratorStateDao;
use Synth\Core\DbManager\Dao\NarratorTranscriptCursorDao;
use Synth\Core\DbManager\Dao\NarratorTranscriptEntryDao;
use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateActorDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateCustomDao;
use Synth\Core\DbManager\Dao\NpcBiographyTemplateDao;
use Synth\Core\DbManager\Dao\NpcDao;
use Synth\Core\DbManager\Dao\NpcHistoryDao;
use Synth\Core\DbManager\Dao\NpcProfileRuleDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\DbManager\Dao\PlaythroughProfileDao;
use Synth\Core\DbManager\Dao\PluginManifestRevisionDao;
use Synth\Core\DbManager\Dao\PluginsDao;
use Synth\Core\DbManager\Dao\ProfileDao;
use Synth\Core\DbManager\Dao\PromptDao;
use Synth\Core\DbManager\Dao\ProtocolEventDao;
use Synth\Core\DbManager\Dao\RechatChainDao;
use Synth\Core\DbManager\Dao\RechatContinuationDao;
use Synth\Core\DbManager\Dao\RechatSelectionDao;
use Synth\Core\DbManager\Dao\RelationshipActorControlDao;
use Synth\Core\DbManager\Dao\RelationshipActorControlEventDao;
use Synth\Core\DbManager\Dao\RelationshipConfigDao;
use Synth\Core\DbManager\Dao\RelationshipEvalQueueDao;
use Synth\Core\DbManager\Dao\RelationshipEvaluationDao;
use Synth\Core\DbManager\Dao\RelationshipEvaluationSourceDao;
use Synth\Core\DbManager\Dao\RelationshipInitQueueDao;
use Synth\Core\DbManager\Dao\RelationshipInteractionEvaluationDao;
use Synth\Core\DbManager\Dao\RelationshipInteractionPriorDao;
use Synth\Core\DbManager\Dao\RelationshipInteractionResultDao;
use Synth\Core\DbManager\Dao\RelationshipInteractionResultPriorDao;
use Synth\Core\DbManager\Dao\RelationshipResponseInputDao;
use Synth\Core\DbManager\Dao\RelationshipResponseSourceDao;
use Synth\Core\DbManager\Dao\RelationshipResultDao;
use Synth\Core\DbManager\Dao\RelationshipResultPriorDao;
use Synth\Core\DbManager\Dao\RelationshipRevisionDao;
use Synth\Core\DbManager\Dao\RelationshipRevisionSourceDao;
use Synth\Core\DbManager\Dao\RequestExecutionDao;
use Synth\Core\DbManager\Dao\RuntimeContextDao;
use Synth\Core\DbManager\Dao\RuntimeSessionDao;
use Synth\Core\DbManager\Dao\SocialTaskClaimDao;
use Synth\Core\DbManager\Dao\SocialTaskEventDao;
use Synth\Core\DbManager\Dao\SocialTaskInputDao;
use Synth\Core\DbManager\Dao\SttConnectorDao;
use Synth\Core\DbManager\Dao\TtsConnectorDao;
use Synth\Core\DbManager\Dao\VisualContextDao;
use Synth\Core\DbManager\Dao\VisualDescriptionRevisionDao;
use Synth\Core\DbManager\Dao\VisualResultDao;
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
        LlmRequestLogDao::class, ConfigSettingDao::class, NpcActorDao::class, NpcRuntimeActorDao::class, PluginManifestRevisionDao::class,
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
        MessageQueueDao::class,
        HistoryEventDao::class,
        ProtocolEventDao::class,
        RechatChainDao::class,
        RechatContinuationDao::class,
        RechatSelectionDao::class,
        DialogueOriginPolicyDao::class,
        DialogueRouteDao::class,
        DialogueUtteranceDao::class,
        RequestExecutionDao::class,
        PromptDao::class,
        VisualContextDao::class,
        VisualResultDao::class,
        VisualDescriptionRevisionDao::class,
        MediaObjectDao::class,
        DiaryControlRevisionDao::class,
        DiaryInputSourceDao::class,
        DiaryInputDao::class,
        DiaryJobResultDao::class,
        DiaryResultDao::class,
        MemoryBatchControlEventDao::class,
        MemoryBatchControlDao::class,
        MemoryBatchRebuildDao::class,
        MemoryBatchSourceDao::class,
        MemoryBatchSummarySourceDao::class,
        MemoryBatchDao::class,
        MemorySummaryDao::class,
        MemorySummaryRevisionDao::class,
        MiddletermControlRevisionDao::class,
        MiddletermInputDao::class,
        MiddletermRegenerationApprovalDao::class,
        MiddletermReplacementDao::class,
        MiddletermResultDao::class,
        MiddletermSourceDao::class,
        PlaythroughProfileDao::class,
        ActorIdentityDao::class,
        ActorActivationDao::class,
        ActorEventBatchDao::class,
        RuntimeSessionDao::class,
        RuntimeContextDao::class,
        PluginsDao::class,
        CatalogVersionDao::class,
        RelationshipActorControlEventDao::class,
        RelationshipActorControlDao::class,
        RelationshipEvaluationSourceDao::class,
        RelationshipEvaluationDao::class,
        RelationshipInteractionEvaluationDao::class,
        RelationshipInteractionPriorDao::class,
        RelationshipInteractionResultPriorDao::class,
        RelationshipInteractionResultDao::class,
        RelationshipResponseInputDao::class,
        RelationshipResponseSourceDao::class,
        RelationshipResultPriorDao::class,
        RelationshipResultDao::class,
        RelationshipRevisionSourceDao::class,
        RelationshipRevisionDao::class,
        SocialTaskClaimDao::class,
        SocialTaskEventDao::class,
        SocialTaskInputDao::class,
        RelationshipEvalQueueDao::class,
        RelationshipInitQueueDao::class,
        NarratorContextStateDao::class,
        NarratorDefinitionRevisionDao::class,
        NarratorDefinitionDao::class,
        NarratorLaneHeadDao::class,
        NarratorPendingOutputDao::class,
        NarratorStateDao::class,
        NarratorTranscriptCursorDao::class,
        NarratorTranscriptEntryDao::class,
        CharacterGenerationFieldDao::class,
        CharacterGenerationDao::class,
        CharacterGenerationRevisionDao::class,
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

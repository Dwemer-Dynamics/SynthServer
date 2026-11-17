<?php declare(strict_types=1);

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Synth\Core\DbManager\Database;
use Synth\Core\Entities\System\CopiesMappedEntity;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
require_once dirname(__DIR__, 2).'/core/entities/system/CopiesMappedEntity.php';

// A stand-in for an Abstract* mapped class and the system class that copies it.
class ScaffoldMapped
{
    public ?string $name = null;
    public ?int $count = null;
}

final class ScaffoldSystem extends ScaffoldMapped
{
    use CopiesMappedEntity;

    public static function from(ScaffoldMapped $mapped): static
    {
        return self::copyOf($mapped);
    }

    public function writeTo(ScaffoldMapped $mapped): void
    {
        $this->copyValuesTo($mapped);
    }
}

// The entity persistence scaffolding (docs/SCHEMA-REDESIGN.md, step 0): Database boot and type registry, the
// microsecond timestamp type, the system-entity copy trait, and the rule that every mapped core_* table has a manifest
// entry. No database is needed.
final class DbManagerScaffoldTest extends TestCase
{
    protected function tearDown(): void
    {
        Database::reset();
    }

    public function testPreciseTimestampKeepsMicrosecondsAndNormalizesToUtc(): void
    {
        Database::registerTypes();
        Database::registerTypes();
        $type = Type::getType('datetimetz_precise');
        $platform = new PostgreSQLPlatform();

        $stored = $type->convertToDatabaseValue(new DateTimeImmutable('2026-11-17 10:20:30.123456+02:00'), $platform);
        self::assertSame('2026-11-17 08:20:30.123456+00:00', $stored);
        $read = $type->convertToPHPValue('2026-11-17 08:20:30.12+00', $platform);
        self::assertSame('2026-11-17 08:20:30.120000', $read->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $read->getTimezone()->getName());
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertSame('TIMESTAMP(6) WITH TIME ZONE', $type->getSQLDeclaration([], $platform));
    }

    public function testPreciseTimestampRefusesANonDateValue(): void
    {
        Database::registerTypes();
        $this->expectException(InvalidType::class);
        Type::getType('datetimetz_precise')->convertToDatabaseValue('2026-11-17', new PostgreSQLPlatform());
    }

    public function testTheEntityManagerIsUnavailableBeforeBootAndSharedAfter(): void
    {
        try {
            Database::getEntityManager();
            self::fail('getEntityManager() must refuse before boot.');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }
        $entityManager = $this->createMock(EntityManagerInterface::class);
        Database::bootWith($entityManager);
        self::assertSame($entityManager, Database::getEntityManager());
    }

    public function testEveryRegisteredDaoCanBeConfigured(): void
    {
        foreach (Database::DAOS as $dao) {
            self::assertTrue(class_exists($dao) && method_exists($dao, 'configure'), $dao.' needs a static configure()');
        }
        self::assertSame(Database::DAOS, array_values(array_unique(Database::DAOS)), 'A DAO is registered once.');
    }

    public function testASystemEntityCopiesAMappedOneWithoutSharingIt(): void
    {
        $mapped = new ScaffoldMapped();
        $mapped->name = 'Piper';
        $mapped->count = 3;

        $copy = ScaffoldSystem::from($mapped);
        self::assertInstanceOf(ScaffoldSystem::class, $copy);
        self::assertSame(['Piper', 3], [$copy->name, $copy->count]);
        $copy->name = 'Nick';
        self::assertSame('Piper', $mapped->name, 'A copy is not tracked.');
        $copy->writeTo($mapped);
        self::assertSame('Nick', $mapped->name);
    }

    public function testEveryMappedCoreTableIsInTheStructureManifest(): void
    {
        $root = dirname(__DIR__, 2);
        $structure = file_get_contents($root.'/core/dbmanager/migrations/Version20261117000000.structure.txt');
        $manifest = json_decode(file_get_contents($root.'/core/dbmanager/migrations/schema-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $listed = '';
        foreach ($manifest['migrations'] as $entry) {
            if (isset($entry['structure'])) {
                $listed .= file_get_contents($root.'/core/dbmanager/migrations/'.$entry['structure']['file'])."\n";
            }
        }
        self::assertStringContainsString('table|synth|core_api_badge|BASE TABLE', $structure, 'The structure file is the one inspected.');
        foreach (glob($root.'/core/entities/base/*.php') ?: [] as $file) {
            if (preg_match("/#\\[ORM\\\\Table\\(name: '(core_[a-z0-9_]+)'/", file_get_contents($file), $match) !== 1) {
                continue;
            }
            self::assertStringContainsString("table|public|{$match[1]}|BASE TABLE", $listed, basename($file).' has no manifest entry for '.$match[1]);
        }
    }
}

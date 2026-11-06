<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';

// DatabaseTestCase recreates the database while runtime helpers cache completion per process.
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NamedGiftUpgradeTest extends DatabaseTestCase
{
    public function testPendingUpgradeAddsNamedGiftAndKeepsCatalogPreferences(): void
    {
        global $db;
        $db ??= new sql();
        $root = dirname(__DIR__, 2);
        require_once $root.'/lib/runtime_bootstrap.php';

        $namedGift = "SELECT * FROM synth.core_action WHERE code_name = 'TakeItemFromPlayer'";
        $otherActions = "SELECT * FROM synth.core_action WHERE code_name <> 'TakeItemFromPlayer' ORDER BY code_name";
        $previousVersion = "UPDATE synth.database_versioning SET version = 20260719001 WHERE tablename = 'core_action'";

        self::assertFalse(synthRuntimeNeedsDbUpdates(), 'The baseline database fixture is not ready.');
        self::assertNotFalse($db->execQuery("UPDATE synth.core_action SET is_activated = false WHERE code_name = 'CheckInventory'"));
        self::assertNotFalse($db->execQuery("DELETE FROM synth.core_action WHERE code_name = 'TakeItemFromPlayer'"));
        self::assertNotFalse($db->execQuery($previousVersion));
        $before = $db->fetchAll($otherActions);
        self::assertNotEmpty($before);
        self::assertTrue(synthRuntimeNeedsDbUpdates());

        require $root.'/debug/db_updates.php';

        self::assertFalse(synthRuntimeNeedsDbUpdates());
        $gift = $db->fetchAll($namedGift);
        self::assertCount(1, $gift);
        self::assertSame('t', $gift[0]['is_activated']);
        self::assertSame(['target', 'item'], json_decode($gift[0]['parameters_json'], true, 512, JSON_THROW_ON_ERROR)['required']);
        self::assertSame($before, $db->fetchAll($otherActions));

        self::assertNotFalse($db->execQuery("UPDATE synth.core_action
            SET is_activated = false, description = 'Fixture gift preference'
            WHERE code_name = 'TakeItemFromPlayer'"));
        $customizedGift = $db->fetchAll($namedGift);
        self::assertNotFalse($db->execQuery($previousVersion));

        require $root.'/debug/db_updates.php';

        self::assertFalse(synthRuntimeNeedsDbUpdates());
        self::assertSame($customizedGift, $db->fetchAll($namedGift));
        self::assertSame($before, $db->fetchAll($otherActions));
    }
}

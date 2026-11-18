<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';

// Retired (schema redesign, step 3a): this test exercised the frozen legacy upgrade chain in debug/db_updates.php through
// the version floor of synth.database_versioning. The schema has no upgrade path (owner decision) and that table is now
// public.core_catalog_version, so the chain is never run against the baseline. The file goes with the chain in step 8.
final class NamedGiftUpgradeTest extends DatabaseTestCase
{
    public function testLegacyUpgradeChainIsRetired(): void
    {
        self::markTestSkipped('The legacy db_updates.php upgrade chain is retired by the schema redesign.');
    }
}

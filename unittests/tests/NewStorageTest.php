<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';

use Synth\Core\Doctrine\EntityManagerFactory;

// The location catalog tables and the authored relationship table: their DAOs, bounds and database checks.
final class NewStorageTest extends DatabaseTestCase
{
    private function boot(sql $db): void
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);
    }

    public function testLocationCatalogStoresMarkersStateAndAncestry(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $locations = \Synth\Core\DbManager\Dao\LocationDao::shared();
            $world = \Synth\Core\DbManager\Dao\WorldspaceDao::shared()->upsert(['plugin' => 'Fallout4.esm', 'baseid' => 0x3C]);
            $this->assertSame($world, \Synth\Core\DbManager\Dao\WorldspaceDao::shared()->upsert(['plugin' => 'fallout4.esm', 'baseid' => 0x3C, 'name' => 'Commonwealth']));
            $this->assertSame($world, (int) \Synth\Core\DbManager\Dao\WorldspaceDao::shared()->findByRef('FALLOUT4.ESM', 0x3C)->toArray()['map_worldspace_id'], 'a worldspace maps to itself by default');

            $root = $locations->upsert(['plugin' => 'Fallout4.esm', 'baseid' => 1, 'name' => 'Commonwealth', 'keywords' => ['LocTypeRegion']]);
            $child = $locations->upsert(['plugin' => 'fallout4.esm', 'baseid' => 2, 'name' => 'Sanctuary', 'parent_plugin' => 'Fallout4.esm', 'parent_baseid' => 1, 'tags' => ['settlement']]);
            $this->assertSame($child, $locations->upsert(['plugin' => 'fallout4.esm', 'baseid' => 2, 'name' => 'Sanctuary Hills']), 'the same reference updates, it never duplicates');
            $db->execQuery("UPDATE public.core_location SET parent_location_id = $root WHERE location_id = $child");
            $this->assertSame(['Sanctuary Hills', 'Commonwealth'], array_column($locations->ancestry($child), 'name'));
            $this->assertCount(1, $locations->ancestry($child, 1));
            $found = \Synth\Core\Entities\System\Location::fromMapped($locations->findByRef('Fallout4.ESM', 2))->toArray();
            $this->assertSame(['Sanctuary Hills', 'game', 'none', ['settlement']], [$found['name'], $found['kind'], $found['position_source'], $found['tags']]);

            $marker = ['marker_type' => 'map_marker', 'plugin' => 'Fallout4.esm', 'baseid' => 77, 'worldspace_id' => $world, 'x' => 12.5, 'y' => -3.0, 'z' => 0.0];
            $this->assertSame(2, $locations->replaceMarkers($child, [$marker, ['marker_type' => 'location_center', 'plugin' => 'fallout4.esm', 'baseid' => 78, 'interior' => true]]));
            $this->assertSame(1, $locations->replaceMarkers($child, [$marker]), 'a record replaces its markers');
            $this->assertSame('1', (string) $db->fetchOne("SELECT count(*) AS n FROM public.core_location_marker WHERE location_id = $child")['n']);

            $states = \Synth\Core\DbManager\Dao\LocationStateDao::shared();
            $states->observe('pt-1', $child, true, null);
            $states->observe('pt-1', $child, null, true);
            $state = $states->find('pt-1', $child)->toArray();
            $this->assertSame([true, true], [$state['cleared'], $state['discovered']], 'a null never overwrites a known value');
            $this->assertNull($states->find('pt-2', $child));

            foreach ([
                "INSERT INTO public.core_location (plugin, baseid, name, map_x, map_y) VALUES ('a.esm', 1, 'X', 0, 0)",
                "INSERT INTO public.core_location (plugin, baseid, name, map_x) VALUES ('a.esm', 2, 'X', 5)",
                "INSERT INTO public.core_location (plugin, baseid, name) VALUES ('A.ESM', 3, 'X')",
                "INSERT INTO public.core_location_marker (location_id, marker_type, plugin, baseid, interior, worldspace_id) VALUES ($child, 'workshop', 'a.esm', 9, true, $world)",
            ] as $sql) {
                $this->assertFalse($this->tryStatement($db, $sql), "must be refused: $sql");
            }
            $db->execQuery("DELETE FROM public.core_location WHERE location_id = $child");
            $this->assertSame('0', (string) $db->fetchOne("SELECT count(*) AS n FROM public.core_location_marker WHERE location_id = $child")['n'], 'markers cascade');
        } finally {
            $db->close();
        }
    }

    public function testAuthoredRelationshipsAreRevisionedBoundedAndPromptSafe(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $dao = \Synth\Core\DbManager\Dao\RelationshipConfigDao::shared();
            $this->assertTrue($dao->create('pt-1', 10, 11, ['relation_detail' => 'Old friends', 'private_notes' => 'secret', 'automatic_updates_locked' => true, 'baseline_affinity' => 40]));
            $this->assertFalse($dao->create('pt-1', 10, 11, []), 'a pair is created once');
            $row = \Synth\Core\Entities\System\RelationshipConfig::fromMapped($dao->read('pt-1', 10, 11))->toArray();
            $this->assertSame([1, 1, 0, true, 40], [$row['revision'], $row['affinity_baseline_revision'], $row['type_baseline_revision'], $row['automatic_updates_locked'], $row['baseline_affinity']]);
            $this->assertNull($dao->read('pt-1', 11, 10), 'pairs are directed');
            $this->assertNull($dao->read('pt-2', 10, 11), 'rows are playthrough scoped');

            $safe = $dao->readForPrompt('pt-1', 10, [11, 12]);
            $this->assertSame([11], array_keys($safe));
            $this->assertArrayNotHasKey('private_notes', $safe[11]);
            $this->assertArrayNotHasKey('automatic_updates_locked', $safe[11]);
            $this->assertSame('Old friends', $dao->readPromptSafe('pt-1', 10, 11)['relation_detail']);
            $this->assertSame([], $dao->readForPrompt('pt-1', 10, []));

            $this->assertSame('accepted', $dao->update('pt-1', 10, 11, ['custom_info' => 'Met at the vault', 'baseline_type' => 'friends'], 1));
            $this->assertSame('stale_revision', $dao->update('pt-1', 10, 11, ['custom_info' => 'lost update'], 1));
            $this->assertSame('not_found', $dao->update('pt-1', 10, 99, ['custom_info' => 'x'], 1));
            $row = \Synth\Core\Entities\System\RelationshipConfig::fromMapped($dao->read('pt-1', 10, 11))->toArray();
            $this->assertSame([2, 1, 2, 'Met at the vault'], [$row['revision'], $row['affinity_baseline_revision'], $row['type_baseline_revision'], $row['custom_info']],
                'a changed baseline records the revision it was set at');
            $this->assertSame('revision_exhausted', $dao->update('pt-1', 10, 11, ['custom_info' => 'x'], PHP_INT_MAX));

            foreach ([
                fn () => $dao->create('pt-1', 5, 5, []),
                fn () => $dao->create('pt-1', 5, 6, ['relation_detail' => str_repeat('x', 513)]),
                fn () => $dao->create('pt-1', 5, 6, ['baseline_affinity' => 101]),
                fn () => $dao->create('pt-1', 5, 6, ['baseline_type' => 'Not Valid']),
                fn () => $dao->readForPrompt('pt-1', 10, range(1, 9)),
            ] as $call) {
                try {
                    $call();
                    $this->fail('An out-of-bounds relationship must be refused');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $db->close();
        }
    }

    private function tryStatement(sql $db, string $statement): bool
    {
        $db->execQuery('BEGIN');
        $db->execQuery('SAVEPOINT attempt');
        $ok = @$db->execQuery($statement) !== false;
        $db->execQuery($ok ? 'RELEASE SAVEPOINT attempt' : 'ROLLBACK TO SAVEPOINT attempt');
        $db->execQuery('ROLLBACK');

        return $ok;
    }
}

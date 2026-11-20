<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/core/entities/system/NpcActor.php';
require_once dirname(__DIR__, 2).'/core/entities/system/NpcRuntimeActor.php';
require_once dirname(__DIR__, 2).'/core/entities/system/PluginManifestRevision.php';

use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\DbManager\Dao\PluginManifestRevisionDao;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Core\Entities\System\NpcActor;
use Synth\Core\Entities\System\NpcRuntimeActor;
use Synth\Core\Entities\System\PluginManifestRevision;

// Placed actors by (plugin, local id), runtime actors by client-minted id, and recorded load-order manifests.
final class IdentityStorageTest extends DatabaseTestCase
{
    // The entity manager loads the Composer autoloader first; Database.php names Doctrine classes, so it loads after.
    private function boot(sql $db): void
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);
    }

    private function characterId(sql $db): int
    {
        $row = $db->fetchOne("INSERT INTO public.core_npc (npc_name) VALUES ('Identity Test') RETURNING id");
        return (int) $row['id'];
    }

    public function testPlacedActorsAreKeyedByPluginAndLocalReference(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $character = $this->characterId($db);
            $dao = NpcActorDao::shared();
            $dao->save(['refid' => '0001A4B2', 'plugin' => 'Fallout4.ESM', 'character_id' => $character, 'name' => 'Preston', 'gender' => 'male']);
            $dao->save(['refid' => '0001a4b2', 'plugin' => 'fallout4.esm', 'character_id' => $character, 'race' => 'Human']);
            $actor = NpcActor::fromMapped($dao->find('0001A4B2', 'FALLOUT4.ESM'));
            $this->assertSame(['0001a4b2', 'fallout4.esm', $character, 'Preston'], [$actor->refId(), $actor->plugin(), $actor->characterId(), $actor->name()],
                'the key is case-folded and a later save keeps fields it does not change');
            $this->assertCount(1, $dao->forCharacter($character));
            $this->assertNull($dao->find('0001a4b2', 'other.esp'));
            foreach ([['refid' => 'zz', 'plugin' => 'a.esp'], ['refid' => '0001', 'plugin' => '']] as $bad) {
                try {
                    $dao->save($bad + ['character_id' => $character]);
                    $this->fail('An invalid actor key must be refused');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $db->close();
        }
    }

    public function testRuntimeActorsAreFoundByClientIdNotByNameOrFormId(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $character = $this->characterId($db);
            $dao = NpcRuntimeActorDao::shared();
            $id = '0b9c1d2e-3f40-4a51-8b62-73c84d95e6f7';
            $dao->register(['playthrough_id' => 'pt-1', 'runtime_actor_id' => $id, 'character_id' => $character, 'last_form_id' => 'ff001234',
                'name' => 'Settler', 'spawn_kind' => 'workshop_settler', 'spawn_context' => ['cell' => ['plugin' => 'fallout4.esm', 'local_id' => '0a5f60']], 'gamets' => 100]);
            $dao->register(['playthrough_id' => 'pt-1', 'runtime_actor_id' => strtoupper($id), 'character_id' => $character, 'last_form_id' => 'FF001299',
                'name' => 'Renamed Settler', 'gamets' => 250]);
            $actor = NpcRuntimeActor::fromMapped($dao->find('pt-1', $id));
            $this->assertSame(['Renamed Settler', 'FF001299', 'active', $character], [$actor->name(), $actor->lastFormId(), $actor->status(), $actor->characterId()],
                'a rename and a new form id hint update the same actor');
            $this->assertSame('workshop_settler', $actor->getSpawnKind(), 'spawn context survives a refresh');
            $this->assertNull($dao->find('pt-2', $id), 'ids are playthrough scoped');
            $this->assertNull($dao->findActiveByFormId('pt-1', 'FF001234'), 'the old hint no longer selects the actor');
            $this->assertSame($id, $dao->findActiveByFormId('pt-1', 'ff001299')->getRuntimeActorId());
            $this->assertTrue($dao->setStatus('pt-1', $id, 'retired'));
            $this->assertNull($dao->findActiveByFormId('pt-1', 'FF001299'), 'a retired actor leaves the active index');
            $this->assertFalse($dao->setStatus('pt-1', '11111111-1111-4111-8111-111111111111', 'retired'));
            foreach ([['last_form_id' => '00001234'], ['runtime_actor_id' => 'not-a-uuid']] as $bad) {
                try {
                    $dao->register($bad + ['playthrough_id' => 'pt-1', 'runtime_actor_id' => $id, 'character_id' => $character, 'last_form_id' => 'FF000001', 'name' => 'X']);
                    $this->fail('An invalid runtime actor must be refused');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
            $this->expectException(\Doctrine\DBAL\Exception::class);
            $other = '22222222-2222-4222-8222-222222222222';
            $dao->setStatus('pt-1', $id, 'active');
            $dao->register(['playthrough_id' => 'pt-1', 'runtime_actor_id' => $other, 'character_id' => $character, 'last_form_id' => 'FF001299', 'name' => 'Clash']);
        } finally {
            $db->close();
        }
    }

    public function testManifestRevisionsAreAppendOnlyAndIdempotent(): void
    {
        $db = new sql();
        try {
            $this->boot($db);
            $dao = PluginManifestRevisionDao::shared();
            $plugins = [['name' => 'fallout4.esm', 'compile_index' => 0], ['name' => 'mod.esl', 'is_light' => true, 'small_file_compile_index' => 3]];
            $first = $dao->record('pt-1', str_repeat('a', 64), $plugins, 'flat', '1.10.163.0');
            $this->assertSame($first, $dao->record('pt-1', strtoupper(str_repeat('a', 64)), $plugins), 'recording the same manifest returns the same revision');
            $second = $dao->record('pt-1', str_repeat('b', 64), array_reverse($plugins));
            $this->assertGreaterThan($first, $second);
            $this->assertSame($second, PluginManifestRevision::fromMapped($dao->latest('pt-1'))->id());
            $this->assertSame($plugins, PluginManifestRevision::fromMapped($dao->find($first))->plugins());
            $this->assertNull($dao->latest('pt-2'));
            $this->expectException(InvalidArgumentException::class);
            $dao->record('pt-1', 'short', []);
        } finally {
            $db->close();
        }
    }
}

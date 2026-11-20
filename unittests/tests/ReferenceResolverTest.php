<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/managers/ReferenceResolver/PluginManifest.php';
require_once dirname(__DIR__, 2).'/managers/ReferenceResolver/Resolution.php';
require_once dirname(__DIR__, 2).'/managers/ReferenceResolver/ReferenceResolver.php';

use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\DbManager\Dao\PluginManifestRevisionDao;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Managers\ReferenceResolver\PluginManifest;
use Synth\Managers\ReferenceResolver\ReferenceResolver;

// Typed references resolved against stored actors, and manifest revisions recorded per playthrough.
final class ReferenceResolverTest extends DatabaseTestCase
{
    private const ID = '0b9c1d2e-3f40-4a51-8b62-73c84d95e6f7';

    private function resolver(sql $db): ReferenceResolver
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);

        return new ReferenceResolver(NpcActorDao::shared(), NpcRuntimeActorDao::shared(), PluginManifestRevisionDao::shared());
    }

    private function plugins(int $modIndex = 5): array
    {
        return [['name' => 'Fallout4.esm', 'compile_index' => 0], ['name' => 'SomeMod.esp', 'compile_index' => $modIndex]];
    }

    public function testPlacedReferencesResolveByPluginAndLocalIdUnderAnyLoadOrder(): void
    {
        $db = new sql();
        try {
            $resolver = $this->resolver($db);
            $row = $db->fetchOne("INSERT INTO public.core_npc (npc_name) VALUES ('Resolver Placed') RETURNING id");
            $character = (int) $row['id'];
            NpcActorDao::shared()->save(['refid' => '01a4b2', 'plugin' => 'somemod.esp', 'character_id' => $character, 'name' => 'Preston']);
            $manifest = new PluginManifest($this->plugins(5));
            $moved = new PluginManifest($this->plugins(9));

            $found = $resolver->resolve('pt-res', ['form_id' => '0x0501A4B2', 'origin_plugin' => 'SomeMod.esp'], $manifest);
            $this->assertSame(['resolved', null, $character], array_values($found->toWire()));
            $this->assertSame($character, $resolver->resolve('pt-res', ['form_id' => '0901A4B2'], $moved)->characterId, 'a moved plugin changes the prefix, not the key');
            $this->assertSame('unknown_plugin_prefix', $resolver->resolve('pt-res', ['form_id' => '0501A4B2'], $moved)->reason);
            $this->assertSame('plugin_mismatch', $resolver->resolve('pt-res', ['form_id' => '0501A4B2', 'origin_plugin' => 'Fallout4.esm'], $manifest)->reason);
            $this->assertSame('unknown_reference', $resolver->resolve('pt-res', ['form_id' => '0501FFFF'], $manifest)->reason);
            $this->assertSame('manifest_missing', $resolver->resolve('pt-res', ['form_id' => '0501A4B2'], null)->reason);
            $this->assertSame('invalid_rid', $resolver->resolve('pt-res', ['form_id' => 12345], $manifest)->reason);
            $this->assertSame('invalid_identity', $resolver->resolve('pt-res', ['form_id' => 'FF001234', 'origin_plugin' => 'somemod.esp'], $manifest)->reason);
            $this->assertSame('player_reference', $resolver->resolve('pt-res', ['form_id' => '00000014'], $manifest)->reason);
            $this->assertSame('unsupported_reference_type', $resolver->resolve('pt-res', ['reference_kind' => 'item', 'form_id' => '0501A4B2'], $manifest)->reason);

            $without = new PluginManifest([['name' => 'Fallout4.esm', 'compile_index' => 0]]);
            $this->assertSame('plugin_missing', $resolver->resolvePlaced('somemod.esp', '01a4b2', $without)->reason);
            $this->assertSame('unknown_plugin_prefix', $resolver->resolve('pt-res', ['form_id' => '0501A4B2'], $without)->reason);
        } finally {
            $db->close();
        }
    }

    public function testRuntimeReferencesResolveByClientIdAndReportStaleOnes(): void
    {
        $db = new sql();
        try {
            $resolver = $this->resolver($db);
            $row = $db->fetchOne("INSERT INTO public.core_npc (npc_name) VALUES ('Resolver Runtime') RETURNING id");
            $character = (int) $row['id'];
            $ref = ['reference_kind' => 'runtime_actor', 'runtime_actor_id' => strtoupper(self::ID), 'form_id' => 'FF001234'];
            $this->assertSame('runtime_actor_unregistered', $resolver->resolve('pt-res', $ref, null)->reason, 'a runtime actor needs no manifest');
            NpcRuntimeActorDao::shared()->register(['playthrough_id' => 'pt-res', 'runtime_actor_id' => self::ID, 'character_id' => $character, 'last_form_id' => 'FF001234', 'name' => 'Settler']);
            $this->assertSame($character, $resolver->resolve('pt-res', $ref, null)->characterId);
            $this->assertSame($character, $resolver->resolve('pt-res', ['form_id' => 'FF009999'] + $ref, null)->characterId, 'the form id is a hint, the id decides');
            $this->assertSame('runtime_actor_unregistered', $resolver->resolve('pt-other', $ref, null)->reason, 'ids are playthrough scoped');
            NpcRuntimeActorDao::shared()->setStatus('pt-res', self::ID, 'retired');
            $this->assertSame('runtime_actor_stale', $resolver->resolve('pt-res', $ref, null)->reason);
            foreach ([['form_id' => '0501A4B2'] + $ref, ['runtime_actor_id' => 'nope'] + $ref, ['origin_plugin' => 'a.esp'] + $ref] as $bad) {
                $this->assertSame('invalid_identity', $resolver->resolve('pt-res', $bad, null)->reason);
            }
        } finally {
            $db->close();
        }
    }

    public function testManifestsAreRecordedPerPlaythroughWithADiff(): void
    {
        $db = new sql();
        try {
            $resolver = $this->resolver($db);
            $first = new PluginManifest($this->plugins(5));
            $this->assertTrue($resolver->isStale('pt-man', $first->sha256()), 'nothing is recorded yet');
            $a = $resolver->acceptManifest('pt-man', ['manifest_sha256' => $first->sha256(), 'plugins' => $this->plugins(5), 'runtime_variant' => 'flat', 'game_version' => '1.10.163.0']);
            $this->assertSame(['added' => ['Fallout4.esm', 'SomeMod.esp'], 'removed' => [], 'moved' => []], $a['diff']);
            $this->assertFalse($resolver->isStale('pt-man', $first->sha256()));
            $again = $resolver->acceptManifest('pt-man', ['manifest_sha256' => $first->sha256(), 'plugins' => $this->plugins(5)]);
            $this->assertSame($a['revision_id'], $again['revision_id'], 'the same manifest is one revision');

            $moved = new PluginManifest($this->plugins(9));
            $b = $resolver->acceptManifest('pt-man', ['manifest_sha256' => $moved->sha256(), 'plugins' => $this->plugins(9)]);
            $this->assertSame(['added' => [], 'removed' => [], 'moved' => ['SomeMod.esp']], $b['diff']);
            $this->assertNotSame($a['revision_id'], $b['revision_id']);
            $this->assertSame($moved->sha256(), $resolver->currentManifest('pt-man')->sha256());
            $this->assertNull($resolver->currentManifest('pt-none'));

            foreach ([['manifest_sha256' => str_repeat('0', 64), 'plugins' => $this->plugins()], ['manifest_sha256' => $first->sha256(), 'plugins' => 'x'],
                ['manifest_sha256' => $first->sha256(), 'plugins' => $this->plugins(), 'runtime_variant' => 'console']] as $bad) {
                try {
                    $resolver->acceptManifest('pt-man', $bad);
                    $this->fail('An invalid manifest message must be refused');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $db->close();
        }
    }
}

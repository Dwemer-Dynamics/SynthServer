<?php declare(strict_types=1);

namespace Synth\Tests\ReferenceResolver;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Synth\Managers\ReferenceResolver\DeferredReferences;
use Synth\Managers\ReferenceResolver\PluginManifest;
use Synth\Managers\ReferenceResolver\Resolution;

// Load-order decoding and the pending-reference bookkeeping: pure logic, no database.
final class PluginManifestTest extends TestCase
{
    private function manifest(array $extra = [], string $variant = 'flat'): PluginManifest
    {
        return new PluginManifest(array_merge([
            ['name' => 'Fallout4.esm', 'compile_index' => 0],
            ['name' => 'SomeMod.esp', 'compile_index' => 5],
            ['name' => 'Tiny.esl', 'is_light' => true, 'small_file_compile_index' => 3],
        ], $extra), $variant);
    }

    public function testDecodeAndEncodeAreInverseUnderOneLoadOrder(): void
    {
        $manifest = $this->manifest();
        $this->assertSame(['plugin' => 'somemod.esp', 'local_id' => '01a4b2'], $manifest->decode('0x0501A4B2'));
        $this->assertSame('0501A4B2', $manifest->encode('SomeMod.ESP', '1A4B2'));
        $this->assertSame(['plugin' => 'tiny.esl', 'local_id' => '000abc'], $manifest->decode('FE003ABC'));
        $this->assertSame('FE003ABC', $manifest->encode('tiny.esl', 'abc'));
        $this->assertNull($manifest->encode('tiny.esl', '1000'), 'a light plugin has 12 bits of local id');
        $this->assertNull($manifest->encode('missing.esp', '1'));
    }

    public function testDecodeRefusesWhatItCannotName(): void
    {
        $manifest = $this->manifest();
        $this->assertSame(['reason' => 'runtime_form'], $manifest->decode('FF001234'));
        $this->assertSame(['reason' => 'unknown_plugin_prefix'], $manifest->decode('0901A4B2'));
        $this->assertSame(['reason' => 'unknown_plugin_prefix'], $manifest->decode('FE009ABC'));
        foreach (['', '1234', 'GG001234', '0x1'] as $bad) {
            $this->assertSame(['reason' => 'invalid_rid'], $manifest->decode($bad));
        }
    }

    public function testInsertingAPluginMovesPrefixesButNotThePluginLocalKey(): void
    {
        $before = $this->manifest();
        $after = new PluginManifest([
            ['name' => 'Fallout4.esm', 'compile_index' => 0],
            ['name' => 'NewMod.esp', 'compile_index' => 5],
            ['name' => 'SomeMod.esp', 'compile_index' => 6],
        ]);
        $this->assertSame('0501A4B2', $before->encode('somemod.esp', '01a4b2'));
        $this->assertSame('0601A4B2', $after->encode('somemod.esp', '01a4b2'));
        $this->assertSame(['plugin' => 'somemod.esp', 'local_id' => '01a4b2'], $after->decode('0601A4B2'));
        $this->assertSame(['added' => ['NewMod.esp'], 'removed' => ['Tiny.esl'], 'moved' => ['SomeMod.esp']], $after->diff($before));
        $this->assertNotSame($before->sha256(), $after->sha256());
        $this->assertSame($before->sha256(), $this->manifest()->sha256(), 'the hash is stable');
    }

    public function testInvalidManifestsAreRefused(): void
    {
        $bad = [
            [['name' => 'a.esp', 'compile_index' => 0], ['name' => 'A.ESP', 'compile_index' => 1]],
            [['name' => 'a.esp', 'compile_index' => 0], ['name' => 'b.esp', 'compile_index' => 0]],
            [['name' => 'a.txt', 'compile_index' => 0]],
            [['name' => 'a/b.esp', 'compile_index' => 0]],
            [['name' => 'a.esp', 'compile_index' => 254]],
            [['name' => 'a.esp', 'compile_index' => '0']],
            [['name' => 'a.esl', 'is_light' => true, 'small_file_compile_index' => 4096]],
        ];
        foreach ($bad as $plugins) {
            try {
                new PluginManifest($plugins);
                $this->fail('An invalid manifest must be refused');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new PluginManifest([['name' => 'a.esl', 'is_light' => true, 'small_file_compile_index' => 0]], 'vr');
    }

    public function testDeferredReferencesAreBoundedAndResolveOnce(): void
    {
        $id = '0b9c1d2e-3f40-4a51-8b62-73c84d95e6f7';
        $metadata = DeferredReferences::add(['keep' => 1], [
            ['role' => 'speaker', 'reference_type' => 'runtime_actor', 'rid' => '0xFF001234', 'runtime_actor_id' => strtoupper($id), 'reason' => 'runtime_actor_unregistered', 'recorded_manifest_id' => 7],
            ['role' => 'hearer', 'reference_type' => 'placed', 'rid' => '0501A4B2', 'plugin' => 'SomeMod.ESP', 'reason' => 'plugin_missing'],
            ['role' => 'bad role', 'reference_type' => 'placed', 'rid' => '0501A4B2', 'reason' => 'x'],
            ['role' => 'speaker', 'reference_type' => 'runtime_actor', 'rid' => 'FF001235', 'runtime_actor_id' => 'nope', 'reason' => 'x'],
            'not an entry',
        ]);
        $this->assertSame(2, DeferredReferences::count($metadata), 'malformed entries are dropped');
        $this->assertSame(['ff001234', $id], [$metadata['unresolved_references'][0]['rid'], $metadata['unresolved_references'][0]['runtime_actor_id']]);
        $this->assertSame('somemod.esp', $metadata['unresolved_references'][1]['plugin']);

        $out = DeferredReferences::resolve($metadata, static fn (array $e): Resolution => $e['reference_type'] === 'runtime_actor'
            ? Resolution::resolved(42, null, null, $e['runtime_actor_id'])
            : Resolution::unresolved('plugin_missing'));
        $this->assertSame([42], array_column($out['resolved'], 'character_id'));
        $this->assertSame(1, DeferredReferences::count($out['metadata']));
        $this->assertSame(1, $out['metadata']['keep']);
        $again = DeferredReferences::resolve($out['metadata'], static fn (array $e): Resolution => Resolution::resolved(9, 'x.esp', '000001', null));
        $this->assertArrayNotHasKey('unresolved_references', $again['metadata'], 'a resolved entry is removed');
    }

    public function testDeferredReferencesOverflowKeepsTheEventAndStopsStoring(): void
    {
        $entries = [];
        for ($i = 0; $i < 70; $i++) {
            $entries[] = ['role' => 'hearer', 'reference_type' => 'placed', 'rid' => sprintf('05%06X', $i), 'reason' => 'manifest_missing'];
        }
        $metadata = DeferredReferences::add([], $entries);
        $this->assertSame(DeferredReferences::MAX_PER_EVENT, DeferredReferences::count($metadata));
        $this->assertTrue($metadata['unresolved_overflow']);
    }
}

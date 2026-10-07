<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/logger.php';
require_once dirname(__DIR__, 2) . '/lib/voice_clone_resolver.php';
require_once dirname(__DIR__, 2) . '/lib/core/tts_connector.class.php';
require_once dirname(__DIR__, 2) . '/lib/core/npc_master.class.php';
require_once dirname(__DIR__, 2) . '/lib/voice_sample.php';
require_once dirname(__DIR__, 2) . '/lib/fuz_convert.php';
require_once dirname(__DIR__, 2) . '/lib/synth_tts.php';

final class NpcVoiceFallbackTest extends TestCase
{
    public function testCuratedVoiceRejectsSupersededCapturedSample(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/voice_catalog.php';
        $root = sys_get_temp_dir() . '/synth_catalog_guard_' . bin2hex(random_bytes(8));
        mkdir($root . '/data/voices', 0755, true);
        $file = $root . '/data/voices/testvoice.json';
        try {
            file_put_contents($file, json_encode(['selection'=>'curated_catalog', 'sha256'=>str_repeat('a', 64)]));
            synth_assert_curated_voice_snapshot($root, 'testvoice', ['sha256'=>str_repeat('a', 64)]);
            $this->expectException(RuntimeException::class);
            synth_assert_curated_voice_snapshot($root, 'testvoice', ['sha256'=>str_repeat('b', 64)]);
        } finally {
            unlink($file); rmdir($root . '/data/voices'); rmdir($root . '/data'); rmdir($root);
        }
    }

    public function testVoiceImportMetadataRejectsTraversalAndIdentityMismatch(): void
    {
        $metadata = [
            'schema' => 'synth.voice_sample.v2', 'session_id' => 'runtime:test:1',
            'request_id' => 'voice:1:1', 'generation' => 1, 'game' => 'fo4',
            'voice_id' => 'npcmpaladindanse', 'actor_name' => 'Paladin Danse',
            'original_name' => 'Sound/Voice/Fallout4.esm/NPCMPaladinDanse/00012345_1.fuz',
            'mode' => 'import', 'compression' => 'none', 'unpacked_bytes' => 80000,
        ];
        self::assertSame('npcmpaladindanse', synth_voice_sample_metadata(json_encode($metadata))['voice_id']);
        foreach ([
            ['voice_id' => '../outside'], ['voice_id' => 'different'], ['generation' => 0],
            ['generation' => '1'], ['unpacked_bytes' => 8388609], ['compression' => 'gzip'],
            ['original_name' => 'Sound/Voice/Fallout4.esm/../00012345_1.fuz'],
            ['original_name' => 'C:/Voice/Fallout4.esm/NPCMPaladinDanse/00012345_1.fuz'],
            ['actor_name' => []], ['game' => 'fnv'], ['url' => 'http://example.invalid'],
        ] as $invalid) {
            try {
                synth_voice_sample_metadata(json_encode(array_replace($metadata, $invalid)));
                self::fail('Accepted invalid voice metadata: ' . json_encode($invalid));
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    public function testVoiceArchiveDecompressionChecksExactLength(): void
    {
        $source = str_repeat('sample', 500);
        self::assertSame($source, synth_voice_sample_unpack(gzcompress($source), ['compression' => 'zlib', 'unpacked_bytes' => strlen($source)]));
        $this->expectException(InvalidArgumentException::class);
        synth_voice_sample_unpack(gzcompress($source), ['compression' => 'zlib', 'unpacked_bytes' => 1]);
    }

    public function testInvalidFuzDoesNotPrintOrCreateAudio(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'synth_fuz_test_');
        try {
            file_put_contents($file, 'FUZE' . pack('VV', 1, 0xffffffff) . str_repeat('x', 24));
            ob_start();
            try { self::assertSame('', fuzToWav($file)); }
            finally { self::assertSame('', ob_get_clean()); }
        } finally { unlink($file); }
    }

    public function testAudioCppPathSharingIsLoopbackOnly(): void
    {
        self::assertTrue(synth_voice_sample_local_endpoint('http://127.0.0.1:8086'));
        self::assertFalse(synth_voice_sample_local_endpoint('http://127.0.0.1.example.com:8086'));
        self::assertFalse(synth_voice_sample_local_endpoint('http://user@localhost:8086'));
    }

    public function testNewImportAndSampleReplacementInvalidateSpeechCache(): void
    {
        $root = sys_get_temp_dir() . '/synth_voice_cache_' . bin2hex(random_bytes(8));
        $voiceDir = $root . '/data/voices';
        mkdir($voiceDir, 0700, true);
        $voice = 'UnitVoice' . bin2hex(random_bytes(4));
        $file = $voiceDir . '/' . $voice . '.wav';
        $GLOBALS['TTSFUNCTION'] = 'pockettts';
        $GLOBALS['SYNTH_NAME'] = 'Voice Cache Test';
        $GLOBALS['PATCH_OVERRIDE_VOICE'] = $voice;
        try {
            $before = synth_tts_cache_seed($root, 'Voice Cache Test', 'Hello');
            file_put_contents($file, str_repeat('a', 100));
            self::assertSame(realpath($file), synth_resolve_voice_clone_wav($voice, ['root' => $root, 'sync_sample' => false]));
            $imported = synth_tts_cache_seed($root, 'Voice Cache Test', 'Hello');
            file_put_contents($file, str_repeat('b', 100));
            $replaced = synth_tts_cache_seed($root, 'Voice Cache Test', 'Hello');
            self::assertNotSame($before, $imported);
            self::assertNotSame($imported, $replaced);
        } finally {
            if (is_file($file)) unlink($file);
            rmdir($voiceDir); rmdir($root . '/data'); rmdir($root);
            unset($GLOBALS['SYNTH_NAME'], $GLOBALS['PATCH_OVERRIDE_VOICE']);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TTSFUNCTION'], $GLOBALS['TTS_FUNCTION']);
    }

    public function testConnectorUsesFalloutAdultGenderDefaults(): void
    {
        $connector = new TTSConnector();
        $connectorData = [
            'driver' => 'pockettts',
            'metadata' => '{}',
        ];

        self::assertSame('maleadult02', $connector->getFallbackVoiceForGender($connectorData, 'Male'));
        self::assertSame('femaleadult02', $connector->getFallbackVoiceForGender($connectorData, 'Female'));
    }

    public function testSampleProviderUsesConfiguredFallbackWhenCloneIsMissing(): void
    {
        $GLOBALS['TTSFUNCTION'] = 'pockettts';
        $npcMaster = (new ReflectionClass(NpcMaster::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(NpcMaster::class, 'withResolvedVoiceSample');
        $method->setAccessible(true);

        $result = $method->invoke($npcMaster, [
            'original_voice' => 'DefinitelyMissingFalloutVoice',
            'fallback_voice' => 'femaleadult02',
            'resolved_voice' => 'DefinitelyMissingFalloutVoice',
            'used_fallback' => false,
        ], [
            'npc_name' => 'Test NPC',
            'gender' => 'Female',
            'extended_data' => '{}',
        ]);

        self::assertSame('femaleadult02', $result['resolved_voice']);
        self::assertSame('femaleadult02', $result['resolved_voice_reference']);
        self::assertSame('', $result['resolved_voice_sample']);
        self::assertSame('sample_missing', $result['fallback_reason']);
        self::assertTrue($result['used_fallback']);
    }

    public function testNamedVoiceProviderKeepsOriginalVoiceWithoutLocalSample(): void
    {
        $GLOBALS['TTSFUNCTION'] = 'inworld';
        $npcMaster = (new ReflectionClass(NpcMaster::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(NpcMaster::class, 'withResolvedVoiceSample');
        $method->setAccessible(true);

        $result = $method->invoke($npcMaster, [
            'original_voice' => 'workspace__voice',
            'fallback_voice' => 'maleadult02',
            'resolved_voice' => 'workspace__voice',
            'used_fallback' => false,
        ], [
            'npc_name' => 'Test NPC',
            'gender' => 'Male',
            'extended_data' => '{}',
        ]);

        self::assertSame('workspace__voice', $result['resolved_voice']);
        self::assertSame('workspace__voice', $result['resolved_voice_reference']);
        self::assertFalse($result['used_fallback']);
    }
}

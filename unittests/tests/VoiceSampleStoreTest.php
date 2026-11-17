<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/managers/VoiceSample/VoiceSampleStore.php';

use Synth\Managers\VoiceSample\VoiceSampleStore;

final class VoiceSampleStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/synth_voice_store_' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    // 16-bit mono PCM WAV with one second of the given samples repeated to $samples frames.
    private function wav(int $samples, callable $sample, int $rate = 24000): string
    {
        $data = '';
        for ($i = 0; $i < $samples; $i++) {
            $data .= pack('v', $sample($i) & 0xFFFF);
        }
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;
    }

    public function testAllZeroSamplesAreSilentButAnyNonzeroSampleIsAudible(): void
    {
        file_put_contents($this->directory . '/silent.wav', $this->wav(24000, static fn() => 0));
        file_put_contents($this->directory . '/quiet.wav', $this->wav(24000, static fn(int $i) => $i === 23999 ? 1 : 0));
        file_put_contents($this->directory . '/tone.wav', $this->wav(24000, static fn(int $i) => (int)(8000 * sin($i / 10))));
        self::assertTrue(VoiceSampleStore::isSilentWav($this->directory . '/silent.wav'));
        self::assertFalse(VoiceSampleStore::isSilentWav($this->directory . '/quiet.wav'));
        self::assertFalse(VoiceSampleStore::isSilentWav($this->directory . '/tone.wav'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('silent');
        VoiceSampleStore::assertAudible($this->directory . '/silent.wav');
    }

    public function testUnreadableAudioIsNeitherSilentNorAccepted(): void
    {
        file_put_contents($this->directory . '/junk.wav', str_repeat('x', 200));
        file_put_contents($this->directory . '/empty.wav', '');
        self::assertNull(VoiceSampleStore::isSilentWav($this->directory . '/junk.wav'));
        self::assertNull(VoiceSampleStore::isSilentWav($this->directory . '/empty.wav'));
        self::assertNull(VoiceSampleStore::isSilentWav($this->directory . '/missing.wav'));
        $this->expectException(InvalidArgumentException::class);
        VoiceSampleStore::assertAudible($this->directory . '/junk.wav');
    }

    public function testListIsMetadataOnlyAndIgnoresUnsafeNames(): void
    {
        self::assertSame([], VoiceSampleStore::listSamples($this->directory));
        file_put_contents($this->directory . '/npcmpaladindanse.wav', $this->wav(48000, static fn(int $i) => $i % 200));
        file_put_contents($this->directory . '/npcmpaladindanse.json', json_encode([
            'source' => 'Sound/Voice/Fallout4.esm/NPCMPaladinDanse/00012345_1.fuz', 'selection' => 'automatic_candidate',
            'reference_text' => "  Ad Victoriam.  ", 'api_key' => 'SYNTHSECRET', 'local_path' => '/srv/synth/data/voices/x.wav',
        ]));
        file_put_contents($this->directory . '/other.wav', $this->wav(24000, static fn(int $i) => $i % 50));
        file_put_contents($this->directory . '/.hidden.wav', $this->wav(24000, static fn() => 1));
        file_put_contents($this->directory . '/bad name.wav', $this->wav(24000, static fn() => 1));
        file_put_contents($this->directory . '/stub.wav', 'RIFF');

        $list = VoiceSampleStore::listSamples($this->directory);
        self::assertSame(['npcmpaladindanse', 'other', 'stub'], array_column($list, 'voice_id'));
        $danse = $list[0];
        self::assertSame(2.0, $danse['duration_seconds']);
        self::assertSame(24000, $danse['sample_rate']);
        self::assertSame('Ad Victoriam.', $danse['reference_text']);
        self::assertTrue($danse['has_reference_text']);
        self::assertSame(hash_file('sha256', $this->directory . '/npcmpaladindanse.wav'), $danse['sha256']);
        self::assertFalse($list[1]['has_reference_text']);
        self::assertFalse($list[2]['playable']);
        self::assertStringNotContainsString('SYNTHSECRET', json_encode($list));
        self::assertStringNotContainsString('/srv/synth', json_encode($list));
        self::assertSame(['other'], array_column(VoiceSampleStore::listSamples($this->directory, 'other'), 'voice_id'));
    }

    public function testVoiceIdsAndReferenceTextAreBounded(): void
    {
        foreach (['femalenord', 'NPCM_Danse', 'a.b-c'] as $valid) {
            self::assertTrue(VoiceSampleStore::isValidId($valid), $valid);
        }
        foreach (['', '../x', '.hidden', 'a/b', 'a b', str_repeat('a', 129), null, ['a']] as $invalid) {
            self::assertFalse(VoiceSampleStore::isValidId($invalid), json_encode($invalid));
        }
        self::assertSame('', VoiceSampleStore::cleanReferenceText(str_repeat('a', 4097)));
        self::assertSame('', VoiceSampleStore::cleanReferenceText("bad\x00text"));
        self::assertSame('Fine text', VoiceSampleStore::cleanReferenceText(" Fine text\n"));
    }
}

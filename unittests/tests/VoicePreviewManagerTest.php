<?php

use PHPUnit\Framework\TestCase;
use Synth\Managers\TtsStudio\VoicePreviewException;
use Synth\Managers\TtsStudio\VoicePreviewManager;

require_once dirname(__DIR__, 2) . '/managers/TtsStudio/VoicePreviewException.php';
require_once dirname(__DIR__, 2) . '/managers/TtsStudio/VoicePreviewManager.php';

final class VoicePreviewManagerTest extends TestCase
{
    private function manager(): VoicePreviewManager
    {
        // A directory with no data/tmp makes the manager lock in the system temp directory.
        return new VoicePreviewManager(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth-preview-none-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR);
    }

    public function testTextIsTrimmedAndBounded(): void
    {
        $manager = $this->manager();
        self::assertSame('Ad Victoriam', $manager->cleanText("  Ad Victoriam\n"));
        self::assertSame(str_repeat('a', 300), $manager->cleanText(str_repeat('a', 300)));
        self::assertSame(str_repeat('é', 300), $manager->cleanText(str_repeat('é', 300)), 'the bound counts characters, not bytes');
        foreach ([' ', '', str_repeat('a', 301), "a\x07b", "a\x00b", "bad\xC3\x28utf"] as $invalid) {
            try {
                $manager->cleanText($invalid);
                self::fail('Accepted invalid text: ' . json_encode($invalid));
            } catch (VoicePreviewException $failure) {
                self::assertSame(400, $failure->status);
                self::assertSame('invalid_input', $failure->reason);
            }
        }
        self::assertSame("a\tb\nc", $manager->cleanText("a\tb\nc"), 'tab and line break are allowed');
    }

    public function testASecondPreviewIsRefusedWhileOneRuns(): void
    {
        $lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth-voice-preview.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->manager()->preview(1, 'Hello there');
            self::fail('A preview ran while another held the lock');
        } catch (VoicePreviewException $failure) {
            self::assertSame(429, $failure->status);
            self::assertSame('busy', $failure->reason);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testInvalidTextIsRefusedBeforeTheLockIsTaken(): void
    {
        $lock = fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth-voice-preview.lock', 'c');
        flock($lock, LOCK_EX | LOCK_NB);
        try {
            $this->manager()->preview(1, str_repeat('a', 301));
            self::fail('Accepted oversized text');
        } catch (VoicePreviewException $failure) {
            self::assertSame(400, $failure->status);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testFilesAreRemovedEvenWhenTheTimeLimitKillsThePreview(): void
    {
        // A fatal error (the execution time limit) skips `finally`; the shutdown cleanup must still delete the files.
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth-preview-fatal-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $audio = $dir . DIRECTORY_SEPARATOR . 'abc.wav';
        file_put_contents($audio, 'RIFF');
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'abc.txt', 'log');
        $script = $dir . DIRECTORY_SEPARATOR . 'run.php';
        file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__, 2) . '/managers/TtsStudio/VoicePreviewException.php', true) . ';
require ' . var_export(dirname(__DIR__, 2) . '/managers/TtsStudio/VoicePreviewManager.php', true) . ';
$m = new Synth\Managers\TtsStudio\VoicePreviewManager(' . var_export($dir . DIRECTORY_SEPARATOR, true) . ');
$r = new ReflectionClass($m);
$r->getMethod("registerShutdownCleanup")->invoke($m);
$p = $r->getProperty("pendingFiles");
$p->setValue($m, [' . var_export($audio, true) . ']);
set_time_limit(1);
while (true) {}
');
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        proc_close($process);
        $left = array_diff(scandir($dir), ['.', '..', 'run.php']);
        unlink($script);
        @array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
        self::assertSame([], array_values($left));
    }
}

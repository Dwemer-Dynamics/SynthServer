<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/background_processor.php';

final class BackgroundProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SYNTH_BACKGROUND_PORT');
        putenv('SYNTH_BACKGROUND_STATE_DIR');
        putenv('SYNTH_BACKGROUND_START_SCRIPT');
    }

    public function testClosedPortProbeDoesNotRetryForSeveralSeconds(): void
    {
        putenv('SYNTH_BACKGROUND_PORT=65431');
        $startedAt = microtime(true);

        self::assertFalse(synthBackgroundProcessorIsRunning(0.1));
        self::assertLessThan(0.5, microtime(true) - $startedAt);
    }

    public function testDefaultPortIsIndependentFromDialecticAndOverrideIsShared(): void
    {
        foreach (['', '-1', '65536'] as $invalid) {
            putenv('SYNTH_BACKGROUND_PORT='.$invalid);
            self::assertSame(12348,synthBackgroundProcessorPort());
        }
        putenv('SYNTH_BACKGROUND_PORT=65431');
        self::assertSame(65431,synthBackgroundProcessorPort());
        $script=file_get_contents(dirname(__DIR__,2).'/service/start.sh');
        self::assertStringContainsString('echo synthBackgroundProcessorPort();',$script);
        self::assertStringContainsString('nc -lk -s 127.0.0.1',$script);
    }

    public function testMissingStartScriptReturnsWithoutCreatingStateFiles(): void
    {
        $stateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth_background_' . uniqid('', true);
        putenv('SYNTH_BACKGROUND_PORT=65431');
        putenv('SYNTH_BACKGROUND_STATE_DIR=' . $stateDirectory);
        putenv('SYNTH_BACKGROUND_START_SCRIPT=' . $stateDirectory . DIRECTORY_SEPARATOR . 'missing.sh');

        self::assertFalse(synthEnsureBackgroundProcessorRunning(false));
        self::assertDirectoryDoesNotExist($stateDirectory);
    }
}

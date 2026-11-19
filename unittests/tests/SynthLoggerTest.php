<?php

use PHPUnit\Framework\TestCase;
use Synth\Lib\Logger;

require_once dirname(__DIR__, 2) . '/lib/logger.php';

final class SynthLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth_logger_' . uniqid('', true) . '.log';
        Logger::setCustomLog($this->file);
    }

    protected function tearDown(): void
    {
        Logger::unsetCustomLog();
        @unlink($this->file);
    }

    public function testEntryCarriesLevelClassifierAndCallerLine(): void
    {
        $line = __LINE__ + 1;
        Logger::log(Logger::WARN, 'Probe', 'hello');
        $text = (string)file_get_contents($this->file);
        self::assertStringContainsString('[warn]', $text);
        self::assertStringContainsString('[Probe]', $text);
        self::assertStringContainsString('[' . basename(__FILE__) . ':' . $line . ']', $text);
    }

    public function testClassifierIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Logger::log(Logger::INFO, '  ', 'x');
    }

    public function testSecretsAreRedactedInTextAndPayloads(): void
    {
        Logger::log(Logger::INFO, 'Probe', 'GET /x?api_key=abc123secret&a=1 Authorization: Bearer abcdefghijkl1234');
        Logger::log(Logger::INFO, 'Probe', ['token' => 'tok-value', 'nested' => ['password' => 'pw-value', 'ok' => 1]]);
        $text = (string)file_get_contents($this->file);
        foreach (['abc123secret', 'abcdefghijkl1234', 'tok-value', 'pw-value'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
        self::assertStringContainsString('&a=1', $text);
    }

    public function testLegacyShimUsesCallingFileAsClassifier(): void
    {
        $line = __LINE__ + 1;
        \Logger::info('legacy message');
        $text = (string)file_get_contents($this->file);
        self::assertStringContainsString('[SynthLoggerTest]', $text);
        self::assertStringContainsString(':' . $line . ']', $text);
    }
}

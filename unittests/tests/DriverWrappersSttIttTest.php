<?php declare(strict_types=1);

namespace Synth\Tests\Drivers;

use PHPUnit\Framework\TestCase;
use Synth\Drivers\Itt\Exception\IttConfigurationException;
use Synth\Drivers\Itt\Exception\IttException;
use Synth\Drivers\Itt\IttRequest;
use Synth\Drivers\Itt\IttWrapper;
use Synth\Drivers\Stt\Exception\SttConfigurationException;
use Synth\Drivers\Stt\SttRequest;
use Synth\Drivers\Stt\SttWrapper;

require_once dirname(__DIR__, 2) . '/drivers/stt/SttWrapper.php';
require_once dirname(__DIR__, 2) . '/drivers/itt/IttWrapper.php';

// Provider selection and "fails cleanly when not configured"; no network is used.
final class DriverWrappersSttIttTest extends TestCase
{
    public function testSttDriverNamesMatchTheConnectorDrivers(): void
    {
        $this->assertSame(['none', 'whisper', 'parakeet', 'localwhisper', 'azure', 'deepgram', 'gemini', 'inworld'], SttWrapper::driverNames());
    }

    public function testUnknownSttDriverIsAConfigurationError(): void
    {
        $this->expectException(SttConfigurationException::class);
        SttWrapper::create('nope');
    }

    public function testKeyedSttProvidersRefuseToStartWithoutAKey(): void
    {
        foreach (['inworld', 'gemini'] as $driver) {
            try {
                SttWrapper::create($driver, ['api_key' => '  ']);
                $this->fail("$driver created without a key");
            } catch (SttConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNoneDriverHearsNothing(): void
    {
        $this->assertSame('', SttWrapper::transcribe('none', [], new SttRequest('/missing.wav'))->text);
        $this->assertSame('', SttWrapper::transcribeOrEmpty('NONE', [], new SttRequest('/missing.wav')));
    }

    public function testFailuresBecomeEmptyTextWithADiagnostic(): void
    {
        $log = ini_set('error_log', sys_get_temp_dir() . '/synth-stt-wrapper-test.log');
        try {
            $diagnostic = null;
            $this->assertSame('', SttWrapper::transcribeOrEmpty('localwhisper', ['url' => ''], new SttRequest('/missing.wav'), $diagnostic));
            $this->assertSame('config', $diagnostic['stage']);
            $this->assertSame('localwhisper', $diagnostic['driver']);

            $this->assertSame('', SttWrapper::transcribeOrEmpty('localwhisper', ['url' => 'http://127.0.0.1:1/x'], new SttRequest('/missing.wav'), $diagnostic));
            $this->assertSame('input', $diagnostic['stage']);

            $this->assertSame('', SttWrapper::transcribeOrEmpty('unknown', [], new SttRequest('/missing.wav'), $diagnostic));
            $this->assertSame([], $diagnostic);

            $this->assertSame('', SttWrapper::transcribeOrEmpty('whisper', ['api_key' => 'k'], new SttRequest('/missing.wav'), $diagnostic));
            $this->assertSame('input', $diagnostic['stage']);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
    }

    public function testIttDriverNamesMatchTheConnectorDrivers(): void
    {
        $this->assertSame(['openai', 'openrouter', 'google_openai', 'custom', 'llamacpp'], IttWrapper::driverNames());
    }

    public function testIttRefusesIncompleteConfiguration(): void
    {
        foreach ([['openai', []], ['custom', ['url' => 'http://x']], ['openrouter', ['model' => 'm']], ['llamacpp', ['url' => ' / ']], ['nope', []]] as [$driver, $config]) {
            try {
                IttWrapper::create($driver, $config);
                $this->fail("$driver was created from incomplete config");
            } catch (IttConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIttRejectsAnUnreadableImageBeforeAnyRequest(): void
    {
        $this->expectException(IttException::class);
        IttWrapper::describe('openai', ['url' => 'http://127.0.0.1:1/', 'model' => 'm'], new IttRequest('/missing.jpg', 'hint'));
    }
}

<?php declare(strict_types=1);

namespace Synth\Tests\Drivers;

use PHPUnit\Framework\TestCase;
use Synth\Drivers\Llm\LlmDriverInterface;
use Synth\Drivers\Llm\LlmWrapper;
use Synth\Drivers\Tts\TtsDriverInterface;
use Synth\Drivers\Tts\TtsWrapper;

require_once dirname(__DIR__, 2) . '/drivers/llm/LlmWrapper.php';
require_once dirname(__DIR__, 2) . '/drivers/tts/TtsWrapper.php';

// The wrappers pick a driver and forward; they never add behaviour of their own.
final class DriverWrappersLlmTtsTest extends TestCase
{
    private function driver(string $name, string $supported, array &$log): LlmDriverInterface
    {
        return new class($name, $supported, $log) implements LlmDriverInterface {
            public function __construct(private string $name, private string $supported, private array &$log) {}
            public function getName(): string { return $this->name; }
            public function supports(array $connector): bool { return ($connector['driver'] ?? '') === $this->supported; }
            public function stream(array $connector, array $messages, callable $onText): void
            {
                $this->log[] = $this->name;
                $onText('hello');
            }
        };
    }

    public function testFirstSupportingDriverStreams(): void
    {
        $log = [];
        $wrapper = new LlmWrapper([$this->driver('a', 'x', $log), $this->driver('b', 'y', $log), $this->driver('c', 'y', $log)]);
        $text = '';
        $wrapper->stream(['driver' => 'y'], [], static function (string $chunk) use (&$text): void { $text .= $chunk; });
        $this->assertSame('hello', $text);
        $this->assertSame(['b'], $log);
        $this->assertTrue($wrapper->supports(['driver' => 'x']));
    }

    public function testUnsupportedConnectorIsRefusedBeforeAnyDriverRuns(): void
    {
        $log = [];
        $wrapper = new LlmWrapper([$this->driver('a', 'x', $log)]);
        $this->assertFalse($wrapper->supports(['driver' => 'nope']));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('provider_unsupported');
        try {
            $wrapper->stream(['driver' => 'nope'], [], static fn(string $c) => null);
        } finally {
            $this->assertSame([], $log);
        }
    }

    public function testWrapperWithoutDriversIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LlmWrapper([]);
    }

    public function testLegacyConnectorNamesAreValidated(): void
    {
        $this->assertTrue(LlmWrapper::hasLegacyConnector('openaijson'));
        foreach (['', '__jpd', 'parse_simple_yaml', '../lib/settings', 'openaijson.php', 'no_such_provider', 'a b'] as $name) {
            $this->assertFalse(LlmWrapper::hasLegacyConnector($name), $name);
        }
        $this->expectException(\InvalidArgumentException::class);
        LlmWrapper::legacyConnector('../lib/settings');
    }

    public function testTtsWrapperForwardsToItsDriver(): void
    {
        $driver = new class implements TtsDriverInterface {
            public array $calls = [];
            public function getName(): string { return 'fake'; }
            public function settings(array $npcRow, array $profile): ?array { $this->calls[] = 'settings'; return null; }
            public function queue(array $speaker, string $lineId, string $text, array $settings): string { $this->calls[] = "queue:$lineId"; return 'key'; }
        };
        $wrapper = new TtsWrapper($driver);
        $this->assertSame('fake', $wrapper->getName());
        $this->assertNull($wrapper->settings([], []));
        $this->assertSame('key', $wrapper->queue([], 'l1', 'hi', []));
        $this->assertSame(['settings', 'queue:l1'], $driver->calls);
    }
}

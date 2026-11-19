<?php declare(strict_types=1);

namespace Synth\Tests\Narrator;

use PHPUnit\Framework\TestCase;
use Synth\Core\LlmDriver;
use Synth\Core\NarratorOutput;
use Synth\Core\PrivateNarratorRepository;
use Synth\Drivers\Tts\NarratorSpeechDriver;
use Synth\Drivers\Tts\TtsWrapper;
use Synth\Managers\PrivateNarratorManager;

require_once dirname(__DIR__, 2) . '/managers/PrivateNarratorManager.php';

// Talking objects have no storage yet: every object request is refused before any lane, state or provider is touched.
final class NarratorObjectRefusalTest extends TestCase
{
    private const DEFINITION = 'nd-0123456789abcdef0123456789abcdef';
    private const OBJECT = ['ref_plugin' => 'Fallout4.esm', 'ref_id' => '0x000A1B2C', 'base_plugin' => 'Fallout4.esm', 'base_id' => '0x000A1B2D'];

    // Builds a manager whose repository and speech driver must never be used, and the terminal codes it emits.
    private function refusals(string $type, array $payload): array
    {
        $codes = [];
        $output = $this->createMock(NarratorOutput::class);
        $output->method('terminal')->willReturnCallback(static function (string $code) use (&$codes): void { $codes[] = $code; });
        $repository = $this->createMock(PrivateNarratorRepository::class);
        $repository->expects($this->never())->method($this->anything());
        $speech = new TtsWrapper((new \ReflectionClass(NarratorSpeechDriver::class))->newInstanceWithoutConstructor());
        $manager = new PrivateNarratorManager($repository, $this->createMock(LlmDriver::class), $speech, $output,
            static fn(): string => '', static fn(): array => []);
        $manager->handle(['type' => $type, 'request_id' => 'r1', 'turn_id' => 't1', 'generation' => 1, 'payload' => $payload]);
        return $codes;
    }

    // An object turn is refused as unavailable.
    public function testObjectTurnIsRefused(): void
    {
        $this->assertSame(['narrator_object_unavailable'], $this->refusals('narrator_turn',
            ['kind' => 'typed', 'definition_id' => self::DEFINITION, 'state_id' => 1, 'text' => 'Hello', 'object' => self::OBJECT]));
    }

    // Binding and object transcript paging are refused as unavailable.
    public function testBindAndObjectTranscriptAreRefused(): void
    {
        $this->assertSame(['narrator_object_unavailable'], $this->refusals('narrator_command',
            ['action' => 'bind_object', 'definition_id' => self::DEFINITION, 'object' => self::OBJECT]));
        $this->assertSame(['narrator_object_unavailable'], $this->refusals('narrator_command',
            ['action' => 'transcript', 'definition_id' => self::DEFINITION]));
    }
}

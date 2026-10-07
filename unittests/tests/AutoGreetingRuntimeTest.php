<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'auto_greeting.php';

final class AutoGreetingRuntimeTest extends TestCase
{
    private function actor(string $name, string $refId, float $distance = 100.0): array
    {
        return [
            'name' => $name,
            'refid' => $refId,
            'distance' => $distance,
            'eligible' => true,
            'auto_eligible' => true,
            'can_hear_player' => true,
            'dead' => false,
            'disabled' => false,
        ];
    }

    private function snapshot(array $actors, int $generation = 4, int $gamets = 20000000): array
    {
        return [
            'schema' => 'synth.nearby_actors.v1',
            'player' => 'Graussy',
            'gamets' => $gamets,
            'runtime_generation' => $generation,
            'actors' => $actors,
        ];
    }

    public function testNpcOverrideWinsOverProfileDefault(): void
    {
        $this->assertFalse(synthResolveAutoGreetingEnabled(
            ['salutation_after_a_while' => false],
            ['SALUTATION_AFTER_A_WHILE' => true]
        ));
        $this->assertTrue(synthResolveAutoGreetingEnabled(
            ['salutation_after_a_while' => true],
            ['SALUTATION_AFTER_A_WHILE' => false]
        ));
        $this->assertTrue(synthResolveAutoGreetingEnabled(
            [],
            ['SALUTATION_AFTER_A_WHILE' => '1']
        ));
    }

    public function testSameActorInSameRuntimeGenerationDoesNotRetrigger(): void
    {
        $actor = $this->actor('Veronica', '0x000E32A9');
        $candidate = synthSelectAutoGreetingCandidate(
            $this->snapshot([$actor]),
            $this->snapshot([$actor]),
            static fn(string $name): array => ['enabled' => true],
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertNull($candidate);
    }

    public function testGenerationChangeTreatsStillLoadedActorAsNewlyPresent(): void
    {
        $actor = $this->actor('Veronica', '0x000E32A9');
        $candidate = synthSelectAutoGreetingCandidate(
            $this->snapshot([$actor], 5),
            $this->snapshot([$actor], 4),
            static fn(string $name): array => ['enabled' => true],
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertSame('Veronica', $candidate['npc'] ?? null);
        $this->assertSame(5, $candidate['runtime_generation'] ?? null);
    }

    public function testRequiresAFullDaySinceRealInteraction(): void
    {
        $actor = $this->actor('Veronica', '0x000E32A9');
        $state = static fn(string $name): array => ['enabled' => true];

        $tooSoon = synthSelectAutoGreetingCandidate(
            $this->snapshot([$actor]),
            [],
            $state,
            static fn(string $player, string $npc): int => 15000000,
            1000
        );
        $this->assertNull($tooSoon);

        $ready = synthSelectAutoGreetingCandidate(
            $this->snapshot([$actor]),
            [],
            $state,
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertSame('Veronica', $ready['npc'] ?? null);
    }

    public function testNeverMetAndManualOnlyActorsDoNotAutoGreet(): void
    {
        $neverMet = synthSelectAutoGreetingCandidate(
            $this->snapshot([$this->actor('Veronica', '0x000E32A9')]),
            [],
            static fn(string $name): array => ['enabled' => true],
            static fn(string $player, string $npc): int => 0,
            1000
        );
        $this->assertNull($neverMet);

        $manualOnly = $this->actor('Rex', '0x0010D8DF');
        $manualOnly['auto_eligible'] = false;
        $candidate = synthSelectAutoGreetingCandidate(
            $this->snapshot([$manualOnly]),
            [],
            static fn(string $name): array => ['enabled' => true],
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertNull($candidate);
    }

    public function testRecentQueueMarkerSuppressesDuplicates(): void
    {
        $candidate = synthSelectAutoGreetingCandidate(
            $this->snapshot([$this->actor('Veronica', '0x000E32A9')]),
            [],
            static fn(string $name): array => [
                'enabled' => true,
                'last_queued_gamets' => 19900000,
                'last_queued_ts' => 950,
            ],
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertNull($candidate);
    }

    public function testFullGameDayAllowsGreetingEvenAfterFastWait(): void
    {
        $candidate = synthSelectAutoGreetingCandidate(
            $this->snapshot([$this->actor('Veronica', '0x000E32A9')], 5, 30000000),
            $this->snapshot([], 4, 30000000),
            static fn(string $name): array => [
                'enabled' => true,
                'last_queued_gamets' => 20000000,
                'last_queued_ts' => 995,
            ],
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertSame('Veronica', $candidate['npc'] ?? null);
    }

    public function testNearestEligibleActorWins(): void
    {
        $candidate = synthSelectAutoGreetingCandidate(
            $this->snapshot([
                $this->actor('Arcade', '0x000156F0', 300.0),
                $this->actor('Veronica', '0x000E32A9', 75.0),
            ]),
            [],
            static fn(string $name): array => ['enabled' => true],
            static fn(string $player, string $npc): int => 10000000,
            1000
        );
        $this->assertSame('Veronica', $candidate['npc'] ?? null);
    }

    public function testSourceWiresCanonicalActivationThroughTheNormalEventQueue(): void
    {
        $root = dirname(__DIR__, 2);
        $configuredClientRoot = trim(strval(getenv('SYNTH_CLIENT_ROOT') ?: ''));
        $clientRepository = $configuredClientRoot !== ''
            ? $configuredClientRoot
            : dirname($root).DIRECTORY_SEPARATOR.'SYNTH';
        $clientRoot = $clientRepository.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'client';
        if (!is_file($clientRoot.DIRECTORY_SEPARATOR.'plugin_session.hpp')) {
            $this->markTestSkipped('Set SYNTH_CLIENT_ROOT to run the cross-repository activation wiring check.');
        }
        $session = file_get_contents($clientRoot.DIRECTORY_SEPARATOR.'plugin_session.hpp');
        $client = file_get_contents($clientRoot.DIRECTORY_SEPARATOR.'synth_client.hpp');

        $this->assertStringContainsString('activate_target(Snapshot snapshot, bool manual)', $session);
        $this->assertStringContainsString('selected_target(*snapshot, !manual)', $session);
        $this->assertStringContainsString('client_.activate(actor, manual ? "manual" : "automatic", context_outcome.context_binding)', $session);
        $this->assertStringContainsString('protocol_native::encode_activate', $client);
    }
}

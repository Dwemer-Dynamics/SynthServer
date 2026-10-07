<?php

function synthAutoGreetingBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return $value != 0;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function synthResolveAutoGreetingEnabled(array $extendedData, array $profileMetadata): bool
{
    if (array_key_exists('salutation_after_a_while', $extendedData) &&
        $extendedData['salutation_after_a_while'] !== null &&
        $extendedData['salutation_after_a_while'] !== '') {
        return synthAutoGreetingBool($extendedData['salutation_after_a_while']);
    }

    return synthAutoGreetingBool($profileMetadata['SALUTATION_AFTER_A_WHILE'] ?? false);
}

function synthAutoGreetingActorKey(array $actor): string
{
    $refId = strtoupper(trim((string)($actor['refid'] ?? '')));
    if ($refId !== '') {
        return 'ref:' . $refId;
    }

    $name = strtolower(trim((string)($actor['name'] ?? '')));
    return $name === '' ? '' : 'name:' . $name;
}

function synthAutoGreetingSnapshotKeys(array $snapshot): array
{
    $keys = [];
    foreach (($snapshot['actors'] ?? []) as $actor) {
        if (!is_array($actor)) {
            continue;
        }
        $key = synthAutoGreetingActorKey($actor);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }
    return $keys;
}

function synthAutoGreetingDaysBetween(int $startGamets, int $endGamets): int
{
    if ($startGamets <= 0 || $endGamets <= $startGamets) {
        return 0;
    }
    if (function_exists('gamets2days_between')) {
        return (int)gamets2days_between($startGamets, $endGamets);
    }

    // SYNTH game timestamps use the same 10,000,000 ticks-per-day scale as CHIM.
    return (int)floor(($endGamets - $startGamets) / 10000000);
}

/**
 * Select one newly-present actor that may greet the player. Runtime data access is
 * supplied by callbacks so the transition/cooldown rules remain independently testable.
 */
function synthSelectAutoGreetingCandidate(
    array $snapshot,
    array $previousSnapshot,
    callable $resolveActorState,
    callable $lastInteraction,
    ?int $localTs = null
): ?array {
    $gamets = (int)($snapshot['gamets'] ?? 0);
    $player = trim((string)($snapshot['player'] ?? ''));
    if ($gamets <= 0 || $player === '') {
        return null;
    }

    $runtimeGeneration = (int)($snapshot['runtime_generation'] ?? 0);
    $previousGeneration = (int)($previousSnapshot['runtime_generation'] ?? 0);
    $previousKeys = ($runtimeGeneration > 0 && $previousGeneration === $runtimeGeneration)
        ? synthAutoGreetingSnapshotKeys($previousSnapshot)
        : [];

    $actors = is_array($snapshot['actors'] ?? null)
        ? array_values(array_filter($snapshot['actors'], 'is_array'))
        : [];
    usort($actors, static fn(array $left, array $right): int =>
        ((float)($left['distance'] ?? PHP_FLOAT_MAX)) <=> ((float)($right['distance'] ?? PHP_FLOAT_MAX))
    );

    $now = $localTs ?? time();
    foreach ($actors as $actor) {
        if (!is_array($actor)) {
            continue;
        }
        $name = trim((string)($actor['name'] ?? ''));
        $key = synthAutoGreetingActorKey($actor);
        if ($name === '' || $key === '' || strcasecmp($name, $player) === 0 || isset($previousKeys[$key])) {
            continue;
        }
        if (!synthAutoGreetingBool($actor['eligible'] ?? false) ||
            !synthAutoGreetingBool($actor['auto_eligible'] ?? false) ||
            !synthAutoGreetingBool($actor['can_hear_player'] ?? false) ||
            synthAutoGreetingBool($actor['dead'] ?? false) ||
            synthAutoGreetingBool($actor['disabled'] ?? false)) {
            continue;
        }

        $state = $resolveActorState($name);
        if (!is_array($state) || !synthAutoGreetingBool($state['enabled'] ?? false)) {
            continue;
        }

        $lastSpoke = (int)$lastInteraction($player, $name);
        if ($lastSpoke <= 0 || synthAutoGreetingDaysBetween($lastSpoke, $gamets) < 1) {
            continue;
        }

        $lastQueuedGamets = (int)($state['last_queued_gamets'] ?? 0);
        $lastQueuedTs = (int)($state['last_queued_ts'] ?? 0);
        if ($lastQueuedGamets > 0) {
            if ($gamets >= $lastQueuedGamets &&
                synthAutoGreetingDaysBetween($lastQueuedGamets, $gamets) < 1) {
                continue;
            }
            if ($gamets < $lastQueuedGamets && $lastQueuedTs > 0 && $now - $lastQueuedTs < 600) {
                continue;
            }
        }

        return [
            'schema' => 'synth.auto_greeting.v1',
            'npc' => $name,
            'npc_refid' => trim((string)($actor['refid'] ?? '')),
            'player' => $player,
            'gamets' => $gamets,
            'runtime_generation' => $runtimeGeneration,
        ];
    }

    return null;
}

function synthBuildAutoGreetingDirective(array $snapshot, array $previousSnapshot, NpcMaster $npcMaster): ?array
{
    $db = $GLOBALS['db'];
    $resolveActorState = static function (string $name) use ($db, $npcMaster): array {
        $npc = $npcMaster->getByName($name);
        if (!is_array($npc)) {
            return ['enabled' => false];
        }
        $extended = $npcMaster->getExtendedData($npc);
        $metadata = $npcMaster->getMetadata($npc);
        $profileMetadata = [];
        $profileId = (int)($npc['profile_id'] ?? 0);
        if ($profileId > 0) {
            $profile = $db->fetchOne("SELECT metadata FROM core_profiles WHERE id={$profileId} LIMIT 1");
            if (is_array($profile)) {
                $profileMetadata = json_decode((string)($profile['metadata'] ?? '{}'), true) ?: [];
            }
        }

        return [
            'enabled' => synthResolveAutoGreetingEnabled($extended, $profileMetadata),
            'last_queued_gamets' => (int)($metadata['auto_greeting_last_queued_gamets'] ?? 0),
            'last_queued_ts' => (int)($metadata['auto_greeting_last_queued_ts'] ?? 0),
        ];
    };

    $directive = synthSelectAutoGreetingCandidate(
        $snapshot,
        $previousSnapshot,
        $resolveActorState,
        static fn(string $player, string $npc): int => function_exists('GetLastInteraction')
            ? (int)GetLastInteraction($player, $npc)
            : 0
    );
    if ($directive === null) {
        return null;
    }

    $npcMaster->updateMetadataKeysByName((string)$directive['npc'], [
        'auto_greeting_last_queued_gamets' => (int)$directive['gamets'],
        'auto_greeting_last_queued_ts' => time(),
    ]);
    return $directive;
}

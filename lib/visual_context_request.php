<?php

declare(strict_types=1);

final class SynthVisualRequestException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false
    ) {
        parent::__construct($message);
    }
}

function synth_visual_expect_keys(array $value, array $required, array $optional, string $field): void
{
    foreach ($required as $key) {
        if (!array_key_exists($key, $value)) {
            throw new SynthVisualRequestException(422, 'invalid_visual_request', "{$field}.{$key} is required.");
        }
    }
    $allowed = array_fill_keys(array_merge($required, $optional), true);
    foreach (array_keys($value) as $key) {
        if (!isset($allowed[$key])) {
            throw new SynthVisualRequestException(422, 'invalid_visual_request', "{$field}.{$key} is unknown.");
        }
    }
}

function synth_visual_valid_id($value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) === 1;
}

function synth_visual_validate_identity($value, string $field, bool $native = false): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new SynthVisualRequestException(422, 'invalid_actor_identity', "{$field} must be an object.");
    }
    synth_visual_expect_keys($value, ['form_id', 'origin_plugin', 'playthrough_id'], ['display_name'], $field);
    if (!is_string($value['form_id']) || preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['form_id']) !== 1
        || !is_string($value['origin_plugin'])
        || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['origin_plugin']) !== 1
        || strlen($value['origin_plugin']) > 255
        || !synth_visual_valid_id($value['playthrough_id'])) {
        throw new SynthVisualRequestException(422, 'invalid_actor_identity', "{$field} is invalid.");
    }
    if (isset($value['display_name'])
        && (!is_string($value['display_name']) || trim($value['display_name']) === ''
            || strlen($value['display_name']) > 255)) {
        throw new SynthVisualRequestException(422, 'invalid_actor_identity', "{$field}.display_name is invalid.");
    }
    $value['form_id'] = $native ? '0x'.strtoupper(substr($value['form_id'], 2)) : strtoupper($value['form_id']);
    return $value;
}

function synth_visual_validate_media($value): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new SynthVisualRequestException(422, 'invalid_media', 'media must be an object.');
    }
    synth_visual_expect_keys($value, ['schema', 'media_id', 'content_type', 'sha256', 'size'], [], 'media');
    if (($value['schema'] ?? '') !== 'synth.media.v1'
        || !is_string($value['media_id'])
        || preg_match('#^media/[a-f0-9]{64}$#D', $value['media_id']) !== 1
        || !in_array($value['content_type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
        || !is_string($value['sha256'])
        || preg_match('/^[a-f0-9]{64}$/D', $value['sha256']) !== 1
        || !is_int($value['size']) || $value['size'] < 1 || $value['size'] > SYNTH_MEDIA_MAX_BYTES
        || !hash_equals(substr($value['media_id'], 6), $value['sha256'])) {
        throw new SynthVisualRequestException(422, 'invalid_media', 'media is not a valid opaque image reference.');
    }
    return $value;
}

function synth_visual_validate_world($value): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new SynthVisualRequestException(422, 'invalid_world_context', 'world must be an object.');
    }
    $keys = ['location', 'worldspace', 'cell_form_id', 'worldspace_form_id', 'weather', 'interior', 'game_time_ticks'];
    synth_visual_expect_keys($value, $keys, [], 'world');
    foreach (['location', 'worldspace', 'weather'] as $key) {
        if (!is_string($value[$key]) || strlen($value[$key]) > 300) {
            throw new SynthVisualRequestException(422, 'invalid_world_context', "world.{$key} is invalid.");
        }
    }
    foreach (['cell_form_id', 'worldspace_form_id'] as $key) {
        if (!is_string($value[$key])
            || ($value[$key] !== '' && preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value[$key]) !== 1)) {
            throw new SynthVisualRequestException(422, 'invalid_world_context', "world.{$key} is invalid.");
        }
    }
    if (!is_bool($value['interior']) || !is_int($value['game_time_ticks']) || $value['game_time_ticks'] < 0) {
        throw new SynthVisualRequestException(422, 'invalid_world_context', 'world interior or game time is invalid.');
    }
    return $value;
}

// Validates the closed visual request independently from the dialogue event envelope.
function synth_visual_validate_request(array $request): array
{
    $native = ($request['protocol_version'] ?? null) === 2;
    synth_visual_expect_keys(
        $request,
        [
            'schema', 'protocol_version', 'session_id', 'request_id', 'turn_id', 'generation',
            'game', 'runtime_variant', 'capture_id', 'interaction_mode', 'perspective', 'media', 'world',
        ],
        $native ? ['subject', 'nearby_actors', 'visual_type', 'visual_key', 'context_sequence']
            : ['subject', 'nearby_actors', 'visual_type', 'visual_key'],
        'request'
    );
    if (($request['schema'] ?? '') !== ($native ? 'synth.visual_context.capture.v2' : 'synth.visual_context.capture.v1')
        || !in_array($request['protocol_version'] ?? null, [1, 2], true)
        || ($request['game'] ?? '') !== 'fo4'
        || !in_array($request['runtime_variant'] ?? '', ['flat', 'vr'], true)
        || !in_array($request['interaction_mode'] ?? '', ['store_only', 'describe', 'npc_portrait'], true)
        || !in_array($request['perspective'] ?? '', ['first_person', 'third_person', 'hmd'], true)
        || ($request['runtime_variant'] === 'vr' && $request['perspective'] !== 'hmd')
        || ($request['runtime_variant'] === 'flat' && $request['perspective'] === 'hmd')) {
        throw new SynthVisualRequestException(422, 'invalid_visual_request', 'Visual protocol, game, runtime, mode or perspective is invalid.');
    }
    foreach (['session_id', 'request_id', 'turn_id', 'capture_id'] as $key) {
        if (!synth_visual_valid_id($request[$key])) {
            throw new SynthVisualRequestException(422, 'invalid_visual_request', "{$key} is invalid.");
        }
    }
    if ($native && (!is_int($request['context_sequence'] ?? null)
        || $request['context_sequence'] < 1 || $request['context_sequence'] > 9007199254740991)) {
        throw new SynthVisualRequestException(422, 'invalid_visual_request', 'Native capture requires an exact context sequence.');
    }
    if (!is_int($request['generation']) || $request['generation'] < 1) {
        throw new SynthVisualRequestException(422, 'invalid_visual_request', 'generation is invalid.');
    }
    $request['media'] = synth_visual_validate_media($request['media']);
    $request['world'] = synth_visual_validate_world($request['world']);
    if (isset($request['subject'])) {
        $request['subject'] = synth_visual_validate_identity($request['subject'], 'subject', $native);
    }
    if (($request['interaction_mode'] === 'npc_portrait' || ($native && $request['interaction_mode'] === 'describe'))
        && !isset($request['subject'])) {
        throw new SynthVisualRequestException(422, 'invalid_actor_identity', 'This visual mode requires subject.');
    }
    $nearby = $request['nearby_actors'] ?? [];
    if (!is_array($nearby) || !array_is_list($nearby) || count($nearby) > 20) {
        throw new SynthVisualRequestException(422, 'invalid_visual_request', 'nearby_actors must contain at most 20 identities.');
    }
    $request['nearby_actors'] = array_map(
        static fn($identity): array => synth_visual_validate_identity($identity, 'nearby_actors[]', $native),
        $nearby
    );
    if (isset($request['visual_type'])
        && !in_array($request['visual_type'], ['scene', 'location', 'actor', 'player', 'item', 'furniture', 'object'], true)) {
        throw new SynthVisualRequestException(422, 'invalid_visual_request', 'visual_type is invalid.');
    }
    if (isset($request['visual_key'])
        && (!is_string($request['visual_key']) || strlen($request['visual_key']) > 500)) {
        throw new SynthVisualRequestException(422, 'invalid_visual_request', 'visual_key is invalid.');
    }
    return $request;
}

function synth_visual_canonical_json(array $request): string
{
    $sort = static function ($value) use (&$sort) {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($sort, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = $sort($item);
        }
        unset($item);
        return $value;
    };
    return json_encode($sort($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

// Enforces the negotiated runtime lane before any image or provider work begins.
function synth_visual_validate_runtime($db, array $request): array
{
    $row = $db->fetchOne(
        "SELECT playthrough_id, runtime_variant, active_generation, active_turn_id, active_turn_sequence, capabilities, state,
            COALESCE((SELECT e.canonical_event->>'protocol_version' FROM synth_meta.protocol_events e
                WHERE e.session_id=r.session_id AND e.generation=r.active_generation AND e.event_type='init' LIMIT 1), '1') AS protocol_version "
        . 'FROM synth_meta.runtime_sessions r WHERE session_id='
        . $db->escapeLiteral($request['session_id']) . ' LIMIT 1'
    );
    if (!$row || strval($row['state'] ?? '') !== 'active') {
        throw new SynthVisualRequestException(409, 'runtime_not_initialized', 'Runtime session is not active.');
    }
    if (intval($row['active_generation'] ?? 0) !== $request['generation']) {
        throw new SynthVisualRequestException(409, 'stale_generation', 'Visual request generation is stale.');
    }
    if (strval($row['runtime_variant'] ?? '') !== $request['runtime_variant']) {
        throw new SynthVisualRequestException(409, 'runtime_lane_mismatch', 'Visual request runtime lane does not match the session.');
    }
    if (intval($row['protocol_version'] ?? 1) !== $request['protocol_version']) {
        throw new SynthVisualRequestException(409, 'protocol_version_mismatch', 'Visual protocol does not match the initialized session.');
    }
    $playthroughId = strval($row['playthrough_id'] ?? '');
    $identities = array_merge(isset($request['subject']) ? [$request['subject']] : [], $request['nearby_actors']);
    foreach ($identities as $identity) {
        if ($identity['playthrough_id'] !== $playthroughId) {
            throw new SynthVisualRequestException(409, 'playthrough_mismatch', 'Actor identity belongs to another playthrough.');
        }
    }
    $capabilities = json_decode(strval($row['capabilities'] ?? '[]'), true);
    $capabilities = is_array($capabilities) ? $capabilities : [];
    foreach (['media.image.upload', 'visual_context.capture'] as $required) {
        if (!in_array($required, $capabilities, true)) {
            throw new SynthVisualRequestException(409, 'capability_not_negotiated', "Runtime did not negotiate {$required}.");
        }
    }
    $modeCapability = $request['interaction_mode'] === 'npc_portrait'
        ? 'visual_context.portrait'
        : ($request['interaction_mode'] === 'describe' ? 'visual_context.describe' : 'visual_context.capture');
    if (!in_array($modeCapability, $capabilities, true)) {
        throw new SynthVisualRequestException(409, 'capability_not_negotiated', "Runtime did not negotiate {$modeCapability}.");
    }
    if (($request['protocol_version'] ?? 1) === 2) {
        require_once __DIR__ . '/synth_protocol_state.php';
        $context = synth_protocol_current_context($db, $request);
        $sequence = intval($request['context_sequence']);
        $session = $db->escapeLiteral($request['session_id']);
        $turn = $db->escapeLiteral($request['turn_id']);
        $generation = intval($request['generation']);
        $anchor = $db->fetchOne("SELECT 1 AS acknowledged FROM synth_meta.protocol_events p
            JOIN synth_meta.request_executions e USING (session_id, request_id)
            WHERE p.session_id=$session AND p.generation=$generation AND p.turn_id=$turn
                AND p.event_type='context' AND p.canonical_event->'payload'->>'snapshot_sequence'='$sequence'
                AND e.state='complete' LIMIT 1");
        if (!$context || !$anchor || intval($row['active_turn_sequence'] ?? 0) > $sequence
            || (intval($row['active_turn_sequence'] ?? 0) === $sequence
                && strval($row['active_turn_id'] ?? '') !== $request['turn_id'])) {
            throw new SynthVisualRequestException(409, 'stale_visual_context', 'Visual snapshot is unavailable, cancelled or superseded.');
        }
        $snapshot = $context['payload'];
        if ($request['world']['cell_form_id'] !== '' || $request['world']['worldspace_form_id'] !== '') {
            throw new SynthVisualRequestException(409, 'visual_world_mismatch', 'This snapshot does not attest world FormIDs.');
        }
        if (isset($request['subject']) && (!synth_visual_same_actor($request['subject'], $context['target'] ?? [])
            || (isset($request['subject']['display_name'])
                && $request['subject']['display_name'] !== ($context['target']['display_name'] ?? '')))) {
            throw new SynthVisualRequestException(409, 'visual_subject_mismatch', 'Capture subject is not the frozen target.');
        }
        $observed = array_merge([$context['player'], $context['target'] ?? []], $snapshot['audience'] ?? []);
        foreach ($request['nearby_actors'] as $actor) {
            if (!array_filter($observed, static fn($candidate) => synth_visual_same_actor($actor, $candidate)
                && (!isset($actor['display_name']) || $actor['display_name'] === ($candidate['display_name'] ?? '')))) {
                throw new SynthVisualRequestException(409, 'visual_audience_mismatch', 'Capture audience is not in the frozen snapshot.');
            }
        }
        foreach (['location' => '', 'worldspace' => '', 'weather' => '', 'interior' => false, 'game_time_ticks' => 0] as $key => $default) {
            if ($request['world'][$key] !== ($snapshot['world'][$key] ?? $default)) {
                throw new SynthVisualRequestException(409, 'visual_world_mismatch', 'Capture world differs from the frozen snapshot.');
            }
        }
    }
    return $row;
}

// Identity comparisons never use mutable display names as an ownership key.
function synth_visual_same_actor(array $left, array $right): bool
{
    return isset($left['form_id'], $right['form_id'], $left['origin_plugin'], $right['origin_plugin'],
            $left['playthrough_id'], $right['playthrough_id'])
        && strcasecmp($left['form_id'], $right['form_id']) === 0
        && strcasecmp($left['origin_plugin'], $right['origin_plugin']) === 0
        && $left['playthrough_id'] === $right['playthrough_id'];
}

// A reaction consumes one successful capture, never a best-match gallery row.
function synth_visual_bound_capture($db, array $event): array
{
    $capture = $db->escapeLiteral(strval($event['payload']['capture_id'] ?? ''));
    $row = $db->fetchOne("SELECT * FROM synth.visual_context WHERE capture_id=$capture AND active=TRUE LIMIT 1");
    $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : json_decode(strval($row['metadata'] ?? ''), true);
    $response = is_array($row['response_payload'] ?? null) ? $row['response_payload'] : json_decode(strval($row['response_payload'] ?? ''), true);
    if (!$row || ($metadata['protocol_version'] ?? null) !== 2 || ($response['ok'] ?? null) !== true
        || ($metadata['interaction_mode'] ?? '') !== 'describe' || trim(strval($row['description'] ?? '')) === '') {
        throw new DomainException('visual_capture_unavailable');
    }
    foreach (['session_id', 'turn_id', 'generation', 'runtime_variant', 'context_sequence'] as $key) {
        if (($metadata[$key] ?? null) !== ($event[$key] ?? null)) throw new DomainException('visual_capture_mismatch');
    }
    if (!synth_visual_same_actor($metadata['subject'] ?? [], $event['payload']['actor'] ?? [])) {
        throw new DomainException('visual_capture_actor_mismatch');
    }
    return $row;
}

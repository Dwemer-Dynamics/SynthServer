<?php

const SYNTH_PROTOCOL_MAX_JSON_BYTES = 1048576;
const SYNTH_PROTOCOL_MAX_JSON_DEPTH = 32;

// Reports a closed-contract validation issue without echoing request data.
function synth_protocol_fail(string $code, int $status, array $issues = []): void
{
    $extra = ['code' => $code, 'retryable' => false];
    if ($issues !== []) {
        $extra['issues'] = $issues;
    }
    synth_reject_json_request($status, 'Request does not match the SYNTH event contract', $extra);
}

function synth_protocol_is_object($value): bool
{
    return is_array($value) && !array_is_list($value);
}

function synth_protocol_valid_id($value): bool
{
    return is_string($value)
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) === 1;
}

function synth_protocol_expect_exact_keys(array $value, array $required, array $optional, string $field): void
{
    $issues = [];
    foreach ($required as $key) {
        if (!array_key_exists($key, $value)) {
            $issues[] = ['field' => $field . '.' . $key, 'issue' => 'required'];
        }
    }
    $allowed = array_fill_keys(array_merge($required, $optional), true);
    foreach (array_keys($value) as $key) {
        if (!isset($allowed[$key])) {
            $issues[] = ['field' => $field . '.' . strval($key), 'issue' => 'unknown'];
        }
    }
    if ($issues !== []) {
        synth_protocol_fail('invalid_envelope', 422, $issues);
    }
}

function synth_protocol_validate_identity($value, string $field): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_identity', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys($value, ['form_id', 'origin_plugin', 'playthrough_id'], ['display_name'], $field);
    if (!is_string($value['form_id']) || preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['form_id']) !== 1) {
        synth_protocol_fail('invalid_identity', 422, [['field' => $field . '.form_id', 'issue' => 'invalid']]);
    }
    if (!is_string($value['origin_plugin'])
        || strlen($value['origin_plugin']) > 255
        || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['origin_plugin']) !== 1) {
        synth_protocol_fail('invalid_identity', 422, [['field' => $field . '.origin_plugin', 'issue' => 'invalid']]);
    }
    if (!synth_protocol_valid_id($value['playthrough_id'])) {
        synth_protocol_fail('invalid_identity', 422, [['field' => $field . '.playthrough_id', 'issue' => 'invalid']]);
    }
    if (array_key_exists('display_name', $value)
        && (!is_string($value['display_name']) || trim($value['display_name']) === '' || strlen($value['display_name']) > 255)) {
        synth_protocol_fail('invalid_identity', 422, [['field' => $field . '.display_name', 'issue' => 'invalid']]);
    }
    return $value;
}

function synth_protocol_validate_media($value, string $field): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_media', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['schema', 'media_id', 'content_type', 'sha256', 'size'],
        [],
        $field
    );
    $valid = $value['schema'] === 'synth.media.v1'
        && is_string($value['media_id'])
        && preg_match('#^media/[a-f0-9]{64}$#D', $value['media_id']) === 1
        && in_array($value['content_type'], ['audio/wav', 'audio/ogg'], true)
        && is_string($value['sha256'])
        && preg_match('/^[a-f0-9]{64}$/D', $value['sha256']) === 1
        && hash_equals(substr($value['media_id'], 6), $value['sha256'])
        && is_int($value['size'])
        && $value['size'] >= 1
        && $value['size'] <= 16777216;
    if (!$valid) {
        synth_protocol_fail('invalid_media', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    return $value;
}

function synth_protocol_validate_action_result($value, string $field, int $version = 1): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_action_result', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    $transfer = ($value['schema'] ?? '') === 'synth.action.transfer-result.v2';
    $observed = $transfer || ($value['schema'] ?? '') === 'synth.action.result.v2';
    synth_protocol_expect_exact_keys(
        $value,
        $transfer ? ['schema', 'action_id', 'idempotency_key', 'status', 'detail', 'inventory', 'recipient_inventory']
            : ($observed ? ['schema', 'action_id', 'idempotency_key', 'status', 'detail', 'inventory']
            : ['schema', 'action_id', 'idempotency_key', 'status', 'detail']),
        [],
        $field
    );
    $statuses = [
        'succeeded', 'rejected', 'unavailable', 'timed_out', 'cancelled', 'failed',
        'unsupported_without_esp', 'unsupported_runtime',
    ];
    $valid = ($value['schema'] === 'synth.action.result.v1' || ($version === 2 && $observed))
        && synth_protocol_valid_id($value['action_id'])
        && synth_protocol_valid_id($value['idempotency_key'])
        && in_array($value['status'], $statuses, true)
        && is_string($value['detail'])
        && strlen($value['detail']) <= 1024;
    if (!$valid) {
        synth_protocol_fail('invalid_action_result', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    foreach ($transfer ? ['inventory', 'recipient_inventory'] : ($observed ? ['inventory'] : []) as $key) {
        $inventory = $value[$key];
        if (!synth_protocol_is_object($inventory)) synth_protocol_fail('invalid_action_inventory', 422);
        synth_protocol_expect_exact_keys($inventory, ['actor', 'items', 'observation'], [], $field.'.'.$key);
        $inventory['actor'] = synth_protocol_validate_identity($inventory['actor'], $field.'.'.$key.'.actor');
        if (!is_array($inventory['items']) || !array_is_list($inventory['items']) || count($inventory['items']) > 512
            || !in_array($inventory['observation'], ['complete','partial','unavailable'], true)
            || ($inventory['observation'] === 'unavailable' && $inventory['items'] !== []))
            synth_protocol_fail('invalid_action_inventory', 422);
        foreach ($inventory['items'] as $index => $item)
            $inventory['items'][$index] = synth_protocol_validate_inventory_item($item, $field.'.'.$key.'.items.'.$index);
        $value[$key] = $inventory;
    }
    if ($transfer && ($value['inventory']['actor']['playthrough_id'] !== $value['recipient_inventory']['actor']['playthrough_id']
        || strcasecmp($value['inventory']['actor']['form_id'], $value['recipient_inventory']['actor']['form_id']) === 0))
        synth_protocol_fail('invalid_transfer_actors', 422);
    return $value;
}

function synth_protocol_validate_inventory_item($value, string $field): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_inventory_item', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['form_id', 'origin_plugin', 'display_name', 'count', 'value', 'weight', 'form_type', 'equipped'],
        [],
        $field
    );
    $valid = is_string($value['form_id'] ?? null)
        && preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['form_id']) === 1
        && is_string($value['origin_plugin'] ?? null)
        && strlen($value['origin_plugin']) <= 255
        && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['origin_plugin']) === 1
        && is_string($value['display_name'] ?? null)
        && trim($value['display_name']) !== ''
        && strlen($value['display_name']) <= 255
        && is_int($value['count'] ?? null) && $value['count'] >= 1 && $value['count'] <= 2147483647
        && is_int($value['value'] ?? null) && $value['value'] >= 0 && $value['value'] <= 2147483647
        && (is_int($value['weight'] ?? null) || is_float($value['weight'] ?? null))
        && is_finite((float)$value['weight']) && $value['weight'] >= 0 && $value['weight'] <= 100000
        && is_int($value['form_type'] ?? null) && $value['form_type'] >= 0 && $value['form_type'] <= 65535
        && is_bool($value['equipped'] ?? null);
    if (!$valid) {
        synth_protocol_fail('invalid_inventory_item', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    return $value;
}

function synth_protocol_validate_faction_membership($value, string $field): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_faction_membership', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['form_id', 'origin_plugin', 'display_name', 'editor_id', 'rank'],
        [],
        $field
    );
    $valid = is_string($value['form_id'] ?? null)
        && preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['form_id']) === 1
        && is_string($value['origin_plugin'] ?? null)
        && strlen($value['origin_plugin']) <= 255
        && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['origin_plugin']) === 1
        && is_string($value['display_name'] ?? null)
        && trim($value['display_name']) !== ''
        && strlen($value['display_name']) <= 255
        && is_string($value['editor_id'] ?? null)
        && strlen($value['editor_id']) <= 255
        && is_int($value['rank'] ?? null)
        && $value['rank'] >= 0
        && $value['rank'] <= 127;
    if (!$valid) {
        synth_protocol_fail('invalid_faction_membership', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    return $value;
}

function synth_protocol_validate_package_state($value, string $field): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_package_state', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['available', 'form_id', 'origin_plugin', 'display_name', 'editor_id'],
        [],
        $field
    );
    if (!is_bool($value['available'] ?? null)) {
        synth_protocol_fail('invalid_package_state', 422, [['field' => $field . '.available', 'issue' => 'boolean_required']]);
    }
    foreach (['form_id', 'origin_plugin', 'display_name', 'editor_id'] as $label) {
        if (!is_string($value[$label] ?? null) || strlen($value[$label]) > 255) {
            synth_protocol_fail('invalid_package_state', 422, [['field' => $field . '.' . $label, 'issue' => 'invalid']]);
        }
    }
    if (!$value['available']) {
        if ($value['form_id'] !== '' || $value['origin_plugin'] !== '' ||
            $value['display_name'] !== '' || $value['editor_id'] !== '') {
            synth_protocol_fail('invalid_package_state', 422, [['field' => $field, 'issue' => 'unavailable_must_be_empty']]);
        }
        return $value;
    }
    if (preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['form_id']) !== 1 ||
        preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['origin_plugin']) !== 1 ||
        trim($value['display_name']) === '') {
        synth_protocol_fail('invalid_package_state', 422, [['field' => $field, 'issue' => 'invalid_identity']]);
    }
    return $value;
}

// Validate bounded runtime state without accepting engine pointers or arbitrary objects.
function synth_protocol_validate_actor_state($value, string $field, int $version = 1): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['actor', 'base_form_id', 'base_origin_plugin', 'position', 'distance', 'alive', 'disabled', 'in_combat', 'hostile_to_player', 'sneaking', 'level',
         'race', 'sex', 'voice_type', 'teammate', 'health_percent', 'action_points_percent', 'inventory',
         'line_of_sight', 'factions', 'faction_observation', 'life_state', 'posture',
         'weapon_drawn', 'movement_speed', 'sprinting', 'talking_to_player', 'in_power_armor',
         'current_package'],
        ['inventory_observation', 'faction_completeness', 'health_percent_available', 'action_points_percent_available'],
        $field
    );
    $value['actor'] = synth_protocol_validate_identity($value['actor'] ?? null, $field . '.actor');
    foreach (['health_percent_available','action_points_percent_available'] as $availability) {
        if (array_key_exists($availability, $value) && !is_bool($value[$availability])) {
            synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.' . $availability, 'issue' => 'boolean_required']]);
        }
    }
    if (!is_string($value['base_form_id'] ?? null) ||
        preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['base_form_id']) !== 1 ||
        !is_string($value['base_origin_plugin'] ?? null) ||
        strlen($value['base_origin_plugin']) > 255 ||
        preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['base_origin_plugin']) !== 1) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.base_identity', 'issue' => 'invalid']]);
    }
    $position = $value['position'] ?? null;
    if (!synth_protocol_is_object($position)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.position', 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys($position, ['x', 'y', 'z'], [], $field . '.position');
    foreach (['x', 'y', 'z'] as $axis) {
        if ((!is_int($position[$axis] ?? null) && !is_float($position[$axis] ?? null)) || !is_finite((float)$position[$axis])) {
            synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.position.' . $axis, 'issue' => 'invalid']]);
        }
    }
    if ((!is_int($value['distance'] ?? null) && !is_float($value['distance'] ?? null)) ||
        !is_finite((float)$value['distance']) || $value['distance'] < 0 || $value['distance'] > 1000000 ||
        !is_int($value['level'] ?? null) || $value['level'] < 0 || $value['level'] > 32767 ||
        (!is_int($value['health_percent'] ?? null) && !is_float($value['health_percent'] ?? null)) ||
        !is_finite((float)$value['health_percent']) || $value['health_percent'] < 0 || $value['health_percent'] > 100 ||
        (!is_int($value['action_points_percent'] ?? null) && !is_float($value['action_points_percent'] ?? null)) ||
        !is_finite((float)$value['action_points_percent']) || $value['action_points_percent'] < 0 ||
        $value['action_points_percent'] > 100 ||
        (!is_int($value['movement_speed'] ?? null) && !is_float($value['movement_speed'] ?? null)) ||
        !is_finite((float)$value['movement_speed']) || $value['movement_speed'] < 0 ||
        $value['movement_speed'] > 100000) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field, 'issue' => 'invalid_range']]);
    }
    foreach (['race', 'voice_type'] as $label) {
        if (!is_string($value[$label] ?? null) || strlen($value[$label]) > 255) {
            synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.' . $label, 'issue' => 'invalid']]);
        }
    }
    if (!is_string($value['sex'] ?? null) ||
        !in_array($value['sex'], ['', 'male', 'female', 'unknown'], true)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.sex', 'issue' => 'invalid']]);
    }
    if (!is_string($value['line_of_sight'] ?? null) ||
        !in_array($value['line_of_sight'], ['visible', 'blocked', 'unknown'], true)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.line_of_sight', 'issue' => 'invalid']]);
    }
    if (!is_string($value['faction_observation'] ?? null) ||
        !in_array($value['faction_observation'], ['effective', 'base_only', 'unavailable'], true)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.faction_observation', 'issue' => 'invalid']]);
    }
    if (array_key_exists('faction_completeness', $value) &&
        (!in_array($value['faction_completeness'], ['complete', 'partial', 'unavailable'], true) ||
         (($value['faction_observation'] === 'unavailable') !== ($value['faction_completeness'] === 'unavailable')))) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.faction_completeness', 'issue' => 'invalid']]);
    }
    if (!is_string($value['life_state'] ?? null) || !in_array($value['life_state'], [
        'alive', 'dying', 'dead', 'unconscious', 'reanimate', 'recycle', 'restrained',
        'essential_down', 'bleedout', 'unknown',
    ], true)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.life_state', 'issue' => 'invalid']]);
    }
    if (!is_string($value['posture'] ?? null) || !in_array($value['posture'], [
        'normal', 'want_to_sit', 'waiting_for_sit', 'sitting', 'want_to_stand',
        'want_to_sleep', 'waiting_for_sleep', 'sleeping', 'want_to_wake', 'unknown',
    ], true)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.posture', 'issue' => 'invalid']]);
    }
    foreach ([
        'alive', 'disabled', 'in_combat', 'hostile_to_player', 'sneaking', 'teammate',
        'weapon_drawn', 'sprinting', 'talking_to_player', 'in_power_armor',
    ] as $flag) {
        if (!is_bool($value[$flag] ?? null)) {
            synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.' . $flag, 'issue' => 'boolean_required']]);
        }
    }
    if (!is_array($value['inventory'] ?? null) || !array_is_list($value['inventory']) ||
        count($value['inventory']) > ($version === 2 ? 512 : 32)) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.inventory', 'issue' => 'invalid']]);
    }
    if (array_key_exists('inventory_observation',$value) &&
        (!in_array($value['inventory_observation'],['complete','partial','unavailable'],true) ||
         ($value['inventory_observation']==='unavailable' && $value['inventory']!==[]))) {
        synth_protocol_fail('invalid_actor_state',422,[['field'=>$field.'.inventory_observation','issue'=>'invalid']]);
    }
    foreach ($value['inventory'] as $index => $item) {
        $value['inventory'][$index] = synth_protocol_validate_inventory_item(
            $item,
            $field . '.inventory.' . $index
        );
    }
    if (!is_array($value['factions'] ?? null) || !array_is_list($value['factions']) ||
        count($value['factions']) > 32 ||
        ($value['faction_observation'] === 'unavailable' && $value['factions'] !== [])) {
        synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.factions', 'issue' => 'invalid']]);
    }
    $factionKeys = [];
    foreach ($value['factions'] as $index => $faction) {
        $faction = synth_protocol_validate_faction_membership(
            $faction,
            $field . '.factions.' . $index
        );
        $key = strtoupper($faction['form_id']) . '|' . strtolower($faction['origin_plugin']);
        if (isset($factionKeys[$key])) {
            synth_protocol_fail('invalid_actor_state', 422, [['field' => $field . '.factions', 'issue' => 'duplicate_identity']]);
        }
        $factionKeys[$key] = true;
        $value['factions'][$index] = $faction;
    }
    $value['current_package'] = synth_protocol_validate_package_state(
        $value['current_package'] ?? null,
        $field . '.current_package'
    );
    return $value;
}

function synth_protocol_validate_world_state($value, string $field, int $version = 1): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_world_state', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['location', 'cell', 'worldspace', 'weather', 'interior', 'game_time_ticks'],
        $version===2 ? ['scene'] : [],
        $field
    );
    foreach (['location', 'cell', 'worldspace', 'weather'] as $label) {
        if (!is_string($value[$label] ?? null) || strlen($value[$label]) > 255) {
            synth_protocol_fail('invalid_world_state', 422, [['field' => $field . '.' . $label, 'issue' => 'invalid']]);
        }
    }
    if (!is_bool($value['interior'] ?? null) || !is_int($value['game_time_ticks'] ?? null) ||
        $value['game_time_ticks'] < 1 || $value['game_time_ticks'] > 9007199254740991) {
        synth_protocol_fail('invalid_world_state', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    if (array_key_exists('scene',$value)) {
        if (!synth_protocol_is_object($value['scene']))
            synth_protocol_fail('invalid_world_state',422,[['field'=>$field.'.scene','issue'=>'object_required']]);
        synth_protocol_expect_exact_keys($value['scene'],['cell','worldspace'],[],$field.'.scene');
        foreach (['cell','worldspace'] as $kind) {
            $reference=$value['scene'][$kind];
            if ($kind==='worldspace' && $value['interior'] && $reference===null) continue;
            if (!synth_protocol_is_object($reference) || ($kind==='worldspace' && $value['interior']))
                synth_protocol_fail('invalid_world_state',422,[['field'=>$field.'.scene.'.$kind,'issue'=>'invalid_reference']]);
            synth_protocol_expect_exact_keys($reference,['form_id','origin_plugin'],[],$field.'.scene.'.$kind);
            synth_protocol_validate_identity($reference+['playthrough_id'=>'scene'],$field.'.scene.'.$kind);
            if ($reference['form_id']==='0x00000000')
                synth_protocol_fail('invalid_world_state',422,[['field'=>$field.'.scene.'.$kind,'issue'=>'zero_reference']]);
        }
    }
    return $value;
}

function synth_protocol_validate_loaded_plugin($value, string $field): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_loaded_plugin', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['plugin_name', 'is_light', 'compile_index', 'small_file_compile_index', 'partial_index', 'formid_prefix'],
        [],
        $field
    );
    $validName = is_string($value['plugin_name'] ?? null) &&
        strlen($value['plugin_name']) <= 255 &&
        preg_match('/^[^\/\\\\]+\.(esm|esp|esl)$/Di', $value['plugin_name']) === 1;
    if (!$validName || !is_bool($value['is_light'] ?? null)) {
        synth_protocol_fail('invalid_loaded_plugin', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    foreach (['compile_index' => 255, 'small_file_compile_index' => 4095, 'partial_index' => 4095] as $key => $maximum) {
        if (!is_int($value[$key] ?? null) || $value[$key] < 0 || $value[$key] > $maximum) {
            synth_protocol_fail('invalid_loaded_plugin', 422, [['field' => $field . '.' . $key, 'issue' => 'invalid']]);
        }
    }
    $prefix = strval($value['formid_prefix'] ?? '');
    $expected = $value['is_light']
        ? sprintf('FE%03X', $value['small_file_compile_index'])
        : sprintf('%02X', $value['compile_index']);
    if ($prefix !== $expected || $value['partial_index'] !==
        ($value['is_light'] ? $value['small_file_compile_index'] : $value['compile_index'])) {
        synth_protocol_fail('invalid_loaded_plugin', 422, [['field' => $field, 'issue' => 'index_mismatch']]);
    }
    return $value;
}

function synth_protocol_validate_quest_state($value, string $field, int $version = 1): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_quest_state', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    synth_protocol_expect_exact_keys(
        $value,
        ['form_id', 'origin_plugin', 'display_name', 'editor_id', 'current_stage', 'active_objectives', 'objectives'],
        $version === 2 ? ['objectives_observation','tracked'] : ['objectives_observation'],
        $field
    );
    $valid = is_string($value['form_id'] ?? null)
        && preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value['form_id']) === 1
        && is_string($value['origin_plugin'] ?? null)
        && strlen($value['origin_plugin']) <= 255
        && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value['origin_plugin']) === 1
        && is_string($value['display_name'] ?? null)
        && trim($value['display_name']) !== ''
        && strlen($value['display_name']) <= 255
        && is_string($value['editor_id'] ?? null) && strlen($value['editor_id']) <= 255
        && is_int($value['current_stage'] ?? null)
        && $value['current_stage'] >= 0 && $value['current_stage'] <= 65535
        && is_int($value['active_objectives'] ?? null)
        && $value['active_objectives'] >= 0 && $value['active_objectives'] <= 65535
        && is_array($value['objectives'] ?? null)
        && array_is_list($value['objectives'])
        && count($value['objectives']) <= 8;
    if (!$valid) {
        synth_protocol_fail('invalid_quest_state', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    if (array_key_exists('objectives_observation',$value) &&
        (!in_array($value['objectives_observation'],['complete','partial','unavailable'],true) ||
         ($value['objectives_observation']==='unavailable' && ($value['objectives']!==[] || $value['active_objectives']!==0)))) {
        synth_protocol_fail('invalid_quest_state',422,[['field'=>$field.'.objectives_observation','issue'=>'invalid']]);
    }
    $objectiveSeen = [];
    if (array_key_exists('tracked',$value) && !is_bool($value['tracked'])) {
        synth_protocol_fail('invalid_quest_state',422,[['field'=>$field.'.tracked','issue'=>'boolean_required']]);
    }
    foreach ($value['objectives'] as $index => $objective) {
        if (!is_string($objective) || trim($objective) === '' || strlen($objective) > 255
            || isset($objectiveSeen[$objective])) {
            synth_protocol_fail('invalid_quest_state', 422, [[
                'field' => $field . '.objectives.' . $index,
                'issue' => 'invalid_or_duplicate',
            ]]);
        }
        $objectiveSeen[$objective] = true;
    }
    return $value;
}

// Validate the canonical reference, cell identity and bounded position shared by nearby world objects.
function synth_protocol_validate_spatial_reference($value, string $field, array $extraKeys): array
{
    if (!synth_protocol_is_object($value)) {
        synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field, 'issue' => 'object_required']]);
    }
    $common = [
        'reference_id', 'reference_origin_plugin', 'base_form_id', 'base_origin_plugin',
        'cell_form_id', 'cell_origin_plugin', 'display_name', 'position', 'distance',
    ];
    synth_protocol_expect_exact_keys($value, array_merge($common, $extraKeys), [], $field);
    foreach (['reference_id', 'base_form_id', 'cell_form_id'] as $id) {
        if (!is_string($value[$id] ?? null) ||
            preg_match('/^0x[0-9A-Fa-f]{8}$/D', $value[$id]) !== 1) {
            synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field . '.' . $id, 'issue' => 'invalid']]);
        }
    }
    foreach (['base_origin_plugin', 'cell_origin_plugin'] as $plugin) {
        if (!is_string($value[$plugin] ?? null) || strlen($value[$plugin]) > 255 ||
            preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $value[$plugin]) !== 1) {
            synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field . '.' . $plugin, 'issue' => 'invalid']]);
        }
    }
    $referencePlugin = $value['reference_origin_plugin'] ?? null;
    if (!is_string($referencePlugin) || strlen($referencePlugin) > 255 ||
        ($referencePlugin !== '' && preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $referencePlugin) !== 1)) {
        synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field . '.reference_origin_plugin', 'issue' => 'invalid']]);
    }
    if (!is_string($value['display_name'] ?? null) || trim($value['display_name']) === '' ||
        strlen($value['display_name']) > 255 || !synth_protocol_is_object($value['position'] ?? null)) {
        synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    synth_protocol_expect_exact_keys($value['position'], ['x', 'y', 'z'], [], $field . '.position');
    foreach (['x', 'y', 'z'] as $axis) {
        if ((!is_int($value['position'][$axis] ?? null) && !is_float($value['position'][$axis] ?? null)) ||
            !is_finite((float)$value['position'][$axis])) {
            synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field . '.position.' . $axis, 'issue' => 'invalid']]);
        }
    }
    if ((!is_int($value['distance'] ?? null) && !is_float($value['distance'] ?? null)) ||
        !is_finite((float)$value['distance']) || $value['distance'] < 0 || $value['distance'] > 1000000) {
        synth_protocol_fail('invalid_spatial_reference', 422, [['field' => $field . '.distance', 'issue' => 'invalid']]);
    }
    return $value;
}

function synth_protocol_validate_nearby_item($value, string $field): array
{
    $value = synth_protocol_validate_spatial_reference($value, $field, [
        'count', 'value', 'weight', 'form_type', 'stealing', 'looking_at', 'held',
    ]);
    $valid = is_int($value['count'] ?? null) && $value['count'] >= 1 && $value['count'] <= 2147483647
        && is_int($value['value'] ?? null) && $value['value'] >= 0 && $value['value'] <= 2147483647
        && (is_int($value['weight'] ?? null) || is_float($value['weight'] ?? null))
        && is_finite((float)$value['weight']) && $value['weight'] >= 0 && $value['weight'] <= 100000
        && is_int($value['form_type'] ?? null) && $value['form_type'] >= 0 && $value['form_type'] <= 65535
        && is_bool($value['stealing'] ?? null) && is_bool($value['looking_at'] ?? null)
        && is_bool($value['held'] ?? null);
    if (!$valid) {
        synth_protocol_fail('invalid_nearby_item', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    return $value;
}

function synth_protocol_validate_point_of_interest($value, string $field): array
{
    $value = synth_protocol_validate_spatial_reference(
        $value,
        $field,
        ['kind', 'locked', 'looking_at']
    );
    $kinds = ['door', 'container', 'activator', 'flora', 'furniture', 'terminal', 'map_marker'];
    if (!is_string($value['kind'] ?? null) || !in_array($value['kind'], $kinds, true) ||
        !is_bool($value['locked'] ?? null) || !is_bool($value['looking_at'] ?? null)) {
        synth_protocol_fail('invalid_point_of_interest', 422, [['field' => $field, 'issue' => 'invalid']]);
    }
    return $value;
}

// A native batch keeps exact transitions separate from the journal used to establish visibility.
function synth_protocol_validate_quest_events($batch): array
{
    if (!synth_protocol_is_object($batch)) synth_protocol_fail('invalid_quest_events',422);
    synth_protocol_expect_exact_keys($batch,['batch_id','events'],[],'payload.quest_events');
    if (!synth_protocol_valid_id($batch['batch_id']) || !is_array($batch['events'])
        || !array_is_list($batch['events']) || count($batch['events'])<1 || count($batch['events'])>32)
        synth_protocol_fail('invalid_quest_events',422);
    foreach ($batch['events'] as $index=>$record) {
        $field='payload.quest_events.events.'.$index;
        if (!synth_protocol_is_object($record)) synth_protocol_fail('invalid_quest_events',422);
        synth_protocol_expect_exact_keys($record,
            ['kind','form_id','origin_plugin','visibility_sequence','stage','item','failed'],[],$field);
        synth_protocol_validate_identity(['form_id'=>$record['form_id'],'origin_plugin'=>$record['origin_plugin'],
            'playthrough_id'=>'validation-only'],$field);
        if (!in_array($record['kind'],['stage','started','stopped'],true)
            || in_array(strtoupper($record['form_id']),['0X00000000','0XFFFFFFFF'],true)
            || !is_int($record['visibility_sequence']) || $record['visibility_sequence']<0
            || $record['visibility_sequence']>9007199254740991
            || !is_int($record['stage']) || $record['stage']<0 || $record['stage']>65535
            || !is_int($record['item']) || $record['item']<0 || $record['item']>255
            || !is_bool($record['failed'])
            || ($record['kind']==='stage' ? $record['failed'] : ($record['stage']!==0 || $record['item']!==0)))
            synth_protocol_fail('invalid_quest_events',422,[['field'=>$field,'issue'=>'invalid']]);
    }
    return $batch;
}

function synth_protocol_validate_payload(string $type, $payload, int $version = 1): array|stdClass
{
    if ($type === 'halt' && $payload instanceof stdClass && get_object_vars($payload) === []) {
        return $payload;
    }
    if (!synth_protocol_is_object($payload) || count($payload) > 64) {
        synth_protocol_fail('invalid_payload', 422, [['field' => 'payload', 'issue' => 'object_required']]);
    }

    if ($type === 'init') {
        synth_protocol_expect_exact_keys($payload, ['locale'], ['hmd_pose_fresh', 'plugins'], 'payload');
        $locale = $payload['locale'] ?? null;
        if (!is_string($locale) || strlen($locale) > 35
            || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $locale) !== 1
            || (isset($payload['hmd_pose_fresh']) && !is_bool($payload['hmd_pose_fresh']))) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload', 'issue' => 'invalid_init']]);
        }
        if (array_key_exists('plugins', $payload)) {
            if (!is_array($payload['plugins']) || !array_is_list($payload['plugins']) ||
                count($payload['plugins']) < 1 || count($payload['plugins']) > 512) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.plugins', 'issue' => 'invalid']]);
            }
            $seenNames = [];
            $seenPrefixes = [];
            foreach ($payload['plugins'] as $index => $plugin) {
                $plugin = synth_protocol_validate_loaded_plugin($plugin, 'payload.plugins.' . $index);
                $nameKey = strtolower($plugin['plugin_name']);
                $prefixKey = $plugin['formid_prefix'];
                if (isset($seenNames[$nameKey]) || isset($seenPrefixes[$prefixKey])) {
                    synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.plugins', 'issue' => 'duplicate']]);
                }
                $seenNames[$nameKey] = true;
                $seenPrefixes[$prefixKey] = true;
                $payload['plugins'][$index] = $plugin;
            }
        }
    } elseif ($type === 'control') {
        synth_protocol_expect_exact_keys($payload, ['setting', 'value'], [], 'payload');
        require_once __DIR__.'/synth_control.php';
        if (!synth_control_valid_change($payload['setting'], $payload['value']))
            synth_protocol_fail('invalid_control', 422);
    } elseif ($type === 'input_text') {
        synth_protocol_expect_exact_keys($payload, ['text'], $version === 2 ? ['control'] : [], 'payload');
        if (!is_string($payload['text']) || trim($payload['text']) === '' || strlen($payload['text']) > 4096) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.text', 'issue' => 'invalid']]);
        }
    } elseif ($type === 'input_audio') {
        synth_protocol_expect_exact_keys($payload, ['media'], $version === 2 ? ['control'] : [], 'payload');
        $payload['media'] = synth_protocol_validate_media($payload['media'] ?? null, 'payload.media');
    } elseif ($type === 'context') {
        synth_protocol_expect_exact_keys(
            $payload,
            ['playthrough_id', 'snapshot_sequence', 'player', 'audience', 'active_quests',
             'nearby_items', 'points_of_interest'],
            $version === 2 ? ['target', 'actor_states', 'world', 'loaded_context', 'active_quests_observation', 'nearby_items_observation', 'points_of_interest_observation', 'audience_observation', 'quest_events', 'player_event', 'actor_events']
                : ['target', 'actor_states', 'world', 'active_quests_observation', 'nearby_items_observation', 'points_of_interest_observation', 'audience_observation'],
            'payload'
        );
        if (!synth_protocol_valid_id($payload['playthrough_id'] ?? null)
            || !is_int($payload['snapshot_sequence'] ?? null)
            || $payload['snapshot_sequence'] < 1
            || $payload['snapshot_sequence'] > 9007199254740991
            || !is_array($payload['audience'])
            || !array_is_list($payload['audience'])
            || count($payload['audience']) > 16
            || !is_array($payload['active_quests'])
            || !array_is_list($payload['active_quests'])
            || count($payload['active_quests']) > 16
            || !is_array($payload['nearby_items']) || !array_is_list($payload['nearby_items'])
            || count($payload['nearby_items']) > 32
            || !is_array($payload['points_of_interest']) || !array_is_list($payload['points_of_interest'])
            || count($payload['points_of_interest']) > 16) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload', 'issue' => 'invalid_context']]);
        }
        $payload['player'] = synth_protocol_validate_identity($payload['player'] ?? null, 'payload.player');
        if (array_key_exists('quest_events',$payload)) {
            $payload['quest_events']=synth_protocol_validate_quest_events($payload['quest_events']);
            foreach ($payload['quest_events']['events'] as $record) {
                if ($record['visibility_sequence'] >= $payload['snapshot_sequence'])
                    synth_protocol_fail('invalid_quest_events',422,[['field'=>'visibility_sequence','issue'=>'must_precede_context_or_zero']]);
            }
        }
        if (array_key_exists('player_event',$payload)) {
            require_once __DIR__.'/player_event.php';
            try {$payload['player_event']=synthValidatePlayerEvent($payload['player_event']);}
            catch (DomainException $error) {synth_protocol_fail($error->getMessage(),422);}
        }
        if (array_key_exists('actor_events',$payload)) {
            require_once __DIR__.'/actor_event.php';
            try {$payload['actor_events']=synthValidateActorEvents($payload['actor_events']);}
            catch (DomainException $error) {synth_protocol_fail($error->getMessage(),422);}
        }
        if (array_key_exists('audience_observation',$payload) &&
            (!in_array($payload['audience_observation'],['complete','partial','unavailable'],true) ||
             ($payload['audience_observation']==='unavailable' && $payload['audience']!==[]))) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload.audience_observation','issue'=>'invalid']]);
        }
        if (array_key_exists('loaded_context', $payload)) {
            $anchor = $payload['loaded_context'];
            if (!synth_protocol_is_object($anchor)) {
                synth_protocol_fail('invalid_payload', 422, [['field'=>'payload.loaded_context','issue'=>'expected_object']]);
            }
            synth_protocol_expect_exact_keys($anchor,
                ['playthrough_id', 'session_id', 'generation', 'context_sequence'], [], 'payload.loaded_context');
            if (!synth_protocol_valid_id($anchor['playthrough_id']) || !synth_protocol_valid_id($anchor['session_id'])
                || $anchor['playthrough_id'] !== $payload['playthrough_id']
                || !is_int($anchor['generation']) || $anchor['generation'] < 1 || $anchor['generation'] > 9007199254740991
                || !is_int($anchor['context_sequence']) || $anchor['context_sequence'] < 1
                || $anchor['context_sequence'] > 9007199254740991) {
                synth_protocol_fail('invalid_payload', 422, [['field'=>'payload.loaded_context','issue'=>'invalid_anchor']]);
            }
        }
        $playerKey = strtoupper($payload['player']['form_id']) . '|' .
            strtolower($payload['player']['origin_plugin']) . '|' . $payload['player']['playthrough_id'];
        if (array_key_exists('target', $payload)) {
            $payload['target'] = synth_protocol_validate_identity($payload['target'], 'payload.target');
        }
        $seen = [];
        foreach ($payload['audience'] as $index => $actor) {
            $actor = synth_protocol_validate_identity($actor, 'payload.audience.' . $index);
            $key = strtoupper($actor['form_id']) . '|' . strtolower($actor['origin_plugin']) . '|' . $actor['playthrough_id'];
            if (isset($seen[$key])) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.audience', 'issue' => 'duplicate_identity']]);
            }
            $seen[$key] = true;
            $payload['audience'][$index] = $actor;
        }
        if (array_key_exists('actor_states', $payload)) {
            if (!is_array($payload['actor_states']) || !array_is_list($payload['actor_states']) ||
                count($payload['actor_states']) > 16) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.actor_states', 'issue' => 'invalid']]);
            }
            $stateSeen = [];
            foreach ($payload['actor_states'] as $index => $state) {
                $state = synth_protocol_validate_actor_state($state, 'payload.actor_states.' . $index, $version);
                $actor = $state['actor'];
                $key = strtoupper($actor['form_id']) . '|' . strtolower($actor['origin_plugin']) . '|' . $actor['playthrough_id'];
                if ((!isset($seen[$key]) && $key !== $playerKey) || isset($stateSeen[$key])) {
                    synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.actor_states', 'issue' => 'identity_mismatch']]);
                }
                $stateSeen[$key] = true;
                $payload['actor_states'][$index] = $state;
            }
        }
        if (array_key_exists('world', $payload)) {
            $payload['world'] = synth_protocol_validate_world_state($payload['world'], 'payload.world', $version);
        }
        foreach (['nearby_items','points_of_interest'] as $collection) {
            $field=$collection.'_observation';
            if (array_key_exists($field,$payload) &&
                (!in_array($payload[$field],['complete','partial','cached','unavailable'],true) ||
                 ($payload[$field]==='unavailable' && $payload[$collection]!==[]))) {
                synth_protocol_fail('invalid_payload',422,[['field'=>'payload.'.$field,'issue'=>'invalid']]);
            }
        }
        $questSeen = [];
        if (array_key_exists('active_quests_observation',$payload) &&
            (!in_array($payload['active_quests_observation'],['complete','partial','cached','unavailable'],true) ||
             ($payload['active_quests_observation']==='unavailable' && $payload['active_quests']!==[]))) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload.active_quests_observation','issue'=>'invalid']]);
        }
        foreach ($payload['active_quests'] as $index => $quest) {
            $quest = synth_protocol_validate_quest_state($quest, 'payload.active_quests.' . $index, $version);
            $key = strtoupper($quest['form_id']) . '|' . strtolower($quest['origin_plugin']);
            if (isset($questSeen[$key])) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.active_quests', 'issue' => 'duplicate_identity']]);
            }
            $questSeen[$key] = true;
            $payload['active_quests'][$index] = $quest;
        }
        $referenceSeen = [];
        foreach ($payload['nearby_items'] as $index => $item) {
            $item = synth_protocol_validate_nearby_item($item, 'payload.nearby_items.' . $index);
            $key = strtoupper($item['reference_id']);
            if (isset($referenceSeen[$key])) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.nearby_items', 'issue' => 'duplicate_reference']]);
            }
            $referenceSeen[$key] = true;
            $payload['nearby_items'][$index] = $item;
        }
        foreach ($payload['points_of_interest'] as $index => $poi) {
            $poi = synth_protocol_validate_point_of_interest($poi, 'payload.points_of_interest.' . $index);
            $key = strtoupper($poi['reference_id']);
            if (isset($referenceSeen[$key])) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.points_of_interest', 'issue' => 'duplicate_reference']]);
            }
            $referenceSeen[$key] = true;
            $payload['points_of_interest'][$index] = $poi;
        }
    } elseif ($type === 'activate') {
        synth_protocol_expect_exact_keys($payload, ['actor', 'source'], [], 'payload');
        $payload['actor'] = synth_protocol_validate_identity($payload['actor'] ?? null, 'payload.actor');
        if (!in_array($payload['source'] ?? null, ['manual', 'automatic'], true)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.source', 'issue' => 'invalid']]);
        }
    } elseif ($type === 'trigger') {
        synth_protocol_expect_exact_keys($payload, ['kind', 'actor'],
            $version === 2 ? ['game_time_ticks', 'instruction', 'text', 'capture_id', 'previous_context_sequence', 'event_id', 'reply_to_request_id', 'reply_to_line_id', 'scene_context_sequence'] : ['game_time_ticks', 'instruction', 'text'], 'payload');
        $payload['actor'] = synth_protocol_validate_identity($payload['actor'] ?? null, 'payload.actor');
        if (!in_array($payload['kind'] ?? null, [
            'auto_greeting', 'bored', 'combat_bark', 'rechat',
            'external_comment', 'external_reaction', 'external_tts', ...($version === 2 ? ['quest_updated','player_reaction'] : []),
        ], true)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.kind', 'issue' => 'invalid']]);
        }
        if (array_key_exists('game_time_ticks', $payload) &&
            (!is_int($payload['game_time_ticks']) || $payload['game_time_ticks'] < 1 ||
             $payload['game_time_ticks'] > 9007199254740991)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.game_time_ticks', 'issue' => 'invalid']]);
        }
        if (($payload['kind'] ?? null) === 'auto_greeting' && !array_key_exists('game_time_ticks', $payload)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.game_time_ticks', 'issue' => 'required']]);
        }
        foreach (['instruction', 'text'] as $field) {
            if (array_key_exists($field, $payload)
                && (!is_string($payload[$field]) || trim($payload[$field]) === '' || strlen($payload[$field]) > 1000)) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.' . $field, 'issue' => 'invalid']]);
            }
        }
        $kind = strval($payload['kind'] ?? '');
        if (array_key_exists('scene_context_sequence',$payload)
            && ($kind!=='rechat' || !isset($payload['reply_to_request_id'],$payload['reply_to_line_id'])
                || !is_int($payload['scene_context_sequence']) || $payload['scene_context_sequence']<1
                || $payload['scene_context_sequence']>9007199254740991)) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload.scene_context_sequence','issue'=>'invalid']]);
        }
        if (array_key_exists('reply_to_request_id',$payload) || array_key_exists('reply_to_line_id',$payload)) {
            foreach (['reply_to_request_id','reply_to_line_id'] as $field) {
                if ($kind!=='rechat' || !is_string($payload[$field] ?? null)
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$payload[$field])) {
                    synth_protocol_fail('invalid_payload',422,[['field'=>'payload.'.$field,'issue'=>'invalid_rechat_parent']]);
                }
            }
        }
        if ($kind === 'player_reaction'
            ? (!is_string($payload['event_id'] ?? null) || !preg_match('/^player:[1-9][0-9]{0,15}$/D',$payload['event_id'])
                || intval(substr($payload['event_id'],7))>9007199254740991 || array_key_exists('game_time_ticks',$payload))
            : array_key_exists('event_id',$payload)) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload.event_id','issue'=>'invalid']]);
        }
        if ($kind === 'quest_updated'
            ? (!is_int($payload['previous_context_sequence'] ?? null) || $payload['previous_context_sequence'] < 1
                || $payload['previous_context_sequence'] > 9007199254740991)
            : array_key_exists('previous_context_sequence', $payload)) {
            synth_protocol_fail('invalid_payload', 422, [['field'=>'payload.previous_context_sequence','issue'=>'invalid']]);
        }
        if (array_key_exists('capture_id', $payload) && ($kind !== 'external_reaction'
            || !is_string($payload['capture_id'])
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $payload['capture_id']))) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.capture_id', 'issue' => 'invalid']]);
        }
        if ($kind === 'external_reaction' && !array_key_exists('instruction', $payload)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.instruction', 'issue' => 'required']]);
        }
        if ($kind === 'external_tts' && !array_key_exists('text', $payload)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.text', 'issue' => 'required']]);
        }
        if (($kind === 'external_reaction' && array_key_exists('text', $payload))
            || ($kind === 'external_tts' && array_key_exists('instruction', $payload))
            || (!in_array($kind, ['external_reaction', 'external_tts'], true)
                && (array_key_exists('instruction', $payload) || array_key_exists('text', $payload)))) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload', 'issue' => 'unexpected_external_text']]);
        }
    } elseif ($type === 'diary_request') {
        synth_protocol_expect_exact_keys($payload,['actors','include_player','include_narrator','reason'],[],'payload');
        if ($version!==2 || !is_array($payload['actors'] ?? null) || !array_is_list($payload['actors'])
            || count($payload['actors'])>16 || !is_bool($payload['include_player'] ?? null)
            || !is_bool($payload['include_narrator'] ?? null) || !in_array($payload['reason'] ?? '',['manual','sleep','wait'],true)
            || (!$payload['actors'] && !$payload['include_narrator'] && !$payload['include_player'])) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload','issue'=>'character_diary_required']]);
        }
        $seen=[];
        foreach ($payload['actors'] as $index=>$actor) {
            $actor=synth_protocol_validate_identity($actor,'payload.actors.'.$index);
            $key=strtoupper($actor['form_id']).'|'.strtolower($actor['origin_plugin']).'|'.$actor['playthrough_id'];
            if (isset($seen[$key])) synth_protocol_fail('invalid_payload',422,[['field'=>'payload.actors','issue'=>'duplicate_identity']]);
            $seen[$key]=true;$payload['actors'][$index]=$actor;
        }
    } elseif ($type === 'profile_refresh') {
        synth_protocol_expect_exact_keys($payload, ['actors', 'include_narrator'], [], 'payload');
        if (!is_array($payload['actors'] ?? null) || !array_is_list($payload['actors']) ||
            count($payload['actors']) > 16 || !is_bool($payload['include_narrator'] ?? null)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload', 'issue' => 'invalid']]);
        }
        $seen = [];
        foreach ($payload['actors'] as $index => $actor) {
            $actor = synth_protocol_validate_identity($actor, 'payload.actors.' . $index);
            $key = strtoupper($actor['form_id']) . '|' . strtolower($actor['origin_plugin']) . '|' .
                $actor['playthrough_id'];
            if (isset($seen[$key])) {
                synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.actors', 'issue' => 'duplicate_identity']]);
            }
            $seen[$key] = true;
            $payload['actors'][$index] = $actor;
        }
    } elseif ($type === 'action_result') {
        synth_protocol_expect_exact_keys($payload, ['result'], [], 'payload');
        $payload['result'] = synth_protocol_validate_action_result($payload['result'] ?? null, 'payload.result', $version);
    } elseif ($type === 'cancel') {
        synth_protocol_expect_exact_keys($payload, ['cancel_request_id'], [], 'payload');
        if (!synth_protocol_valid_id($payload['cancel_request_id'] ?? null)) {
            synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.cancel_request_id', 'issue' => 'invalid']]);
        }
    } elseif ($type === 'halt') {
        synth_protocol_expect_exact_keys($payload, [], [], 'payload');
    }

    return $payload;
}

function synth_protocol_validate_event(array $event): array
{
    $required = [
        'schema', 'protocol_version', 'type', 'session_id', 'request_id', 'turn_id', 'generation', 'game',
        'runtime_variant', 'client_version', 'runtime_version', 'capabilities', 'payload',
    ];
    $version = $event['protocol_version'] ?? null;
    if ($version === 2) $required[] = 'context_sequence';
    synth_protocol_expect_exact_keys($event, $required, [], 'event');

    $types = ['init', 'input_text', 'input_audio', 'context', 'activate', 'trigger', 'profile_refresh', 'diary_request', 'action_result', 'cancel', 'halt'];
    if ($version === 2) $types[] = 'control';
    $valid = in_array($version, [1, 2], true)
        && $event['schema'] === 'synth.event.v' . $version
        && in_array($event['type'], $types, true)
        && synth_protocol_valid_id($event['session_id'])
        && synth_protocol_valid_id($event['request_id'])
        && synth_protocol_valid_id($event['turn_id'])
        && is_int($event['generation'])
        && $event['generation'] >= 1
        && $event['generation'] <= 9007199254740991
        && $event['game'] === 'fo4'
        && in_array($event['runtime_variant'], ['flat', 'vr'], true)
        && is_string($event['client_version'])
        && strlen($event['client_version']) >= 1
        && strlen($event['client_version']) <= 64
        && is_string($event['runtime_version'])
        && strlen($event['runtime_version']) >= 1
        && strlen($event['runtime_version']) <= 64;
    if (!$valid) {
        synth_protocol_fail('invalid_envelope', 422, [['field' => 'event', 'issue' => 'invalid']]);
    }
    if ($version === 2) {
        $bound = in_array($event['type'], ['input_text', 'input_audio', 'activate', 'trigger', 'profile_refresh', 'diary_request', 'action_result'], true);
        $sequence = $event['context_sequence'];
        if (!is_int($sequence) || $sequence > 9007199254740991
            || ($bound ? $sequence < 1 : $sequence !== 0)) {
            synth_protocol_fail('invalid_context_binding', 422, [['field' => 'context_sequence', 'issue' => 'invalid']]);
        }
    }
    if (!is_array($event['capabilities']) || !array_is_list($event['capabilities']) || count($event['capabilities']) > 128) {
        synth_protocol_fail('invalid_capabilities', 422, [['field' => 'event.capabilities', 'issue' => 'invalid']]);
    }
    $seen = [];
    foreach ($event['capabilities'] as $capability) {
        if (!is_string($capability)
            || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $capability) !== 1
            || isset($seen[$capability])) {
            synth_protocol_fail('invalid_capabilities', 422, [['field' => 'event.capabilities', 'issue' => 'invalid']]);
        }
        $seen[$capability] = true;
    }
    if (isset($seen['action.consume_inventory']) && ($version !== 2 || $event['runtime_variant'] !== 'flat'
        || !isset($seen['action.consume'], $seen['action.inventory_observation'], $seen['dialogue.turn_ownership'])))
        synth_protocol_fail('invalid_consumption_capability', 422);
    if (isset($seen['action.transfer_inventory']) && ($version !== 2 || $event['runtime_variant'] !== 'flat'
        || !isset($seen['action.give_item_to'], $seen['action.inventory_observation'], $seen['dialogue.turn_ownership'])))
        synth_protocol_fail('invalid_transfer_capability', 422);
    if (isset($seen['action.caps_inventory']) && ($version !== 2 || $event['runtime_variant'] !== 'flat'
        || !isset($seen['action.transfer_inventory'], $seen['action.give_caps_to'], $seen['action.give_item_to'],
            $seen['action.inventory_observation'], $seen['dialogue.turn_ownership'])))
        synth_protocol_fail('invalid_caps_capability', 422);
    if (isset($seen['action.player_caps_inventory']) && ($version !== 2 || $event['runtime_variant'] !== 'flat'
        || !isset($seen['action.take_caps_from_player'], $seen['action.caps_inventory'], $seen['action.give_caps_to'],
            $seen['action.transfer_inventory'], $seen['action.give_item_to'], $seen['action.inventory_observation'], $seen['dialogue.turn_ownership'])))
        synth_protocol_fail('invalid_player_caps_capability', 422);
    $event['payload'] = synth_protocol_validate_payload($event['type'], $event['payload'], $event['protocol_version']);
    if ($event['type'] === 'action_result' && in_array($event['payload']['result']['schema'] ?? '',
        ['synth.action.result.v2', 'synth.action.transfer-result.v2'], true)) {
        if ($event['protocol_version'] !== 2 || intval($event['context_sequence'] ?? 0) < 1 || !in_array('action.inventory_observation', $event['capabilities'], true)
            || !in_array('dialogue.turn_ownership', $event['capabilities'], true))
            synth_protocol_fail('invalid_action_inventory_capability', 422);
        $transfer = $event['payload']['result']['schema'] === 'synth.action.transfer-result.v2';
        if ($transfer && !isset($seen['action.transfer_inventory'])) synth_protocol_fail('invalid_transfer_capability', 422);
        foreach ($transfer ? ['inventory', 'recipient_inventory'] : ['inventory'] as $key)
            if (count($event['payload']['result'][$key]['items']) > 32 && ($event['runtime_variant'] !== 'flat'
                || !isset($seen['context.inventory_512']))) synth_protocol_fail('invalid_action_inventory_limit', 422);
    }
    $ownedRechat=in_array('dialogue.rechat.ownership',$event['capabilities'],true);
    $sceneRechat=in_array('dialogue.rechat.scene',$event['capabilities'],true);
    if ($event['type']==='context' && array_key_exists('scene',$event['payload']['world'] ?? []) && !$sceneRechat) {
        synth_protocol_fail('invalid_payload',422,[['field'=>'payload.world.scene','issue'=>'scene_capability_required']]);
    }
    if ($sceneRechat && ($event['protocol_version']!==2 || !$ownedRechat)) {
        synth_protocol_fail('invalid_payload',422,[['field'=>'capabilities','issue'=>'rechat_scene_owner_required']]);
    }
    if ($event['protocol_version']===2 && $ownedRechat && !in_array('dialogue.turn_ownership',$event['capabilities'],true)) {
        synth_protocol_fail('invalid_payload',422,[['field'=>'capabilities','issue'=>'rechat_turn_owner_required']]);
    }
    if ($event['type']==='trigger' && $event['payload']['kind']==='rechat') {
        $hasScene=array_key_exists('scene_context_sequence',$event['payload']);
        if ($hasScene!==$sceneRechat || ($hasScene && $event['payload']['scene_context_sequence']<=$event['context_sequence'])) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload.scene_context_sequence','issue'=>'fresh_scene_required']]);
        }
        $hasParent=isset($event['payload']['reply_to_request_id'],$event['payload']['reply_to_line_id']);
        if ($hasParent!==($event['protocol_version']===2 && $ownedRechat)
            || ($hasParent && (intval($event['context_sequence'] ?? 0)<1
                || $event['payload']['reply_to_request_id']===$event['request_id']))) {
            synth_protocol_fail('invalid_payload',422,[['field'=>'payload','issue'=>'owned_rechat_parent_required']]);
        }
    }
    if ($event['type'] === 'context') foreach ($event['payload']['actor_states'] ?? [] as $index => $state) {
        if (count($state['inventory']) > 32 && ($event['protocol_version'] !== 2 || $event['runtime_variant'] !== 'flat'
            || !in_array('context.inventory_512', $event['capabilities'], true))) {
            synth_protocol_fail('invalid_actor_state', 422,
                [['field'=>'payload.actor_states.'.$index.'.inventory','issue'=>'flat_inventory_512_required']]);
        }
    }
    if ($event['type']==='trigger' && $event['payload']['kind']==='player_reaction'
        && ($event['protocol_version']!==2 || $event['runtime_variant']!=='flat'
            || !in_array('context.player_events',$event['capabilities'],true)
            || !in_array('dialogue.player_reactions',$event['capabilities'],true)))
        synth_protocol_fail('invalid_payload',422,[['field'=>'capabilities','issue'=>'owned_flat_player_reaction_required']]);
    if ($event['type']==='trigger' && $event['payload']['kind']==='quest_updated'
        && ($event['protocol_version']!==2 || $event['runtime_variant']!=='flat'
            || !in_array('dialogue.quest_reactions',$event['capabilities'],true)
            || !in_array('context.quest_tracking',$event['capabilities'],true)
            || $event['payload']['previous_context_sequence'] >= $event['context_sequence'])) {
        synth_protocol_fail('invalid_payload',422,[['field'=>'payload','issue'=>'owned_flat_quest_reaction_required']]);
    }
    if ($event['type']==='diary_request' &&
        (($event['payload']['reason']!=='manual' && !in_array('diary.automatic.'.$event['payload']['reason'],$event['capabilities'],true))
        || ($event['payload']['actors'] && !in_array('diary.manual.npc',$event['capabilities'],true))
        || ($event['payload']['include_narrator'] && !in_array('diary.manual.narrator',$event['capabilities'],true))
        || ($event['payload']['include_player'] && !in_array('diary.manual.player',$event['capabilities'],true)))) {
        synth_protocol_fail('invalid_payload',422,[['field'=>'capabilities','issue'=>'diary_capability_required']]);
    }
    if ($event['type'] === 'context' && array_key_exists('loaded_context', $event['payload'])
        && !in_array('context.saved_anchor', $event['capabilities'], true)) {
        synth_protocol_fail('invalid_payload', 422, [['field'=>'payload.loaded_context','issue'=>'capability_required']]);
    }
    if ($event['type']==='context' && array_key_exists('actor_events',$event['payload'])
        && ($event['protocol_version']!==2 || $event['runtime_variant']!=='flat'
            || !in_array('context.actor_events',$event['capabilities'],true)))
        synth_protocol_fail('invalid_actor_events',422,[['field'=>'capabilities','issue'=>'flat_actor_events_required']]);
    if ($event['type']==='context' && array_key_exists('player_event',$event['payload'])
        && ($event['protocol_version']!==2 || $event['runtime_variant']!=='flat'
            || !in_array('context.player_events',$event['capabilities'],true)))
        synth_protocol_fail('invalid_player_event',422,[['field'=>'capabilities','issue'=>'flat_player_events_required']]);
    if ($event['type']==='context' && array_key_exists('quest_events',$event['payload'])
        && ($event['protocol_version']!==2 || $event['runtime_variant']!=='flat'
            || !in_array('context.quest_events',$event['capabilities'],true)))
        synth_protocol_fail('invalid_quest_events',422,[['field'=>'capabilities','issue'=>'flat_quest_events_required']]);
    if ($event['type']==='context') foreach ($event['payload']['active_quests'] as $quest) {
        if (array_key_exists('tracked',$quest) && ($event['protocol_version']!==2 || $event['runtime_variant']!=='flat'
            || !in_array('context.quest_tracking',$event['capabilities'],true))) {
            synth_protocol_fail('invalid_quest_state',422,[['field'=>'capabilities','issue'=>'flat_quest_tracking_required']]);
        }
    }
    if ($event['type'] === 'control' || (is_array($event['payload']) && isset($event['payload']['control']))) {
        require_once __DIR__.'/synth_control.php';
        if ($version !== 2 || !in_array('control.menu', $event['capabilities'], true))
            synth_protocol_fail('control_capability_required', 422);
        if (isset($event['payload']['control'])) {
            $control = $event['payload']['control'];
            synth_protocol_expect_exact_keys($control, ['mode', 'model_slot'], [], 'payload.control');
            if (!synth_control_valid_change('mode', $control['mode']) ||
                !is_int($control['model_slot']) || $control['model_slot'] < 1 || $control['model_slot'] > 4)
                synth_protocol_fail('invalid_control', 422);
        }
    }
    return $event;
}

function synth_protocol_sort_keys($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('synth_protocol_sort_keys', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) {
        $value[$key] = synth_protocol_sort_keys($child);
    }
    return $value;
}

function synth_protocol_canonical_json(array $event): string
{
    $encoded = json_encode(
        synth_protocol_sort_keys($event),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    return is_string($encoded) ? $encoded : '';
}

function synth_protocol_internal_type(string $type): string
{
    return [
        'input_text' => 'inputtext',
        'input_audio' => 'input_audio',
        'action_result' => 'funcret',
        'context' => 'context',
        'activate' => 'activate',
        'trigger' => 'trigger',
        'profile_refresh' => 'updateprofiles_batch_async',
        'cancel' => 'cancel',
        'halt' => 'halt',
    ][$type] ?? $type;
}

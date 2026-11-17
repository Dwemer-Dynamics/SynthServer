<?php
// Location catalog export (v2 event `location_catalog`): the strict ingress check and the per-record review
// of what the client read from the game's BGSLocation records. docs/LOCATION-CATALOG.md owns the design.
//
// This phase stores nothing. The request is validated, reviewed and its counts are logged; the storage
// (core_location and its siblings) and the prompt use arrive with the schema redesign. A request is
// idempotent by construction until then: it writes no row, so it takes no request claim.

const SYNTH_LOCATION_CATALOG_BATCH_LIMIT = 50;
const SYNTH_LOCATION_CATALOG_MARKER_LIMIT = 32;
const SYNTH_LOCATION_CATALOG_KEYWORD_LIMIT = 64;
const SYNTH_LOCATION_CATALOG_COORDINATE_LIMIT = 10000000;
const SYNTH_LOCATION_CATALOG_MARKER_TYPES = ['location_center', 'inside_entrance', 'outside_entrance', 'boss_treasure',
    'map_marker', 'world_marker', 'workshop'];

// Granted to flat and VR alike: the fields are identical and the client decides what it can read.
function synth_location_catalog_supported(array $event): bool
{
    return ($event['protocol_version'] ?? null) === 2 && in_array('world.location_catalog', $event['capabilities'] ?? [], true);
}

function synth_location_catalog_issue(string $field, string $issue): void
{
    synth_protocol_fail('invalid_payload', 422, [['field' => $field, 'issue' => $issue]]);
}

function synth_location_catalog_exact(array $value, array $required, array $optional, string $field): void
{
    if (array_is_list($value) && $value !== []) synth_location_catalog_issue($field, 'object_required');
    $allowed = array_fill_keys(array_merge($required, $optional), true);
    foreach ($required as $key) if (!array_key_exists($key, $value)) synth_location_catalog_issue($field.'.'.$key, 'required');
    foreach (array_keys($value) as $key) if (!isset($allowed[$key])) synth_location_catalog_issue($field.'.'.strval($key), 'unknown');
}

// A plugin-local form: the plugin that defines it and 24 local bits as 0x plus six uppercase hex digits, nonzero.
// Runtime-created forms have no plugin-local identity and never match.
function synth_location_catalog_ref($value, string $field): array
{
    if (!is_array($value)) synth_location_catalog_issue($field, 'object_required');
    synth_location_catalog_exact($value, ['origin_plugin', 'local_form_id'], [], $field);
    $plugin = $value['origin_plugin'];
    $local = $value['local_form_id'];
    if (!is_string($plugin) || strlen($plugin) < 1 || strlen($plugin) > 255 || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $plugin) !== 1
        || !is_string($local) || preg_match('/^0x[0-9A-F]{6}$/D', $local) !== 1 || $local === '0x000000')
        synth_location_catalog_issue($field, 'invalid_reference');
    return $value;
}

function synth_location_catalog_ref_key(array $ref): string
{
    return strtolower($ref['origin_plugin']).'|'.$ref['local_form_id'];
}

function synth_location_catalog_text($value, int $maxBytes, bool $required, string $field): string
{
    if (!is_string($value) || strlen($value) > $maxBytes || !mb_check_encoding($value, 'UTF-8')
        || ($required && trim($value) === '')) synth_location_catalog_issue($field, 'invalid_text');
    return $value;
}

function synth_location_catalog_point($value, string $field): void
{
    if (!is_array($value)) synth_location_catalog_issue($field, 'object_required');
    synth_location_catalog_exact($value, ['x', 'y', 'z'], [], $field);
    foreach (['x', 'y', 'z'] as $axis) {
        $n = $value[$axis];
        if ((!is_int($n) && !is_float($n)) || !is_finite((float)$n) || abs((float)$n) > SYNTH_LOCATION_CATALOG_COORDINATE_LIMIT)
            synth_location_catalog_issue($field.'.'.$axis, 'invalid_coordinate');
    }
}

function synth_location_catalog_worldspace($value, string $field): void
{
    if (!is_array($value)) synth_location_catalog_issue($field, 'object_required');
    synth_location_catalog_exact($value, ['ref', 'uses_parent_map'], ['editor_id', 'name', 'parent_ref'], $field);
    synth_location_catalog_ref($value['ref'], $field.'.ref');
    if (!is_bool($value['uses_parent_map'])) synth_location_catalog_issue($field.'.uses_parent_map', 'boolean_required');
    if (array_key_exists('editor_id', $value) && (!is_string($value['editor_id']) || preg_match('/^[A-Za-z0-9_]{1,128}$/D', $value['editor_id']) !== 1))
        synth_location_catalog_issue($field.'.editor_id', 'invalid_editor_id');
    if (array_key_exists('name', $value)) synth_location_catalog_text($value['name'], 255, false, $field.'.name');
    if (array_key_exists('parent_ref', $value) && $value['parent_ref'] !== null) synth_location_catalog_ref($value['parent_ref'], $field.'.parent_ref');
}

function synth_location_catalog_marker($value, string $field): void
{
    if (!is_array($value)) synth_location_catalog_issue($field, 'object_required');
    synth_location_catalog_exact($value, ['type', 'ref', 'interior', 'position', 'disabled'], ['cell_ref', 'worldspace'], $field);
    if (!in_array($value['type'], SYNTH_LOCATION_CATALOG_MARKER_TYPES, true)) synth_location_catalog_issue($field.'.type', 'invalid_marker_type');
    synth_location_catalog_ref($value['ref'], $field.'.ref');
    if (!is_bool($value['interior']) || !is_bool($value['disabled'])) synth_location_catalog_issue($field, 'boolean_required');
    if (array_key_exists('cell_ref', $value)) synth_location_catalog_ref($value['cell_ref'], $field.'.cell_ref');
    // An interior has no worldspace and an exterior always has one; the position is in that space (or the cell).
    if ($value['interior'] === array_key_exists('worldspace', $value)) synth_location_catalog_issue($field.'.worldspace', 'interior_exterior_mismatch');
    if (array_key_exists('worldspace', $value)) synth_location_catalog_worldspace($value['worldspace'], $field.'.worldspace');
    synth_location_catalog_point($value['position'], $field.'.position');
}

function synth_location_catalog_location($value, string $field): array
{
    if (!is_array($value)) synth_location_catalog_issue($field, 'object_required');
    synth_location_catalog_exact($value, ['ref', 'name', 'keywords', 'markers'], ['editor_id', 'parent_ref', 'cleared', 'discovered'], $field);
    synth_location_catalog_ref($value['ref'], $field.'.ref');
    synth_location_catalog_text($value['name'], 255, true, $field.'.name');
    if (array_key_exists('editor_id', $value) && (!is_string($value['editor_id']) || preg_match('/^[A-Za-z0-9_]{1,128}$/D', $value['editor_id']) !== 1))
        synth_location_catalog_issue($field.'.editor_id', 'invalid_editor_id');
    if (array_key_exists('parent_ref', $value) && $value['parent_ref'] !== null) synth_location_catalog_ref($value['parent_ref'], $field.'.parent_ref');
    foreach (['cleared', 'discovered'] as $flag)
        if (array_key_exists($flag, $value) && !is_bool($value[$flag])) synth_location_catalog_issue($field.'.'.$flag, 'boolean_required');
    $keywords = $value['keywords'];
    if (!is_array($keywords) || !array_is_list($keywords) || count($keywords) > SYNTH_LOCATION_CATALOG_KEYWORD_LIMIT)
        synth_location_catalog_issue($field.'.keywords', 'invalid_list');
    $seen = [];
    foreach ($keywords as $keyword) {
        if (!is_string($keyword) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $keyword) !== 1 || isset($seen[$keyword]))
            synth_location_catalog_issue($field.'.keywords', 'invalid_keyword');
        $seen[$keyword] = true;
    }
    $markers = $value['markers'];
    if (!is_array($markers) || !array_is_list($markers) || count($markers) > SYNTH_LOCATION_CATALOG_MARKER_LIMIT)
        synth_location_catalog_issue($field.'.markers', 'invalid_list');
    foreach ($markers as $index => $marker) synth_location_catalog_marker($marker, $field.'.markers.'.$index);
    return $value;
}

// Shape and trust-boundary check for the whole payload. Any violation refuses the request (422).
function synth_location_catalog_validate_payload(array $payload): array
{
    if (array_is_list($payload)) synth_location_catalog_issue('payload', 'object_required');
    $mode = $payload['mode'] ?? null;
    if ($mode === 'batch') synth_location_catalog_exact($payload, ['mode', 'playthrough_id', 'locations', 'export_id', 'sequence', 'final'], [], 'payload');
    elseif ($mode === 'upsert') synth_location_catalog_exact($payload, ['mode', 'playthrough_id', 'locations'], [], 'payload');
    else synth_location_catalog_issue('payload.mode', 'invalid');
    if (!synth_protocol_valid_id($payload['playthrough_id'])) synth_location_catalog_issue('payload.playthrough_id', 'invalid');
    if ($mode === 'batch') {
        if (!synth_protocol_valid_id($payload['export_id']) || !is_int($payload['sequence']) || $payload['sequence'] < 0
            || $payload['sequence'] > 9007199254740991 || !is_bool($payload['final'])) synth_location_catalog_issue('payload', 'invalid_batch');
    }
    $locations = $payload['locations'];
    $limit = $mode === 'batch' ? SYNTH_LOCATION_CATALOG_BATCH_LIMIT : 1;
    if (!is_array($locations) || !array_is_list($locations) || count($locations) < 1 || count($locations) > $limit)
        synth_location_catalog_issue('payload.locations', 'invalid_count');
    foreach ($locations as $index => $location) synth_location_catalog_location($location, 'payload.locations.'.$index);
    return $payload;
}

// Semantic review of a shape-valid payload: per-record refusals that do not stop the other records. The same
// reasons will govern storage, so the acknowledgement's meaning does not change when storage arrives.
//   duplicate_ref  the location appears earlier in this request
//   self_parent    the record names itself as its parent
//   duplicate_marker  the same marker type and reference is listed twice
function synth_location_catalog_review(array $payload): array
{
    $seen = [];
    $refused = [];
    $markers = 0;
    foreach ($payload['locations'] as $index => $location) {
        $key = synth_location_catalog_ref_key($location['ref']);
        $reason = null;
        if (isset($seen[$key])) $reason = 'duplicate_ref';
        elseif (isset($location['parent_ref']) && synth_location_catalog_ref_key($location['parent_ref']) === $key) $reason = 'self_parent';
        else {
            $markerKeys = [];
            foreach ($location['markers'] as $marker) {
                $markerKey = $marker['type'].'|'.synth_location_catalog_ref_key($marker['ref']);
                if (isset($markerKeys[$markerKey])) { $reason = 'duplicate_marker'; break; }
                $markerKeys[$markerKey] = true;
            }
        }
        $seen[$key] = true;
        if ($reason !== null) { $refused[] = ['index' => $index, 'reason' => $reason]; continue; }
        $markers += count($location['markers']);
    }
    return ['received' => count($payload['locations']), 'valid' => count($payload['locations']) - count($refused),
        'markers' => $markers, 'refused' => $refused];
}

// The acknowledgement detail (a status string of at most 1024 bytes): counts and up to sixteen refusals.
function synth_location_catalog_detail(array $review): string
{
    $review['refused'] = array_slice($review['refused'], 0, 16);
    $review['stored'] = false;
    return json_encode($review, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

// One log line of counts and identifiers only: no names, positions or keywords.
function synth_location_catalog_log(array $event, array $review): void
{
    $payload = $event['payload'];
    Logger::info('[location_catalog] '.json_encode([
        'mode' => $payload['mode'], 'runtime' => $event['runtime_variant'], 'playthrough' => $payload['playthrough_id'],
        'export' => $payload['export_id'] ?? null, 'sequence' => $payload['sequence'] ?? null, 'final' => $payload['final'] ?? null,
        'received' => $review['received'], 'valid' => $review['valid'], 'refused' => count($review['refused']),
        'markers' => $review['markers'], 'stored' => false,
    ], JSON_UNESCAPED_SLASHES));
}

<?php
declare(strict_types=1);

namespace Synth\Managers\LocationManager;

// LocationCatalog: ingress validation and review of the `location_catalog` v2 event (a batch export or a
// first-entry upsert of Commonwealth locations). docs/LOCATION-CATALOG.md owns the design.
//
// This phase stores nothing. A request is validated, reviewed and its counts logged; storage (core_location and
// its siblings) arrives with the schema redesign, behind a system entity, not here. Until then a request writes no
// row, so it takes no request claim and replays freely.

final class LocationCatalog
{
    public const BATCH_LIMIT = 50;
    public const MARKER_LIMIT = 32;
    public const KEYWORD_LIMIT = 64;
    public const COORDINATE_LIMIT = 10000000;
    public const MARKER_TYPES = ['location_center', 'inside_entrance', 'outside_entrance', 'boss_treasure',
        'map_marker', 'world_marker', 'workshop'];

    // Granted to flat and VR alike: the fields are identical and the client decides what it can read.
    public static function isSupported(array $event): bool
    {
        return ($event['protocol_version'] ?? null) === 2 && in_array('world.location_catalog', $event['capabilities'] ?? [], true);
    }

    // Refuses the request with one issue (422); never echoes request data.
    private static function issue(string $field, string $issue): void
    {
        \synth_protocol_fail('invalid_payload', 422, [['field' => $field, 'issue' => $issue]]);
    }

    // Requires exactly the required keys and allows only the optional ones.
    private static function exact(array $value, array $required, array $optional, string $field): void
    {
        if (array_is_list($value) && $value !== []) self::issue($field, 'object_required');
        $allowed = array_fill_keys(array_merge($required, $optional), true);
        foreach ($required as $key) if (!array_key_exists($key, $value)) self::issue($field.'.'.$key, 'required');
        foreach (array_keys($value) as $key) if (!isset($allowed[$key])) self::issue($field.'.'.strval($key), 'unknown');
    }

    // A plugin-local form: the plugin that defines it and 24 local bits as 0x plus six uppercase hex digits, nonzero.
    // Runtime-created forms have no plugin-local identity and never match.
    private static function ref($value, string $field): array
    {
        if (!is_array($value)) self::issue($field, 'object_required');
        self::exact($value, ['origin_plugin', 'local_form_id'], [], $field);
        $plugin = $value['origin_plugin'];
        $local = $value['local_form_id'];
        if (!is_string($plugin) || strlen($plugin) < 1 || strlen($plugin) > 255 || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $plugin) !== 1
            || !is_string($local) || preg_match('/^0x[0-9A-F]{6}$/D', $local) !== 1 || $local === '0x000000')
            self::issue($field, 'invalid_reference');
        return $value;
    }

    // The case-folded plugin plus local FormID that identifies a form inside one request.
    private static function refKey(array $ref): string
    {
        return strtolower($ref['origin_plugin']).'|'.$ref['local_form_id'];
    }

    // Checks a bounded UTF-8 string, optionally non-blank, and returns it.
    private static function text($value, int $maxBytes, bool $required, string $field): string
    {
        if (!is_string($value) || strlen($value) > $maxBytes || !mb_check_encoding($value, 'UTF-8')
            || ($required && trim($value) === '')) self::issue($field, 'invalid_text');
        return $value;
    }

    // Checks an {x,y,z} position of finite numbers within the coordinate bound.
    private static function point($value, string $field): void
    {
        if (!is_array($value)) self::issue($field, 'object_required');
        self::exact($value, ['x', 'y', 'z'], [], $field);
        foreach (['x', 'y', 'z'] as $axis) {
            $n = $value[$axis];
            if ((!is_int($n) && !is_float($n)) || !is_finite((float)$n) || abs((float)$n) > self::COORDINATE_LIMIT)
                self::issue($field.'.'.$axis, 'invalid_coordinate');
        }
    }

    // Checks the worldspace object embedded in an exterior marker.
    private static function worldspace($value, string $field): void
    {
        if (!is_array($value)) self::issue($field, 'object_required');
        self::exact($value, ['ref', 'uses_parent_map'], ['editor_id', 'name', 'parent_ref'], $field);
        self::ref($value['ref'], $field.'.ref');
        if (!is_bool($value['uses_parent_map'])) self::issue($field.'.uses_parent_map', 'boolean_required');
        if (array_key_exists('editor_id', $value) && (!is_string($value['editor_id']) || preg_match('/^[A-Za-z0-9_]{1,128}$/D', $value['editor_id']) !== 1))
            self::issue($field.'.editor_id', 'invalid_editor_id');
        if (array_key_exists('name', $value)) self::text($value['name'], 255, false, $field.'.name');
        if (array_key_exists('parent_ref', $value) && $value['parent_ref'] !== null) self::ref($value['parent_ref'], $field.'.parent_ref');
    }

    // Checks one marker, including the interior/exterior worldspace rule.
    private static function marker($value, string $field): void
    {
        if (!is_array($value)) self::issue($field, 'object_required');
        self::exact($value, ['type', 'ref', 'interior', 'position', 'disabled'], ['cell_ref', 'worldspace'], $field);
        if (!in_array($value['type'], self::MARKER_TYPES, true)) self::issue($field.'.type', 'invalid_marker_type');
        self::ref($value['ref'], $field.'.ref');
        if (!is_bool($value['interior']) || !is_bool($value['disabled'])) self::issue($field, 'boolean_required');
        if (array_key_exists('cell_ref', $value)) self::ref($value['cell_ref'], $field.'.cell_ref');
        // An interior has no worldspace and an exterior always has one; the position is in that space (or the cell).
        if ($value['interior'] === array_key_exists('worldspace', $value)) self::issue($field.'.worldspace', 'interior_exterior_mismatch');
        if (array_key_exists('worldspace', $value)) self::worldspace($value['worldspace'], $field.'.worldspace');
        self::point($value['position'], $field.'.position');
    }

    // Checks one location record and its keywords and markers.
    private static function location($value, string $field): array
    {
        if (!is_array($value)) self::issue($field, 'object_required');
        self::exact($value, ['ref', 'name', 'keywords', 'markers'], ['editor_id', 'parent_ref', 'cleared', 'discovered'], $field);
        self::ref($value['ref'], $field.'.ref');
        self::text($value['name'], 255, true, $field.'.name');
        if (array_key_exists('editor_id', $value) && (!is_string($value['editor_id']) || preg_match('/^[A-Za-z0-9_]{1,128}$/D', $value['editor_id']) !== 1))
            self::issue($field.'.editor_id', 'invalid_editor_id');
        if (array_key_exists('parent_ref', $value) && $value['parent_ref'] !== null) self::ref($value['parent_ref'], $field.'.parent_ref');
        foreach (['cleared', 'discovered'] as $flag)
            if (array_key_exists($flag, $value) && !is_bool($value[$flag])) self::issue($field.'.'.$flag, 'boolean_required');
        $keywords = $value['keywords'];
        if (!is_array($keywords) || !array_is_list($keywords) || count($keywords) > self::KEYWORD_LIMIT)
            self::issue($field.'.keywords', 'invalid_list');
        $seen = [];
        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $keyword) !== 1 || isset($seen[$keyword]))
                self::issue($field.'.keywords', 'invalid_keyword');
            $seen[$keyword] = true;
        }
        $markers = $value['markers'];
        if (!is_array($markers) || !array_is_list($markers) || count($markers) > self::MARKER_LIMIT)
            self::issue($field.'.markers', 'invalid_list');
        foreach ($markers as $index => $marker) self::marker($marker, $field.'.markers.'.$index);
        return $value;
    }

    // Shape and trust-boundary check for the whole payload. Any violation refuses the request (422).
    public static function validatePayload(array $payload): array
    {
        if (array_is_list($payload)) self::issue('payload', 'object_required');
        $mode = $payload['mode'] ?? null;
        if ($mode === 'batch') self::exact($payload, ['mode', 'playthrough_id', 'locations', 'export_id', 'sequence', 'final'], [], 'payload');
        elseif ($mode === 'upsert') self::exact($payload, ['mode', 'playthrough_id', 'locations'], [], 'payload');
        else self::issue('payload.mode', 'invalid');
        if (!\synth_protocol_valid_id($payload['playthrough_id'])) self::issue('payload.playthrough_id', 'invalid');
        if ($mode === 'batch') {
            if (!\synth_protocol_valid_id($payload['export_id']) || !is_int($payload['sequence']) || $payload['sequence'] < 0
                || $payload['sequence'] > 9007199254740991 || !is_bool($payload['final'])) self::issue('payload', 'invalid_batch');
        }
        $locations = $payload['locations'];
        $limit = $mode === 'batch' ? self::BATCH_LIMIT : 1;
        if (!is_array($locations) || !array_is_list($locations) || count($locations) < 1 || count($locations) > $limit)
            self::issue('payload.locations', 'invalid_count');
        foreach ($locations as $index => $location) self::location($location, 'payload.locations.'.$index);
        return $payload;
    }

    // Semantic review of a shape-valid payload: per-record refusals that do not stop the other records. The same
    // reasons will govern storage, so the acknowledgement's meaning does not change when storage arrives.
    //   duplicate_ref  the location appears earlier in this request
    //   self_parent    the record names itself as its parent
    //   duplicate_marker  the same marker type and reference is listed twice
    public static function review(array $payload): array
    {
        $seen = [];
        $refused = [];
        $markers = 0;
        foreach ($payload['locations'] as $index => $location) {
            $key = self::refKey($location['ref']);
            $reason = null;
            if (isset($seen[$key])) $reason = 'duplicate_ref';
            elseif (isset($location['parent_ref']) && self::refKey($location['parent_ref']) === $key) $reason = 'self_parent';
            else {
                $markerKeys = [];
                foreach ($location['markers'] as $marker) {
                    $markerKey = $marker['type'].'|'.self::refKey($marker['ref']);
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
    public static function detail(array $review): string
    {
        $review['refused'] = array_slice($review['refused'], 0, 16);
        $review['stored'] = false;
        return json_encode($review, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // One log line of counts and identifiers only: no names, positions or keywords.
    public static function logRequest(array $event, array $review): void
    {
        $payload = $event['payload'];
        \Logger::info('[location_catalog] '.json_encode([
            'mode' => $payload['mode'], 'runtime' => $event['runtime_variant'], 'playthrough' => $payload['playthrough_id'],
            'export' => $payload['export_id'] ?? null, 'sequence' => $payload['sequence'] ?? null, 'final' => $payload['final'] ?? null,
            'received' => $review['received'], 'valid' => $review['valid'], 'refused' => count($review['refused']),
            'markers' => $review['markers'], 'stored' => false,
        ], JSON_UNESCAPED_SLASHES));
    }
}

<?php
// Plugin-local location ancestry (payload.world.location_ancestry): the strict ingress check and a
// fail-closed reader for prompt integration. It describes where the captured scene was; it writes
// nothing and grants no location knowledge to any NPC.

require_once __DIR__.'/synth_social_contract.php';

const SYNTH_LOCATION_ANCESTRY_LIMIT = 8;

function synth_location_ancestry_supported(array $event): bool
{
    return ($event['protocol_version'] ?? null) === 2 && ($event['runtime_variant'] ?? '') === 'flat'
        && count(array_diff(['context.location_identity', 'context.location_ancestry'], $event['capabilities'] ?? [])) === 0;
}

// Null when the ancestry is the exact plugin-local chain of $locationRef, otherwise the issue code.
function synth_location_ancestry_issue($ancestry, $locationRef): ?string
{
    if (!is_array($ancestry) || array_is_list($ancestry) || count($ancestry) !== 2
        || !array_key_exists('end', $ancestry) || !array_key_exists('chain', $ancestry)) return 'invalid_shape';
    if (!synth_social_ref_valid($locationRef)) return 'location_ref_required';
    $chain = $ancestry['chain'];
    if (!in_array($ancestry['end'], ['root', 'unresolved', 'cycle', 'depth_limit'], true) || !is_array($chain)
        || !array_is_list($chain) || count($chain) < 1 || count($chain) > SYNTH_LOCATION_ANCESTRY_LIMIT) return 'invalid_chain';
    // Reaching a root or unresolved parent on the last allowed entry still preserves that end.
    if ($ancestry['end'] === 'depth_limit' && count($chain) !== SYNTH_LOCATION_ANCESTRY_LIMIT) return 'invalid_end';
    $seen = [];
    foreach ($chain as $entry) {
        if (!is_array($entry) || array_is_list($entry) || count($entry) !== 3
            || array_diff(['origin_plugin', 'local_form_id', 'display_name'], array_keys($entry)) !== []) return 'invalid_entry';
        $plugin = $entry['origin_plugin'];
        $local = $entry['local_form_id'];
        $name = $entry['display_name'];
        if (!is_string($plugin) || strlen($plugin) > 255 || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $plugin) !== 1
            || !is_string($local) || preg_match('/^0x[0-9A-F]{6}$/D', $local) !== 1 || $local === '0x000000'
            || !is_string($name) || strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8')) return 'invalid_entry';
        $key = strtolower($plugin).'|'.$local;
        if (isset($seen[$key])) return 'repeated_location';
        $seen[$key] = true;
    }
    // The first entry is the same form as the runtime reference: a light FE slot keeps 12 local bits,
    // a full slot 24. Runtime-created (FF) forms have no plugin-local identity.
    $runtime = intval(hexdec(substr($locationRef['form_id'], 2)));
    $prefix = $runtime >> 24;
    $expected = $prefix === 0xFE ? $runtime & 0xFFF : $runtime & 0xFFFFFF;
    if ($prefix === 0xFF || $chain[0]['origin_plugin'] !== $locationRef['origin_plugin']
        || intval(hexdec(substr($chain[0]['local_form_id'], 2))) !== $expected) return 'location_ref_mismatch';
    return null;
}

function synth_location_validate_ancestry($ancestry, $locationRef, string $field): array
{
    $issue = synth_location_ancestry_issue($ancestry, $locationRef);
    if ($issue !== null) synth_protocol_fail('invalid_world_state', 422, [['field' => $field, 'issue' => $issue]]);
    return $ancestry;
}

// Validated ancestry facts from a context's world object, or null. Stored snapshots are re-checked so
// a caller never relies on an older or unvalidated shape. Identity is (plugin, local FormID): equal
// display names from different plugins stay distinct, and nothing past the last entry is inferred.
function synth_location_ancestry_facts($world): ?array
{
    if (!is_array($world) || !array_key_exists('location_ancestry', $world)
        || synth_location_ancestry_issue($world['location_ancestry'], $world['location_ref'] ?? null) !== null) return null;
    $entries = [];
    foreach ($world['location_ancestry']['chain'] as $entry) {
        $entries[] = ['key' => strtolower($entry['origin_plugin']).'|'.$entry['local_form_id'],
            'origin_plugin' => $entry['origin_plugin'], 'local_form_id' => $entry['local_form_id'],
            'display_name' => trim($entry['display_name'])];
    }
    return ['end' => $world['location_ancestry']['end'], 'complete' => $world['location_ancestry']['end'] === 'root',
        'interior' => ($world['interior'] ?? null) === true, 'cell' => trim(strval($world['cell'] ?? '')),
        'worldspace' => trim(strval($world['worldspace'] ?? '')), 'entries' => $entries];
}

// One bounded prompt sentence for the scene's current location, or '' when no valid named ancestry exists.
function synth_location_prompt_line($world): string
{
    $facts = synth_location_ancestry_facts($world);
    if ($facts === null) return '';
    $keysByName = [];
    foreach ($facts['entries'] as $entry) {
        if ($entry['display_name'] !== '') $keysByName[mb_strtolower($entry['display_name'])][$entry['key']] = true;
    }
    $names = [];
    foreach ($facts['entries'] as $entry) {
        if ($entry['display_name'] === '') continue;
        $shared = count($keysByName[mb_strtolower($entry['display_name'])]) > 1;
        $names[] = $entry['display_name'].($shared ? ' ('.$entry['origin_plugin'].')' : '');
    }
    if ($names === []) return '';
    $line = 'Current location: '.implode(', within ', $names);
    if ($facts['interior']) $line .= $facts['cell'] !== '' ? ' (interior: '.$facts['cell'].')' : ' (interior)';
    elseif ($facts['worldspace'] !== '') $line .= ' (worldspace: '.$facts['worldspace'].')';
    $line .= '.';
    if (!$facts['complete']) $line .= ' Where '.end($names).' sits beyond this is not known.';
    return $line;
}

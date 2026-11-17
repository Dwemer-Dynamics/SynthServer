<?php

namespace Synth\Managers\Narrator;

// Talking-object identity rules (docs/TALKING-OBJECTS.md): the trust-boundary shape of one object identity and the
// extra capability grant that object payloads need. Pure validation; it touches no storage.
final class NarratorObjectIdentity
{
    public const CAPABILITY = 'narrator.object.v1';

    // Validates one identity: a plugin reference by local ID, or a runtime (FF) reference with a null plugin. The base
    // always comes from a plugin. Every field is exact, upper-case and load-order free. Fails the request when invalid.
    public static function validate(mixed $value): array
    {
        $invalid = static fn() => \synth_protocol_fail('invalid_payload', 422, [['field' => 'payload.object', 'issue' => 'narrator_object_invalid']]);
        if (!is_array($value) || array_is_list($value)) $invalid();
        \synth_protocol_expect_exact_keys($value, ['ref_plugin', 'ref_id', 'base_plugin', 'base_id'], [], 'payload.object');
        $runtime = $value['ref_plugin'] === null;
        if (!is_string($value['ref_id']) || !is_string($value['base_id']) || (!$runtime && !self::plugin($value['ref_plugin']))
            || !self::plugin($value['base_plugin'])
            || preg_match($runtime ? '/^0xFF[0-9A-F]{6}$/D' : '/^0x00(?!000000)[0-9A-F]{6}$/D', $value['ref_id']) !== 1
            || preg_match('/^0x00(?!000000)[0-9A-F]{6}$/D', $value['base_id']) !== 1) $invalid();
        return $value;
    }

    // True when the event is a narrator request that uses talking objects but did not negotiate the grant.
    public static function grantMissing(array $event): bool
    {
        $payload = $event['payload'] ?? null;
        if (!str_starts_with($event['type'] ?? '', 'narrator_') || !is_array($payload)
            || in_array(self::CAPABILITY, $event['capabilities'] ?? [], true)) return false;
        $action = $payload['action'] ?? '';
        return array_key_exists('object', $payload) || $action === 'bind_object'
            || ($action === 'transcript' && array_key_exists('definition_id', $payload));
    }

    // A loaded plugin file name: bounded, printable, with a plugin extension (any letter case).
    private static function plugin(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 255 && preg_match('/^[^\/\\\\\x00-\x1F\x7F]+\.(esm|esp|esl)$/Di', $name) === 1;
    }
}

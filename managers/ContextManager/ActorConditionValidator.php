<?php declare(strict_types=1);

namespace Synth\Managers\ContextManager;

// Strict validation of the optional v2 `actor_states[].actor_condition` wire object (capability
// `context.actor_condition`). Called from the protocol ingress hook; it stores nothing and never trusts a name.
final class ActorConditionValidator
{
    public const LIMBS = ['head', 'torso', 'left_arm', 'right_arm', 'left_leg', 'right_leg'];
    public const LIST_MAX = 8;
    public const NAME_CHARS = 64;

    // True when the event is v2 and offered the capability.
    public static function supported(array $event): bool
    {
        return ($event['protocol_version'] ?? 1) === 2 && in_array('context.actor_condition', $event['capabilities'] ?? [], true);
    }

    // Returns the value unchanged when it matches the contract; any violation rejects the request (422).
    public static function validate($value, string $field): array
    {
        $fail = static fn(string $path, string $issue) => \synth_protocol_fail('invalid_actor_condition', 422, [['field' => $path, 'issue' => $issue]]);
        if (!\synth_protocol_is_object($value)) $fail($field, 'object_required');
        $observation = $value['observation'] ?? null;
        if ($observation === 'unavailable') {
            \synth_protocol_expect_exact_keys($value, ['observation'], [], $field);
            return $value;
        }
        if ($observation !== 'observed') $fail($field . '.observation', 'invalid');
        \synth_protocol_expect_exact_keys($value, ['observation', 'radiation_percent', 'addictions', 'effects', 'crippled_limbs', 'truncated'], [], $field);
        $radiation = $value['radiation_percent'];
        if ($radiation !== null && ((!is_int($radiation) && !is_float($radiation)) || !is_finite((float) $radiation) || $radiation < 0 || $radiation > 100))
            $fail($field . '.radiation_percent', 'percent_or_null_required');
        if (!is_bool($value['truncated'])) $fail($field . '.truncated', 'boolean_required');
        foreach (['addictions' => false, 'effects' => true] as $list => $withKind) self::validateEntries($value[$list], $field . '.' . $list, $withKind, $fail);
        $limbs = $value['crippled_limbs'];
        if (!is_array($limbs) || !array_is_list($limbs) || count($limbs) > count(self::LIMBS)
            || count($limbs) !== count(array_unique($limbs, SORT_REGULAR))) $fail($field . '.crippled_limbs', 'unique_bounded_list_required');
        foreach ($limbs as $limb) if (!in_array($limb, self::LIMBS, true)) $fail($field . '.crippled_limbs', 'invalid_limb');
        return $value;
    }

    // One bounded list of {form_id, origin_plugin, name[, kind]} entries.
    private static function validateEntries($entries, string $field, bool $withKind, callable $fail): void
    {
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::LIST_MAX) $fail($field, 'bounded_list_required');
        foreach ($entries as $index => $entry) {
            $path = $field . '.' . $index;
            if (!\synth_protocol_is_object($entry)) $fail($path, 'object_required');
            \synth_protocol_expect_exact_keys($entry, $withKind ? ['form_id', 'origin_plugin', 'name', 'kind'] : ['form_id', 'origin_plugin', 'name'], [], $path);
            if (!is_string($entry['form_id']) || preg_match('/^0x[0-9A-Fa-f]{8}$/D', $entry['form_id']) !== 1) $fail($path . '.form_id', 'invalid');
            if (!is_string($entry['origin_plugin']) || strlen($entry['origin_plugin']) > 255
                || preg_match('#^[^/\\\\]+\.(esm|esp|esl)$#Di', $entry['origin_plugin']) !== 1) $fail($path . '.origin_plugin', 'invalid');
            if (!is_string($entry['name']) || $entry['name'] === '' || mb_strlen($entry['name']) > self::NAME_CHARS) $fail($path . '.name', 'bounded_string_required');
            if ($withKind && !in_array($entry['kind'], ['chem', 'effect'], true)) $fail($path . '.kind', 'invalid');
        }
    }
}

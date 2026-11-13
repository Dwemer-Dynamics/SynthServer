<?php declare(strict_types=1);

/**
 * Project the player's and the responder's HP/AP observation from this turn's
 * own actor_states for a provider situation block. Pure: it matches only the
 * exact runtime FormID/plugin/playthrough, never a display name, base ID,
 * profile or stored value. A missing, ambiguous or unattested row is unknown.
 */
function synth_condition_prompt(array $payload, array $player, array $responder): array
{
    $key = static function ($identity): ?string {
        if (!is_array($identity)) return null;
        $form = $identity['form_id'] ?? null;
        $plugin = $identity['origin_plugin'] ?? null;
        $playthrough = $identity['playthrough_id'] ?? null;
        if (!is_string($form) || preg_match('/^0x[0-9A-Fa-f]{8}$/D', $form) !== 1
            || strcasecmp($form, '0x00000000') === 0
            || !is_string($plugin) || strlen($plugin) > 255
            || preg_match('#^[^/\\\\|\x00-\x1f\x7f]+\.(esm|esp|esl)$#Di', $plugin) !== 1
            || !is_string($playthrough)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $playthrough) !== 1) return null;
        return strtoupper($form) . '|' . strtolower($plugin) . '|' . $playthrough;
    };
    $unknown = ['observation' => 'unknown', 'health_percent' => null, 'action_points_percent' => null];
    $states = $payload['actor_states'] ?? null;
    $turnPlaythrough = $payload['playthrough_id'] ?? null;
    // An oversized or foreign-playthrough payload is not trusted for any participant.
    $usable = is_array($states) && array_is_list($states) && count($states) <= 16
        && (!array_key_exists('playthrough_id', $payload) || is_string($turnPlaythrough));
    $project = static function (array $identity) use ($key, $unknown, $states, $usable, $turnPlaythrough): array {
        $wanted = $key($identity);
        if (!$usable || $wanted === null
            || ($turnPlaythrough !== null && $identity['playthrough_id'] !== $turnPlaythrough)) return $unknown;
        $match = null;
        foreach ($states as $state) {
            if (!is_array($state) || $key($state['actor'] ?? null) !== $wanted) continue;
            // Two rows for one actor cannot say which read is current.
            if ($match !== null) return $unknown;
            $match = $state;
        }
        if ($match === null) return $unknown;
        $result = [];
        foreach (['health_percent', 'action_points_percent'] as $field) {
            $value = $match[$field] ?? null;
            // A numeric placeholder is authoritative only with an explicit true flag; zero stays zero.
            $result[$field] = ($match[$field . '_available'] ?? null) === true
                && (is_int($value) || is_float($value)) && is_finite((float) $value)
                && $value >= 0 && $value <= 100 ? $value : null;
        }
        // A matched row that attests neither value observed nothing.
        if ($result['health_percent'] === null && $result['action_points_percent'] === null) return $unknown;
        return ['observation' => 'observed'] + $result;
    };
    return [
        'semantics' => [
            'unit' => "percent (0-100) of the actor's permanent maximum, not the current HUD bar",
            'sampling' => 'one snapshot taken with this turn; not live and not a trend',
            'null' => 'unknown this turn; do not guess',
            'not' => 'a cause, a disease or a guaranteed visible injury',
        ],
        'player' => $project($player),
        'responder' => $project($responder),
    ];
}

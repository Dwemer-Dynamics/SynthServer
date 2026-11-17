<?php declare(strict_types=1);

namespace Synth\Managers\ContextManager;

// Projects the captured actor condition (radiation, addictions, active chems/effects, crippled limbs, plus the
// long-standing low-health and power-armor fields) into ONE bounded prompt line for the player and the exact
// responder. Everything is read from the turn's own snapshot rows; every name is untrusted client text and is
// normalised, deduplicated and capped. Nothing is stored and no database table is involved.
final class ConditionProjector
{
    public const LINE_CHARS = 700;
    public const LOW_HEALTH_PERCENT = 25;
    private const LIMB_LABELS = ['head' => 'head', 'torso' => 'torso', 'left_arm' => 'left arm',
        'right_arm' => 'right arm', 'left_leg' => 'left leg', 'right_leg' => 'right leg'];

    // The player's and the responder's condition as one short line, or '' when nothing is noteworthy or the setting
    // is off. `$names` carries the display labels for 'player' and 'responder'.
    public static function line(array $event, array $payload, array $player, array $responder, array $names, bool $enabled): string
    {
        if (!$enabled) return '';
        $captured = ActorConditionValidator::supported($event);
        $parts = [];
        foreach (['player' => $player, 'responder' => $responder] as $role => $identity) {
            $clauses = self::clauses(self::state($payload, $identity), $captured);
            if ($clauses === []) continue;
            $label = self::label($names[$role] ?? '');
            $parts[] = ($label !== '' ? $label : ($role === 'player' ? 'Player' : 'Responder')) . ': ' . implode('; ', $clauses);
        }
        if ($parts === []) return '';
        $line = 'Condition at this moment (observed once, not live): ' . implode(' | ', $parts) . '.';
        return mb_strlen($line) > self::LINE_CHARS ? mb_substr($line, 0, self::LINE_CHARS - 1) . '…' : $line;
    }

    // Opt-out only: a stored general setting wins, then server configuration, and the default is on.
    public static function enabled($db, array $config = []): bool
    {
        $value = null;
        if ($db !== null) {
            try {
                require_once dirname(__DIR__, 2) . '/core/dbmanager/repositories/SettingsRepository.php';
                $value = (new \Synth\Core\SettingsRepository($db))->value('CONDITION_CONTEXT_ENABLED');
            } catch (\Throwable) { $value = null; }
        }
        $value ??= $config['CONDITION_CONTEXT_ENABLED'] ?? $GLOBALS['CONDITION_CONTEXT_ENABLED'] ?? true;
        return !in_array(is_string($value) ? strtolower(trim($value)) : $value, [false, 0, '0', 'false', 'off', 'no', ''], true);
    }

    // A client string becomes a short plain label: letters, digits and common name punctuation only.
    public static function label($value): string
    {
        $text = preg_replace('/[^\p{L}\p{N} \'()&+.,:\-]+/u', ' ', is_string($value) ? $value : '') ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, ActorConditionValidator::NAME_CHARS);
    }

    // Order-preserving, case-insensitive deduplication of entry names; empty labels are dropped.
    private static function labels(array $entries): array
    {
        $seen = [];
        foreach (array_slice($entries, 0, ActorConditionValidator::LIST_MAX) as $entry) {
            $label = self::label(is_array($entry) ? ($entry['name'] ?? '') : '');
            if ($label !== '') $seen[mb_strtolower($label)] ??= $label;
        }
        return array_values($seen);
    }

    // Radiation wording by tier; below ten percent nothing is said.
    private static function radiation($percent): ?string
    {
        if ((!is_int($percent) && !is_float($percent)) || !is_finite((float) $percent) || $percent < 10) return null;
        $tier = $percent >= 85 ? 'lethal-range radiation' : ($percent >= 60 ? 'heavy radiation' : ($percent >= 30 ? 'moderate radiation' : 'mild radiation'));
        return $tier . ' (' . (int) round($percent) . '%)';
    }

    // Exact FormID/plugin/playthrough match against this turn's own rows; a name never matches. Missing, foreign,
    // oversized or ambiguous rows yield null (unknown) rather than a guess.
    private static function state(array $payload, array $identity): ?array
    {
        $key = static function ($actor): ?string {
            if (!is_array($actor)) return null;
            $form = $actor['form_id'] ?? null;
            $plugin = $actor['origin_plugin'] ?? null;
            $playthrough = $actor['playthrough_id'] ?? null;
            if (!is_string($form) || preg_match('/^0x[0-9A-Fa-f]{8}$/D', $form) !== 1 || strcasecmp($form, '0x00000000') === 0
                || !is_string($plugin) || $plugin === '' || strlen($plugin) > 255 || !is_string($playthrough) || $playthrough === '') return null;
            return strtoupper($form) . '|' . strtolower($plugin) . '|' . $playthrough;
        };
        $states = $payload['actor_states'] ?? null;
        $wanted = $key($identity);
        if ($wanted === null || !is_array($states) || !array_is_list($states) || count($states) > 16) return null;
        $match = null;
        foreach ($states as $state) {
            if (!is_array($state) || $key($state['actor'] ?? null) !== $wanted) continue;
            if ($match !== null) return null;
            $match = $state;
        }
        return $match;
    }

    // One actor's clauses. Low health and power armor come from the long-standing state fields, so a client without
    // the capability still gets them; the rest needs the captured actor_condition. Observed zero or empty adds nothing.
    private static function clauses(?array $state, bool $captured): array
    {
        if ($state === null) return [];
        $clauses = [];
        $health = $state['health_percent'] ?? null;
        if (($state['health_percent_available'] ?? null) === true && (is_int($health) || is_float($health)) && is_finite((float) $health)
            && $health >= 0 && $health <= 100 && $health <= self::LOW_HEALTH_PERCENT) $clauses[] = 'low health (' . (int) round($health) . '%)';
        if (($state['in_power_armor'] ?? null) === true) $clauses[] = 'in power armor';
        $condition = $captured && is_array($state['actor_condition'] ?? null) ? $state['actor_condition'] : null;
        if ($condition === null || ($condition['observation'] ?? '') !== 'observed') return $clauses;
        $radiation = self::radiation($condition['radiation_percent'] ?? null);
        if ($radiation !== null) $clauses[] = $radiation;
        $addictions = self::labels(is_array($condition['addictions'] ?? null) ? $condition['addictions'] : []);
        if ($addictions !== []) $clauses[] = 'addicted: ' . implode(', ', $addictions);
        $effects = array_values(array_filter(is_array($condition['effects'] ?? null) ? $condition['effects'] : [], 'is_array'));
        $chems = self::labels(array_filter($effects, static fn($entry) => ($entry['kind'] ?? '') === 'chem'));
        $others = self::labels(array_filter($effects, static fn($entry) => ($entry['kind'] ?? '') !== 'chem'));
        // A name already listed as a chem is not repeated as another effect.
        $others = array_values(array_udiff($others, $chems, 'strcasecmp'));
        if ($chems !== []) $clauses[] = 'on chems: ' . implode(', ', $chems);
        if ($others !== []) $clauses[] = 'effects: ' . implode(', ', $others);
        $limbs = [];
        foreach (is_array($condition['crippled_limbs'] ?? null) ? $condition['crippled_limbs'] : [] as $limb)
            if (is_string($limb) && isset(self::LIMB_LABELS[$limb])) $limbs[self::LIMB_LABELS[$limb]] = true;
        if ($limbs !== []) $clauses[] = 'crippled: ' . implode(', ', array_keys($limbs));
        if (($condition['truncated'] ?? false) === true && $clauses !== []) $clauses[] = 'more may be unlisted';
        return $clauses;
    }
}

<?php

namespace Synth\Core;

require_once dirname(__DIR__, 2).'/synth_protocol_state.php';

// Stable placed-character key: playthrough + normalized origin plugin + plugin-local REFERENCE ID.
// Never the base NPC ID or display name. The runtime full form ID is only the current load-order binding.
final class CharacterKey
{
    // Regular plugins keep 6 hex digits, light (FE) plugins keep 3. Dynamic FF references have no stable
    // incarnation and are rejected.
    public static function localReference($formId): ?string
    {
        if (!is_string($formId) || preg_match('/^0x[0-9A-Fa-f]{8}$/D', $formId) !== 1) return null;
        $hex = synthNormalizeRuntimeFormId($formId);
        if (str_starts_with($hex, 'FF')) return null;
        return str_starts_with($hex, 'FE') ? substr($hex, 5) : substr($hex, 2);
    }

    public static function of(array $identity): ?string
    {
        $local = self::localReference($identity['form_id'] ?? null);
        $plugin = strtolower(trim(strval($identity['origin_plugin'] ?? '')));
        $playthrough = $identity['playthrough_id'] ?? null;
        if ($local === null || $plugin === '' || !synth_protocol_valid_id($playthrough)) return null;
        return 'chr:'.substr(hash('sha256', 'fo4|'.$playthrough.'|'.$plugin.'|'.$local), 0, 40);
    }

    public static function runtimeFormId(string $formId): string
    {
        return '0x'.synthNormalizeRuntimeFormId($formId);
    }
}

// One conversation request bound to its claimed context snapshot.
final class TurnRequest
{
    public function __construct(public readonly array $event, public readonly array $context) {}

    public function text(): string
    {
        return trim(strval($this->event['payload']['text']));
    }

    public function playerName(): string
    {
        return strval($this->context['player']['display_name'] ?? 'Player');
    }

    public function lineId(string $suffix): string
    {
        return $this->event['request_id'].':'.$suffix;
    }
}

// A registered placed NPC: stable key, current runtime binding and the profile that owns model and voice.
final class PlacedCharacter
{
    public function __construct(public readonly string $key, public readonly array $identity,
        public readonly int $identityId, public readonly array $npc, public readonly array $profile,
        public readonly array $connector) {}

    public function displayName(): string
    {
        return strval($this->identity['display_name']);
    }
}

// Admitted turn: request, character and the profile's explicit speech inputs.
final class AdmittedTurn
{
    public function __construct(public readonly TurnRequest $request, public readonly PlacedCharacter $character,
        public readonly array $speech) {}
}

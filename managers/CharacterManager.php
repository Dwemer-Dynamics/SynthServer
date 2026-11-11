<?php

namespace Synth\Managers;

use Synth\Core\CharacterKey;
use Synth\Core\CharacterRepository;
use Synth\Core\PlacedCharacter;
use Synth\Core\TurnRejected;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/contracts/dialogue.php';
require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once dirname(__DIR__).'/core/dbmanager/repositories/CharacterRepository.php';

// Resolves the addressed target to a registered placed NPC: stable placed key, current runtime binding
// and the profile that owns its model and voice.
final class CharacterManager
{
    public function __construct(private CharacterRepository $characters) {}

    public function resolve(TurnRequest $request): PlacedCharacter
    {
        $target = is_array($request->context['target'] ?? null) ? $request->context['target'] : null;
        if ($target === null || trim(strval($target['display_name'] ?? '')) === '') throw new TurnRejected('target_missing');
        $key = CharacterKey::of($target);
        if ($key === null) throw new TurnRejected('target_not_placed_reference');
        $row = $this->characters->registered(CharacterKey::runtimeFormId($target['form_id']),
            $target['origin_plugin'], $target['playthrough_id']);
        if ($row === null) throw new TurnRejected('npc_not_registered');
        // Storage is keyed by the runtime binding. Another identity for the same plugin-local reference means
        // the load order moved; continuity cannot be proven, so the turn is not admitted.
        $local = CharacterKey::localReference($target['form_id']);
        foreach ($this->characters->otherFormIds($target['origin_plugin'], $target['playthrough_id'], intval($row['identity_id'])) as $other) {
            if (CharacterKey::localReference($other) === $local) throw new TurnRejected('load_order_identity_ambiguous');
        }
        $npc = $this->characters->npc(intval($row['npc_id']));
        $profile = $this->characters->profile(intval($row['profile_id']));
        if ($npc === null || $profile === null) throw new TurnRejected('tts_profile_unsupported');
        return new PlacedCharacter($key, $target, intval($row['identity_id']), $npc, $profile, $row);
    }
}

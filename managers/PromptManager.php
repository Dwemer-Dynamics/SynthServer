<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;

require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once dirname(__DIR__).'/lib/synth_biography.php';

// Pure prompt assembly from the NPC profile, bounded context and the player's text.
final class PromptManager
{
    // Authored expertise is part of the background; skills are only the actor's captured SPECIAL values.
    private const PROFILE_FIELDS = ['prompt_head' => 'Prompt head', 'core' => 'Short description', 'npc_static_bio' => 'Background',
        'appearance' => 'Appearance', 'personality' => 'Personality', 'occupation' => 'Occupation', 'speechstyle' => 'Speechstyle',
        'goals' => 'Goals'];

    public function messages(PlacedCharacter $character, array $situation, array $history, string $text, string $skills = ''): array
    {
        $system = "You are {$character->displayName()} in Fallout 4. Return only the character's utterance, no narration, actions or markup. Respect nonverbal speech restrictions.";
        foreach (self::PROFILE_FIELDS as $field => $label) {
            $value = trim(strval($character->npc[$field] ?? ''));
            if ($field === 'npc_static_bio') $value = \synth_biography_background($value, strval($character->npc['skills'] ?? ''));
            if ($value !== '') $system .= "\n".$label.": ".mb_substr($value, 0, 4000);
        }
        if ($skills !== '') $system .= "\n".$skills;
        $situation = json_encode($situation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return [['role' => 'system', 'content' => $system."\nSituation: ".$situation], ...$history,
            ['role' => 'user', 'content' => $text]];
    }
}

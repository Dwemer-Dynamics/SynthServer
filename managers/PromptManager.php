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
        'appearance' => 'Appearance', 'personality' => 'Personality', 'occupation' => 'Occupation', 'speechstyle' => 'Speech style',
        'goals' => 'Goals'];

    // $knowledge is the turn's already rendered and bounded World Knowledge block, framed as in ordinary dialogue.
    // $questKnowledge is the already rendered, 8 KiB-bounded <quest_knowledge> block for this NPC.
    public function messages(PlacedCharacter $character, array $situation, array $history, string $text, string $skills = '',
        string $knowledge = '', string $questKnowledge = ''): array
    {
        $system = "You are {$character->displayName()} in Fallout 4. Reply in character with the character's utterance only, no narration, actions or markup. Respect nonverbal speech restrictions.";
        foreach (self::PROFILE_FIELDS as $field => $label) {
            $value = trim(strval($character->npc[$field] ?? ''));
            if ($field === 'npc_static_bio') $value = \synth_biography_background($value, strval($character->npc['skills'] ?? ''));
            if ($value !== '') $system .= "\n".$label.": ".mb_substr($value, 0, 4000);
        }
        if ($skills !== '') $system .= "\n".$skills;
        if ($knowledge !== '') $system .= "\n<fallout_context>\n<knowledge>\n".$knowledge."\n</knowledge>\n</fallout_context>";
        if ($questKnowledge !== '') $system .= "\n".$questKnowledge;
        $situation = json_encode($situation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return [['role' => 'system', 'content' => $system."\nSituation: ".$situation], ...$history,
            ['role' => 'user', 'content' => $text]];
    }
}

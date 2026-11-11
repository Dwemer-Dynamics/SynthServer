<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;

require_once dirname(__DIR__).'/core/dialogue/entities.php';

// Pure prompt assembly from the NPC profile, bounded context and the player's text.
final class PromptManager
{
    private const PROFILE_FIELDS = ['prompt_head', 'npc_static_bio', 'personality', 'occupation', 'speechstyle'];

    public function messages(PlacedCharacter $character, array $situation, array $history, string $text): array
    {
        $system = "You are {$character->displayName()} in Fallout 4. Reply in character with spoken dialogue only, no narration, actions or markup.";
        foreach (self::PROFILE_FIELDS as $field) {
            $value = trim(strval($character->npc[$field] ?? ''));
            if ($value !== '') $system .= "\n".ucfirst(str_replace('_', ' ', $field)).": ".mb_substr($value, 0, 4000);
        }
        $situation = json_encode($situation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return [['role' => 'system', 'content' => $system."\nSituation: ".$situation], ...$history,
            ['role' => 'user', 'content' => $text]];
    }
}

<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once __DIR__.'/SaveState.php';
require_once dirname(__DIR__).'/lib/synth_biography.php';
require_once dirname(__DIR__).'/lib/synth_condition_prompt.php';

// Bounded turn context: the snapshot's situation and recent heard conversation with this NPC.
final class ContextManager
{
    public const HISTORY_ROWS = 12;
    public const LINE_CHARS = 1000;

    public function __construct(private SaveState $saves) {}

    public function situation(TurnRequest $request): array
    {
        $payload = $request->context['payload'];
        $situation = ['player' => strval($request->context['player']['display_name'] ?? ''),
            'audience' => array_values(array_filter(array_map(static fn($actor) => is_array($actor)
                ? strval($actor['display_name'] ?? '') : '', $payload['audience'] ?? [])))];
        if (is_array($payload['world'] ?? null)) $situation['world'] = array_intersect_key($payload['world'], array_flip(['location', 'time', 'weather']));
        // Only this snapshot's rows for the exact player and responder; never a name match or an older value.
        $situation['condition'] = \synth_condition_prompt(is_array($payload) ? $payload : [],
            is_array($request->context['player'] ?? null) ? $request->context['player'] : [],
            is_array($request->context['target'] ?? null) ? $request->context['target'] : []);
        return $situation;
    }

    // The addressed actor's own SPECIAL values from this bound snapshot; never a name or stored profile.
    public function skills(TurnRequest $request, PlacedCharacter $character): string
    {
        return \synth_actor_stats_prompt($request->event, \synth_actor_stats_state($request->context, $character->identity));
    }

    // Oldest first, speaker prefix and talking-to suffix removed.
    public function history(TurnRequest $request, PlacedCharacter $character): array
    {
        $messages = [];
        foreach (array_reverse($this->saves->conversationRows($request->event, $character->displayName(), self::HISTORY_ROWS)) as $row) {
            $text = preg_replace('/\s+\((?:talking|Talking) to [^()]*\)$/u', '', strval($row['data']));
            $colon = strpos($text, ': ');
            $text = trim($colon === false ? $text : substr($text, $colon + 2));
            if ($text !== '') $messages[] = ['role' => $row['type'] === 'chat' ? 'assistant' : 'user',
                'content' => mb_substr($text, 0, self::LINE_CHARS)];
        }
        return $messages;
    }
}

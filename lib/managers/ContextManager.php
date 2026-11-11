<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;
use Synth\Core\SaveState;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/dialogue/entities.php';
require_once dirname(__DIR__).'/core/dialogue/SaveState.php';

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
        return $situation;
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

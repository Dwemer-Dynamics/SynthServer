<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once __DIR__.'/SaveState.php';

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

    // Oldest first. This NPC's own lines and player input addressed to it lose the speaker prefix and
    // talking-to suffix. Player input addressed to someone else, or without a readable addressee, keeps its
    // stored attribution so it is never presented as direct input to this NPC; its talking-to suffix, even one
    // with an unparseable name, leads the line so truncation of a long body cannot cut it off.
    public function history(TurnRequest $request, PlacedCharacter $character): array
    {
        $messages = [];
        foreach (array_reverse($this->saves->conversationRows($request->event, $character->displayName(), self::HISTORY_ROWS)) as $row) {
            $text = strval($row['data']);
            $suffix = preg_match('/\s+\((?:talking|Talking) to ([^()]*)\)$/u', $text, $match, PREG_OFFSET_CAPTURE) === 1;
            if ($row['type'] === 'chat' || ($suffix && $match[1][0] === $character->displayName())) {
                if ($suffix) $text = substr($text, 0, $match[0][1]);
                $colon = strpos($text, ': ');
                $text = $colon === false ? $text : substr($text, $colon + 2);
            } elseif (preg_match('/^(.*)\s+(\((?:talking|Talking) to .*\))$/su', $text, $tail) === 1) {
                $text = $tail[2].' '.$tail[1];
            }
            $text = trim($text);
            if ($text !== '') $messages[] = ['role' => $row['type'] === 'chat' ? 'assistant' : 'user',
                'content' => mb_substr($text, 0, self::LINE_CHARS)];
        }
        return $messages;
    }
}

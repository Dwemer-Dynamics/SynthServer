<?php

namespace Synth\Managers;

use Synth\Core\PlacedCharacter;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once __DIR__.'/SaveState.php';
require_once dirname(__DIR__).'/lib/synth_biography.php';
require_once dirname(__DIR__).'/lib/synth_location.php';
require_once dirname(__DIR__).'/lib/synth_condition_prompt.php';
require_once dirname(__DIR__).'/lib/quest_knowledge.php';
require_once dirname(__DIR__).'/lib/synth_notes.php';

// Bounded turn context: the snapshot's situation and recent heard conversation with this NPC.
final class ContextManager
{
    public const HISTORY_ROWS = 12;
    public const LINE_CHARS = 1000;

    // $db and $owner are the explicit claimed admission owner for the quest reader; without them it is unavailable.
    public function __construct(private SaveState $saves, private $db = null, private ?\SYNTHClaimedRequest $owner = null) {}

    public function situation(TurnRequest $request): array
    {
        $payload = $request->context['payload'];
        $situation = ['player' => strval($request->context['player']['display_name'] ?? ''),
            'audience' => array_values(array_filter(array_map(static fn($actor) => is_array($actor)
                ? strval($actor['display_name'] ?? '') : '', $payload['audience'] ?? [])))];
        if (is_array($payload['world'] ?? null)) $situation['world'] = array_intersect_key($payload['world'], array_flip(['location', 'time', 'weather']));
        $location = synth_location_prompt_line($payload['world'] ?? null);
        if ($location !== '') $situation['location'] = $location;
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

    // Oldest first. This NPC's own lines and player input addressed to it lose the speaker prefix and
    // talking-to suffix. Player input addressed to someone else, or without a readable addressee, keeps its
    // stored attribution so it is never presented as direct input to this NPC; its talking-to suffix, even one
    // with an unparseable name, leads the line so truncation of a long body cannot cut it off. Observed vanilla
    // lines (`backgroundchat`) are never player input and stay verbatim whatever their literal text ends with.
    public function history(TurnRequest $request, PlacedCharacter $character): array
    {
        $messages = [];
        foreach (array_reverse($this->saves->conversationRows($request->event, $character->displayName(), self::HISTORY_ROWS)) as $row) {
            $text = strval($row['data']);
            $suffix = preg_match('/\s+\((?:talking|Talking) to ([^()]*)\)$/u', $text, $match, PREG_OFFSET_CAPTURE) === 1;
            // Only this NPC's own delivered replies are assistant turns; witnessed lines by others stay attributed.
            $own = $row['type'] === 'chat' && intval($row['own'] ?? 1) === 1;
            if ($own || ($row['type'] === 'inputtext' && $suffix && $match[1][0] === $character->displayName())) {
                if ($suffix) $text = substr($text, 0, $match[0][1]);
                $colon = strpos($text, ': ');
                $text = $colon === false ? $text : substr($text, $colon + 2);
            } elseif (in_array($row['type'], ['inputtext', 'chat'], true)
                && preg_match('/^(.*)\s+(\((?:talking|Talking) to .*\))$/su', $text, $tail) === 1) {
                $text = $tail[2].' '.$tail[1];
            }
            $text = trim($text);
            if ($text !== '') $messages[] = ['role' => $own ? 'assistant' : 'user',
                'content' => mb_substr($text, 0, self::LINE_CHARS)];
        }
        return $messages;
    }

    // The existing locked quest reader for this exact claimed request and addressed NPC: attributed player reports
    // and authored participant background, never the player's journal. Native /talk modes are never the narrator.
    // A failed read is optional context and yields the unavailable view.
    public function questKnowledge(TurnRequest $request, PlacedCharacter $character): array
    {
        if ($this->db === null || $this->owner === null) return \synthQuestKnowledgeUnavailable();
        try {
            return \synthQuestKnowledgeView($this->db, $request->event, $request->context, $character->identity, false, $this->owner);
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable) { return \synthQuestKnowledgeUnavailable(); }
    }

    // Notes the player explicitly handed to this exact NPC in the request's saved ancestry, as the bounded quoted
    // <shared_notes> block. Independent of the quest view; the player's private reads never qualify. A failed read is
    // optional context and yields no block.
    public function noteKnowledge(TurnRequest $request, PlacedCharacter $character): string
    {
        if ($this->db === null || $this->owner === null || $this->owner->event !== $request->event) return '';
        try {
            if (!\synth_note_schema_ready($this->db)) return '';
            $identity = $character->identity;
            $db = $this->db;
            return \synth_note_prompt($this->db->withClaimedRequestLock($this->owner,
                static fn($connection, array $event): array => \synth_note_shared_with($db, $connection, $event, $identity), 2000));
        } catch (\SYNTHProtocolCancelled $error) { throw $error; }
        catch (\Throwable) { return ''; }
    }
}

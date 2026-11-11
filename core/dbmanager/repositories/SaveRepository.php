<?php

namespace Synth\Core;

require_once dirname(__DIR__, 3).'/lib/synth_protocol_state.php';

// Save-scoped reads over the shared native primitives: the claimed snapshot of the active generation and
// playthrough, and saved-ancestry history in which older and newer saves stay non-destructively visible.
class SaveRepository
{
    public function __construct(private $db) {}

    public function snapshot(array $event): ?array
    {
        return synth_protocol_original_context($this->db, $event);
    }

    /** Newest first: the player's own input to, and the given delivery states of, the named placed NPC. */
    public function conversationRows(array $event, string $targetName, array $deliveredStates, int $limit): array
    {
        $states = implode(',', array_map(fn($state) => $this->db->escapeLiteral(strval($state)), $deliveredStates));
        $rows = $this->db->fetchAll(synth_protocol_history_cte($this->db, $event, $targetName)
            ." SELECT type,data FROM native_history WHERE ((type='inputtext' AND speaker_identity_id=reader_player_id)
                OR (type='chat' AND speaker_identity_id=reader_identity_id AND delivery_state IN ($states)))
            ORDER BY history_event_id DESC LIMIT ".intval($limit));
        return is_array($rows) ? $rows : [];
    }
}

<?php

namespace Synth\Core;

require_once dirname(__DIR__, 2).'/synth_protocol_state.php';

// Save and session state over the shared native primitives: the claimed snapshot of the active generation
// and playthrough, and saved-ancestry history in which older/newer saves stay non-destructively visible.
class SaveState
{
    public function __construct(private $db) {}

    public function boundContext(array $event): ?array
    {
        return synth_protocol_original_context($this->db, $event);
    }

    // Newest first: real player input and only receipt-confirmed (spoken/presented) lines between this
    // player and this placed NPC.
    public function conversationRows(array $event, string $targetName, int $limit): array
    {
        $rows = $this->db->fetchAll(synth_protocol_history_cte($this->db, $event, $targetName)
            ." SELECT type,data FROM native_history WHERE ((type='inputtext' AND speaker_identity_id=reader_player_id)
                OR (type='chat' AND speaker_identity_id=reader_identity_id AND delivery_state IN ('spoken','presented')))
            ORDER BY history_event_id DESC LIMIT ".intval($limit));
        return is_array($rows) ? $rows : [];
    }
}

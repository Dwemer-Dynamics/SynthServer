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
        $context = synth_protocol_original_context($this->db, $event);
        if ($context !== null && ($event['type'] ?? '') === 'input_text'
            && ($event['payload']['control']['mode'] ?? '') === 'WHISPER')
            $context = synth_protocol_listener_private_context($context);
        return $context;
    }

    /**
     * Newest first: the player's own input, and chat lines in the given delivery states that the named placed
     * NPC already witnessed. `own` is 1 for that NPC's own replies and 0 for other speakers' attributed lines.
     * First-observed vanilla lines (`backgroundchat`) appear only through their canonical witness binding.
     */
    public function conversationRows(array $event, string $targetName, array $deliveredStates, int $limit): array
    {
        $states = implode(',', array_map(fn($state) => $this->db->escapeLiteral(strval($state)), $deliveredStates));
        $rows = $this->db->fetchAll(synth_protocol_history_cte($this->db, $event, $targetName)
            ." SELECT type,data,CASE WHEN speaker_identity_id=reader_identity_id THEN 1 ELSE 0 END AS own
            FROM native_history WHERE ((type='inputtext' AND speaker_identity_id=reader_player_id)
                OR (type='chat' AND speaker_identity_id IS NOT NULL AND speaker_identity_id<>reader_player_id
                    AND delivery_state IN ($states))
                OR (type='backgroundchat' AND witness_basis='first_observed' AND speaker_identity_id IS NOT NULL
                    AND speaker_identity_id<>reader_player_id))
            ORDER BY history_event_id DESC LIMIT ".intval($limit));
        return is_array($rows) ? $rows : [];
    }
}

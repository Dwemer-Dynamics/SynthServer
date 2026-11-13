<?php

namespace Synth\Core;

require_once dirname(__DIR__, 2).'/entities/dialogue.php';

// Turn and receipt persistence. History rows use the native insertion shape, so saved-ancestry scopes stay
// non-destructive; every write runs under the explicit claimed owner's lock and transaction.
class UtteranceRepository
{
    public function __construct(private $db, private \SYNTHClaimedRequest $owner) {}

    public function recordInput(TurnRequest $request, string $data): void
    {
        $this->owned($request);
        $this->db->insertClaimedHistory($this->owner, self::historyFields($request->context, 'inputtext', $data, null, 'received'));
    }

    public function recordLine(TurnRequest $request, PlacedCharacter $character, string $lineId, string $text): void
    {
        $event = $this->owned($request);
        $this->db->withClaimedRequestLock($this->owner, static function ($connection) use ($event, $character, $lineId, $text): void {
            $result = @pg_query_params($connection, "INSERT INTO synth_meta.dialogue_utterances
                (session_id,request_id,line_id,turn_id,generation,context_sequence,speaker_identity_id,character_id,text,text_sha256)
                VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)", [$event['session_id'], $event['request_id'], $lineId,
                $event['turn_id'], $event['generation'], $event['context_sequence'], $character->identityId,
                $character->key, $text, hash('sha256', $text)]);
            if (!$result) throw new \RuntimeException('Unable to record utterance');
        });
    }

    // One receipt transaction: lock the line, match its exact owner, keep the first terminal outcome and, for a
    // heard line, insert its history row. Repeats of the stored outcome are idempotent; others conflict.
    // The first write also attests delivery: the origin is the line's own conversation turn and was not revoked
    // (cancelled, superseded in flight, or a completed input whose delivery a later cancel revoked), and the line
    // has its exact emitted route. Generated text that was never emitted has no route. The origin need not be
    // the active turn: an emitted line of an unrevoked earlier turn may still be receipted.
    /** @param ?\Closure(string):string $heard history text for the stored line text; null when not heard */
    public function recordReceipt(TurnRequest $receipt, array $character, string $characterId, string $state, ?\Closure $heard): string
    {
        $event = $this->owned($receipt);
        $payload = $event['payload'];
        $context = $receipt->context;
        return $this->db->withClaimedRequestLock($this->owner, static function ($connection) use ($event, $payload, $character, $characterId, $state, $heard, $context): string {
            $result = @pg_query_params($connection, "SELECT u.state,u.character_id,u.text,u.speaker_identity_id,a.full_form_id,a.origin_plugin,a.playthrough_id,
                    (o.request_id IS NOT NULL AND op.request_id IS NOT NULL) AS origin_valid,rr.speaker AS route_speaker,
                    op.canonical_event#>>'{payload,control,mode}' AS origin_mode
                FROM synth_meta.dialogue_utterances u
                JOIN synth.actor_identities a ON a.id=u.speaker_identity_id
                JOIN synth_meta.runtime_sessions r ON r.session_id=u.session_id AND r.state='active' AND r.active_generation=u.generation
                LEFT JOIN synth_meta.request_executions o ON o.session_id=u.session_id AND o.request_id=u.request_id
                    AND o.generation=u.generation AND o.turn_id=u.turn_id AND o.event_type='input_text'
                    AND o.state IN ('claimed','streaming','complete','failed')
                    AND COALESCE(o.error_code,'') NOT IN ('turn_cancelled','request_cancelled','superseded_turn')
                LEFT JOIN synth_meta.protocol_events op ON op.session_id=o.session_id AND op.request_id=o.request_id
                    AND op.generation=o.generation AND op.turn_id=o.turn_id AND op.event_type=o.event_type
                    AND op.event_sha256=o.request_sha256
                LEFT JOIN synth_meta.response_routes rr ON rr.session_id=u.session_id AND rr.request_id=u.request_id
                    AND rr.line_id=u.line_id AND rr.utterance_id=u.line_id AND rr.generation=u.generation
                    AND rr.context_sequence=u.context_sequence
                WHERE u.session_id=$1 AND u.request_id=$2 AND u.line_id=$3 AND u.generation=$4 AND u.context_sequence=$5 AND u.turn_id=$6
                FOR UPDATE OF u", [$event['session_id'], $payload['dialogue_request_id'], $payload['line_id'], $event['generation'],
                $event['context_sequence'], $event['turn_id']]);
            if (!$result) throw new \RuntimeException('Unable to read utterance');
            $row = pg_fetch_assoc($result);
            // Exact wire identity: same placed reference key and the same runtime binding as the emitted speaker.
            if (!$row || !hash_equals($row['character_id'], $characterId)
                || strcasecmp($row['full_form_id'], $character['form_id']) !== 0
                || strcasecmp($row['origin_plugin'], $character['origin_plugin']) !== 0
                || $row['playthrough_id'] !== $character['playthrough_id']) throw new \DomainException('receipt_owner_mismatch');
            if ($row['state'] === $state) return $state;
            if ($row['state'] !== 'generated') throw new \DomainException('receipt_conflict');
            $route = $row['route_speaker'] === null ? null : json_decode($row['route_speaker'], true);
            if ($row['origin_valid'] !== 't' || !is_array($route)
                || strcasecmp(strval($route['form_id'] ?? ''), $character['form_id']) !== 0
                || strcasecmp(strval($route['origin_plugin'] ?? ''), $character['origin_plugin']) !== 0
                || ($route['playthrough_id'] ?? null) !== $character['playthrough_id']) throw new \DomainException('receipt_owner_mismatch');
            $update = @pg_query_params($connection, "UPDATE synth_meta.dialogue_utterances SET state=$4,receipt_request_id=$5,
                receipt_at=NOW() WHERE session_id=$1 AND request_id=$2 AND line_id=$3 AND state='generated'",
                [$event['session_id'], $payload['dialogue_request_id'], $payload['line_id'], $state, $event['request_id']]);
            if (!$update || pg_affected_rows($update) !== 1) throw new \RuntimeException('Unable to record receipt');
            // The receipt has no mode of its own. Use the proven original turn to keep Whisper history listener-private.
            if (($row['origin_mode'] ?? '') === 'WHISPER') $context = \synth_protocol_listener_private_context($context);
            if ($heard !== null) self::insertHeard($connection, $event, $payload['dialogue_request_id'],
                self::historyFields($context, 'chat', $heard($row['text']), $payload['line_id'], $state),
                ($row['origin_mode'] ?? '') === 'WHISPER' ? intval($row['speaker_identity_id']) : null);
            return $state;
        });
    }

    // A repository writes only for the request it was claimed for.
    private function owned(TurnRequest $request): array
    {
        if ($request->event !== $this->owner->event) throw new \LogicException('Native request owner mismatch');
        return $this->owner->event;
    }

    // Same native insertion as sql::insert, but provenance is the original dialogue request (whose generation,
    // context and turn the receipt owner already matched) rather than the receipt event. The history_events id
    // is allocated now, inside the receipt transaction, so receipt time is the insertion frontier.
    private static function insertHeard($connection, array $event, string $dialogueRequestId, array $data, ?int $privateListener = null): void
    {
        $data['synth_session_id'] = $event['session_id'];
        $data['synth_generation'] = $event['generation'];
        $data['synth_context_sequence'] = $event['context_sequence'];
        $data['synth_request_id'] = $dialogueRequestId;
        $columns = array_keys($data);
        $values = implode(',', array_map(static fn($i) => '$'.($i + 1), array_keys($columns)));
        $snapshot = @pg_query_params($connection, 'SELECT playthrough_id,player_identity_id FROM synth_meta.context_snapshots
            WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3', [$event['session_id'], $event['generation'], $event['context_sequence']]);
        $snapshot = $snapshot ? pg_fetch_assoc($snapshot) : false;
        if (!$snapshot) throw new \RuntimeException('Native history context unavailable');
        $scope = $privateListener === null ? null : json_encode(
            [intval($snapshot['player_identity_id']), $privateListener], JSON_THROW_ON_ERROR);
        $result = @pg_query_params($connection, 'WITH inserted AS (INSERT INTO synth.eventlog ('.implode(',', $columns).') VALUES ('.$values.') RETURNING *)
            INSERT INTO synth_meta.history_events
                (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload,scene_context_sequence)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$'.(count($columns) + 1).',rowid,
                CASE WHEN $'.(count($columns) + 2).'::jsonb IS NULL THEN to_jsonb(inserted)
                    ELSE to_jsonb(inserted)||jsonb_build_object(\'synth_dialogue_hearer_ids\',$'.(count($columns) + 2).'::jsonb) END,NULL
            FROM inserted RETURNING id', [...array_values($data), $snapshot['playthrough_id'], $scope]);
        if (!$result || pg_num_rows($result) !== 1) throw new \RuntimeException('Unable to persist heard history');
    }

    public static function historyFields(array $context, string $type, string $data, ?string $utterance, string $state): array
    {
        $world = is_array($context['payload']['world'] ?? null) ? $context['payload']['world'] : [];
        $ticks = $world['game_time_ticks'] ?? 0;
        $location = $world['location'] ?? '';
        return ['type' => $type, 'data' => $data, 'sess' => 'pending', 'gamets' => is_int($ticks) ? $ticks : 0,
            'localts' => time(), 'ts' => time(), 'people' => self::people($context),
            'location' => is_scalar($location) ? strval($location) : '', 'party' => '',
            'utterance_id' => $utterance, 'delivery_state' => $state];
    }

    // Visibility tokens for the existing insertion-time history ownership rules.
    private static function people(array $context): string
    {
        $payload = $context['payload'] ?? [];
        $names = [strval($payload['target']['display_name'] ?? ''), strval($payload['player']['display_name'] ?? '')];
        foreach ($payload['audience'] ?? [] as $actor) if (is_array($actor)) $names[] = strval($actor['display_name'] ?? '');
        return '|'.implode('|', array_unique(array_filter(array_map('trim', $names)))).'|';
    }
}

<?php

namespace Synth\Core;

require_once dirname(__DIR__, 2).'/entities/dialogue.php';

// Turn and receipt persistence. History rows use the native insertion shape, so saved-ancestry scopes stay
// non-destructive; every write runs under the explicit claimed owner's lock and transaction.
class UtteranceRepository
{
    // The released ordinary delivery-receipt capability. Bootstrap passes it; null (the default) leaves every
    // ordinary origin unreceiptable, so a repository built without it keeps the /talk-only behaviour.
    public const DELIVERY_RECEIPTS = 'dialogue.delivery_receipts';

    // Ordinary (non-/talk) origins become receiptable only through the negotiated capability named here.
    public function __construct(private $db, private \SYNTHClaimedRequest $owner, private ?string $ordinaryCapability = null) {}

    public function recordInput(TurnRequest $request, string $data): void
    {
        $this->owned($request);
        $this->db->insertClaimedHistory($this->owner, self::historyFields($request->context, 'inputtext', $data, null, 'received'));
    }

    public function recordLine(TurnRequest $request, PlacedCharacter $character, string $lineId, string $text): void
    {
        $event = $this->owned($request);
        $this->db->withClaimedRequestLock($this->owner, static function ($connection) use ($event, $character, $lineId, $text): void {
            $result = @pg_query_params($connection, "INSERT INTO public.core_dialogue_utterance
                (session_id,request_id,line_id,turn_id,generation,context_sequence,speaker_identity_id,character_id,text,text_sha256)
                VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)", [$event['session_id'], $event['request_id'], $lineId,
                $event['turn_id'], $event['generation'], $event['context_sequence'], $character->identityId,
                $character->key, $text, hash('sha256', $text)]);
            if (!$result) throw new \RuntimeException('Unable to record utterance');
        });
    }

    // An ordinary origin admitted for delivery receipts: the event itself carries the negotiated capability, and it
    // is unmarked typed text, voice input, an action result, or an owned rechat. /talk keeps its own native record.
    public static function ordinaryOrigin(array $event, string $capability): bool
    {
        if (($event['protocol_version'] ?? null) !== 2 || !is_int($event['context_sequence'] ?? null)
            || $event['context_sequence'] < 1 || $capability === ''
            || !in_array($capability, is_array($event['capabilities'] ?? null) ? $event['capabilities'] : [], true)) return false;
        return match ($event['type'] ?? '') {
            'input_text' => !array_key_exists('intent', is_array($event['payload'] ?? null) ? $event['payload'] : []),
            'input_audio', 'action_result' => true,
            'trigger' => \synth_protocol_owns_rechat($event),
            default => false,
        };
    }

    // Enrol one emitted ordinary NPC line as a generated utterance so only a later receipt makes it heard.
    // Call it inside the session-locked transaction that recorded the line's route, with the routed wire bytes
    // (the recorder's return value). Text and speaker come from that exact route, never from a client. Returns
    // false when the line stays on its existing path: an unadmitted origin, a non-NPC role (player rewrite,
    // narrator), the captured player, a dynamic reference, unstorable text or an unregistered identity. The
    // supplied event must be the origin's exact admitted bytes (as the route recorder proves), so a copied event
    // with an added capability never enrols a line its receipt would refuse. One statement over primary-key and
    // canonical-identity indexes; a differing replay of an enrolled line throws.
    public static function enrollEmittedLine($connection, array $event, string $wire, string $role, string $capability): bool
    {
        if ($role !== 'npc' || !self::ordinaryOrigin($event, $capability)) return false;
        if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_INTRANS) throw new \LogicException('Utterance enrolment requires the route transaction');
        $line = json_decode($wire, true);
        $speaker = $line['payload']['speaker'] ?? null;
        $text = $line['payload']['text'] ?? null;
        if (!is_array($line) || ($line['type'] ?? '') !== 'dialogue' || !is_string($line['line_id'] ?? null)) return false;
        foreach (['request_id', 'turn_id', 'generation', 'context_sequence', 'runtime_variant'] as $field)
            if (($line[$field] ?? null) !== ($event[$field] ?? null)) throw new \LogicException('Utterance owner mismatch');
        if (!is_array($speaker) || isset($speaker['kind']) || !is_string($text) || $text === ''
            || str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 4096) return false;
        $characterId = CharacterKey::of($speaker);
        if ($characterId === null) return false;
        // An action reply or owned rechat is receiptable only while its root policy is proven; otherwise it keeps its
        // existing unmarked path rather than claiming an enrolment its receipt could never scope.
        if (self::inherits($event)) {
            try { self::inheritedPolicy($connection, $event); } catch (\LogicException) { return false; }
        }
        $canonical = \synth_protocol_canonical_json($event);
        $result = @pg_query_params($connection, "INSERT INTO public.core_dialogue_utterance
                (session_id,request_id,line_id,turn_id,generation,context_sequence,speaker_identity_id,character_id,text,text_sha256)
            SELECT rr.session_id,rr.request_id,rr.line_id,e.turn_id,rr.generation,rr.context_sequence,a.id,$7,$8,$9
            FROM public.core_dialogue_route rr
            JOIN public.core_request_execution e ON e.session_id=rr.session_id AND e.request_id=rr.request_id
                AND e.generation=rr.generation AND e.turn_id=$4 AND e.event_type=$10 AND e.state IN ('claimed','streaming')
            JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                AND p.event_sha256=e.request_sha256 AND p.event_sha256=$14 AND p.canonical_event=$13::jsonb
                AND p.canonical_event->'capabilities' ? $15::text
            JOIN public.core_runtime_context c ON c.session_id=rr.session_id AND c.generation=rr.generation
                AND c.snapshot_sequence=rr.context_sequence
            JOIN public.core_runtime_session s ON s.session_id=rr.session_id AND s.playthrough_id=c.playthrough_id
                AND s.runtime_variant=$16
            JOIN public.core_actor_identity a ON a.game='fo4' AND upper(a.full_form_id)=upper(rr.speaker->>'form_id')
                AND lower(a.origin_plugin)=lower(rr.speaker->>'origin_plugin') AND a.playthrough_id=rr.speaker->>'playthrough_id'
            WHERE rr.session_id=$1 AND rr.request_id=$2 AND rr.line_id=$3 AND rr.generation=$5 AND rr.context_sequence=$6
                AND rr.line_sha256=$11 AND rr.speaker=$12::jsonb
                AND upper(COALESCE(c.context_payload#>>'{player,form_id}','')) <> upper(rr.speaker->>'form_id')
            ON CONFLICT (session_id,request_id,line_id) DO UPDATE SET text_sha256=EXCLUDED.text_sha256
            WHERE core_dialogue_utterance.text_sha256=EXCLUDED.text_sha256 AND core_dialogue_utterance.turn_id=EXCLUDED.turn_id
                AND core_dialogue_utterance.generation=EXCLUDED.generation AND core_dialogue_utterance.context_sequence=EXCLUDED.context_sequence
                AND core_dialogue_utterance.speaker_identity_id=EXCLUDED.speaker_identity_id
                AND core_dialogue_utterance.character_id=EXCLUDED.character_id
            RETURNING 1", [$event['session_id'], $event['request_id'], $line['line_id'], $event['turn_id'], $event['generation'],
            $event['context_sequence'], $characterId, $text, hash('sha256', $text), $event['type'], hash('sha256', $wire),
            json_encode($speaker, JSON_THROW_ON_ERROR), $canonical, hash('sha256', $canonical), $capability,
            $event['runtime_variant']]);
        if (!$result) throw new \RuntimeException('Unable to enrol utterance');
        if (pg_num_rows($result) === 1) return true;
        // Only the unusual path pays a second lookup: an existing row that differs is a conflicting replay.
        $existing = @pg_query_params($connection, 'SELECT 1 FROM public.core_dialogue_utterance
            WHERE session_id=$1 AND request_id=$2 AND line_id=$3', [$event['session_id'], $event['request_id'], $line['line_id']]);
        if (!$existing) throw new \RuntimeException('Unable to verify utterance enrolment');
        if (pg_num_rows($existing) !== 0) throw new \LogicException('dialogue_utterance_conflict');
        return false;
    }

    // One receipt transaction: lock the line, match its exact owner, keep the first terminal outcome and, for a
    // heard line, insert its history row. Repeats of the stored outcome are idempotent; others conflict.
    // The first write also attests delivery: the origin is the line's own conversation turn and was not revoked
    // (cancelled, superseded in flight, or a completed input whose delivery a later cancel revoked), and the line
    // has its exact emitted route. Generated text that was never emitted has no route. The origin need not be
    // the active turn: an emitted line of an unrevoked earlier turn may still be receipted.
    // The origin's own admitted bytes select its correlation rule. A /talk origin (conversation intent) needs a route
    // whose speech utterance is the line itself. An ordinary origin needs the negotiated capability in those bytes
    // and the same emitted route; its speech utterance may differ from the line or be absent for text-only output.
    // An ordinary heard row is written as its emission-time chat row would have been, only now: the origin's own
    // admitted event supplies its scene, Close and Whisper hearers, and the stored route supplies the speaker label
    // and listener. Nothing comes from the receipt's text, the current target or a live scene or name lookup.
    /** @param ?\Closure(string, ?array):string $heard history text for the stored line text (the ordinary route as
     *  speaker/listener/mode labels for an ordinary line); null when not heard */
    public function recordReceipt(TurnRequest $receipt, array $character, string $characterId, string $state, ?\Closure $heard): string
    {
        $event = $this->owned($receipt);
        $payload = $event['payload'];
        $context = $receipt->context;
        $ordinary = $this->ordinaryCapability;
        $db = $this->db;
        return $this->db->withClaimedRequestLock($this->owner, static function ($connection) use ($db, $event, $payload, $character, $characterId, $state, $heard, $context, $ordinary): string {
            $result = @pg_query_params($connection, "SELECT u.state,u.line_id,u.character_id,u.text,u.speaker_identity_id,a.full_form_id,a.origin_plugin,a.playthrough_id,
                    CASE WHEN o.request_id IS NULL OR op.request_id IS NULL THEN NULL
                        WHEN o.event_type='input_text' AND op.canonical_event#>>'{payload,intent}'='conversation' THEN 'native'
                        WHEN $7::text IS NOT NULL AND COALESCE(op.canonical_event->'capabilities' ? $7::text,false) AND (
                            (o.event_type='input_text' AND op.canonical_event#>'{payload,intent}' IS NULL)
                            OR o.event_type IN ('input_audio','action_result')
                            OR (o.event_type='trigger' AND op.canonical_event#>>'{payload,kind}'='rechat'
                                AND COALESCE(op.canonical_event->'capabilities' ? 'dialogue.rechat.ownership',false)))
                        THEN 'ordinary' END AS origin_kind,
                    rr.speaker AS route_speaker,rr.utterance_id AS route_utterance,rr.listener_state AS route_listener_state,
                    rr.listener AS route_listener,op.canonical_event AS origin_event,o.event_type AS origin_type,
                    CASE WHEN pu.line_id IS NOT NULL THEN CASE WHEN pu.generation=u.generation THEN pu.state END
                        WHEN pr.line_id IS NOT NULL THEN 'unmarked' END AS parent_state,
                    op.canonical_event#>>'{payload,control,mode}' AS origin_mode
                FROM public.core_dialogue_utterance u
                JOIN public.core_actor_identity a ON a.id=u.speaker_identity_id
                JOIN public.core_runtime_session r ON r.session_id=u.session_id AND r.state='active' AND r.active_generation=u.generation
                LEFT JOIN public.core_request_execution o ON o.session_id=u.session_id AND o.request_id=u.request_id
                    AND o.generation=u.generation AND o.turn_id=u.turn_id
                    AND o.event_type IN ('input_text','input_audio','action_result','trigger')
                    AND o.state IN ('claimed','streaming','complete','failed')
                    AND COALESCE(o.error_code,'') NOT IN ('turn_cancelled','request_cancelled','superseded_turn')
                LEFT JOIN public.core_protocol_event op ON op.session_id=o.session_id AND op.request_id=o.request_id
                    AND op.generation=o.generation AND op.turn_id=o.turn_id AND op.event_type=o.event_type
                    AND op.event_sha256=o.request_sha256
                LEFT JOIN public.core_dialogue_route rr ON rr.session_id=u.session_id AND rr.request_id=u.request_id
                    AND rr.line_id=u.line_id AND rr.generation=u.generation AND rr.context_sequence=u.context_sequence
                LEFT JOIN public.core_dialogue_utterance pu ON o.event_type='trigger' AND pu.session_id=u.session_id
                    AND pu.request_id=op.canonical_event#>>'{payload,reply_to_request_id}'
                    AND pu.line_id=op.canonical_event#>>'{payload,reply_to_line_id}'
                LEFT JOIN public.core_dialogue_route pr ON o.event_type='trigger' AND pr.session_id=u.session_id
                    AND pr.request_id=op.canonical_event#>>'{payload,reply_to_request_id}'
                    AND pr.line_id=op.canonical_event#>>'{payload,reply_to_line_id}' AND pr.generation=u.generation
                WHERE u.session_id=$1 AND u.request_id=$2 AND u.line_id=$3 AND u.generation=$4 AND u.context_sequence=$5 AND u.turn_id=$6
                FOR UPDATE OF u", [$event['session_id'], $payload['dialogue_request_id'], $payload['line_id'], $event['generation'],
                $event['context_sequence'], $event['turn_id'], $ordinary]);
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
            // An ordinary line is receipted only by a receipt that itself negotiated the same capability. A rechat
            // child prepared while its enrolled parent line played is released only by that parent's accepted delivery,
            // so none of its lines can become heard until the parent line was heard. The parent's stored final wire,
            // proven by the completed-parent evidence for the child's own stored origin, decides which rule applies:
            // a marked line (receipt_required true) needs its enrolment in this generation, spoken or presented; an
            // unmarked line needs a heard enrolment if one exists and is otherwise a legacy line. Missing or invalid
            // proof throws as the inherited-mode proof does; a marked parent without its enrolment, or any other
            // marker value, is never heard.
            $origin = $row['origin_event'] === null ? null : json_decode($row['origin_event'], true, 64, JSON_THROW_ON_ERROR);
            $parentHeard = true;
            if ($heard !== null && $row['origin_kind'] === 'ordinary' && $row['origin_type'] === 'trigger') {
                $proof = is_array($origin) ? self::parentEvidence($connection, $origin) : null;
                if ($proof === null) throw new \LogicException('rechat_parent_unavailable');
                $parentPayload = $proof['line']['payload'] ?? null;
                $marker = is_array($parentPayload) && array_key_exists('receipt_required', $parentPayload)
                    ? $parentPayload['receipt_required'] : false;
                $parentHeard = match ($marker) {
                    true => in_array($row['parent_state'], ['spoken', 'presented'], true),
                    false => in_array($row['parent_state'], ['spoken', 'presented', 'unmarked'], true),
                    default => false,
                };
            }
            $correlated = match ($row['origin_kind']) {
                'native' => $row['route_utterance'] === $row['line_id'],
                'ordinary' => in_array($ordinary, $event['capabilities'] ?? [], true) && $parentHeard,
                default => false,
            };
            if (!$correlated || !is_array($route)
                || strcasecmp(strval($route['form_id'] ?? ''), $character['form_id']) !== 0
                || strcasecmp(strval($route['origin_plugin'] ?? ''), $character['origin_plugin']) !== 0
                || ($route['playthrough_id'] ?? null) !== $character['playthrough_id']) throw new \DomainException('receipt_owner_mismatch');
            $update = @pg_query_params($connection, "UPDATE public.core_dialogue_utterance SET state=$4,receipt_request_id=$5,
                receipt_at=NOW() WHERE session_id=$1 AND request_id=$2 AND line_id=$3 AND state='generated'",
                [$event['session_id'], $payload['dialogue_request_id'], $payload['line_id'], $state, $event['request_id']]);
            if (!$update || pg_affected_rows($update) !== 1) throw new \RuntimeException('Unable to record receipt');
            // The receipt has no mode of its own. Use the proven original turn to keep Whisper history listener-private;
            // an action reply or owned rechat takes its proven root's frozen mode (its own payload has none).
            $mode = strval($row['origin_mode'] ?? '');
            $inherited = $heard !== null && $row['origin_kind'] === 'ordinary' && self::inherits($origin);
            // The row join already proved the origin unrevoked, so its own failed execution keeps a line it emitted
            // receiptable; the shared ancestry and scene proofs still require every ancestor to be complete.
            $policy = $inherited ? self::inheritedPolicy($connection, $origin, true) : null;
            if ($policy !== null) $mode = $policy['execution_mode'];
            $hearers = $inherited && $mode === 'WHISPER' ? self::whisperHearers($connection, $origin) : null;
            $noSceneSpeaker = null;
            if ($inherited && $mode !== 'WHISPER') {
                // Readable fields come from the origin's frozen scene when it has one, never a later capture.
                $scene = \synth_rechat_response_scene_locked($connection, $origin, true);
                if ($scene !== null) {
                    $context = $scene;
                } elseif ($mode === 'CLOSE' || ($policy['audience_intent'] ?? null) === 'everyone') {
                    // Without a fresh rechat scene, only the player and exact selected responder are proven.
                    $replyOrigin = \synth_rechat_action_chain_origin($connection, $origin, $origin, false, true);
                    if (\synth_protocol_owns_rechat($replyOrigin)) $noSceneSpeaker = intval($row['speaker_identity_id']);
                }
            }
            if ($mode === 'WHISPER') $context = \synth_protocol_listener_private_context($context);
            if ($heard !== null && $row['origin_kind'] === 'ordinary') {
                // The receipt transaction allocates the history id, so receipt time is the insertion frontier. The
                // utterance id is the route's speech id, as on the emission-time row, so speech attribution still joins.
                $listener = $row['route_listener_state'] === 'addressed' && $row['route_listener'] !== null
                    ? json_decode($row['route_listener'], true) : null;
                $labels = ['speaker' => strval($route['display_name'] ?? ''),
                    'listener' => is_array($listener) ? strval($listener['display_name'] ?? '') : '', 'mode' => $mode];
                $db->insertReceiptHistory($connection, $origin, self::historyFields($context, 'chat',
                    $heard($row['text'], $labels), $row['route_utterance'] ?? $row['line_id'], $state), $hearers, $mode === 'CLOSE', $noSceneSpeaker);
            } elseif ($heard !== null) self::insertHeard($connection, $event, $payload['dialogue_request_id'],
                self::historyFields($context, 'chat', $heard($row['text']), $payload['line_id'], $state),
                ($row['origin_mode'] ?? '') === 'WHISPER' ? intval($row['speaker_identity_id']) : null);
            return $state;
        });
    }

    // Action results and owned rechats carry no mode; their scope is the proven chain root's.
    private static function inherits(?array $origin): bool
    {
        return in_array($origin['type'] ?? '', ['action_result', 'trigger'], true);
    }

    // Completed-parent proof for a receipted rechat child's stored origin; the child's own failed, unrevoked
    // execution still counts, every ancestor stays complete. Null when the proof is missing.
    private static function parentEvidence($connection, array $origin): ?array
    {
        require_once dirname(__DIR__, 3).'/lib/synth_rechat_routes.php';
        return \synth_rechat_completed_parent_evidence($connection, $origin, true);
    }

    // The proven root's saved execution mode, under the caller's claimed lock. Missing or mismatched ancestry throws.
    private static function inheritedPolicy($connection, array $origin, bool $ownFailed = false): array
    {
        require_once dirname(__DIR__, 3).'/lib/synth_rechat_routes.php';
        return \synth_rechat_inherited_policy_locked($connection, $origin, $ownFailed);
    }

    // A Whisper root is heard by its captured player and its private listener only: the canonical identities the
    // origin's immutable snapshot resolved (the root's own snapshot, as root proof requires), never names.
    private static function whisperHearers($connection, array $origin): array
    {
        $result = @pg_query_params($connection, 'SELECT player_identity_id,target_identity_id FROM public.core_runtime_context
            WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
            [$origin['session_id'], $origin['generation'], $origin['context_sequence']]);
        $row = $result ? pg_fetch_assoc($result) : false;
        if (!$row) throw new \RuntimeException('Whisper hearer snapshot unavailable');
        $ids = [intval($row['player_identity_id']), intval($row['target_identity_id'])];
        if ($ids[0] < 1 || $ids[1] < 1 || $ids[0] === $ids[1]) throw new \LogicException('whisper_listener_unavailable');
        return $ids;
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
        $snapshot = @pg_query_params($connection, 'SELECT playthrough_id,player_identity_id FROM public.core_runtime_context
            WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3', [$event['session_id'], $event['generation'], $event['context_sequence']]);
        $snapshot = $snapshot ? pg_fetch_assoc($snapshot) : false;
        if (!$snapshot) throw new \RuntimeException('Native history context unavailable');
        $scope = $privateListener === null ? null : json_encode(
            [intval($snapshot['player_identity_id']), $privateListener], JSON_THROW_ON_ERROR);
        $result = @pg_query_params($connection, 'WITH inserted AS (INSERT INTO public.core_context_event ('.implode(',', $columns).') VALUES ('.$values.') RETURNING *)
            INSERT INTO public.core_history_event
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

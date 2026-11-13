<?php

namespace Synth\Core;

require_once dirname(__DIR__, 2).'/contracts/narrator.php';

// Private narrator persistence. Definitions are a shared library of immutable revisions; everything a save restores
// (applied revisions, selection, transcript head) is an immutable state node reached from the lane head of one
// runtime session generation. Every write runs under the explicit claimed owner's session lock and transaction, the
// same lock the context claim takes, so a publication captures either all or none of each private change. Nothing
// here writes eventlog, history_events or any diary/memory source.
class PrivateNarratorRepository
{
    public const LIBRARY_LIMIT = 16;

    public function __construct(private $db, private \SYNTHClaimedRequest $owner) {}

    /** The live lane head of this request's session generation; null when the lane was never captured. */
    public function lane(): ?array
    {
        $event = $this->owner->event;
        $row = $this->one('SELECT state_id,playthrough_id FROM synth_meta.narrator_lane_heads WHERE session_id=$1 AND generation=$2',
            [$event['session_id'], $event['generation']]);
        return $row === null ? null : ['state_id' => $row['state_id'] === null ? null : intval($row['state_id']),
            'playthrough_id' => strval($row['playthrough_id'])];
    }

    /** Applied revisions, selection and transcript head of one immutable state; the empty state for null. */
    public function state(?int $stateId): array
    {
        if ($stateId === null) return ['state_id' => 0, 'applied' => [], 'persona_heads' => [], 'selected' => null, 'transcript_head' => null];
        $row = $this->one('SELECT applied_revisions,persona_heads,selected_definition_id,transcript_head_entry_id FROM synth_meta.narrator_states
            WHERE state_id=$1', [$stateId]);
        if ($row === null) throw new \RuntimeException('Private narrator state unavailable');
        $applied = json_decode(strval($row['applied_revisions']), true, 4, JSON_THROW_ON_ERROR);
        return ['state_id' => $stateId, 'applied' => array_map('intval', is_array($applied) ? $applied : []),
            'persona_heads' => array_map('intval', json_decode($row['persona_heads'], true, 4, JSON_THROW_ON_ERROR)),
            'selected' => $row['selected_definition_id'],
            'transcript_head' => $row['transcript_head_entry_id'] === null ? null : intval($row['transcript_head_entry_id'])];
    }

    public function revision(int $revisionId): ?array
    {
        return $this->one('SELECT r.*,d.edit_generation FROM synth_meta.narrator_definition_revisions r
            JOIN synth_meta.narrator_definitions d USING(definition_id) WHERE revision_id=$1', [$revisionId]);
    }

    /** Latest revision of every live library definition, by name. */
    public function library(): array
    {
        $result = $this->db->execQuery('SELECT DISTINCT ON (r.definition_id) r.definition_id,r.revision_id,r.name
            FROM synth_meta.narrator_definitions d JOIN synth_meta.narrator_definition_revisions r USING(definition_id)
            WHERE d.retired_at IS NULL ORDER BY r.definition_id,r.revision_id DESC');
        if ($result === false) throw new \RuntimeException('Unable to read narrator definitions');
        $rows = pg_fetch_all($result) ?: [];
        usort($rows, static fn($a, $b) => [mb_strtolower($a['name']), $a['definition_id']] <=> [mb_strtolower($b['name']), $b['definition_id']]);
        return array_slice($rows, 0, self::LIBRARY_LIMIT);
    }

    /** Oldest first: up to $limit entries of a transcript branch ending at $head, optionally one definition's own. */
    public function transcript(?int $head, int $limit, ?string $definitionId = null): array
    {
        if ($head === null || $limit < 1) return [];
        $parent = $definitionId === null ? 'parent_entry_id' : 'parent_persona_entry_id';
        $result = $this->db->execQuery('WITH RECURSIVE chain AS (
                SELECT e.*,1 AS depth FROM synth_meta.narrator_transcript_entries e WHERE e.entry_id=$1
                UNION ALL SELECT p.*,c.depth+1 FROM chain c
                    JOIN synth_meta.narrator_transcript_entries p ON p.entry_id=c.'.$parent.' WHERE c.depth<$2
            ) SELECT entry_id,parent_entry_id,role,kind,definition_id,speaker_name,text FROM chain
            WHERE $3::text IS NULL OR definition_id=$3 ORDER BY depth ASC LIMIT $2', [$head, $limit, $definitionId]);
        if ($result === false) throw new \RuntimeException('Unable to read private narrator transcript');
        return array_reverse(pg_fetch_all($result) ?: []);
    }

    // A page visits at most 24 parent links. Only cursors issued from this exact immutable state can continue it.
    public function transcriptPage(array $state, ?string $cursor = null): array
    {
        $head = $state['transcript_head'];
        if ($cursor !== null) {
            $page = $this->one('SELECT next_entry_id FROM synth_meta.narrator_transcript_cursors WHERE cursor_id=$1 AND state_id=$2',
                [$cursor, $state['state_id']]);
            if ($page === null) throw new NarratorRejected('narrator_cursor_stale');
            $head = intval($page['next_entry_id']);
        }
        $entries = $this->transcript($head, 24);
        $next = $entries[0]['parent_entry_id'] ?? null;
        $nextCursor = null;
        if ($next !== null) {
            $nextCursor = hash('sha256', 'narrator-page:'.$state['state_id'].':'.$next);
            if ($this->db->execQuery('INSERT INTO synth_meta.narrator_transcript_cursors(cursor_id,state_id,next_entry_id)
                VALUES($1,$2,$3) ON CONFLICT DO NOTHING', [$nextCursor, $state['state_id'], $next]) === false)
                throw new \RuntimeException('Unable to issue narrator transcript cursor');
        }
        return [$entries, $nextCursor];
    }

    /** The definition's own profile, else the server's default narrator profile; with its primary LLM connector. */
    public function profile(?int $profileId): ?array
    {
        $profile = $profileId !== null ? $this->one('SELECT * FROM synth.core_profiles WHERE id=$1', [$profileId])
            : $this->one("SELECT * FROM synth.core_profiles WHERE default_narrator='1' ORDER BY id LIMIT 1", []);
        if ($profile === null) return null;
        $connector = $this->one('SELECT * FROM core_llm_connector WHERE id=$1', [intval($profile['llm_primary_id'] ?? 0)]);
        return $connector === null ? null : ['profile' => $profile, 'connector' => $connector];
    }

    // The authored player background is read live, like provider settings and profile contents.
    public function playerBackground(): string
    {
        $row = $this->one("SELECT value FROM synth.core_player WHERE id IN ('background','bio') AND btrim(value)<>''
            ORDER BY CASE id WHEN 'background' THEN 0 ELSE 1 END LIMIT 1", []);
        return trim(strval($row['value'] ?? ''));
    }

    /** Selecting applies the library's latest revision of that definition; the other applied revisions are kept. */
    public function select(string $definitionId): int
    {
        return $this->locked(function ($connection, array $event, array $head) use ($definitionId): int {
            $latest = $this->lockedOne($connection, 'SELECT r.revision_id FROM synth_meta.narrator_definitions d
                JOIN synth_meta.narrator_definition_revisions r USING(definition_id)
                WHERE d.definition_id=$1 AND d.retired_at IS NULL ORDER BY r.revision_id DESC LIMIT 1', [$definitionId]);
            if ($latest === null) throw new NarratorRejected('narrator_definition_unknown');
            $state = $this->state($head['state_id']);
            $revision = intval($latest['revision_id']);
            if ($state['selected'] === $definitionId && ($state['applied'][$definitionId] ?? null) === $revision) {
                return intval($head['state_id']);
            }
            $applied = $state['applied'];
            if (!isset($applied[$definitionId]) && count($applied) >= self::LIBRARY_LIMIT)
                throw new NarratorRejected('narrator_save_definition_limit');
            $applied[$definitionId] = $revision;
            $stateId = $this->insertState($connection, $event, $head, $applied, $definitionId, $state['transcript_head'], 'select');
            self::retireLane($connection, $event);
            return $stateId;
        });
    }

    // Cancel Reply: every unfinished reply of this lane, and its in-flight request, is retired. State is unchanged.
    public function cancelLane(): ?int
    {
        return $this->locked(static function ($connection, array $event, array $head): ?int {
            self::retireLane($connection, $event);
            return $head['state_id'];
        });
    }

    /**
     * Player input is committed before any provider call: a transcript entry, a new state naming it, the moved lane
     * head and this request's pending reply, whose parent checkpoint is that new state.
     * @return int the new state ID
     */
    public function recordInput(int $expectedState, string $definitionId, int $revisionId, string $kind, string $speaker, string $text,
        int $editGeneration): int
    {
        return $this->locked(function ($connection, array $event, array $head) use ($expectedState, $definitionId, $revisionId, $kind, $speaker, $text, $editGeneration): int {
            $definition = $this->lockedOne($connection, 'SELECT edit_generation FROM synth_meta.narrator_definitions WHERE definition_id=$1', [$definitionId]);
            if ($definition === null || intval($definition['edit_generation']) !== $editGeneration)
                throw new NarratorRejected('narrator_definition_changed');
            if (intval($head['state_id'] ?? 0) !== $expectedState) throw new NarratorRejected('narrator_state_changed');
            $state = $this->state($head['state_id']);
            if ($state['selected'] !== $definitionId || ($state['applied'][$definitionId] ?? null) !== $revisionId)
                throw new NarratorRejected('narrator_selection_changed');
            self::retireLane($connection, $event);
            $entry = $this->lockedOne($connection, "INSERT INTO synth_meta.narrator_transcript_entries
                (parent_entry_id,playthrough_id,role,kind,definition_id,revision_id,speaker_name,text,session_id,generation,request_id,parent_persona_entry_id)
                VALUES($1,$2,'player',$3,$4,$5,$6,$7,$8,$9,$10,$11) RETURNING entry_id",
                [$state['transcript_head'], $head['playthrough_id'], $kind, $definitionId, $revisionId, $speaker, $text,
                    $event['session_id'], $event['generation'], $event['request_id'], $state['persona_heads'][$definitionId] ?? null]);
            if ($entry === null) throw new \RuntimeException('Unable to record private narrator input');
            $stateId = $this->insertState($connection, $event, $head, $state['applied'], $definitionId, intval($entry['entry_id']), 'input');
            $pending = @pg_query_params($connection, 'INSERT INTO synth_meta.narrator_pending_outputs
                (session_id,request_id,generation,turn_id,context_sequence,playthrough_id,line_id,definition_id,revision_id,parent_state_id,input_entry_id,definition_edit_generation)
                VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12)', [$event['session_id'], $event['request_id'], $event['generation'],
                $event['turn_id'], $event['context_sequence'], $head['playthrough_id'], $event['request_id'].':narrator',
                $definitionId, $revisionId, $stateId, $entry['entry_id'], $editGeneration]);
            if (!$pending) throw new \RuntimeException('Unable to record private narrator reply owner');
            return $stateId;
        });
    }

    // The generated reply text is stored before its line is sent. A reply retired meanwhile is a cancellation.
    public function recordGenerated(string $text): void
    {
        $this->locked(static function ($connection, array $event) use ($text): void {
            $result = @pg_query_params($connection, "UPDATE synth_meta.narrator_pending_outputs SET state='generated',text=$3,updated_at=NOW()
                WHERE session_id=$1 AND request_id=$2 AND state='pending'
                    AND definition_edit_generation=(SELECT edit_generation FROM synth_meta.narrator_definitions d
                        WHERE d.definition_id=narrator_pending_outputs.definition_id)", [$event['session_id'], $event['request_id'], $text]);
            if (!$result) throw new \RuntimeException('Unable to record private narrator reply');
            if (pg_affected_rows($result) !== 1) throw new \SYNTHProtocolCancelled('narrator_reply_retired');
        });
    }

    public function failPending(): void
    {
        $this->locked(static function ($connection, array $event): void {
            if (!@pg_query_params($connection, "UPDATE synth_meta.narrator_pending_outputs SET state='failed',updated_at=NOW()
                WHERE session_id=$1 AND request_id=$2 AND state='pending'", [$event['session_id'], $event['request_id']]))
                throw new \RuntimeException('Unable to retire private narrator reply');
        });
    }

    /**
     * One receipt transaction for the exact reply: same session, generation, request, turn, context, definition,
     * revision and parent checkpoint. The first outcome is kept and repeats replay it. Completed audio of a reply whose
     * parent is still the lane head appends it to the transcript; any other change since its input retires it, which
     * is committed and reported as "cancelled".
     * @return array{0:string,1:?int} stored outcome and the lane's state afterwards
     */
    public function recordReceipt(array $payload, string $outcome): array
    {
        return $this->locked(function ($connection, array $event, array $head) use ($payload, $outcome): array {
            $row = $this->lockedOne($connection, 'SELECT * FROM synth_meta.narrator_pending_outputs
                WHERE session_id=$1 AND request_id=$2 FOR UPDATE', [$event['session_id'], $payload['narrator_request_id']]);
            if ($row === null || $row['line_id'] !== $payload['line_id'] || intval($row['generation']) !== $event['generation']
                || $row['turn_id'] !== $event['turn_id'] || intval($row['context_sequence']) !== $event['context_sequence']
                || $row['definition_id'] !== $payload['definition_id'] || intval($row['revision_id']) !== $payload['revision_id']
                || intval($row['parent_state_id']) !== $payload['parent_state_id']) throw new NarratorRejected('receipt_owner_mismatch');
            if ($row['state'] === $outcome) return [$outcome, $head['state_id']];
            if ($row['state'] === 'cancelled') return ['cancelled', $head['state_id']];
            if ($row['state'] !== 'generated') throw new NarratorRejected('receipt_conflict');
            $origin = $this->lockedOne($connection, "SELECT 1 FROM synth_meta.request_executions e
                JOIN synth_meta.narrator_definitions d ON d.definition_id=$3 AND d.edit_generation=$4
                JOIN synth_meta.runtime_sessions r ON r.session_id=e.session_id AND r.state='active'
                    AND r.active_generation=e.generation AND r.active_turn_id=e.turn_id AND r.active_turn_sequence=$6
                WHERE e.session_id=$1 AND e.request_id=$2 AND e.generation=$5 AND e.event_type='narrator_turn'
                    AND e.state='complete' AND COALESCE(e.error_code,'') NOT IN ('turn_cancelled','request_cancelled','superseded_turn')",
                [$event['session_id'], $row['request_id'], $row['definition_id'], $row['definition_edit_generation'], $event['generation'], $row['context_sequence']]);
            if ($origin === null) return ['cancelled', $head['state_id']];
            $stored = $outcome;
            if ($outcome === 'spoken') {
                if (intval($head['state_id'] ?? 0) !== intval($row['parent_state_id'])) {
                    $stored = 'cancelled';
                } else {
                    $state = $this->state($head['state_id']);
                    $revision = $this->revision(intval($row['revision_id']));
                    $entry = $this->lockedOne($connection, "INSERT INTO synth_meta.narrator_transcript_entries
                        (parent_entry_id,playthrough_id,role,kind,definition_id,revision_id,speaker_name,text,session_id,generation,request_id,parent_persona_entry_id)
                        VALUES($1,$2,'narrator','reply',$3,$4,$5,$6,$7,$8,$9,$10) RETURNING entry_id",
                        [$row['input_entry_id'], $row['playthrough_id'], $row['definition_id'], $row['revision_id'],
                            strval($revision['name'] ?? 'Narrator'), $row['text'], $event['session_id'], $event['generation'],
                            $row['request_id'], $row['input_entry_id']]);
                    if ($entry === null || $state['transcript_head'] !== intval($row['input_entry_id']))
                        throw new \RuntimeException('Private narrator transcript branch mismatch');
                    $this->insertState($connection, $event, $head, $state['applied'], $state['selected'], intval($entry['entry_id']), 'reply');
                }
            }
            $update = @pg_query_params($connection, "UPDATE synth_meta.narrator_pending_outputs SET state=$3,receipt_request_id=$4,
                updated_at=NOW() WHERE session_id=$1 AND request_id=$2 AND state='generated'",
                [$event['session_id'], $row['request_id'], $stored, $event['request_id']]);
            if (!$update || pg_affected_rows($update) !== 1) throw new \RuntimeException('Unable to record private narrator receipt');
            $after = $this->lockedOne($connection, 'SELECT state_id FROM synth_meta.narrator_lane_heads WHERE session_id=$1 AND generation=$2',
                [$event['session_id'], $event['generation']]);
            return [$stored, $after['state_id'] === null ? null : intval($after['state_id'])];
        });
    }

    // Retire this lane's unfinished replies and stop their in-flight requests, except the caller's own.
    private static function retireLane($connection, array $event): void
    {
        $params = [$event['session_id'], $event['generation'], $event['request_id']];
        if (!@pg_query_params($connection, "UPDATE synth_meta.narrator_pending_outputs SET state='cancelled',updated_at=NOW()
                WHERE session_id=$1 AND generation=$2 AND request_id<>$3 AND state IN ('pending','generated')", $params)
            || !@pg_query_params($connection, "UPDATE synth_meta.request_executions SET state='cancelled',response_body=NULL,
                error_code='request_cancelled',completed_at=NOW() WHERE session_id=$1 AND generation=$2 AND request_id<>$3
                AND event_type='narrator_turn' AND state IN ('claimed','streaming')", $params))
            throw new \RuntimeException('Unable to retire private narrator replies');
    }

    private function insertState($connection, array $event, array $head, array $applied, ?string $selected, ?int $transcriptHead, string $cause): int
    {
        $personaHeads = $this->state($head['state_id'])['persona_heads'];
        if ($selected !== null && in_array($cause, ['input', 'reply'], true)) $personaHeads[$selected] = $transcriptHead;
        $row = $this->lockedOne($connection, 'INSERT INTO synth_meta.narrator_states
            (parent_state_id,playthrough_id,applied_revisions,selected_definition_id,transcript_head_entry_id,cause,session_id,generation,request_id,persona_heads)
            VALUES($1,$2,$3::jsonb,$4,$5,$6,$7,$8,$9,$10::jsonb) RETURNING state_id', [$head['state_id'], $head['playthrough_id'],
            json_encode((object)$applied, JSON_THROW_ON_ERROR), $selected, $transcriptHead, $cause,
            $event['session_id'], $event['generation'], $event['request_id'], json_encode((object)$personaHeads, JSON_THROW_ON_ERROR)]);
        if ($row === null) throw new \RuntimeException('Unable to record private narrator state');
        $moved = @pg_query_params($connection, 'UPDATE synth_meta.narrator_lane_heads SET state_id=$3,updated_at=NOW()
            WHERE session_id=$1 AND generation=$2', [$event['session_id'], $event['generation'], $row['state_id']]);
        if (!$moved || pg_affected_rows($moved) !== 1) throw new \RuntimeException('Unable to move private narrator lane');
        return intval($row['state_id']);
    }

    // The claimed owner's session lock and transaction with the lane head locked; a lane never captured is refused.
    private function locked(callable $operation)
    {
        return $this->db->withClaimedRequestLock($this->owner, function ($connection, array $event) use ($operation) {
            $head = $this->lockedOne($connection, 'SELECT state_id,playthrough_id FROM synth_meta.narrator_lane_heads
                WHERE session_id=$1 AND generation=$2 FOR UPDATE', [$event['session_id'], $event['generation']]);
            if ($head === null) throw new NarratorRejected('narrator_state_unavailable');
            $head['state_id'] = $head['state_id'] === null ? null : intval($head['state_id']);
            return $operation($connection, $event, $head);
        }, 2000);
    }

    private function lockedOne($connection, string $sql, array $params): ?array
    {
        $result = @pg_query_params($connection, $sql, $params);
        if (!$result) throw new \RuntimeException('Private narrator query failed');
        $row = pg_fetch_assoc($result);
        return is_array($row) ? $row : null;
    }

    private function one(string $sql, array $params): ?array
    {
        $result = $this->db->execQuery($sql, $params);
        if ($result === false) throw new \RuntimeException('Private narrator query failed');
        $row = pg_fetch_assoc($result);
        return is_array($row) ? $row : null;
    }
}

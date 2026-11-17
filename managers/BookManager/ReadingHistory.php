<?php

/**
 * Records one reading in the existing observation timeline, once.
 *
 * Runs inside context admission's session lock and transaction. The history lookup below is the only
 * SQL; it moves into a system entity class when the persistence redesign lands a home for it.
 */
declare(strict_types=1);

namespace Synth\Managers\BookManager;

use DomainException;
use RuntimeException;

require_once __DIR__ . '/ReadingEvent.php';
require_once __DIR__ . '/../../lib/synth_protocol_state.php';

final class ReadingHistory
{
    /**
     * Stores the reading carried by a context event; a retry of the same reading is a no-op.
     *
     * @param object $db The request's database connection.
     * @param array<string, mixed> $event The validated context event.
     * @throws DomainException When the reading is not negotiated or conflicts with an earlier one.
     * @throws RuntimeException When the history cannot be read or written.
     */
    public static function persist($db, array $event): void
    {
        $payload = $event['payload'];
        if (!isset($payload['reading_event'])) {
            return;
        }
        if (!ReadingEvent::isAllowedOn($event)) {
            throw new DomainException('reading_events_capability_required');
        }
        $reading = ReadingEvent::validate($payload['reading_event']);
        $player = array_intersect_key($payload['player'], array_flip(['form_id', 'origin_plugin', 'playthrough_id']));
        $input = $reading;
        unset($input['age_ms']);
        if (self::alreadyRecorded($db, $event, $reading, $player, $input)) {
            return;
        }
        $name = str_replace('|', ' ', trim($payload['player']['display_name']));
        $metadata = ['schema' => 'synth.reading.event.v1', 'event_id' => $reading['event_id'], 'player' => $player,
            'input_event' => $input, 'capture_age_ms' => $reading['age_ms'],
            'snapshot_sequence' => $payload['snapshot_sequence']];
        $gamets = (int) ($payload['world']['game_time_ticks'] ?? 0);
        \synth_protocol_persist_observation_history($db, $event, 'reading_event', ReadingEvent::describe($reading, $name),
            '|' . $name . '|', (string) ($payload['world']['location'] ?? ''), $metadata,
            $gamets > 0 ? $gamets : (int) $payload['snapshot_sequence']);
    }

    // Looks for the same event ID in this session; same content is a retry, different content a conflict.
    private static function alreadyRecorded($db, array $event, array $reading, array $player, array $input): bool
    {
        $payload = $event['payload'];
        $result = $db->execQuery("SELECT (event_payload->>'party')::jsonb AS party FROM synth_meta.history_events
            WHERE session_id=$1 AND generation=$2 AND playthrough_id=$3 AND event_payload->>'type'='reading_event'
              AND (event_payload->>'party')::jsonb->>'event_id'=$4 LIMIT 1",
            [$event['session_id'], $event['generation'], $payload['playthrough_id'], $reading['event_id']]);
        if ($result === false) {
            throw new RuntimeException('reading_event_history_read_failed');
        }
        $row = pg_fetch_assoc($result);
        if (!$row) {
            return false;
        }
        $previous = json_decode($row['party'], true, flags: JSON_THROW_ON_ERROR);
        if (($previous['player'] ?? null) != $player || ($previous['input_event'] ?? null) != $input) {
            throw new DomainException('reading_event_conflict');
        }
        return true;
    }
}

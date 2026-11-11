<?php

namespace Synth\Core;

require_once __DIR__.'/entities.php';

// Turn persistence. History rows use the existing native insertion path, so saved-ancestry scopes stay
// non-destructive; utterance records share the claimed request's lock and transaction owner.
class UtteranceRepository
{
    public function __construct(private $db) {}

    public function recordInput(TurnRequest $request, string $data): void
    {
        $this->db->insert('eventlog', self::historyFields($request->context, 'inputtext', $data, null, 'received'));
    }

    public function recordLine(TurnRequest $request, PlacedCharacter $character, string $lineId, string $text): void
    {
        $event = $request->event;
        $this->db->withNativeRequestLock(static function ($connection) use ($event, $character, $lineId, $text): void {
            $result = @pg_query_params($connection, "INSERT INTO synth_meta.dialogue_utterances
                (session_id,request_id,line_id,turn_id,generation,context_sequence,speaker_identity_id,character_id,text,text_sha256)
                VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)", [$event['session_id'], $event['request_id'], $lineId,
                $event['turn_id'], $event['generation'], $event['context_sequence'], $character->identityId,
                $character->key, $text, hash('sha256', $text)]);
            if (!$result) throw new \RuntimeException('Unable to record utterance');
        });
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

<?php

namespace Synth\Managers;

use Synth\Core\CharacterKey;
use Synth\Core\UtteranceRepository;

require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once dirname(__DIR__).'/core/dbmanager/repositories/UtteranceRepository.php';
require_once __DIR__.'/SessionManager.php';

// Delivery receipts for emitted dialogue lines: binding to the claimed snapshot, the heard policy and the one
// owned receipt write. It needs no provider, speech, knowledge or turn services.
final class ReceiptManager
{
    // skipped (dialogue.conversation) is a line the player never heard: it is stored exactly like a failed one,
    // and the stored receipt event keeps the distinct outcome.
    private const RECEIPT_STATES = ['audio_completed' => 'spoken', 'text_presented' => 'presented',
        'interrupted' => 'interrupted', 'failed' => 'failed', 'skipped' => 'failed'];
    // Marks a line the player only partly heard, so a later prompt can tell it was cut off.
    public const CUT_OFF_MARK = ' [cut off]';

    public function __construct(private SessionManager $sessions, private UtteranceRepository $utterances) {}

    // Delivery receipt for one emitted line; returns the stored state. Text and speaker come from the server
    // record, never the request. Refusals throw a DomainException naming receipt_invalid, receipt_owner_mismatch
    // or receipt_conflict.
    public function receipt(array $event): string
    {
        $payload = $event['payload'];
        $state = self::RECEIPT_STATES[$payload['outcome'] ?? ''] ?? null;
        $character = is_array($payload['character'] ?? null) ? $payload['character'] : [];
        $characterId = CharacterKey::of($character);
        if ($state === null || $characterId === null) throw new \DomainException('receipt_invalid');
        $receipt = $this->sessions->receiptOwner($event);
        $speaker = strval($receipt->context['target']['display_name'] ?? $character['display_name'] ?? '');
        $player = $receipt->playerName();
        // A /talk line keeps its fixed form. An ordinary line is labelled only from its own stored route: speaker,
        // addressed listener and the origin's mode (the emission-time suffix wording), never the receipt's target.
        // With dialogue.conversation an interrupted line stays in context, marked as cut off; without it the
        // released behaviour is unchanged and an interrupted line is never heard.
        $cutOff = $state === 'interrupted' && in_array('dialogue.conversation', $event['capabilities'] ?? [], true);
        $heard = $this->sessions->heard($state) || $cutOff
            ? static function (string $text, ?array $ordinary = null) use ($speaker, $player, $cutOff): string {
                if ($cutOff) $text .= self::CUT_OFF_MARK;
                if ($ordinary === null) return $speaker.': '.$text.' (talking to '.$player.')';
                $listener = trim($ordinary['listener']);
                $suffix = $listener === '' ? '' : ' '.match ($ordinary['mode']) {
                    'WHISPER' => '(whispering to '.$listener.')',
                    'CLOSE' => '(speaking privately to '.$listener.')',
                    'SHOUT' => '(shouting to '.$listener.')',
                    default => '(talking to '.$listener.')',
                };
                return ($ordinary['speaker'] === '' ? '' : $ordinary['speaker'].': ').$text.$suffix;
            } : null;
        return $this->utterances->recordReceipt($receipt, $character, $characterId, $state, $heard);
    }
}

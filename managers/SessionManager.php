<?php

namespace Synth\Managers;

use Synth\Core\TurnRejected;
use Synth\Core\TurnRequest;

require_once dirname(__DIR__).'/core/contracts/dialogue.php';
require_once dirname(__DIR__).'/core/entities/dialogue.php';
require_once __DIR__.'/SaveState.php';

// Request admission for an explicit conversation-intent (/talk) turn: the negotiated contract, a supported
// mode and the claimed snapshot of the active save. Capability alone never selects this route, and the
// route emits no actions, so action requests belong in unmarked text. Also binds speech receipts.
final class SessionManager
{
    private const MODES = ['STANDARD', 'WHISPER', 'CLOSE', 'SHOUT'];

    public function __construct(private SaveState $saves) {}

    public function admit(array $event): TurnRequest
    {
        $capabilities = $event['capabilities'] ?? [];
        if (($event['type'] ?? '') !== 'input_text' || ($event['protocol_version'] ?? 1) !== 2
            || ($event['payload']['intent'] ?? null) !== 'conversation'
            || !in_array('dialogue.native_turn', $capabilities, true)
            || !in_array('dialogue.turn_ownership', $capabilities, true)) throw new TurnRejected('native_turn_not_negotiated');
        if (!in_array($event['payload']['control']['mode'] ?? 'STANDARD', self::MODES, true))
            throw new TurnRejected('conversation_mode_unsupported');
        $context = $this->saves->boundContext($event);
        if ($context === null) throw new TurnRejected('target_missing');
        return new TurnRequest($event, $context);
    }

    // A speech receipt is owned only by the claimed snapshot of the active save it names.
    public function receiptOwner(array $event): TurnRequest
    {
        $context = $this->saves->boundContext($event);
        if ($context === null) throw new \DomainException('receipt_owner_mismatch');
        return new TurnRequest($event, $context);
    }

    public function heard(string $state): bool
    {
        return $this->saves->heard($state);
    }
}

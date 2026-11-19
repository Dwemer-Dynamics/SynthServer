<?php

namespace Synth\Managers;

use Synth\Core\SaveRepository;

require_once dirname(__DIR__).'/core/dbmanager/repositories/SaveRepository.php';

// Save policy. A turn reads only its claimed snapshot of the active save, and history is that snapshot's saved
// ancestry: an older save sees earlier ancestry without deleting newer history. Only lines receipted as
// delivered (spoken or presented) count as heard.
class SaveState
{
    public const HEARD_STATES = ['spoken', 'presented'];
    // Lines that may appear in a prompt: heard lines, plus lines the player only partly heard (interrupted). An
    // interrupted line is written to history only under dialogue.conversation, marked as cut off; it is never heard.
    public const CONTEXT_STATES = ['spoken', 'presented', 'interrupted'];

    public function __construct(private SaveRepository $saves) {}

    public function boundContext(array $event): ?array
    {
        return $this->saves->snapshot($event);
    }

    // Newest first: real player input and only heard lines between this player and this placed NPC.
    public function conversationRows(array $event, string $targetName, int $limit): array
    {
        return $this->saves->conversationRows($event, $targetName, self::CONTEXT_STATES, $limit);
    }

    // The save signal of the named playthrough, validated; no row is written until the save schema lands, so the
    // record is the structured log line and the returned summary.
    public function created(array $event): array
    {
        $playthrough = $event['payload']['playthrough_id'] ?? null;
        if (($event['type'] ?? '') !== 'save_created' || !is_string($playthrough) || $playthrough === '')
            throw new \DomainException('save_created_invalid');
        $summary = ['playthrough_id' => $playthrough, 'session_id' => strval($event['session_id'] ?? ''),
            'generation' => intval($event['generation'] ?? 0)];
        \Logger::info('[save] created playthrough='.$playthrough.' session='.$summary['session_id'].' generation='.$summary['generation']);
        return $summary;
    }

    public function heard(string $state): bool
    {
        return in_array($state, self::HEARD_STATES, true);
    }
}

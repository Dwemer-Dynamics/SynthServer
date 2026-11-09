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

    public function __construct(private SaveRepository $saves) {}

    public function boundContext(array $event): ?array
    {
        return $this->saves->snapshot($event);
    }

    // Newest first: real player input and only heard lines between this player and this placed NPC.
    public function conversationRows(array $event, string $targetName, int $limit): array
    {
        return $this->saves->conversationRows($event, $targetName, self::HEARD_STATES, $limit);
    }

    public function heard(string $state): bool
    {
        return in_array($state, self::HEARD_STATES, true);
    }
}

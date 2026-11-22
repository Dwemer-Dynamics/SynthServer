<?php

declare(strict_types=1);

namespace Synth\Managers\SaveState;

use Synth\Core\SaveRepository;

require_once __DIR__ . '/SaveState.php';

/**
 * A read-only view of the conversation history of the active load: the lines the session's current target NPC may read
 * (the player's own input, heard lines, first-observed vanilla lines), through the same saved-ancestry reader a dialogue
 * turn uses. Nothing is written and nothing outside that reader's visibility is returned.
 */
final class HistoryRead
{
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 50;
    private const LINE_CHARS = 500;
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';

    /**
     * Oldest first. Returns an empty list while the session has no target yet.
     *
     * @param object $db The legacy sql session.
     * @throws \InvalidArgumentException When an id or the limit is malformed.
     * @throws \DomainException When the session is not the active load of that playthrough.
     * @return array{type: string, playthrough_id: string, session_id: string, generation: int, target: ?string, messages: list<array{role: string, text: string}>}
     */
    public static function read(object $db, mixed $playthroughId, mixed $sessionId, mixed $limit = null): array
    {
        if (!is_string($playthroughId) || !is_string($sessionId)
            || preg_match(self::ID_PATTERN, $playthroughId) !== 1 || preg_match(self::ID_PATTERN, $sessionId) !== 1) {
            throw new \InvalidArgumentException('history ids');
        }
        if ($limit !== null && (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT)) {
            throw new \InvalidArgumentException('history limit');
        }
        $session = \Synth\Core\DbManager\Dao\RuntimeSessionDao::current($db)->activeInPlaythrough($sessionId, $playthroughId);
        if (!is_array($session) || $session === []) {
            throw new \DomainException('session not active');
        }
        $generation = intval($session['active_generation']);
        $context = \Synth\Core\DbManager\Dao\RuntimeContextDao::current($db)->newestTarget($sessionId, $generation);
        $target = is_array($context) && trim(strval($context['target'] ?? '')) !== '' ? trim(strval($context['target'])) : null;
        $answer = ['type' => 'history.result', 'playthrough_id' => $playthroughId, 'session_id' => $sessionId,
            'generation' => $generation, 'target' => $target, 'messages' => []];
        if ($target === null) {
            return $answer;
        }

        $capabilities = is_array($session['capabilities']) ? $session['capabilities'] : (json_decode(strval($session['capabilities']), true) ?: []);
        $event = ['protocol_version' => 2, 'type' => 'context', 'session_id' => $sessionId, 'runtime_variant' => strval($session['runtime_variant']),
            'generation' => $generation, 'context_sequence' => intval($context['snapshot_sequence']), 'capabilities' => $capabilities, 'payload' => []];
        $rows = (new SaveState(new SaveRepository($db)))->conversationRows($event, $target, $limit ?? self::DEFAULT_LIMIT);
        foreach (array_reverse($rows) as $row) {
            $role = $row['type'] === 'inputtext' ? 'player' : (intval($row['own'] ?? 0) === 1 ? 'npc' : 'other');
            $answer['messages'][] = ['role' => $role, 'text' => mb_substr(strval($row['data']), 0, self::LINE_CHARS)];
        }

        return $answer;
    }
}

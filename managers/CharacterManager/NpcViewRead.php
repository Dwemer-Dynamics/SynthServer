<?php

declare(strict_types=1);

namespace Synth\Managers\CharacterManager;

use Synth\Core\DbManager\Dao\NpcActorDao;
use Synth\Core\DbManager\Dao\NpcRuntimeActorDao;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__, 2) . '/core/dbmanager/EntityManagerFactory.php';
require_once dirname(__DIR__, 2) . '/core/dbmanager/dao/NpcActorDao.php';
require_once dirname(__DIR__, 2) . '/core/dbmanager/dao/NpcRuntimeActorDao.php';

/**
 * A bounded, read-only description of one NPC for a client panel: who it is and what kind of actor it is. It never
 * registers or updates the actor, and returns nothing of the NPC's profile text, memories or history.
 */
final class NpcViewRead
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    /**
     * The actor, or null when no actor of that character is known. A runtime id in `rid` selects the runtime actor of the
     * playthrough; otherwise the character's placed actors are described.
     *
     * @param object $db The legacy sql session.
     * @throws \InvalidArgumentException When an id is malformed.
     * @throws \DomainException When the session is not the active load of that playthrough.
     * @return array{character_id: int, name: string, kind: string, status: ?string}|null
     */
    public static function read(object $db, mixed $playthroughId, mixed $sessionId, mixed $rid, mixed $characterId): ?array
    {
        if (!is_string($playthroughId) || !is_string($sessionId) || !is_int($characterId) || $characterId < 1
            || preg_match(self::ID_PATTERN, $playthroughId) !== 1 || preg_match(self::ID_PATTERN, $sessionId) !== 1
            || ($rid !== null && (!is_string($rid) || preg_match(self::UUID_PATTERN, $rid) !== 1))) {
            throw new \InvalidArgumentException('npc view ids');
        }
        $active = $db->fetchOne('SELECT 1 AS active FROM public.core_runtime_session WHERE session_id=' . $db->escapeLiteral($sessionId)
            . ' AND playthrough_id=' . $db->escapeLiteral($playthroughId) . " AND state='active'");
        if (!is_array($active)) {
            throw new \DomainException('session not active');
        }
        $entityManager = EntityManagerFactory::forSharedSession($db);
        if ($rid !== null) {
            $actor = NpcRuntimeActorDao::using($entityManager)->find($playthroughId, strtolower($rid));
            if ($actor === null || $actor->getCharacterId() !== $characterId) {
                return null;
            }

            return ['character_id' => $characterId, 'name' => $actor->getName(), 'kind' => 'runtime', 'status' => $actor->getStatus()];
        }
        $placed = NpcActorDao::using($entityManager)->forCharacter($characterId);
        if ($placed === []) {
            return null;
        }

        return ['character_id' => $characterId, 'name' => strval($placed[0]->getName() ?? ''), 'kind' => 'placed', 'status' => null];
    }
}

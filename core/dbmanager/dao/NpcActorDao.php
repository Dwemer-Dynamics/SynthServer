<?php

// Reads and writes core_npc_actor: placed actors keyed by plugin and plugin-local reference id. Writes are one parameterized
// statement each, so they run inside whatever legacy transaction the caller owns; Doctrine never flushes this entity.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractNpcActor;


/** Finds and stores placed actors. */
final class NpcActorDao
{
    use UsesEntityManager;

    /** The actor of one plugin-local reference, or null. $plugin is lower-cased, $refId is hex without a load-order prefix. */
    public function find(string $refId, string $plugin): ?AbstractNpcActor
    {
        return $this->fresh()->find(AbstractNpcActor::class, ['refId' => strtolower($refId), 'plugin' => strtolower($plugin)]);
    }

    /** Every actor of one character, ordered by plugin and reference. @return list<AbstractNpcActor> */
    public function forCharacter(int $characterId): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcActor::class)
            ->findBy(['characterId' => $characterId], ['plugin' => 'ASC', 'refId' => 'ASC']));
    }

    /**
     * Inserts or updates one actor; the key and character are required, the descriptive fields only change when given.
     *
     * @param array{refid: string, plugin: string, character_id: int, name?: ?string, gender?: ?string, race?: ?string,
     *     actor_class?: ?string, base_plugin?: ?string, base_formid?: ?string, template_plugin?: ?string, template_formid?: ?string} $row
     */
    public function save(array $row): void
    {
        $refId = strtolower((string) ($row['refid'] ?? ''));
        $plugin = strtolower((string) ($row['plugin'] ?? ''));
        $characterId = (int) ($row['character_id'] ?? 0);
        if (preg_match('/^[0-9a-f]{1,8}$/D', $refId) !== 1 || $plugin === '' || $characterId < 1) {
            throw new InvalidArgumentException('An actor needs a hex reference id, a plugin and a character id.');
        }
        $this->connection()->executeStatement(
            'INSERT INTO public.core_npc_actor (refid, plugin, character_id, name, gender, race, actor_class, base_plugin, base_formid, template_plugin, template_formid)
             VALUES (:refid, :plugin, :character_id, :name, :gender, :race, :actor_class, :base_plugin, :base_formid, :template_plugin, :template_formid)
             ON CONFLICT (refid, plugin) DO UPDATE SET character_id = EXCLUDED.character_id,
               name = COALESCE(EXCLUDED.name, core_npc_actor.name), gender = COALESCE(EXCLUDED.gender, core_npc_actor.gender),
               race = COALESCE(EXCLUDED.race, core_npc_actor.race), actor_class = COALESCE(EXCLUDED.actor_class, core_npc_actor.actor_class),
               base_plugin = COALESCE(EXCLUDED.base_plugin, core_npc_actor.base_plugin), base_formid = COALESCE(EXCLUDED.base_formid, core_npc_actor.base_formid),
               template_plugin = COALESCE(EXCLUDED.template_plugin, core_npc_actor.template_plugin),
               template_formid = COALESCE(EXCLUDED.template_formid, core_npc_actor.template_formid)',
            [
                'refid' => $refId, 'plugin' => $plugin, 'character_id' => $characterId,
                'name' => $row['name'] ?? null, 'gender' => $row['gender'] ?? null, 'race' => $row['race'] ?? null,
                'actor_class' => $row['actor_class'] ?? null, 'base_plugin' => $row['base_plugin'] ?? null,
                'base_formid' => $row['base_formid'] ?? null, 'template_plugin' => $row['template_plugin'] ?? null,
                'template_formid' => $row['template_formid'] ?? null,
            ]
        );
    }
}

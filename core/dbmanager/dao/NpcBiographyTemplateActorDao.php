<?php

// Reads core_npc_biography_template_actor. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractNpcBiographyTemplateActor;

/** Finds rows of core_npc_biography_template_actor. */
class NpcBiographyTemplateActorDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractNpcBiographyTemplateActor
    {
        return $this->fresh()->find(AbstractNpcBiographyTemplateActor::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcBiographyTemplateActor> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcBiographyTemplateActor::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * Maps one form id of a standard template to its NPC unless the mapping already exists. A reference mapping stores the
     * reference plugin and local form id, a base mapping the base plugin and local form id. False when the statement fails.
     */
    public function insertMappingIfMissing(string $templateName, bool $reference, string $plugin, string $localFormId, string $exactName, bool $nonverbal): bool
    {
        $columns = $reference ? 'reference_plugin,reference_local_formid' : 'base_plugin,base_local_formid';
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_npc_biography_template_actor(template_name,'.$columns.',exact_name,is_nonverbal_creature) VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',
                [$templateName, $plugin, $localFormId, $exactName, $nonverbal ? 'true' : 'false']
            );

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }
}

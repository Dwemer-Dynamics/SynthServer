<?php

// Reads core_npc_biography_template_custom. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractNpcBiographyTemplateCustom;

/** Finds rows of core_npc_biography_template_custom. */
final class NpcBiographyTemplateCustomDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $npcName): ?AbstractNpcBiographyTemplateCustom
    {
        return $this->fresh()->find(AbstractNpcBiographyTemplateCustom::class, $npcName);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcBiographyTemplateCustom> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcBiographyTemplateCustom::class)->findBy([], ['npcName' => 'ASC'], max(1, $limit)));
    }

    private const IMPORT_COLUMNS = ['npc_name', 'core', 'worldknowledge_tags', 'npc_static_bio', 'appearance', 'personality', 'relationships',
        'occupation', 'skills', 'speechstyle', 'goals', 'voiceid', 'gender', 'race', 'refid'];

    /**
     * Stores one imported biography template by npc_name, replacing every given column (a null value is stored as '', as the
     * legacy upsert did). Columns outside the known set are refused. False when the statement fails.
     *
     * @param array<string, mixed> $row column => value, including npc_name
     */
    public function upsertImported(array $row): bool
    {
        $row = array_intersect_key($row, array_flip(self::IMPORT_COLUMNS));
        if (!isset($row['npc_name'])) {
            return false;
        }
        $updates = [];
        foreach (array_keys($row) as $column) {
            $updates[] = $column.' = EXCLUDED.'.$column;
        }
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_npc_biography_template_custom ('.implode(', ', array_keys($row)).') VALUES ('
                .implode(', ', array_fill(0, count($row), '?')).') ON CONFLICT (npc_name) DO UPDATE SET '.implode(', ', $updates),
                array_map(static fn($value): string => (string) $value, array_values($row))
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}

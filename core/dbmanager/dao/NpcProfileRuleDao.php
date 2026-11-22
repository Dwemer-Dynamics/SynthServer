<?php

// Reads core_npc_profile_rule. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcProfileRule;

/** Finds rows of core_npc_profile_rule. */
final class NpcProfileRuleDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractNpcProfileRule
    {
        return $this->fresh()->find(AbstractNpcProfileRule::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcProfileRule> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcProfileRule::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * Enabled rules whose patterns match this character, highest priority first. Empty patterns match everything; a faction
     * pattern needs one of the character's factions to match; a rule's mod list must be contained in the character's mods.
     *
     * @param list<string> $factions
     * @param list<string> $mods
     * @return list<array<string, mixed>>
     */
    public function matching(string $name, string $race, string $gender, string $base, array $factions, array $mods): array
    {
        $parameters = [$name, $race, $gender, $base];
        $array = static function (array $values) use (&$parameters): string {
            if ($values === []) {
                return 'ARRAY[]::text[]';
            }
            array_push($parameters, ...array_map('strval', array_values($values)));

            return 'ARRAY['.implode(', ', array_fill(0, count($values), '?')).']::text[]';
        };
        $factionsArray = $array($factions);
        $modsArray = $array($mods);

        return $this->connection()->fetchAllAssociative(
            "SELECT * FROM public.core_npc_profile_rule r
             WHERE r.enabled = TRUE
               AND (NULLIF(BTRIM(r.match_name), '') IS NULL OR ? ~ r.match_name)
               AND (NULLIF(BTRIM(r.match_race), '') IS NULL OR ? ~ r.match_race)
               AND (NULLIF(BTRIM(r.match_gender), '') IS NULL OR ? ~ r.match_gender)
               AND (NULLIF(BTRIM(r.match_base), '') IS NULL OR ? ~ r.match_base)
               AND (NULLIF(BTRIM(r.match_faction), '') IS NULL
                    OR EXISTS (SELECT 1 FROM unnest({$factionsArray}) AS npc_faction(name) WHERE npc_faction.name ~ r.match_faction))
               AND (r.match_mods IS NULL OR r.match_mods <@ {$modsArray})
             ORDER BY r.priority DESC, r.id DESC",
            $parameters
        );
    }
}

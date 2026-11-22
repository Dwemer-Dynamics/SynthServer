<?php

// Reads core_npc_history. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcHistory;

/** Finds rows of core_npc_history. */
class NpcHistoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $historyId): ?AbstractNpcHistory
    {
        return $this->fresh()->find(AbstractNpcHistory::class, $historyId);
    }

    /**
     * Newest snapshots that carry relationship state (the 'relationships' key or the history-source marker key in extended data), most
     * recent first, at most $perNpcLimit per NPC within the newest $rowScanLimit rows and $scanLimit overall. Optionally only one NPC id
     * or name (compared without case). Returns [] when the read fails.
     *
     * @return list<array<string, mixed>>
     */
    public function relationshipSnapshots(string $markerKey, int $npcId, string $npcName, int $rowScanLimit, int $perNpcLimit, int $scanLimit): array
    {
        $conditions = [
            'extended_data IS NOT NULL',
            "jsonb_typeof(extended_data) = 'object'",
            // Only snapshots that carry relationship state can produce a timeline row.
            "(jsonb_exists(extended_data, 'relationships') OR jsonb_exists(extended_data, ?))",
        ];
        $parameters = [$markerKey];
        if ($npcId > 0) {
            $conditions[] = 'npc_id = ?';
            $parameters[] = $npcId;
        }
        if ($npcName !== '') {
            $conditions[] = "lower(btrim(COALESCE(npc_name, ''))) = lower(?)";
            $parameters[] = $npcName;
        }
        $where = implode(' AND ', $conditions);
        $parameters[] = $markerKey;
        // Bound the scan by history_id, then keep a per-NPC window so one mass save snapshot cannot crowd every other NPC out.
        $sql = "SELECT history_id, npc_id, npc_name, gamets_last_updated, created_epoch, relationships, history_source
                  FROM (
                      SELECT h.history_id, h.npc_id, h.npc_name, h.gamets_last_updated,
                             EXTRACT(EPOCH FROM h.created)::bigint AS created_epoch,
                             h.extended_data -> 'relationships' AS relationships,
                             h.extended_data ->> ? AS history_source,
                             row_number() OVER (PARTITION BY h.npc_id ORDER BY h.history_id DESC) AS rn
                      FROM (
                          SELECT history_id, npc_id, npc_name, gamets_last_updated, created, extended_data
                          FROM core_npc_history WHERE $where ORDER BY history_id DESC LIMIT ".max(1, $rowScanLimit)."
                      ) h
                  ) s
                  WHERE s.rn <= ".max(1, $perNpcLimit)." ORDER BY s.history_id DESC LIMIT ".max(1, $scanLimit);

        // The marker key is bound twice: once in the select list (first placeholder) and once in the inner where.
        $ordered = [$parameters[count($parameters) - 1], ...array_slice($parameters, 0, count($parameters) - 1)];
        try {
            return $this->connection()->fetchAllAssociative($sql, $ordered);
        } catch (\Doctrine\DBAL\Exception $error) {
            throw new \RuntimeException('Snapshot query failed: '.$error->getMessage(), 0, $error);
        }
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcHistory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcHistory::class)->findBy([], ['historyId' => 'ASC'], max(1, $limit)));
    }
}

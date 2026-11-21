<?php

// Reads core_context_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractContextEvent;

/** Finds rows of core_context_event. */
final class ContextEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractContextEvent
    {
        return $this->fresh()->find(AbstractContextEvent::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractContextEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractContextEvent::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * The newest event of this type that carries a party payload (by game time, then insert time): party, localts, ts.
     *
     * @return array<string, mixed>|null
     */
    public function latestPartyRow(string $type): ?array
    {
        return $this->firstRow(
            "SELECT party, localts, ts FROM core_context_event WHERE type = :type AND COALESCE(party, '') <> '' ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0",
            ['type' => $type]
        );
    }

    /**
     * The newest events of this type that carry a party payload, by game time: gamets, party, ts.
     *
     * @return list<array<string, mixed>>
     */
    public function latestPartyRows(string $type, int $limit): array
    {
        return $this->rows(
            "SELECT gamets, party, ts FROM core_context_event WHERE type = :type AND COALESCE(party, '') <> '' ORDER BY gamets DESC LIMIT {$limit} OFFSET 0",
            ['type' => $type]
        );
    }

    /**
     * The newest world-context payload row by local time: party and data text.
     *
     * @return array<string, mixed>|null
     */
    public function latestWorldContextRow(): ?array
    {
        return $this->firstRow(
            "SELECT party, data FROM core_context_event WHERE type = 'world_context' AND COALESCE(party, '') <> '' ORDER BY localts DESC, ts DESC, rowid DESC LIMIT 1",
            []
        );
    }

    /**
     * Recent player/NPC input rows that name their audience: rowid, type, data, people, newest first, at most 120.
     *
     * @return list<array<string, mixed>>
     */
    public function recentInputRowsWithPeople(int $cutoff): array
    {
        return $this->rows(
            "SELECT rowid, type, data, people FROM core_context_event
             WHERE localts > :cutoff AND type IN ('chat','prechat','inputtext','inputtext_s','narrator_inputtext')
               AND people IS NOT NULL AND TRIM(people) <> '' ORDER BY rowid DESC LIMIT 120",
            ['cutoff' => $cutoff]
        );
    }

    /**
     * The ten newest info-action, item-found and item-transfer events by game time: gamets, data, ts, type.
     *
     * @return list<array<string, mixed>>
     */
    public function recentItemAndActionEvents(): array
    {
        return $this->rows(
            "SELECT gamets, data, ts, type FROM core_context_event WHERE type IN ('infoaction','itemfound','itemtransfer') ORDER BY gamets DESC LIMIT 10 OFFSET 0",
            []
        );
    }

    /**
     * Input-text events whose audience contains this actor name (a LIKE match, wildcards in the name keep their meaning),
     * newest first: gamets, data, ts.
     *
     * @return list<array<string, mixed>>
     */
    public function inputEventsForActor(string $actor): array
    {
        return $this->rows(
            "SELECT gamets, data, ts FROM core_context_event
             WHERE type IN ('inputtext','inputtext_s','narrator_inputtext') AND people LIKE '%' || :actor || '%'
             ORDER BY gamets DESC, ts DESC",
            ['actor' => $actor]
        );
    }

    /**
     * Rechat and narration events of the last two minutes of local time, newest first, at most 10: type, data, gamets.
     *
     * @return list<array<string, mixed>>
     */
    public function recentRechatRows(int $cutoff): array
    {
        return $this->rows(
            "SELECT type, data, gamets FROM core_context_event a WHERE type IN ('rechat','narration') AND localts > :cutoff
             ORDER BY gamets DESC, ts DESC LIMIT 10 OFFSET 0",
            ['cutoff' => $cutoff]
        );
    }

    /** The data of the newest function-return event that mentions this actor (a LIKE match), or null. */
    public function latestFunctionReturnData(string $actor): ?string
    {
        $row = $this->firstRow(
            "SELECT a.data AS data FROM core_context_event a WHERE data LIKE '%' || :actor || '%' AND type IN ('funcret')
             ORDER BY gamets DESC, ts DESC LIMIT 1 OFFSET 0",
            ['actor' => $actor]
        );

        return $row === null ? null : (string) $row['data'];
    }

    /** Whether a death event says this actor was defeated or killed (a LIKE match on the event text). */
    public function actorHasDied(string $actor): bool
    {
        return $this->firstRow(
            "SELECT 1 AS n, gamets FROM core_context_event WHERE type = 'death'
               AND (data LIKE '%defeated ' || :actor || '%' OR data LIKE '%killed ' || :actor || '%')
             ORDER BY gamets DESC LIMIT 1",
            ['actor' => $actor]
        ) !== null;
    }

    /**
     * The 150 newest events that mention this actor (a LIKE match) and are not bookkeeping types, with the narrator prefix
     * the event kind implies: one column, data.
     *
     * @return list<array<string, mixed>>
     */
    public function eventDataMentioning(string $actor): array
    {
        return $this->rows(
            "SELECT CASE
                 WHEN type LIKE 'info%' OR type LIKE 'death%' OR type LIKE 'funcret%' OR type LIKE 'location%' OR data LIKE '%background chat%' THEN 'The Narrator:'
                 ELSE ''
               END || a.data AS data
             FROM core_context_event a WHERE data LIKE '%' || :actor || '%'
               AND type <> 'combatend' AND type <> 'bored' AND type <> 'init' AND type <> 'lockpicked' AND type <> 'info' AND type <> 'funcret' AND type <> 'quest'
               AND type <> 'user_input' AND data NOT ILIKE '%sSubtitle%' AND data NOT ILIKE '%sSpeakerName%'
               AND type <> 'funccall' AND type <> 'togglemodel' ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC LIMIT 150 OFFSET 0",
            ['actor' => $actor]
        );
    }

    /** The largest positive game timestamp, as the driver returns it (null when there is none). */
    public function maxGameTimestamp(): ?string
    {
        $row = $this->firstRow('SELECT MAX(gamets) AS m_gts FROM core_context_event WHERE (gamets > 0)', []);

        return $row === null || $row['m_gts'] === null ? null : (string) $row['m_gts'];
    }

    /** The largest insert time among events with a positive game timestamp, as the driver returns it (null when there is none). */
    public function maxTimestamp(): ?string
    {
        $row = $this->firstRow('SELECT MAX(ts) AS m_gts FROM core_context_event WHERE (gamets > 0)', []);

        return $row === null || $row['m_gts'] === null ? null : (string) $row['m_gts'];
    }

    /** The newest game timestamp of any event, as the driver returns it, or null when there are no events. */
    public function newestGameTimestamp(): ?string
    {
        $row = $this->firstRow('SELECT gamets AS gamets FROM core_context_event ORDER BY gamets DESC LIMIT 1', []);

        return $row === null || $row['gamets'] === null ? null : (string) $row['gamets'];
    }

    /**
     * The newest event with a positive game timestamp with its game date in every converted format (needs the convert_gamets
     * SQL functions): gamets and the fallout_* columns and rowid.
     *
     * @return array<string, mixed>|null
     */
    public function latestGameDateRow(): ?array
    {
        return $this->firstRow(
            "SELECT gamets,
                convert_gamets2fallout_date(gamets) AS fallout_date,
                convert_gamets2fallout_long_date(gamets) AS fallout_long_date,
                convert_gamets2fallout_long_date2(gamets) AS fallout_long_date2,
                convert_gamets2days(gamets) AS fallout_days,
                convert_gamets2hours(gamets) AS fallout_hours,
                convert_gamets2gregorian_date(gamets) AS fallout_gregorian_date,
                ((gamets * 0.0000001) - floor(gamets * 0.0000001)) AS fallout_time,
                rowid
             FROM core_context_event WHERE (gamets > 0) ORDER BY gamets DESC, ts DESC LIMIT 1",
            []
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function firstRow(string $sql, array $parameters): ?array
    {
        $rows = $this->rows($sql, $parameters);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Reads as the legacy fetchAll did: a failed statement reads as no rows.
     *
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        try {
            return $this->connection()->fetchAllAssociative($sql, $parameters);
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}

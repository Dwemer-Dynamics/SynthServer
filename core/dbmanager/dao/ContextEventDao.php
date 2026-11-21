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

    private const ADVENTURE_TYPES = "type IN ('im_alive', 'chat', 'infoaction', 'rpg_lvlup', 'rechat', 'quest', 'itemfound', 'inputtext', 'goodnight', 'goodmorning', 'death', 'combatendmighty', 'combatend', 'lockpicked')";

    /** The UTC date (YYYY-MM-DD) of the newest adventure-log event, or null when there is none. */
    public function adventureLatestDate(): ?string
    {
        $value = $this->connection()->fetchOne("SELECT to_char(to_timestamp(localts::double precision) AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS event_date
            FROM public.core_context_event WHERE ".self::ADVENTURE_TYPES.' ORDER BY localts DESC LIMIT 1');

        return $value === false || $value === null ? null : (string) $value;
    }

    /** Adventure-log events inside a local timestamp window (inclusive), oldest first. @return list<array<string, mixed>> */
    public function adventureEventsBetween(int $start, int $end): array
    {
        return $this->connection()->fetchAllAssociative('SELECT type, data, people, location, localts, gamets FROM public.core_context_event
            WHERE '.self::ADVENTURE_TYPES.' AND to_timestamp(localts::double precision) BETWEEN to_timestamp(CAST(? AS double precision)) AND to_timestamp(CAST(? AS double precision))
            ORDER BY localts ASC', [$start, $end]);
    }

    /** Every adventure-log event, oldest first. @return list<array<string, mixed>> */
    public function adventureEventsAll(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT type, data, people, location, localts, gamets FROM public.core_context_event
            WHERE '.self::ADVENTURE_TYPES.' ORDER BY localts ASC');
    }

    /**
     * The events the adventure page lists for one selected day: every event with a game time in Fallout mode (the page
     * filters by game date), else those from $start up to but excluding $end.
     *
     * @return list<array<string, mixed>>
     */
    public function adventureEventsForDay(bool $falloutTime, int $start, int $end): array
    {
        $where = $falloutTime ? 'gamets > 0' : 'localts >= ? AND localts < ?';

        return $this->connection()->fetchAllAssociative('SELECT type, data, people, location, localts, gamets FROM public.core_context_event
            WHERE '.self::ADVENTURE_TYPES.' AND ('.$where.') ORDER BY localts ASC', $falloutTime ? [] : [$start, $end]);
    }

    /** Adventure-log events with a game time, for the calendar highlights. @return list<array<string, mixed>> */
    public function adventureCalendarEvents(bool $falloutTime): array
    {
        $sort = $falloutTime ? 'gamets' : 'localts';

        return $this->connection()->fetchAllAssociative("SELECT DISTINCT gamets, localts, type, data, people, location,
                to_char(to_timestamp(CAST(localts AS bigint)), 'YYYY-MM-DD') AS date, {$sort} AS sort_field
            FROM public.core_context_event WHERE ".self::ADVENTURE_TYPES.' AND gamets > 0 ORDER BY sort_field ASC');
    }

    /** The people column of every event in a game-time window (from exclusive, to inclusive). @return list<array<string, mixed>> */
    public function peopleBetween(int $gametsFrom, int $gametsTo): array
    {
        return $this->connection()->fetchAllAssociative('SELECT people FROM core_context_event WHERE gamets > ? AND gamets <= ?', [$gametsFrom, $gametsTo]);
    }

    /** How many context events there are, or null when the count fails. */
    public function countRows(): ?int
    {
        try {
            return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_context_event');
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }
    }

    /** Number of events, all or of one type (home dashboard). */
    public function dashboardCount(?string $type = null): int
    {
        if ($type === null) {
            return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_context_event');
        }

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_context_event WHERE type = ?', [$type]);
    }

    /**
     * Newest events by ts, optionally of these types only (home dashboard).
     *
     * @param list<string>|null $types
     * @return list<array<string, mixed>>
     */
    public function dashboardRecent(?array $types, int $limit): array
    {
        $where = '';
        $params = [];
        if ($types !== null) {
            $where = ' WHERE type IN ('.implode(',', array_fill(0, count($types), '?')).')';
            $params = array_values($types);
        }

        return $this->connection()->fetchAllAssociative(
            'SELECT type, data, people, location, gamets, localts, ts FROM core_context_event'.$where.' ORDER BY ts DESC LIMIT '.max(1, $limit),
            $params
        );
    }

    /** Event counts per type, most frequent first (home dashboard). @return list<array<string, mixed>> */
    public function dashboardTypeCounts(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT type, COUNT(*) AS event_count FROM core_context_event GROUP BY type ORDER BY COUNT(*) DESC, type ASC');
    }

    /** The newest world_context event, as a list of at most one row (home dashboard). @return list<array<string, mixed>> */
    public function dashboardLatestWorldContext(): array
    {
        return $this->connection()->fetchAllAssociative("SELECT data, location, party, gamets, localts, ts FROM core_context_event WHERE type='world_context' ORDER BY gamets DESC, ts DESC LIMIT 1");
    }

    /** Highest gamets across all events, or null when there are none. */
    public function maxGamets(): ?int
    {
        $max = $this->connection()->fetchOne('SELECT MAX(gamets) FROM public.core_context_event');

        return $max === false || $max === null ? null : (int) $max;
    }

    /** The newest player input events (typed or spoken), newest first, at most $limit. @return list<array<string, mixed>> */
    public function latestPlayerInputs(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT data, type, gamets, ts FROM core_context_event WHERE type IN ('inputtext','inputtext_s') ORDER BY gamets DESC, ts DESC LIMIT ".max(1, $limit)
        );
    }

    /** The highest gamets and the newest ts recorded at it, as stored; both null when there are no events. @return array{last_gamets: mixed, ts: mixed} */
    public function latestPosition(): array
    {
        $last = $this->connection()->fetchOne('SELECT max(gamets) FROM core_context_event');
        $ts = $this->connection()->fetchOne('SELECT max(ts) FROM core_context_event WHERE gamets = ?', [$last === false ? null : $last]);

        return ['last_gamets' => $last === false ? null : $last, 'ts' => $ts === false ? null : $ts];
    }
}

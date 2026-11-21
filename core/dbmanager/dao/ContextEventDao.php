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
}

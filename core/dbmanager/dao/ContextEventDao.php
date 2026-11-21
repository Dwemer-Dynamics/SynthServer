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

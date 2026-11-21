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

    /** Deletes every event. */
    public function deleteAll(): void
    {
        $this->connection()->executeStatement('DELETE FROM public.core_context_event');
    }

    /** Deletes the newest $count events (gamets, ts, localts, rowid) whose type is not in $excludedTypes. */
    public function deleteLatestExcluding(array $excludedTypes, int $count): void
    {
        [$in, $params] = $this->typeList($excludedTypes);
        $this->connection()->executeStatement(
            'DELETE FROM public.core_context_event WHERE rowid IN (SELECT rowid FROM public.core_context_event'
            ." WHERE type NOT IN ($in) ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC LIMIT ?)",
            array_merge($params, [$count])
        );
    }

    /** Which of $rowids still exist. @param list<int> $rowids @return list<int> */
    public function existingRowids(array $rowids): array
    {
        if ($rowids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($rowids), '?'));

        return array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT rowid FROM public.core_context_event WHERE rowid IN ($marks)",
            array_values($rowids)
        ));
    }

    /** Deletes the given events. @param list<int> $rowids */
    public function deleteRowids(array $rowids): void
    {
        if ($rowids === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($rowids), '?'));
        $this->connection()->executeStatement("DELETE FROM public.core_context_event WHERE rowid IN ($marks)", array_values($rowids));
    }

    /** Events whose type is not excluded. @param list<string> $excludedTypes */
    public function countExcluding(array $excludedTypes): int
    {
        [$in, $params] = $this->typeList($excludedTypes);

        return intval($this->connection()->fetchOne("SELECT COUNT(*) FROM public.core_context_event WHERE type NOT IN ($in)", $params));
    }

    /**
     * One page of events whose type is not excluded, newest game time first.
     *
     * @param list<string> $excludedTypes
     * @return list<array<string,mixed>>
     */
    public function pageExcluding(array $excludedTypes, int $limit, int $offset): array
    {
        [$in, $params] = $this->typeList($excludedTypes);

        return $this->connection()->fetchAllAssociative(
            'SELECT type, data, gamets, localts, ts, rowid FROM public.core_context_event a'
            ." WHERE type NOT IN ($in) ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    }

    /** Every event with all columns, newest ts first. @return list<array<string,mixed>> */
    public function allNewestTsFirst(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT a.*, rowid FROM public.core_context_event a ORDER BY ts DESC');
    }

    /**
     * Events for the Events page and its API: types not in $excludedTypes (and equal to $selectedType when given), or only
     * the types in $onlyTypes when that is given, filtered by game time or by row id, in the page order.
     *
     * @param list<string>      $excludedTypes
     * @param list<string>|null $onlyTypes
     * @return list<array<string,mixed>>
     */
    public function visible(array $excludedTypes, ?string $selectedType, ?array $onlyTypes, int $limit, int $offset = 0, int $sinceGamets = 0, int $sinceRowid = 0): array
    {
        [$where, $params] = $this->visibleWhere($excludedTypes, $selectedType, $onlyTypes);
        $paged = false;
        if ($sinceGamets > 0) {
            $where .= ' AND gamets >= ?';
            $params[] = $sinceGamets;
        } elseif ($sinceRowid > 0) {
            $where .= ' AND rowid > ?';
            $params[] = $sinceRowid;
            $limit = 50;
        } else {
            $paged = true;
        }
        $sql = 'SELECT type, data, people, gamets, localts, ts, rowid FROM public.core_context_event a WHERE '.$where
            .' ORDER BY localts DESC, rowid DESC, gamets DESC, ts DESC LIMIT ?';
        $params[] = $limit;
        if ($paged) {
            $sql .= ' OFFSET ?';
            $params[] = $offset;
        }

        return $this->connection()->fetchAllAssociative($sql, $params);
    }

    /** Number of events the same filter as visible() selects. @param list<string> $excludedTypes @param list<string>|null $onlyTypes */
    public function countVisible(array $excludedTypes, ?string $selectedType, ?array $onlyTypes): int
    {
        [$where, $params] = $this->visibleWhere($excludedTypes, $selectedType, $onlyTypes);

        return intval($this->connection()->fetchOne("SELECT COUNT(*) FROM public.core_context_event WHERE $where", $params));
    }

    /**
     * Of the newest $scan events, those whose type is in $types, newest first.
     *
     * @param list<string> $types
     * @return list<array<string,mixed>>
     */
    public function recentOfTypes(array $types, int $scan = 5000): array
    {
        [$in, $params] = $this->typeList($types);

        return $this->connection()->fetchAllAssociative(
            'SELECT rowid, type, data, party FROM (SELECT rowid, type, data, party FROM public.core_context_event ORDER BY rowid DESC LIMIT ?) recent'
            ." WHERE type IN ($in) ORDER BY rowid DESC",
            array_merge([$scan], $params)
        );
    }

    /**
     * Count per type among the newest $scan events, leaving out $excludedTypes; most frequent first.
     *
     * @param list<string> $excludedTypes
     * @return list<array<string,mixed>>
     */
    public function recentTypeCounts(array $excludedTypes, int $scan = 5000): array
    {
        [$in, $params] = $this->typeList($excludedTypes);

        return $this->connection()->fetchAllAssociative(
            'SELECT type, COUNT(*) AS count FROM (SELECT rowid, type FROM public.core_context_event ORDER BY rowid DESC LIMIT ?) recent'
            ." WHERE type NOT IN ($in) GROUP BY type ORDER BY COUNT(*) DESC, LOWER(type) ASC",
            array_merge([$scan], $params)
        );
    }

    /** @return array{0: string, 1: list<string>} placeholders and values; an empty list matches no type name */
    private function typeList(array $types): array
    {
        $types = array_values(array_map('strval', $types));
        if ($types === []) {
            return ['NULL', []];
        }

        return [implode(',', array_fill(0, count($types), '?')), $types];
    }

    /** @return array{0: string, 1: list<string>} */
    private function visibleWhere(array $excludedTypes, ?string $selectedType, ?array $onlyTypes): array
    {
        if ($onlyTypes !== null) {
            [$in, $params] = $this->typeList($onlyTypes);

            return ["type IN ($in)", $params];
        }
        [$in, $params] = $this->typeList($excludedTypes);
        $where = "type NOT IN ($in)";
        if ($selectedType !== null && $selectedType !== '') {
            $where .= ' AND type = ?';
            $params[] = $selectedType;
        }

        return [$where, $params];
    }
}

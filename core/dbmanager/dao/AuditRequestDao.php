<?php

// Reads core_audit_request. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractAuditRequest;

/** Finds rows of core_audit_request. */
final class AuditRequestDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractAuditRequest
    {
        return $this->fresh()->find(AbstractAuditRequest::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractAuditRequest> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractAuditRequest::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * Successful and total requests in a period: 24h, 72h, 7d or lifetime (home dashboard).
     *
     * @return array<string, mixed>|null
     */
    public function dashboardOutcomeCounts(string $period): ?array
    {
        $conditions = [
            '24h' => "created_at >= NOW() - INTERVAL '24 HOURS'",
            '72h' => "created_at >= NOW() - INTERVAL '72 HOURS'",
            '7d' => "created_at >= NOW() - INTERVAL '7 DAYS'",
            'lifetime' => 'TRUE',
        ];
        if (!isset($conditions[$period])) {
            throw new \InvalidArgumentException('Unknown audit period.');
        }
        $row = $this->connection()->fetchAssociative("SELECT COUNT(*) FILTER (WHERE result='Ok') AS success, COUNT(*) AS total FROM core_audit_request WHERE {$conditions[$period]}");

        return $row === false ? null : $row;
    }

    /** Result, url and connector of the newest audited request, or null. @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT result, url, connector FROM core_audit_request ORDER BY id DESC LIMIT 1');

        return $row === false ? null : $row;
    }

    /** Lower-case column names of the table as installed (older installs lack some). @return list<string> */
    public function columnNames(): array
    {
        return array_map('strval', $this->connection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'core_audit_request'"
        ));
    }

    /**
     * One page of rows, selecting only the given columns. $columns maps result alias to source column and $order lists
     * "column DESC" parts; every name is checked against the installed columns, and an empty list orders by position.
     *
     * @param array<string,string> $columns
     * @param list<string>         $order
     * @return list<array<string,mixed>>
     */
    public function page(array $columns, array $order, int $limit, int $offset): array
    {
        $installed = $this->columnNames();
        $select = [];
        foreach ($columns as $alias => $source) {
            if (!in_array($source, $installed, true) || !preg_match('/^[a-z_]+$/', (string)$alias)) {
                throw new \InvalidArgumentException('Unknown core_audit_request column.');
            }
            $select[] = '"'.$source.'"'.($source === $alias ? '' : ' AS "'.$alias.'"');
        }
        $orderParts = [];
        foreach ($order as $part) {
            if (!preg_match('/^([a-z_]+) DESC$/', $part, $m) || !in_array($m[1], $installed, true)) {
                throw new \InvalidArgumentException('Unknown core_audit_request order column.');
            }
            $orderParts[] = '"'.$m[1].'" DESC';
        }

        return $this->connection()->fetchAllAssociative(
            'SELECT '.implode(', ', $select).' FROM public.core_audit_request ORDER BY '.($orderParts === [] ? '1 DESC' : implode(', ', $orderParts)).' LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    /** Number of rows. */
    public function countAll(): int
    {
        return intval($this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_audit_request'));
    }

    /** Deletes every row. */
    public function deleteAll(): void
    {
        $this->connection()->executeStatement('DELETE FROM public.core_audit_request');
    }

    /** Deletes every relationship-connector row, or only those older than $interval ("1 day", "1 week", ...). */
    public function deleteRelationship(?string $olderThanInterval = null): void
    {
        if ($olderThanInterval === null) {
            $this->connection()->executeStatement("DELETE FROM public.core_audit_request WHERE connector LIKE '%Relationship%'");

            return;
        }
        $this->connection()->executeStatement(
            "DELETE FROM public.core_audit_request WHERE connector LIKE '%Relationship%' AND created_at < NOW() - CAST(? AS interval)",
            [$olderThanInterval]
        );
    }

    /** Relationship-connector rows in total. */
    public function relationshipTotal(): int
    {
        return intval($this->connection()->fetchOne("SELECT COUNT(*) FROM public.core_audit_request WHERE connector LIKE '%Relationship%'"));
    }

    /** Relationship-connector rows created in the last hour. */
    public function relationshipRecent(): int
    {
        return intval($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM public.core_audit_request WHERE connector LIKE '%Relationship%' AND created_at > NOW() - INTERVAL '1 hour'"
        ));
    }

    /**
     * One page of relationship-connector rows, newest first, optionally only requests whose JSON "type" starts with $type.
     *
     * @return list<array<string,mixed>>
     */
    public function relationshipPage(?string $type, int $limit, int $offset): array
    {
        $sql = 'SELECT rowid, created_at, request, result, connector, usage FROM public.core_audit_request'
            ." WHERE connector LIKE '%Relationship%'";
        $params = [];
        if ($type !== null && $type !== '') {
            $sql .= ' AND request LIKE ?';
            $params[] = '%"type":"'.$type.'%';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->connection()->fetchAllAssociative($sql, $params);
    }

    /**
     * Cost per request type (the part of the connector after "/") for today, one date (Y-m-d) or one ISO week (YYYY-Www),
     * most expensive first.
     *
     * @param 'today'|'date'|'week' $filter
     * @return list<array<string,mixed>>
     */
    public function costByRequestType(string $filter, ?string $value = null): array
    {
        $params = [];
        if ($filter === 'date') {
            $where = 'WHERE DATE(created_at) = ?';
            $params[] = $value;
        } elseif ($filter === 'week') {
            $where = "WHERE TO_CHAR(created_at, 'YYYY-\"W\"WW') = ?";
            $params[] = $value;
        } else {
            $where = 'WHERE DATE(created_at) = CURRENT_DATE';
        }

        return $this->connection()->fetchAllAssociative(
            "SELECT split_part(connector, '/', 2) AS type_of_request, SUM((usage->>'cost')::numeric) AS total_cost"
            ." FROM public.core_audit_request $where GROUP BY split_part(connector, '/', 2) ORDER BY total_cost DESC",
            $params
        );
    }
}

<?php

// Statement helpers for DAOs whose callers keep the legacy pg_query_params contract: a failed statement is reported as null
// (the caller raises its own "query failed" error) instead of an exception, and an empty result is not a failure.
// Used together with UsesEntityManager. SQL uses :p1, :p2, ... placeholders; the parameter list is bound in order.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;

trait FailSoftQueries
{
    /** @return list<array<string, mixed>>|null every row, or null when the statement failed */
    private function rowsOrNull(string $sql, array $parameters = []): ?array
    {
        [$sql, $bound] = self::positional($sql, $parameters);
        try {
            return $this->connection()->fetchAllAssociative($sql, $bound);
        } catch (DbalException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null the first row, [] when there is none, null when the statement failed */
    private function rowOrNull(string $sql, array $parameters = []): ?array
    {
        $rows = $this->rowsOrNull($sql, $parameters);

        return $rows === null ? null : ($rows[0] ?? []);
    }

    /** Runs a statement that returns no rows; false when it failed. */
    private function statementOk(string $sql, array $parameters = []): bool
    {
        [$sql, $bound] = self::positional($sql, $parameters);
        try {
            $this->connection()->executeStatement($sql, $bound);

            return true;
        } catch (DbalException) {
            return false;
        }
    }

    /**
     * The shared session driver numbers each placeholder occurrence, so a repeated :pN must be bound once per occurrence:
     * every :pN becomes a positional placeholder and its value is appended in order of appearance.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function positional(string $sql, array $parameters): array
    {
        $values = array_values($parameters);
        $bound = [];
        $sql = preg_replace_callback('/(?<!:):p(\d+)\b/', static function (array $match) use ($values, &$bound): string {
            $bound[] = $values[(int) $match[1] - 1];

            return '?';
        }, $sql);

        return [$sql, $bound];
    }
}

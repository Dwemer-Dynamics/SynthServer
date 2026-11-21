<?php

// Reads core_memory. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemory;

/** Finds rows of core_memory. */
final class MemoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $rowid): ?AbstractMemory
    {
        return $this->fresh()->find(AbstractMemory::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemory::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * Inserts one diary memory row (column => value, every column a plain scalar); returns false when the statement fails.
     *
     * @param array<string, scalar|null> $row
     */
    public function insertDiaryEntry(array $row): bool
    {
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string) $column)) {
                throw new \InvalidArgumentException('Invalid core_memory column.');
            }
        }
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_memory ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
                array_values($row)
            );
        } catch (\Throwable $error) {
            return false;
        }

        return true;
    }
}

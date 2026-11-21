<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

use Synth\Core\DbManager\Dao\ExtensionDataDao;

// An extension's door to its own ext_<id>_* tables. It needs the storage.tables permission. Table names are built here
// (table('talks') is ext_<id>_talks), values go in as named parameters, and every statement may name only the extension's
// own tables, so one extension cannot read or change another's data or any core table.
final class ExtensionDatabase
{
    public function __construct(private string $id, private ExtensionDataDao $dao) {}

    /** The full name of one of this extension's tables. */
    public function table(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $name) !== 1) throw new \InvalidArgumentException('extension_table_name');
        return MigrationChecker::prefix($this->id).$name;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->dao->fetchAll($this->checked($sql, ['SELECT']), $params);
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->dao->fetchOne($this->checked($sql, ['SELECT']), $params);
    }

    /** Runs one INSERT, UPDATE or DELETE and returns the number of rows changed. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->dao->run($this->checked($sql, ['INSERT', 'UPDATE', 'DELETE']), $params);
    }

    private function checked(string $sql, array $verbs): string
    {
        $problem = MigrationChecker::checkStatement($sql, $this->id, $verbs);
        if ($problem !== null) throw new \InvalidArgumentException('extension_sql_refused: '.$problem);
        return $sql;
    }
}

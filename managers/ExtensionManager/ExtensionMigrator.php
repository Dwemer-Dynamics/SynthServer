<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

use Synth\Core\DbManager\Dao\ExtensionDataDao;

// Per-extension migrations: extensions/<id>/migrations/NNNN_name.sql, applied once each in name order and recorded in
// core_extension_migration with their SHA-256. Every file is checked by MigrationChecker before anything runs (an extension
// with a refused file cannot be enabled), each file runs in one transaction with its ledger row, and a file that changed
// after it was applied is refused instead of re-run. Files only touch the extension's own ext_<id>_* relations.
final class ExtensionMigrator
{
    public const FILE_PATTERN = '/^(\d{4}_[a-z0-9_]{1,60})\.sql$/D';
    public const MAX_FILES = 50;

    public function __construct(private string $root, private ?ExtensionDataDao $dao = null) {}

    /** @return array<string, string> migration name => path, in order */
    public function files(string $id): array
    {
        $found = [];
        foreach (glob($this->root.'/extensions/'.$id.'/migrations/*.sql') ?: [] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path), $m) === 1) $found[$m[1]] = $path;
        }
        ksort($found);
        return $found;
    }

    public function hasMigrations(string $id): bool
    {
        return is_dir($this->root.'/extensions/'.$id.'/migrations') && glob($this->root.'/extensions/'.$id.'/migrations/*.sql') !== [];
    }

    /** @return list<string> problems with the files (names, size, content); empty when all pass the check */
    public function fileProblems(string $id): array
    {
        $problems = [];
        $dir = $this->root.'/extensions/'.$id.'/migrations';
        foreach (glob($dir.'/*.sql') ?: [] as $path) {
            $base = basename($path);
            if (preg_match(self::FILE_PATTERN, $base) !== 1 && !str_ends_with($base, '.down.sql')) $problems[] = "migration file name not allowed: {$base}";
        }
        $files = $this->files($id);
        if (count($files) > self::MAX_FILES) $problems[] = 'too many migration files';
        foreach ($files as $name => $path) {
            $sql = (string) file_get_contents($path);
            if (strlen($sql) > 262144) { $problems[] = "{$name}: file too large"; continue; }
            foreach (MigrationChecker::checkFile($sql, $id)['problems'] as $problem) $problems[] = "{$name}: {$problem}";
        }
        return $problems;
    }

    /** @return list<string> names not applied yet */
    public function pending(string $id): array
    {
        $applied = $this->dao()->appliedMigrations($id);
        return array_values(array_filter(array_keys($this->files($id)), static fn(string $name): bool => !isset($applied[$name])));
    }

    /**
     * Applies the pending files.
     *
     * @return list<string> the names applied now
     * @throws \RuntimeException when a file is refused, an applied file changed, or a statement fails (that file is rolled back)
     */
    public function migrate(string $id): array
    {
        $problems = $this->fileProblems($id);
        if ($problems !== []) throw new \RuntimeException('migration_refused: '.implode('; ', $problems));
        $dao = $this->dao();
        $applied = $dao->appliedMigrations($id);
        $files = $this->files($id);
        foreach ($applied as $name => $sha) {
            if (isset($files[$name]) && hash('sha256', (string) file_get_contents($files[$name])) !== $sha) {
                throw new \RuntimeException('migration_changed: '.$name);
            }
        }
        $done = [];
        foreach ($files as $name => $path) {
            if (isset($applied[$name])) continue;
            $sql = (string) file_get_contents($path);
            $statements = MigrationChecker::checkFile($sql, $id)['statements'];
            $dao->run('BEGIN');
            try {
                foreach ($statements as $statement) $dao->run($statement);
                $dao->recordMigration($id, $name, hash('sha256', $sql));
                $dao->run('COMMIT');
            } catch (\Throwable $error) {
                try { $dao->run('ROLLBACK'); } catch (\Throwable) {}
                throw new \RuntimeException('migration_failed: '.$name);
            }
            $done[] = $name;
        }
        return $done;
    }

    private function dao(): ExtensionDataDao
    {
        return $this->dao ?? ExtensionDataDao::current();
    }
}

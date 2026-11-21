<?php

declare(strict_types=1);

// Manage SYNTH server extensions:  php bin/extensions.php list | validate | enable <id> | disable <id> | migrate <id>
// Writes conf/extensions.json (the enabled list). enable and migrate also apply the extension's own SQL migrations
// (extensions/<id>/migrations/*.sql, checked first) to its ext_<id>_* tables, which needs the database.

require_once dirname(__DIR__).'/lib/autoload.php';

use Synth\Managers\ExtensionManager\ExtensionManager;

$manager = new ExtensionManager(dirname(__DIR__));
// The command-line database session, for what needs the extension's own tables.
$bootDatabase = static function (): void {
    require_once dirname(__DIR__).'/lib/runtime_bootstrap.php';
    synthRuntimeBootstrap(dirname(__DIR__).DIRECTORY_SEPARATOR, ['run_db_updates' => false]);
    \Synth\Core\DbManager\Database::boot(\Synth\Bootstrap::db());
};
$command = $argv[1] ?? 'list';
$id = $argv[2] ?? '';

try {
    switch ($command) {
        case 'list':
        case 'validate':
            $found = $manager->discover();
            if ($found === []) echo "No extensions installed.\n";
            $bad = 0;
            foreach ($found as $name => $info) {
                $status = $info['errors'] !== [] ? 'INVALID' : ($info['enabled'] ? 'enabled' : 'disabled');
                echo sprintf("%-24s %-8s %s\n", $name, $status, $info['manifest']['version'] ?? '');
                foreach ($info['errors'] as $error) { echo "    - {$error}\n"; $bad++; }
            }
            foreach ($manager->failures() as $name => $reason) echo "LOAD FAILED {$name}: {$reason}\n";
            exit($command === 'validate' && $bad > 0 ? 1 : 0);
        case 'migrate':
            $bootDatabase();
            $done = (new \Synth\Managers\ExtensionManager\ExtensionMigrator(dirname(__DIR__)))->migrate($id);
            echo $done === [] ? "Nothing to apply for {$id}.
" : 'Applied: '.implode(', ', $done)."
";
            break;
        case 'enable':
            if ((new \Synth\Managers\ExtensionManager\ExtensionMigrator(dirname(__DIR__)))->hasMigrations($id)) $bootDatabase();
            $manager->enable($id);
            echo "Enabled {$id}.\n";
            break;
        case 'disable':
            $manager->disable($id);
            echo "Disabled {$id}.\n";
            break;
        default:
            fwrite(STDERR, "Usage: php bin/extensions.php list | validate | enable <id> | disable <id>\n");
            exit(2);
    }
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}

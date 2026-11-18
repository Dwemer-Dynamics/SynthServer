<?php

declare(strict_types=1);

// Manage SYNTH server extensions:  php bin/extensions.php list | validate | enable <id> | disable <id>
// Writes only conf/extensions.json (the enabled list). It never reads or writes the database.

require_once dirname(__DIR__).'/managers/ExtensionManager/ExtensionManager.php';

use Synth\Managers\ExtensionManager\ExtensionManager;

$manager = new ExtensionManager(dirname(__DIR__));
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
        case 'enable':
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

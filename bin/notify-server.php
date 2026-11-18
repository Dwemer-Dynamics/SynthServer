<?php

/**
 * Runs the wake-up notification channel for the plugin.
 *
 *   php bin/notify-server.php [--host=127.0.0.1] [--port=8091] [--internal-port=8092]
 *
 * Defaults come from SYNTH_NOTIFY_HOST / SYNTH_NOTIFY_PORT / SYNTH_NOTIFY_INTERNAL_PORT. The plugin-facing listener
 * binds loopback unless --host says otherwise; remote hosting binds it as the remote-hosting guide describes for the
 * server and opens only that port. The publish listener is always loopback. There is no authentication because a
 * frame carries no content: it only tells the plugin to look again over the ordinary protocol.
 *
 * It is not started by default: service/start.sh and service/manager.php keep their current behaviour, and with the
 * channel absent the plugin polls exactly as before.
 */
declare(strict_types=1);

use Synth\Managers\Notification\NotifyConfig;
use Synth\Managers\Notification\NotifyServer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(host|port|internal-port)=(.+)$/', $argument, $match) !== 1) {
        fwrite(STDERR, "Unknown argument '{$argument}'." . PHP_EOL);
        exit(1);
    }
    $options[$match[1]] = $match[2];
}
$variables = ['host' => 'SYNTH_NOTIFY_HOST', 'port' => 'SYNTH_NOTIFY_PORT', 'internal-port' => 'SYNTH_NOTIFY_INTERNAL_PORT'];
foreach ($variables as $name => $variable) {
    if (isset($options[$name])) {
        putenv($variable . '=' . $options[$name]);
    }
}

try {
    $server = new NotifyServer(NotifyConfig::webSocketAddress(), NotifyConfig::internalAddress());
} catch (Throwable $error) {
    fwrite(STDERR, 'Notification channel not started: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static fn() => $server->stop());
    pcntl_signal(SIGINT, static fn() => $server->stop());
}
fwrite(STDOUT, 'Notification channel listening on ' . NotifyConfig::webSocketAddress()
    . ' (publish on ' . NotifyConfig::internalAddress() . ')' . PHP_EOL);
$server->run();

<?php

/**
 * Keeps the response queue tidy; meant to run from cron, for example once an hour.
 *
 *   php bin/message-maintenance.php [--days=N] [--apply]
 *
 * Retention is N days (default 7, from 1 to 365). Without --apply it only reports what it would remove. With --apply it
 * deletes the dashboard's response log rows (`public.core_response_log`) older than the retention. It always prints one JSON
 * report: how many rows were or would be removed, the response log size, how many finished speech jobs
 * (`core_worker_job`, type `tts`) are older than the retention, and how many history events (`core_context_event`)
 * exist.
 *
 * It also keeps the durable message queue (`core_message_queue`, managers/MessageHandler) tidy. With --apply it marks pending
 * and completed messages past their expiry as expired, cancels the messages whose speech job was cancelled, announces
 * completed messages again whose wake-up did not reach a client (at most five attempts each), and deletes delivered, cancelled
 * and expired messages older than the retention; active work is never deleted. Without --apply the `message_queue` section only
 * counts. Retention: `terminal_older_than_retention` is what a run would delete.
 *
 * Deliberately not touched: `core_context_event` is the conversation history that dialogue, memory and save ancestry
 * read, so it is only counted here, never pruned; finished speech jobs are retired by the speech worker together with
 * their private input files (`synth_tts_cleanup_job_inputs`), so they are only counted. Exit code: 0 done, 1 failed,
 * 2 usage error.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$days = 7;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif (preg_match('/^--days=(\d{1,3})$/', $argument, $match) === 1 && intval($match[1]) >= 1 && intval($match[1]) <= 365) {
        $days = intval($match[1]);
    } else {
        fwrite(STDERR, "usage: php bin/message-maintenance.php [--days=1..365] [--apply]\n");
        exit(2);
    }
}

$root = dirname(__DIR__);
require_once $root . '/lib/autoload.php';
require_once $root . '/lib/runtime_bootstrap.php';

try {
    synthRuntimeBootstrap($root . DIRECTORY_SEPARATOR, ['run_db_updates' => false]);
    $db = \Synth\Bootstrap::db() ?? null;
    if (!is_object($db)) {
        throw new RuntimeException('no database');
    }
    $cutoff = time() - $days * 86400;
    $count = static function (string $sql) use ($db): int {
        $row = $db->fetchOne($sql);
        if (!is_array($row)) {
            throw new RuntimeException('count failed');
        }

        return intval($row['n'] ?? 0);
    };

    $expired = $count('SELECT COUNT(*) AS n FROM public.core_response_log WHERE localts < ' . $cutoff);
    $deleted = 0;
    if ($apply && $expired > 0) {
        if ($db->execQuery('DELETE FROM public.core_response_log WHERE localts < ' . $cutoff) === false) {
            throw new RuntimeException('delete failed');
        }
        $deleted = $expired;
    }

    $queue = \Synth\Managers\MessageHandler\MessageHandler::current();
    $queueActions = ['expired' => 0, 'cancelled' => 0, 'announced' => 0, 'purged' => 0];
    if ($apply) {
        $queueActions = ['expired' => $queue->expire(), 'cancelled' => $queue->cancelCancelledWork(),
            'announced' => $queue->retryNotifications(), 'purged' => $queue->purge($days)];
    }

    $report = [
        'retention_days' => $days,
        'applied' => $apply,
        'response_log' => [
            'older_than_retention' => $expired,
            'deleted' => $deleted,
            'remaining' => $count('SELECT COUNT(*) AS n FROM public.core_response_log'),
        ],
        'finished_speech_jobs_older_than_retention' => $count("SELECT COUNT(*) AS n FROM public.core_worker_job WHERE job_type='tts' AND state IN ('ready','failed','cancelled','stale') AND updated_at < now() - interval '" . $days . " days'"),
        'message_queue' => $queue->report($days) + ['actions' => $queueActions],
        'history_events' => $count('SELECT COUNT(*) AS n FROM public.core_context_event'),
    ];
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'message-maintenance failed: ' . get_class($error) . "\n");
    exit(1);
}

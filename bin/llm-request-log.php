<?php

/**
 * Inspects, replays and prunes the LLM request log (`public.core_llm_request_log`).
 *
 *   php bin/llm-request-log.php list [--limit=N] [--status=completed|failed|cancelled|running]
 *   php bin/llm-request-log.php show ID
 *   php bin/llm-request-log.php replay ID [--connector=ID] [--send]
 *   php bin/llm-request-log.php prune [--apply]
 *
 * `replay` re-sends a logged request through the same driver (the original connector, or another by id). Without --send it
 * only prints what it would send: a replay is a real provider call and costs what the original cost. A replay is logged as
 * a new row whose replay_of_id is the original, and the report says whether its provider body equals the original's.
 * `prune` applies the retention settings (LLM_REQUEST_LOG_RETENTION_DAYS, default 14, and LLM_REQUEST_LOG_MAX_ROWS, default
 * 5000); without --apply it prints them and does nothing. The same retention also runs with the periodic log trim.
 *
 * Output is JSON on stdout. Secrets were redacted when the rows were written. Exit code: 0 done, 1 failed, 2 usage error.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = "usage: php bin/llm-request-log.php list [--limit=N] [--status=S] | show ID | replay ID [--connector=ID] [--send] | prune [--apply]\n";
$command = $argv[1] ?? '';
$options = [];
$positional = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/D', $argument, $match) === 1) {
        $options[$match[1]] = $match[2] ?? true;
    } else {
        $positional[] = $argument;
    }
}
$knownOptions = ['list' => ['limit', 'status'], 'show' => [], 'replay' => ['connector', 'send'], 'prune' => ['apply']];
if (!isset($knownOptions[$command]) || array_diff(array_keys($options), $knownOptions[$command]) !== []) {
    fwrite(STDERR, $usage);
    exit(2);
}
$id = null;
if (in_array($command, ['show', 'replay'], true)) {
    if (count($positional) !== 1 || preg_match('/^[1-9]\d{0,17}$/D', $positional[0]) !== 1) {
        fwrite(STDERR, $usage);
        exit(2);
    }
    $id = (int) $positional[0];
} elseif ($positional !== []) {
    fwrite(STDERR, $usage);
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/lib/autoload.php';
require_once $root . '/lib/runtime_bootstrap.php';
require_once $root . '/lib/core/llm_connector.class.php';

use Synth\Core\DbManager\Dao\LlmRequestLogDao;
use Synth\Drivers\Llm\Logging\DatabaseLlmRequestRecorder;
use Synth\Drivers\Llm\Logging\LlmRequestReplayer;

$emit = static function (array $report, int $status = 0): never {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    exit($status);
};
$summary = static fn($entry): array => [
    'id' => $entry->getId(),
    'created_at' => $entry->getCreatedAt()->format(DATE_ATOM),
    'status' => $entry->getStatus(),
    'call_kind' => $entry->getCallKind(),
    'purpose' => $entry->getPurpose(),
    'driver' => $entry->getDriver(),
    'model' => $entry->getModel(),
    'connector_id' => $entry->getConnectorId(),
    'duration_ms' => $entry->getDurationMs(),
    'http_status' => $entry->getHttpStatus(),
    'replay_of_id' => $entry->getReplayOfId(),
    'total_tokens' => $entry->getTotalTokens(),
    'error_class' => $entry->getErrorClass(),
];

try {
    synthRuntimeBootstrap($root . DIRECTORY_SEPARATOR, ['run_db_updates' => false]);
    if (!is_object(\Synth\Bootstrap::db())) {
        throw new RuntimeException('no database');
    }
    $dao = LlmRequestLogDao::current();

    if ($command === 'list') {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 20;
        $status = isset($options['status']) && is_string($options['status']) ? $options['status'] : null;
        if ($status !== null && !in_array($status, ['running', 'completed', 'failed', 'cancelled'], true)) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        $emit(['requests' => array_map($summary, $dao->recent($limit, $status))]);
    }
    if ($command === 'show') {
        $entry = $dao->find($id);
        if ($entry === null) {
            $emit(['error' => 'not_found'], 1);
        }
        $emit($summary($entry) + [
            'url' => $entry->getUrl(),
            'request' => $entry->getRequest(),
            'request_body' => $entry->getRequestBody(),
            'request_truncated' => $entry->isRequestTruncated(),
            'response_content' => $entry->getResponseContent(),
            'response_truncated' => $entry->isResponseTruncated(),
            'usage' => $entry->getUsage(),
            'error_message' => $entry->getErrorMessage(),
        ]);
    }
    if ($command === 'prune') {
        if (!isset($options['apply'])) {
            $emit(['dry_run' => true, 'hint' => 'pass --apply to apply the retention settings']);
        }
        $emit(['pruned' => DatabaseLlmRequestRecorder::prune($dao)]);
    }

    // replay
    $connectorOverride = isset($options['connector']) && is_string($options['connector']) && ctype_digit($options['connector'])
        ? (int) $options['connector'] : null;
    $replayer = new LlmRequestReplayer($dao, new LLMConnector());
    if (!isset($options['send'])) {
        $emit(['dry_run' => true, 'would_send' => $replayer->plan($id, $connectorOverride), 'hint' => 'pass --send to send it']);
    }
    $emit($replayer->replay($id, $connectorOverride));
} catch (InvalidArgumentException | DomainException $error) {
    $emit(['error' => $error->getMessage()], 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'llm-request-log failed: ' . $error::class . PHP_EOL);
    exit(1);
}

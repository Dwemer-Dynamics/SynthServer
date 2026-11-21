<?php

declare(strict_types=1);

use Synth\Api\V1\LegacyEntry;
use Synth\Managers\MessageHandler\MessageHandler;

// GET /api/v1/messages/<id>. Two kinds of id share the route:
//
// - A queue message id (a positive integer, from `speech.message_id`): fetches one result from core_message_queue, exactly once.
//   The first GET of a completed message answers 200 with the stored payload and marks it delivered; every later GET answers 409
//   {"status":"delivered"}. A message still being produced answers 409 {"status":"pending"}. A cancelled, expired or unknown id
//   answers 404 with that status. HEAD never delivers: it answers the status code a GET would, without consuming the message.
//   With the queue switched off (SYNTH_MESSAGE_QUEUE=0; the default is on) these ids answer 404 {"status":"unknown"}.
// - A TTS cache key (32 hex digits): the legacy response path, the delivery state through tts_status.php, unchanged and
//   repeatable, so a client that does not use the queue keeps working.

if (preg_match('/^[1-9][0-9]{0,17}$/D', $messageIdText) !== 1) {
    $_GET['cache_key'] = $messageIdText;
    require LegacyEntry::script('tts_status.php');
    return;
}

require_once dirname(__DIR__, 2) . '/lib/autoload.php';
require_once dirname(__DIR__, 2) . '/lib/runtime_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$synthMessageHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
$synthMessageReply = static function (int $status, array $body) use ($synthMessageHead): void {
    http_response_code($status);
    if (!$synthMessageHead) {
        echo json_encode(['schema' => 'synth.message.v1'] + $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
};
$synthMessageNumber = intval($messageIdText);

if (!MessageHandler::enabled()) {
    $synthMessageReply(404, ['message_id' => $synthMessageNumber, 'status' => 'unknown']);
    return;
}

try {
    synthRuntimeBootstrap(dirname(__DIR__, 2), ['run_db_updates' => false]);
    $synthHandler = MessageHandler::current();
    if ($synthMessageHead) {
        $synthStatus = $synthHandler->status($synthMessageNumber);
        http_response_code(match ($synthStatus) {
            'completed' => 200,
            'pending', 'delivered' => 409,
            default => 404,
        });
        return;
    }
    $synthRow = $synthHandler->deliver($synthMessageNumber);
    if ($synthRow !== null) {
        $synthPayload = is_string($synthRow['payload'] ?? null) ? json_decode($synthRow['payload'], true) : ($synthRow['payload'] ?? null);
        $synthMessageReply(200, [
            'message_id' => $synthMessageNumber,
            'message_type' => strval($synthRow['message_type']),
            'session_id' => strval($synthRow['session_id']),
            'request_id' => strval($synthRow['request_id']),
            'generation' => intval($synthRow['generation']),
            'status' => 'delivered',
            'completed_at' => gmdate('Y-m-d\TH:i:s\Z', intval(strtotime(strval($synthRow['completed_at'])))),
            'delivered_at' => gmdate('Y-m-d\TH:i:s\Z', intval(strtotime(strval($synthRow['delivered_at'])))),
            'payload' => is_array($synthPayload) ? $synthPayload : new stdClass(),
        ]);
        return;
    }
    // Not delivered by this call: say why. Pending and delivered are conflicts (try later, or already taken); the rest are gone.
    $synthStatus = $synthHandler->status($synthMessageNumber) ?? 'unknown';
    // A message that completed between the two statements is reported as pending: the next fetch will return it.
    $synthMessageReply(in_array($synthStatus, ['pending', 'completed', 'delivered'], true) ? 409 : 404, [
        'message_id' => $synthMessageNumber,
        'status' => $synthStatus === 'completed' ? 'pending' : $synthStatus,
    ]);
} catch (Throwable $error) {
    error_log('[SYNTH MESSAGE] Queue read failed: ' . get_class($error));
    $synthMessageReply(503, ['message_id' => $synthMessageNumber, 'status' => 'unavailable']);
}

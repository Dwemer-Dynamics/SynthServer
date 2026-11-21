<?php

declare(strict_types=1);

namespace Synth\Managers\MessageHandler;

use Synth\Drivers\Tts\SpeechStorage;

/**
 * Connects the speech job to its queue message. When a speech job is admitted for a client that negotiated `message.queue`,
 * its message is admitted too (admit()); when the job reaches a final state the worker settles it (settle()): a finished job
 * completes the message with the `synth.tts_status.v1` document the legacy poll would have shown, a failed one completes it
 * with the failed status, and a job cancelled with its turn cancels the request's messages. Settling is idempotent, so the
 * worker, the dispatcher and a retry may all call it.
 */
final class SpeechMessages
{
    /** @param array<string,mixed> $event the claimed request event (session_id, request_id, generation, capabilities) */
    public static function admit(array $event, string $utteranceId, ?MessageHandler $handler = null): ?int
    {
        if (!MessageHandler::enabled() || !in_array('message.queue', (array) ($event['capabilities'] ?? []), true)) {
            return null;
        }
        return ($handler ?? MessageHandler::current())->admit(
            MessageHandler::TYPE_SPEECH,
            strval($event['session_id']),
            strval($event['request_id']),
            intval($event['generation']),
            $utteranceId
        );
    }

    /**
     * Completes or cancels the message of a speech job whose state is final. Returns 'completed', 'cancelled', 'waiting' (the
     * job is still running), 'skipped' (the job has no message, or it was already settled) or 'unavailable'.
     */
    public static function settle(object $db, int $jobId, string $root, ?MessageHandler $handler = null): string
    {
        $job = \Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->speechJob($jobId);
        if (!is_array($job) || !is_string($job['session_id'] ?? null) || !is_string($job['utterance_id'] ?? null)) {
            return 'skipped';
        }
        if (in_array($job['state'], ['pending', 'running'], true)) {
            return 'waiting';
        }
        $handler ??= MessageHandler::current();
        $messageId = $handler->idFor($job['session_id'], strval($job['request_id']), $job['utterance_id']);
        if ($messageId === null) {
            return 'skipped';
        }
        if (in_array($job['state'], ['cancelled', 'stale'], true)) {
            return $handler->cancelRequest($job['session_id'], strval($job['request_id'])) > 0 ? 'cancelled' : 'skipped';
        }
        require_once dirname(__DIR__, 2) . '/lib/npc_tts_status.php';
        $status = SpeechStorage::readStatus(strval($job['cache_key']), $root);
        unset($status['_job_id']);
        if ($job['state'] === 'ready' && ($status['status'] ?? '') === 'ready' && is_array($status['media'] ?? null)) {
            return $handler->complete($messageId, $status) ? 'completed' : 'skipped';
        }
        $failed = ['schema' => 'synth.tts_status.v1', 'cache_key' => strval($job['cache_key']), 'status' => 'failed',
            'error_code' => 'synthesis_failed'];
        return $handler->complete($messageId, $failed) ? 'completed' : 'skipped';
    }
}

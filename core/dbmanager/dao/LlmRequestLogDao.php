<?php

// Reads and writes core_llm_request_log: the replayable log of LLM requests. A row is inserted when a request is sent
// (status running) and completed when it ends; rows are never edited otherwise. Writes are parameterized DBAL statements
// on the shared session, reads go through the read-only mapping. Retention (prune) lives here too.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractLlmRequestLog;


/** Records, completes, reads and prunes logged LLM requests. */
final class LlmRequestLogDao
{
    use UsesEntityManager;

    private const FINAL_STATUSES = [
        AbstractLlmRequestLog::STATUS_COMPLETED,
        AbstractLlmRequestLog::STATUS_FAILED,
        AbstractLlmRequestLog::STATUS_CANCELLED,
    ];

    /**
     * Stores a request that has just been sent and returns its id.
     *
     * @param array{call_kind: string, driver: string, model?: string, url?: ?string, connector_id?: ?int, purpose?: ?string,
     *     correlation_id?: ?string, replay_of_id?: ?int, request: array<string, mixed>, request_body?: ?array<string, mixed>,
     *     request_truncated?: bool} $row already redacted and size-bounded by the recorder
     */
    public function start(array $row): int
    {
        $kind = $row['call_kind'];
        if (!in_array($kind, [AbstractLlmRequestLog::KIND_STREAM, AbstractLlmRequestLog::KIND_FAST], true) || ($row['driver'] ?? '') === '') {
            throw new InvalidArgumentException('A request log row needs a call kind and a driver.');
        }
        $body = $row['request_body'] ?? null;

        return (int) $this->connection()->fetchOne(
            'INSERT INTO public.core_llm_request_log
                 (call_kind, purpose, correlation_id, replay_of_id, connector_id, driver, model, url, request, request_body, request_truncated)
             VALUES (:kind, :purpose, :correlation, :replay_of, :connector, :driver, :model, :url,
                     CAST(:request AS jsonb), CAST(:body AS jsonb), CAST(:truncated_text AS boolean))
             RETURNING id',
            [
                'kind' => $kind,
                'purpose' => $row['purpose'] ?? null,
                'correlation' => $row['correlation_id'] ?? null,
                'replay_of' => $row['replay_of_id'] ?? null,
                'connector' => $row['connector_id'] ?? null,
                'driver' => $row['driver'],
                'model' => $row['model'] ?? '',
                'url' => $row['url'] ?? null,
                'request' => json_encode($row['request'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'body' => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'truncated_text' => ($row['request_truncated'] ?? false) ? 'true' : 'false',
            ]
        );
    }

    /**
     * Completes a running row: the outcome, the reply and the timing. A row that already ended is left as it is.
     *
     * @param array{status: string, duration_ms?: ?int, http_status?: ?int, response_content?: ?string,
     *     response_truncated?: bool, usage?: ?array<string, mixed>, prompt_tokens?: ?int, completion_tokens?: ?int,
     *     total_tokens?: ?int, error_class?: ?string, error_message?: ?string, url?: ?string,
     *     request_body?: ?array<string, mixed>, request_truncated?: bool} $result
     */
    public function finish(int $id, array $result): bool
    {
        $status = $result['status'];
        if (!in_array($status, self::FINAL_STATUSES, true)) {
            throw new InvalidArgumentException('A request log row ends as completed, failed or cancelled.');
        }
        $usage = $result['usage'] ?? null;
        $body = $result['request_body'] ?? null;
        $changed = $this->connection()->executeStatement(
            'UPDATE public.core_llm_request_log SET
                 status = :status, finished_at = now(), duration_ms = :duration, http_status = :http,
                 response_content = :content, response_truncated = CAST(:content_truncated AS boolean), usage = CAST(:usage AS jsonb),
                 prompt_tokens = :prompt, completion_tokens = :completion, total_tokens = :total,
                 error_class = :error_class, error_message = :error_message,
                 url = COALESCE(CAST(:url AS text), url),
                 request_body = COALESCE(CAST(:body AS jsonb), request_body),
                 request_truncated = request_truncated OR CAST(:body_truncated AS boolean)
             WHERE id = :id AND status = \'running\'',
            [
                'id' => $id,
                'status' => $status,
                'duration' => $result['duration_ms'] ?? null,
                'http' => $result['http_status'] ?? null,
                'content' => $result['response_content'] ?? null,
                'content_truncated' => ($result['response_truncated'] ?? false) ? 'true' : 'false',
                'usage' => $usage === null ? null : json_encode($usage, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'prompt' => $result['prompt_tokens'] ?? null,
                'completion' => $result['completion_tokens'] ?? null,
                'total' => $result['total_tokens'] ?? null,
                'error_class' => $result['error_class'] ?? null,
                'error_message' => $result['error_message'] ?? null,
                'url' => $result['url'] ?? null,
                'body' => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                'body_truncated' => ($result['request_truncated'] ?? false) ? 'true' : 'false',
            ]
        );

        return $changed > 0;
    }

    /** One logged request by id, or null. */
    public function find(int $id): ?AbstractLlmRequestLog
    {
        return $this->fresh()->find(AbstractLlmRequestLog::class, $id);
    }

    /**
     * The newest logged requests, newest first.
     *
     * @return list<AbstractLlmRequestLog>
     */
    public function recent(int $limit = 20, ?string $status = null): array
    {
        $criteria = $status === null ? [] : ['status' => $status];

        return array_values($this->fresh()->getRepository(AbstractLlmRequestLog::class)
            ->findBy($criteria, ['id' => 'DESC'], max(1, min(500, $limit))));
    }

    /**
     * Retention: ends rows left running for more than an hour (a worker killed mid-request), deletes rows older than the
     * given number of days, then deletes the oldest rows beyond the cap. A replay's link to a deleted original is cleared
     * by the foreign key.
     *
     * @return array{abandoned: int, expired: int, capped: int}
     */
    public function prune(int $retentionDays, int $maxRows): array
    {
        $connection = $this->connection();
        $abandoned = $connection->executeStatement(
            "UPDATE public.core_llm_request_log SET status = 'failed', finished_at = now(), error_class = 'abandoned',
                    error_message = 'The request never reported an end.'
             WHERE status = 'running' AND created_at < now() - interval '1 hour'"
        );
        $expired = $connection->executeStatement(
            "DELETE FROM public.core_llm_request_log WHERE created_at < now() - make_interval(days => :days)",
            ['days' => max(1, $retentionDays)]
        );
        $capped = $connection->executeStatement(
            'DELETE FROM public.core_llm_request_log WHERE id IN (
                 SELECT id FROM public.core_llm_request_log ORDER BY id DESC OFFSET :keep)',
            ['keep' => max(100, $maxRows)]
        );

        return ['abandoned' => $abandoned, 'expired' => $expired, 'capped' => $capped];
    }
}

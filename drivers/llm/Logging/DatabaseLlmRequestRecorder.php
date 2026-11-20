<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

use Synth\Core\DbManager\Dao\LlmRequestLogDao;

/**
 * Stores each request in core_llm_request_log: inserted as running when it is sent, completed when it ends.
 *
 * Secrets are redacted and sizes bounded before anything is written (LlmRequestSanitizer). A storage error is logged and
 * never reaches the request. Logging is on unless the meta setting LLM_REQUEST_LOG_ENABLED is false, and it does nothing
 * when no database session exists. Retention is LLM_REQUEST_LOG_RETENTION_DAYS and LLM_REQUEST_LOG_MAX_ROWS, applied by
 * prune() (the hourly log trim and bin/llm-request-log.php).
 */
final class DatabaseLlmRequestRecorder implements LlmRequestRecorder
{
    public const DEFAULT_RETENTION_DAYS = 14;
    public const DEFAULT_MAX_ROWS = 5000;

    private static ?bool $enabled = null;

    public function __construct(private readonly ?LlmRequestLogDao $dao = null)
    {
    }

    /** Forgets the cached enabled flag (tests, and a settings change inside one long-running process). */
    public static function resetCache(): void
    {
        self::$enabled = null;
    }

    public function begin(array $entry): ?int
    {
        try {
            $dao = $this->dao();
            if ($dao === null) {
                return null;
            }
            $sanitizer = new LlmRequestSanitizer($entry['secrets'] ?? []);
            [$request, $cut] = $sanitizer->clean($entry['request']);

            return $dao->start([
                'call_kind' => $entry['call_kind'],
                'driver' => $entry['driver'],
                'model' => $entry['model'] ?? '',
                'connector_id' => $entry['connector_id'] ?? null,
                'purpose' => isset($entry['purpose']) ? $sanitizer->message($entry['purpose']) : null,
                'correlation_id' => isset($entry['correlation_id']) ? $sanitizer->message($entry['correlation_id']) : null,
                'replay_of_id' => $entry['replay_of_id'] ?? null,
                'request' => is_array($request) ? $request : ['_value' => $request],
                'request_truncated' => $cut,
            ]);
        } catch (\Throwable $error) {
            $this->report('begin', $error);
            // A database without the table (not migrated yet) would fail on every request; stop trying in this process.
            self::$enabled = false;

            return null;
        }
    }

    public function end(?int $id, array $result): void
    {
        if ($id === null) {
            return;
        }
        try {
            $dao = $this->dao();
            if ($dao === null) {
                return;
            }
            $sanitizer = new LlmRequestSanitizer($result['secrets'] ?? []);
            $error = $result['error'] ?? null;
            $body = null;
            $bodyCut = false;
            if (isset($result['request_body']) && $result['request_body'] !== '') {
                $decoded = json_decode($result['request_body'], true);
                [$cleaned, $bodyCut] = $sanitizer->clean(is_array($decoded) ? $decoded : ['_raw' => $result['request_body']]);
                $body = is_array($cleaned) ? $cleaned : ['_value' => $cleaned];
            }
            $content = null;
            $contentCut = false;
            $usage = null;
            if (isset($result['response']) && $result['response'] !== '') {
                [$content, $contentCut] = $sanitizer->cleanText($result['response']);
                $usage = self::usageOf($result['response']);
            }
            $dao->finish($id, [
                'status' => $result['status'],
                'duration_ms' => $result['duration_ms'] ?? null,
                'http_status' => $result['http_status'] ?? null,
                'url' => isset($result['url']) ? $sanitizer->message($result['url']) : null,
                'request_body' => $body,
                'request_truncated' => $bodyCut,
                'response_content' => $content,
                'response_truncated' => $contentCut,
                'usage' => $usage,
                'prompt_tokens' => self::int($usage['prompt_tokens'] ?? null),
                'completion_tokens' => self::int($usage['completion_tokens'] ?? null),
                'total_tokens' => self::int($usage['total_tokens'] ?? null),
                'error_class' => $error instanceof \Throwable ? $error::class : ($result['error_class'] ?? null),
                'error_message' => $error instanceof \Throwable ? $sanitizer->message($error->getMessage())
                    : (isset($result['error_message']) ? $sanitizer->message($result['error_message']) : null),
            ]);
        } catch (\Throwable $error) {
            $this->report('end', $error);
        }
    }

    /** Applies the configured retention. @return array{abandoned: int, expired: int, capped: int} */
    public static function prune(?LlmRequestLogDao $dao = null): array
    {
        $dao ??= LlmRequestLogDao::current();

        return $dao->prune(
            self::setting('LLM_REQUEST_LOG_RETENTION_DAYS', self::DEFAULT_RETENTION_DAYS, 1, 3650),
            self::setting('LLM_REQUEST_LOG_MAX_ROWS', self::DEFAULT_MAX_ROWS, 100, 1000000)
        );
    }

    private function dao(): ?LlmRequestLogDao
    {
        if ($this->dao !== null) {
            return $this->dao;
        }
        if (\Synth\Bootstrap::db() === null) {
            return null;
        }
        self::$enabled ??= self::flag();

        return self::$enabled ? LlmRequestLogDao::current() : null;
    }

    private static function flag(): bool
    {
        try {
            return \Synth\Managers\ConfigManager\ConfigManager::shared()->getBool('meta', 'LLM_REQUEST_LOG_ENABLED', true);
        } catch (\Throwable) {
            return true;
        }
    }

    private static function setting(string $key, int $default, int $min, int $max): int
    {
        try {
            $raw = \Synth\Managers\ConfigManager\ConfigManager::shared()->get('meta', $key);
        } catch (\Throwable) {
            $raw = null;
        }

        return $raw !== null && preg_match('/^\d{1,9}$/D', trim($raw)) === 1 ? max($min, min($max, (int) $raw)) : $default;
    }

    /** @return array<string, mixed>|null the provider's usage object, when the reply is a whole provider body */
    private static function usageOf(string $response): ?array
    {
        if ($response === '' || $response[0] !== '{') {
            return null;
        }
        $decoded = json_decode($response, true);
        $usage = is_array($decoded) ? ($decoded['usage'] ?? null) : null;

        return is_array($usage) ? $usage : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function report(string $step, \Throwable $error): void
    {
        // Never the message of a storage error: a driver message can carry connection details or the SQL.
        error_log('[LLM REQUEST LOG] ' . $step . ' failed: ' . $error::class);
    }
}

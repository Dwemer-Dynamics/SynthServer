<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

/**
 * The log of one LLM call: begin() when it is sent, end() when it finishes. Shared by the typed driver path
 * (LlmWrapper::stream) and the stateful provider drivers (LoggedLegacyConnector), so both write the same rows.
 */
final class LlmCallLog
{
    private ?int $id = null;
    private float $startedAt = 0.0;
    private bool $open = false;
    /** @var list<string> */
    private array $secrets = [];

    public function __construct(private readonly LlmRequestRecorder $recorder)
    {
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * @param array<string, mixed> $request what a replay needs: the call, its messages and its parameters
     * @param list<string> $secrets exact keys of the connector in use, removed from everything stored
     */
    public function begin(string $kind, string $driver, string $model, ?int $connectorId, ?string $purpose, array $request, array $secrets = []): void
    {
        $this->secrets = $secrets;
        $this->startedAt = microtime(true);
        $this->open = true;
        LlmRequestScope::begin();
        $this->id = $this->recorder->begin([
            'call_kind' => $kind,
            'driver' => $driver,
            'model' => $model,
            'connector_id' => $connectorId,
            'purpose' => $purpose,
            'correlation_id' => self::correlationId(),
            'replay_of_id' => LlmRequestScope::replayedLogId(),
            'request' => $request,
            'secrets' => $secrets,
        ]);
        LlmRequestScope::setLastLogId($this->id);
    }

    /** Ends the call. $status is completed, failed or cancelled; a call that is not open is ignored. */
    public function end(string $status, ?string $response = null, ?\Throwable $error = null, ?string $errorMessage = null): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $seen = LlmRequestScope::take();
        $this->recorder->end($this->id, [
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'response' => $response,
            'http_status' => $seen['status'],
            'url' => $seen['url'],
            'request_body' => $seen['body'],
            'error' => $error,
            'error_message' => $errorMessage,
            'secrets' => $this->secrets,
        ]);
    }

    /** The status a failure ends with: a cancelled turn is not a provider failure. */
    public static function statusFor(\Throwable $error): string
    {
        return stripos($error::class, 'cancel') !== false ? 'cancelled' : 'failed';
    }

    /** The turn or request id of the game request being served, to find every LLM call of one turn. */
    private static function correlationId(): ?string
    {
        $json = \Synth\Bootstrap::requestEvent()['json'] ?? null;
        if (!is_array($json)) {
            return null;
        }
        foreach (['turn_id', 'request_id'] as $key) {
            if (is_string($json[$key] ?? null) && $json[$key] !== '' && strlen($json[$key]) <= 200) {
                return $json[$key];
            }
        }

        return null;
    }
}

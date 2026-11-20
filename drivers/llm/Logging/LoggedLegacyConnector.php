<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

/**
 * Wraps a stateful provider driver (open, process, close, fast_request) so every request it sends is logged.
 *
 * The wrapped driver is untouched: calls and property reads and writes pass straight through (callers use
 * primary_handler, name and the public buffers), and the wrapper only notes when a request starts and how it ends.
 * A streamed request begins at open() and ends at close(); a request that is never closed is ended as failed when the
 * wrapper is released. fast_request() is one begin and one end.
 *
 * Replay input: the stored request is {call, messages, parameters}, exactly what open()/fast_request() received.
 */
final class LoggedLegacyConnector
{
    private readonly LlmCallLog $log;

    public function __construct(
        private readonly object $driver,
        private readonly string $driverName,
        private readonly ?array $connectorRow = null,
        ?LlmRequestRecorder $recorder = null
    ) {
        $this->log = new LlmCallLog($recorder ?? new DatabaseLlmRequestRecorder());
    }

    public function __destruct()
    {
        try {
            $this->log->end('failed', null, null, 'The request was never closed.');
        } catch (\Throwable) {
            // Shutdown order is not guaranteed; a log write must never turn into a fatal error here.
        }
    }

    /** The driver being logged. */
    public function inner(): object
    {
        return $this->driver;
    }

    public function open($contextData, $customParms)
    {
        $this->log->end('failed', null, null, 'A new request was opened before the previous one was closed.');
        $this->log->begin(\Synth\Core\Entities\Base\AbstractLlmRequestLog::KIND_STREAM, $this->driverName, $this->model(),
            $this->connectorId(), null, ['call' => 'open', 'messages' => $contextData, 'parameters' => $customParms], $this->secrets());
        try {
            return $this->driver->open($contextData, $customParms);
        } catch (\Throwable $error) {
            $this->log->end(LlmCallLog::statusFor($error), null, $error);
            throw $error;
        }
    }

    public function process()
    {
        try {
            return $this->driver->process();
        } catch (\Throwable $error) {
            $this->log->end(LlmCallLog::statusFor($error), null, $error);
            throw $error;
        }
    }

    public function close($callName = '')
    {
        try {
            $reply = $this->driver->close($callName);
        } catch (\Throwable $error) {
            $this->log->end(LlmCallLog::statusFor($error), null, $error);
            throw $error;
        }
        // Callers name a failed close ("failed-stream-before-fallback", "empty-response-before-fallback").
        $name = (string) $callName;
        $failed = $name !== '' && preg_match('/fail|empty|cancel/i', $name) === 1;
        $this->log->end($failed ? 'failed' : 'completed', is_string($reply) ? $reply : null, null, $failed ? $name : null);

        return $reply;
    }

    public function fast_request($contextData, $customParms, $callName = '')
    {
        $this->log->end('failed', null, null, 'A new request was sent before the previous one was closed.');
        $purpose = (string) $callName !== '' ? (string) $callName : null;
        $this->log->begin(\Synth\Core\Entities\Base\AbstractLlmRequestLog::KIND_FAST, $this->driverName, $this->model(),
            $this->connectorId(), $purpose, ['call' => 'fast_request', 'messages' => $contextData, 'parameters' => $customParms, 'call_name' => $purpose], $this->secrets());
        try {
            $reply = $this->driver->fast_request($contextData, $customParms, $callName);
        } catch (\Throwable $error) {
            $this->log->end(LlmCallLog::statusFor($error), null, $error);
            throw $error;
        }
        $text = is_string($reply) ? $reply : null;
        $this->log->end($text !== null && $text !== '' ? 'completed' : 'failed', $text, null, $text === null || $text === '' ? 'The provider returned no text.' : null);

        return $reply;
    }

    public function __get(string $name)
    {
        return $this->driver->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->driver->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->driver->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->driver->$name);
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments)
    {
        return $this->driver->$method(...$arguments);
    }

    private function model(): string
    {
        $model = $this->connectorRow['model'] ?? \Synth\Bootstrap::config('CONNECTOR')[$this->driverName]['model'] ?? '';

        return is_string($model) ? $model : '';
    }

    private function connectorId(): ?int
    {
        $id = $this->connectorRow['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return list<string> the keys of the connector in use, so none can reach the log */
    private function secrets(): array
    {
        $settings = \Synth\Bootstrap::config('CONNECTOR')[$this->driverName] ?? [];
        $secrets = [];
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                if (is_string($value) && is_string($key) && preg_match('/key|secret|token|password/i', $key) === 1 && !preg_match('/max_tokens|tokens$/i', $key)) {
                    $secrets[] = $value;
                }
            }
        }

        return $secrets;
    }
}

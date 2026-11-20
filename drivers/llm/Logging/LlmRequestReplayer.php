<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

use Synth\Core\DbManager\Dao\LlmRequestLogDao;
use Synth\Core\Entities\Base\AbstractLlmRequestLog;
use Synth\Drivers\Llm\LlmWrapper;
use Synth\Drivers\Llm\OpenAiCompatibleDriver;

/**
 * Sends a logged request again through the same driver.
 *
 * It rebuilds the driver input from the stored request (messages and parameters), loads the connector as it is stored now
 * (the original one, or another by id), and calls the same driver through LlmWrapper, so the replay is logged as a new row
 * whose replay_of_id points at the original. The result says whether the provider body of the replay equals the original.
 * A request that was cut for size is refused: a replay of a partial prompt would not be a replay.
 */
final class LlmRequestReplayer
{
    private const STREAM_DEADLINE_SECONDS = 300;

    /**
     * @param \LLMConnector $connectors the stored connector rows (lib/core/llm_connector.class.php)
     */
    public function __construct(private readonly LlmRequestLogDao $dao, private readonly object $connectors)
    {
    }

    /**
     * What replaying would send, without sending it.
     *
     * @return array<string, mixed>
     */
    public function plan(int $id, ?int $connectorId = null): array
    {
        $entry = $this->entry($id);
        $connector = $this->connectorRow($entry, $connectorId);

        return [
            'log_id' => $id,
            'call' => $entry->getRequest()['call'] ?? $entry->getCallKind(),
            'original_driver' => $entry->getDriver(),
            'original_model' => $entry->getModel(),
            'connector_id' => (int) ($connector['id'] ?? 0),
            'connector_driver' => (string) ($connector['driver'] ?? ''),
            'connector_model' => (string) ($connector['model'] ?? ''),
            'messages' => count($entry->getRequest()['messages'] ?? []),
        ];
    }

    /**
     * Sends the request again and returns the outcome.
     *
     * @return array{replay_log_id: ?int, replay_of: int, status: string, reply: string, body_matches: ?bool, error: ?string}
     */
    public function replay(int $id, ?int $connectorId = null): array
    {
        $entry = $this->entry($id);
        $connector = $this->connectorRow($entry, $connectorId);
        $request = $entry->getRequest();
        $messages = $request['messages'];
        $parameters = is_array($request['parameters'] ?? null) ? $request['parameters'] : [];
        $driver = (string) $connector['driver'];

        LlmRequestScope::replayOf($id);
        LlmRequestScope::setLastLogId(null);
        $reply = '';
        $error = null;
        try {
            if (($request['call'] ?? '') === 'stream' && $driver === 'openaijson') {
                $this->replayTyped($connector, $messages, $reply);
            } else {
                $reply = $this->replayLegacy($connector, $driver, $entry->getCallKind(), $messages, $parameters, $request['call_name'] ?? null);
            }
        } catch (\Throwable $failure) {
            $error = $failure::class;
        } finally {
            LlmRequestScope::replayOf(null);
        }
        $newId = LlmRequestScope::lastLogId();
        $new = $newId === null ? null : $this->dao->find($newId);
        $matches = $new !== null && $entry->getRequestBody() !== null && $new->getRequestBody() !== null
            ? $new->getRequestBody() === $entry->getRequestBody() : null;

        return [
            'replay_log_id' => $newId,
            'replay_of' => $id,
            'status' => $new?->getStatus() ?? ($error === null ? 'unlogged' : AbstractLlmRequestLog::STATUS_FAILED),
            'reply' => $reply,
            'body_matches' => $matches,
            'error' => $error,
        ];
    }

    private function entry(int $id): AbstractLlmRequestLog
    {
        $entry = $this->dao->find($id);
        if ($entry === null) {
            throw new \InvalidArgumentException("No logged LLM request {$id}.");
        }
        if ($entry->isRequestTruncated() || !is_array($entry->getRequest()['messages'] ?? null)) {
            throw new \DomainException("Logged request {$id} was cut for size and cannot be replayed faithfully.");
        }

        return $entry;
    }

    /** @return array<string, mixed> the stored connector row to send through */
    private function connectorRow(AbstractLlmRequestLog $entry, ?int $override): array
    {
        $connectorId = $override ?? $entry->getConnectorId();
        if ($connectorId === null) {
            throw new \DomainException('The logged request names no connector; pass one with --connector.');
        }
        $row = $this->connectors->getById($connectorId);
        if (!is_array($row) || ($row['driver'] ?? '') === '') {
            throw new \InvalidArgumentException("Connector {$connectorId} does not exist.");
        }

        return $row;
    }

    private function replayTyped(array $connector, array $messages, string &$reply): void
    {
        $wrapper = new LlmWrapper([new OpenAiCompatibleDriver(
            static function (): void {
            },
            fn(array $row): array => $this->connectors->dialogueProviderConfig($row)
        )]);
        $wrapper->stream($connector, $messages, static function ($text) use (&$reply): void {
            $reply .= (string) $text;
        });
    }

    private function replayLegacy(array $connector, string $driver, string $kind, array $messages, array $parameters, mixed $callName): string
    {
        $this->connectors->setOldGlobals($connector);
        $handler = LlmWrapper::legacyConnector($driver, \Synth\Bootstrap::enginePath() ?? null, $connector);
        if ($kind === AbstractLlmRequestLog::KIND_FAST) {
            return (string) $handler->fast_request($messages, $parameters, is_string($callName) ? $callName : '');
        }
        $handler->open($messages, $parameters);
        $deadline = time() + self::STREAM_DEADLINE_SECONDS;
        while (!$handler->isDone() && time() < $deadline) {
            if ($handler->process() == -1) {
                break;
            }
        }

        return (string) $handler->close('replay');
    }
}

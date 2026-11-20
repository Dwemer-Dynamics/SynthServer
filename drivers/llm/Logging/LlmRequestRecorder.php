<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

/**
 * Where the LLM driver layer reports each request it sends and how it ended (docs/CHEVO-PARITY.md, "LLM request log").
 *
 * A recorder never breaks the request it observes: implementations swallow and log their own storage errors.
 */
interface LlmRequestRecorder
{
    /**
     * Reports a request about to be sent and returns its log id, or null when nothing was stored.
     *
     * @param array{call_kind: string, driver: string, model?: string, connector_id?: ?int, purpose?: ?string,
     *     correlation_id?: ?string, replay_of_id?: ?int, request: array<string, mixed>, secrets?: list<string>} $entry
     */
    public function begin(array $entry): ?int;

    /**
     * Reports how a request ended.
     *
     * @param array{status: string, duration_ms?: ?int, response?: ?string, http_status?: ?int, url?: ?string,
     *     request_body?: ?string, error?: ?\Throwable, error_class?: ?string, error_message?: ?string,
     *     secrets?: list<string>} $result
     */
    public function end(?int $id, array $result): void;
}

<?php

namespace Synth\Drivers\Llm;

use Synth\Core\LlmDriver;

require_once dirname(__DIR__, 2).'/core/contracts/dialogue.php';
require_once dirname(__DIR__, 2).'/lib/provider_stream.php';

// Plain OpenAI-compatible chat streaming from the stored connector; credentials stay in memory. Reasoning,
// quickstart-managed and other drivers keep their legacy connector semantics and are not supported here.
final class OpenAiCompatibleDriver implements LlmDriver
{
    /**
     * @param \Closure():void $assertActive liveness check before connecting, during provider waits and at every line
     * @param \Closure(array):array $config url, model, api_key and sampling options of one stored connector
     */
    public function __construct(private \Closure $assertActive, private \Closure $config, private int $maxBytes = 2 * 1024 * 1024) {}

    public function supports(array $connector): bool
    {
        $metadata = json_decode(strval($connector['metadata'] ?? '{}'), true);
        return ($connector['driver'] ?? '') === 'openaijson'
            && !filter_var($connector['reasoning_model'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && !filter_var(is_array($metadata) ? ($metadata['quickstart_managed'] ?? false) : false, FILTER_VALIDATE_BOOLEAN)
            && trim(strval($connector['url'] ?? '')) !== '' && trim(strval($connector['model'] ?? '')) !== '';
    }

    public function stream(array $connector, array $messages, callable $onText): void
    {
        if (!$this->supports($connector)) throw new \DomainException('provider_unsupported');
        $config = ($this->config)($connector);
        $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
        if ($config['api_key'] !== '') $headers[] = 'Authorization: Bearer '.$config['api_key'];
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers,
            'content' => self::requestBody($config, $messages), 'timeout' => 60]]);
        // The owner check also runs before connecting and at the stream's bounded waits, not only per line.
        $stream = new \SynthProviderStream($config['url'], $context, $this->maxBytes, $this->assertActive);
        try {
            // Error bodies may still be valid SSE; never parse or echo them.
            $status = $stream->status();
            if ($stream->failed() || $status < 200 || $status > 299) throw new \RuntimeException('provider_failed');
            self::parseSse(static fn() => $stream->readLine(), $onText, $this->assertActive);
            if ($stream->failed()) throw new \RuntimeException('provider_failed');
        } finally { $stream->close(); }
    }

    public static function requestBody(array $config, array $messages): string
    {
        $body = ['model' => $config['model'], 'messages' => $messages, 'stream' => true,
            'max_tokens' => max(16, min(2048, intval($config['max_tokens'])))];
        foreach (['temperature', 'top_p', 'presence_penalty', 'frequency_penalty'] as $key)
            if (isset($config[$key])) $body[$key] = $config[$key];
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // Success only with finish_reason "stop" or [DONE]. EOF, malformed chunks, other finish reasons and data
    // after the terminal chunk fail; liveness is checked at every provider line.
    public static function parseSse(callable $readLine, callable $onText, callable $assertActive): void
    {
        $finished = false;
        while (($line = $readLine()) !== false) {
            $assertActive();
            $line = trim(strval($line));
            if ($line === '' || str_starts_with($line, ':')) continue;
            if (!str_starts_with($line, 'data:')) {
                if (preg_match('/^(event|id|retry):/', $line) === 1) continue;
                throw new \RuntimeException('provider_stream_malformed');
            }
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') return;
            if ($finished) throw new \RuntimeException('provider_stream_malformed');
            $chunk = json_decode($data, true);
            if (!is_array($chunk) || isset($chunk['error']) || !is_array($chunk['choices'] ?? null)) throw new \RuntimeException('provider_stream_malformed');
            $choice = $chunk['choices'][0] ?? [];
            $delta = $choice['delta']['content'] ?? '';
            if (!is_string($delta)) throw new \RuntimeException('provider_stream_malformed');
            if ($delta !== '') $onText($delta);
            $reason = $choice['finish_reason'] ?? null;
            if ($reason !== null) {
                if ($reason !== 'stop') throw new \RuntimeException('provider_stream_incomplete');
                $finished = true;
            }
        }
        if (!$finished) throw new \RuntimeException('provider_stream_incomplete');
    }
}

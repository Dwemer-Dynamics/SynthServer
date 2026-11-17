<?php

namespace Synth\Drivers\Llm;

use Synth\Core\LlmDriver;

require_once dirname(__DIR__, 2).'/core/contracts/dialogue.php';
require_once __DIR__.'/OpenAiCompatibleDriver.php';

// Auto Chat's player connector (CORE_CONNECTOR_PLAYER) as one bounded plain-text completion. Unlike
// OpenAiCompatibleDriver it accepts the Quickstart-managed openaijson row with that row's own transport: timeout,
// no redirects and streaming or non-streaming. lmstudio_compat only rewrites a json_object response_format,
// which this plain-text request never sends. It also accepts a nonreasoning openrouterjson row (the fresh-install
// default #2) as connector/openrouterjson.php fast_request sends it: non-streaming, the row's provider order,
// sampling and stop. Reasoning (flagged or implied by the model name), other drivers, model fallbacks, other routing,
// enabled extra parameters and unknown or malformed metadata fail closed before config or network; there is no legacy
// fast_request or other fallback.
// It reads no globals: the legacy HTTP_TIMEOUT arrives as the caller's transient config 'http_timeout' entry.
final class PlayerRewriteDriver implements LlmDriver
{
    // SynthPlayerRewrite::MAX_OUTPUT_BYTES: the lib/response.php payload.text byte cut; longer text is refused, not shortened.
    public const MAX_TEXT_BYTES = 4096;
    public const DEFAULT_HTTP_TIMEOUT = 30;
    private const METADATA = ['quickstart_managed', 'quickstart_server_type', 'quickstart_scope', 'quickstart_timeout',
        'disable_streaming', 'lmstudio_compat', 'remove_action_prompt', 'extra_parameters_enabled', 'extra_parameters'];
    // fast_request ignores disable_streaming, extra_parameters and remove_action_prompt; routing keys pass only when blank.
    private const OPENROUTER_METADATA = ['disable_streaming', 'remove_action_prompt', 'extra_parameters_enabled',
        'extra_parameters', 'block_none', 'stop', 'fallback_models', 'providers_sort', 'providers_to_ignore',
        'provider_quantizations', 'provider_max_price_input', 'provider_max_price_output'];
    private const OPENROUTER_ROUTING = ['fallback_models', 'providers_sort', 'providers_to_ignore', 'provider_quantizations',
        'provider_max_price_input', 'provider_max_price_output'];
    private const FLAGS = ['quickstart_managed', 'disable_streaming', 'lmstudio_compat', 'remove_action_prompt',
        'extra_parameters_enabled', 'block_none'];
    // Superset of openrouterjson isReasoningModel/isOpenAIModel and its grok stop removal: those families change
    // reasoning, token or stop semantics, so they are refused rather than approximated.
    private const OPENROUTER_REFUSED_MODEL = '/deepseek-r|qwq-|[-:]thinking|-reasoning|grok|sonar-deep-research|r1-1776|'
        .'dolphin3\.0-r1|aion-1\.0|reka-flash-3|olympiccoder-|mai-ds-r1|qwen3-|openai\/(o\d|gpt-5|gpt-oss)|gpt-5-mini|azure-o\d|^o\d(-|$)/i';
    // connector/openaijson.php isReasoningModel (its constructor sets _is_reasoning from the model even when the row's
    // reasoning_model is false; isOpenAIModel only matters once that is set): refused rather than approximated.
    // The whole gpt-5 family, gpt-5-chat included: fast_request sends it max_completion_tokens, not max_tokens.
    private const OPENAIJSON_REFUSED_MODEL = '/deepseek-r|qwq-32b|qwq-max|aion-1|grok-3-mini|[-:]thinking|-reasoning|mai-ds-r1|'
        .'openai\/o[134]|o1-preview|o1-mini|o4-mini|o3-mini|o3-pro|gpt-oss-120b|gpt-oss-20b|gpt-5/i';
    // Row columns dialogueProviderConfig does not carry: LLMConnector::setOldGlobals defaults, fast_request ranges.
    private const ROW_SAMPLING = ['top_k' => [0, 0, null], 'min_p' => [0.0, 0.0, 1.0], 'top_a' => [0.0, 0.0, 1.0],
        'repetition_penalty' => [1.0, 0.0, 2.0]];

    /**
     * @param \Closure():void $assertActive owner check before config, before connecting, during provider waits and before delivery
     * @param \Closure(array):array $config url, model, api_key and sampling options of the caller-selected connector, plus
     *     an optional transient 'http_timeout' (the caller's existing HTTP_TIMEOUT; default 30) used only for openrouterjson
     * @param ?\Closure(string, resource):object $open test transport with SynthProviderStream's status/failed/readLine/contents/close
     * @param ?float $deadlineSeconds optional hard total-time cap for this caller only: it overrides the row's timeout and
     *     openrouterjson's 30 s floor on both transports. Null (every ordinary caller) keeps the existing timeouts.
     */
    public function __construct(private \Closure $assertActive, private \Closure $config,
        private int $maxBytes = 2 * 1024 * 1024, private ?\Closure $open = null, private ?float $deadlineSeconds = null) {}

    public function supports(array $connector): bool
    {
        try { self::transport($connector); return true; } catch (\DomainException) { return false; }
    }

    // The transport the stored row configures, as the legacy connector applies its metadata. Pure row validation;
    // an openrouterjson timeout is null here and resolved from the caller's config in stream().
    public static function transport(array $connector): array
    {
        $raw = $connector['metadata'] ?? null;
        $metadata = $raw === null || $raw === '' ? [] : (is_string($raw) ? json_decode($raw, true) : null);
        $driver = $connector['driver'] ?? null;
        $openRouter = $driver === 'openrouterjson';
        if (!is_array($metadata) || ($driver !== 'openaijson' && !$openRouter)
            || self::flag($connector['reasoning_model'] ?? null, false) !== false
            || !is_string($connector['url'] ?? null) || trim($connector['url']) === ''
            || !is_string($connector['model'] ?? null) || trim($connector['model']) === ''
            || array_diff(array_keys($metadata), $openRouter ? self::OPENROUTER_METADATA : self::METADATA) !== []
            || array_filter(self::FLAGS, static fn($key) => self::flag($metadata[$key] ?? null, false) === null) !== []
            || (isset($metadata['quickstart_timeout']) && !is_numeric($metadata['quickstart_timeout']))
            || (!empty($metadata['extra_parameters']) && self::flag($metadata['extra_parameters_enabled'] ?? null, true))) {
            throw new \DomainException('provider_unsupported');
        }
        if ($openRouter) return self::openRouter($connector, $metadata);
        if (preg_match(self::OPENAIJSON_REFUSED_MODEL, $connector['model']) === 1) throw new \DomainException('provider_unsupported');
        $managed = self::flag($metadata['quickstart_managed'] ?? null, false);
        return ['stream' => !self::flag($metadata['disable_streaming'] ?? null, false),
            'timeout' => $managed ? max(5, min(120, intval($metadata['quickstart_timeout'] ?? 30))) : 60,
            'follow_location' => !$managed];
    }

    // fast_request's shape for the selected row: always non-streaming and redirects followed.
    private static function openRouter(array $connector, array $metadata): array
    {
        $model = trim($connector['model']);
        $provider = $connector['provider'] ?? null;
        $stop = $metadata['stop'] ?? null;
        if (preg_match(self::OPENROUTER_REFUSED_MODEL, $model) === 1 || $model === 'mistralai/ministral-8b'
            || stripos($connector['url'], 'mistral.ai') !== false || ($provider !== null && !is_string($provider))
            || ($stop !== null && (!is_array($stop) || !array_is_list($stop)
                || array_filter($stop, static fn($s) => !is_string($s) || $s === '') !== []))) {
            throw new \DomainException('provider_unsupported');
        }
        foreach (self::OPENROUTER_ROUTING as $key) {
            $value = $metadata[$key] ?? null;
            if ($value !== null && !(is_string($value) && trim($value) === '') && !(is_numeric($value) && floatval($value) == 0)) {
                throw new \DomainException('provider_unsupported');
            }
        }
        $sampling = [];
        foreach (self::ROW_SAMPLING as $key => [$default, $min, $max]) {
            $value = $connector[$key] ?? null;
            if ($value !== null && !is_numeric($value)) throw new \DomainException('provider_unsupported');
            $sampling[$key] = $key === 'top_k' ? max($min, intval($value ?? $default)) : max($min, min($max, floatval($value ?? $default)));
        }
        return ['stream' => false, 'timeout' => null, 'follow_location' => true,
            'openrouter' => ['provider' => empty($provider) ? null : explode(',', $provider), 'sampling' => $sampling,
                'stop' => $stop === null || $stop === [] ? ['USER'] : $stop,
                'safety' => self::flag($metadata['block_none'] ?? null, false) && preg_match('/google|gemini/i', $model) === 1]];
    }

    // Strict boolean: bool, 0/1 or the usual strings; anything else is malformed (null), never a PHP warning.
    private static function flag(mixed $value, bool $default): ?bool
    {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        if (!is_int($value) && !is_string($value)) return null;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    // Delivers the whole accepted completion once, never partial deltas: truncation or cancellation discovered
    // later can no longer be undone by the caller. An uncertain outcome is a failure, not a retryable success.
    public function stream(array $connector, array $messages, callable $onText): void
    {
        $transport = self::transport($connector);
        $assertActive = $this->assertActive;
        $assertActive();
        $config = ($this->config)($connector);
        foreach (['url', 'model', 'api_key'] as $key) {
            if (!is_string($config[$key] ?? null)) throw new \DomainException('provider_unsupported');
        }
        $headers = ['Content-Type: application/json', 'Accept: '.($transport['stream'] ? 'text/event-stream' : 'application/json')];
        if ($config['api_key'] !== '') $headers[] = 'Authorization: Bearer '.$config['api_key'];
        if (isset($transport['openrouter'])) {
            // The selected row's endpoint and model, never a substitute supplied by config.
            if (trim($config['url']) !== trim($connector['url']) || trim($config['model']) !== trim($connector['model'])) {
                throw new \DomainException('provider_unsupported');
            }
            // fast_request's max(HTTP_TIMEOUT, 30), from the caller's value; a malformed one is refused, not defaulted.
            $timeout = $config['http_timeout'] ?? self::DEFAULT_HTTP_TIMEOUT;
            if (!is_int($timeout) && !is_float($timeout) && !(is_string($timeout) && is_numeric($timeout))) {
                throw new \DomainException('provider_unsupported');
            }
            $transport['timeout'] = max(30, intval($timeout));
            array_push($headers, 'HTTP-Referer: https://dwemerdynamics.com/', 'X-Title: Dwemer Dynamics');
            $content = self::openRouterBody($config, $messages, $transport['openrouter']);
        } else {
            $content = self::requestBody($config, $messages, $transport['stream']);
        }
        // cURL treats the context timeout as the total transfer time, so this is a real deadline, not a per-read one.
        if ($this->deadlineSeconds !== null) $transport['timeout'] = max(0.1, min(floatval($transport['timeout'] ?? INF), $this->deadlineSeconds));
        $http = ['method' => 'POST', 'header' => $headers, 'timeout' => $transport['timeout'], 'content' => $content];
        if (!$transport['follow_location']) $http['follow_location'] = 0;
        $context = stream_context_create(['http' => $http]);
        $assertActive(); // the config closure may have outlived the owner
        $stream = $this->open !== null ? ($this->open)($config['url'], $context)
            : new \SynthProviderStream($config['url'], $context, $this->maxBytes, $assertActive);
        try {
            // Error bodies are never parsed or echoed.
            $status = $stream->status();
            if ($stream->failed() || $status < 200 || $status > 299) throw new \RuntimeException('provider_failed');
            if ($transport['stream']) {
                $text = '';
                $nonText = false;
                // The shared parser reads only choices[0].delta.content; tool calls or extra choices beside the
                // text mean the reply was not spoken-only, so a completed stream carrying them is refused below.
                OpenAiCompatibleDriver::parseSse(static function () use ($stream, &$nonText) {
                    $line = $stream->readLine();
                    if (is_string($line) && !$nonText) $nonText = self::sseHasNonText($line);
                    return $line;
                }, static function (string $delta) use (&$text): void {
                    if (strlen($text) + strlen($delta) > self::MAX_TEXT_BYTES) throw new \LengthException('provider_output_oversized');
                    $text .= $delta;
                }, $assertActive);
                if ($stream->failed()) throw new \RuntimeException('provider_failed');
                if ($nonText) throw new \RuntimeException('provider_stream_malformed');
            } else {
                $body = $stream->contents();
                if ($body === false || $stream->failed()) throw new \RuntimeException('provider_failed');
                $text = self::completionText($body);
            }
        } finally { $stream->close(); }
        if (!mb_check_encoding($text, 'UTF-8')) throw new \UnexpectedValueException('provider_output_malformed');
        if (preg_match('/[^\s\p{Z}]/u', $text) !== 1) throw new \UnexpectedValueException('provider_output_empty');
        $assertActive();
        $onText($text);
    }

    private static function sseHasNonText(string $line): bool
    {
        $line = trim($line);
        if (!str_starts_with($line, 'data:')) return false;
        $chunk = json_decode(trim(substr($line, 5)), true);
        if (!is_array($chunk) || !is_array($chunk['choices'] ?? null)) return false;
        if (count($chunk['choices']) > 1) return true;
        foreach ($chunk['choices'] as $choice) {
            $delta = is_array($choice) ? ($choice['delta'] ?? null) : null;
            if (is_array($delta) && (!empty($delta['tool_calls']) || !empty($delta['function_call']))) return true;
        }
        return false;
    }

    // Sampling is clamped to the legacy fast_request ranges; local servers take max_tokens, not max_completion_tokens.
    public static function requestBody(array $config, array $messages, bool $stream): string
    {
        $body = ['model' => $config['model'], 'messages' => $messages, 'stream' => $stream,
            'max_tokens' => max(16, min(2048, intval($config['max_tokens'] ?? 0)))];
        foreach (['temperature' => [0.0, 2.0], 'top_p' => [0.0, 1.0], 'presence_penalty' => [-2.0, 2.0],
            'frequency_penalty' => [-2.0, 2.0]] as $key => [$min, $max]) {
            if (isset($config[$key])) $body[$key] = max($min, min($max, floatval($config[$key])));
        }
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // openrouterjson fast_request's body for the non-refused families: max_tokens (omitted below 1) and its clamps,
    // except that a supplied zero temperature or top_p stays zero instead of becoming the falsy default.
    public static function openRouterBody(array $config, array $messages, array $route): string
    {
        $values = [];
        foreach (['max_tokens' => 1024, 'temperature' => 1.0, 'top_p' => 1.0, 'presence_penalty' => 0.0, 'frequency_penalty' => 0.0] as $key => $default) {
            $value = $config[$key] ?? $default;
            if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) throw new \DomainException('provider_unsupported');
            $values[$key] = $value;
        }
        $clamp = static fn(string $key, float $min, float $max): float => max($min, min($max, floatval($values[$key])));
        $s = $route['sampling'];
        $body = ['model' => $config['model'], 'messages' => $messages, 'stream' => false, 'usage' => ['include' => true],
            'max_tokens' => intval($values['max_tokens']), 'temperature' => $clamp('temperature', 0.0, 2.0), 'top_k' => $s['top_k'],
            'top_p' => $clamp('top_p', 0.0, 1.0), 'min_p' => $s['min_p'], 'top_a' => $s['top_a'],
            'presence_penalty' => $clamp('presence_penalty', -2.0, 2.0), 'frequency_penalty' => $clamp('frequency_penalty', -2.0, 2.0),
            'repetition_penalty' => $s['repetition_penalty'], 'stop' => $route['stop'], 'transforms' => []];
        if ($body['max_tokens'] < 1) unset($body['max_tokens']);
        if ($route['provider'] !== null) $body['provider'] = ['order' => $route['provider']];
        if ($route['safety']) {
            $body['safety_settings'] = array_map(static fn($category) => ['category' => $category, 'threshold' => 'BLOCK_NONE'],
                ['HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH', 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_DANGEROUS_CONTENT']);
        }
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    // One choice with string content and finish_reason "stop"; truncation, tool calls and errors fail.
    public static function completionText(string $body): string
    {
        $response = json_decode($body, true);
        $choices = is_array($response) ? ($response['choices'] ?? null) : null;
        if (!is_array($response) || isset($response['error']) || !is_array($choices) || !array_is_list($choices)
            || count($choices) !== 1 || !is_array($choices[0]) || !is_array($choices[0]['message'] ?? null)) {
            throw new \RuntimeException('provider_output_malformed');
        }
        if (($choices[0]['finish_reason'] ?? null) !== 'stop') throw new \RuntimeException('provider_output_incomplete');
        $message = $choices[0]['message'];
        if (!empty($message['tool_calls']) || !empty($message['function_call']) || !is_string($message['content'] ?? null)) {
            throw new \RuntimeException('provider_output_malformed');
        }
        if (strlen($message['content']) > self::MAX_TEXT_BYTES) throw new \LengthException('provider_output_oversized');
        return $message['content'];
    }
}

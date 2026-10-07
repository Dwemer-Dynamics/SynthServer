<?php

require_once __DIR__ . '/synth_protocol_state.php';

// Incremental provider HTTP on a PHP worker, with bounded waits and durable turn cancellation.
final class SynthProviderStream
{
    private ?CurlHandle $handle = null;
    private ?CurlMultiHandle $multi = null;
    private string $buffer = '';
    private array $headers = [];
    private bool $headersReady = false;
    private bool $done = false;
    private bool $failed = false;
    private int $received = 0;
    private int $nextGuard = 0;

    public function __construct(string $url, $context, private int $maxBytes = 16 * 1024 * 1024)
    {
        synth_protocol_assert_request_active();
        if (!in_array(strtolower(strval(parse_url($url, PHP_URL_SCHEME))), ['http','https'], true)) {
            throw new InvalidArgumentException('Provider URL must use HTTP or HTTPS');
        }
        $options = stream_context_get_options($context);
        $http = $options['http'] ?? [];
        $ssl = $options['ssl'] ?? [];
        $follow = !empty($http['follow_location'] ?? true);
        $timeout = max(1, min(1200000, intval(1000 * floatval($http['timeout'] ?? ini_get('default_socket_timeout')))));
        $this->handle = curl_init($url);
        $this->multi = curl_multi_init();
        $headers = $http['header'] ?? [];
        if (is_string($headers)) $headers = preg_split('/\r?\n/', trim($headers), -1, PREG_SPLIT_NO_EMPTY);
        $settings = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strval($http['method'] ?? 'GET'),
            CURLOPT_NOBODY => strtoupper(strval($http['method'] ?? 'GET')) === 'HEAD',
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeout, 30000),
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => intval($http['max_redirects'] ?? 20),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => boolval($ssl['verify_peer'] ?? true),
            CURLOPT_SSL_VERIFYHOST => !empty($ssl['verify_peer_name'] ?? true) ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use ($follow): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $this->headers = [];
                    $this->headersReady = false;
                }
                if (trim($line) !== '') $this->headers[] = rtrim($line, "\r\n");
                if (trim($line) === '') {
                    $status = intval(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
                    $redirect = $follow && in_array($status, [301,302,303,307,308], true)
                        && count(preg_grep('/^Location:/i', $this->headers)) > 0;
                    $this->headersReady = $status >= 200 && !$redirect;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk): int {
                // Bound the full response, not just the unread buffer, including error bodies.
                $this->received += strlen($chunk);
                if ($this->received > $this->maxBytes) return 0;
                if ($this->headersReady) $this->buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if (array_key_exists('content', $http)) $settings[CURLOPT_POSTFIELDS] = strval($http['content']);
        if (isset($ssl['cafile'])) $settings[CURLOPT_CAINFO] = $ssl['cafile'];
        if (isset($ssl['capath'])) $settings[CURLOPT_CAPATH] = $ssl['capath'];
        curl_setopt_array($this->handle, $settings);
        curl_multi_add_handle($this->multi, $this->handle);
        try {
            while (!$this->headersReady && !$this->done) $this->pump();
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    // Recheck at most every 100 ms; socket waiting never hides a cancellation for a provider timeout.
    private function pump(): void
    {
        try {
            $now = hrtime(true);
            if ($now >= $this->nextGuard) {
                $this->assertActive();
                $this->nextGuard = $now + 100_000_000;
            }
            if ($this->done) return;
            do { $code = curl_multi_exec($this->multi, $running); } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK) { $this->failed = true; $this->done = true; }
            while ($info = curl_multi_info_read($this->multi)) {
                if ($info['msg'] === CURLMSG_DONE) {
                    $this->done = true;
                    $this->failed = $info['result'] !== CURLE_OK;
                }
            }
            if (!$this->done) {
                $start = hrtime(true);
                $ready = curl_multi_select($this->multi, 0.05);
                if ($ready <= 0 && hrtime(true) - $start < 1_000_000) usleep(1000);
            }
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function readLine(): string|false
    {
        $this->assertActive();
        while (($end = strpos($this->buffer, "\n")) === false && !$this->done) $this->pump();
        if ($this->buffer === '') return false;
        $length = $end === false ? strlen($this->buffer) : $end + 1;
        $line = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $line;
    }

    public function contents(): string|false
    {
        while (!$this->done) $this->pump();
        $this->assertActive();
        $body = $this->buffer;
        $this->buffer = '';
        return $this->failed ? false : $body;
    }

    public function metadata(): array { return ['wrapper_data' => $this->headers]; }
    public function eof(): bool { return $this->done && $this->buffer === ''; }
    public function failed(): bool { return $this->failed; }

    private function assertActive(): void
    {
        try { synth_protocol_assert_request_active(); }
        catch (Throwable $error) { $this->close(); throw $error; }
    }

    public function close(): void
    {
        if ($this->multi !== null && $this->handle !== null) curl_multi_remove_handle($this->multi, $this->handle);
        // Drop callbacks capturing this object immediately, rather than waiting for cyclic GC.
        if ($this->handle !== null) curl_reset($this->handle);
        $this->handle = null;
        $this->multi = null;
        $this->buffer = '';
        $this->done = true;
    }

    public function __destruct() { $this->close(); }
}

// JSON-style connectors and their audit rows must encode the same literal action fields.
function synth_provider_action_history(string $actor, string $listener, string $action, string $target): string
{
    return json_encode(['character' => $actor, 'listener' => $listener, 'mood' => '',
        'action' => $action, 'target' => $target, 'message' => ''],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

// Dialogue examples must keep literal names and text inside their JSON fields.
function synth_provider_dialogue_history(string $actor, string $listener, string $message, string $action = 'Talk', string $mood = ''): string
{
    return json_encode(['character' => $actor, 'listener' => $listener, 'mood' => $mood,
        'action' => $action, 'target' => '', 'message' => trim($message)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function synth_provider_json_prefill(string $actor): string
{
    return '{"character": '.json_encode($actor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).',';
}

// Native replies retain their individual listener/order instead of entering legacy concatenation buffers.
function synth_provider_dialogue_context(array $messages): array
{
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) !== 2) return $messages;
    foreach ($messages as &$message) {
        if (($message['role'] ?? '') !== 'assistant' || isset($message['tool_calls'])
            || !empty($message['_synth_compact_history']) || !is_string($message['content'] ?? null)
            || trim($message['content']) === '' || is_array(json_decode($message['content'], true))) continue;
        $dialogue = extractDialogueTarget($message['content']);
        $message['content'] = synth_provider_dialogue_history(strval($GLOBALS['SYNTH_NAME']),
            strval($dialogue['target'] ?? ''), strval($dialogue['cleanedString'] ?? $message['content']));
    }
    unset($message);
    return $messages;
}

// Native tool evidence must survive a customized return template without a #RESULT# placeholder.
function synth_provider_tool_result(string $template, string $result, ?string $status = null): string
{
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) === 2 && $status !== null) {
        if (!in_array($status, ['succeeded','rejected','unavailable','timed_out','cancelled','failed',
            'unsupported_without_esp','unsupported_runtime'], true)) throw new RuntimeException('action_tool_status_invalid');
        // Status is paired internal metadata, never inferred from the reported text or a success template.
        if ($status !== 'succeeded') {
            return 'Action outcome: '.$status."\nReported detail: ".$result
                ."\nThis result does not establish successful completion; do not invent the missing observation or effect.";
        }
        return "Action outcome: succeeded\n".synth_provider_tool_result($template, $result);
    }
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) === 2
        && !str_contains($template, '#RESULT#') && $result !== '') {
        return $template."\nAction result: ".$result;
    }
    return strtr($template, ['#RESULT#' => $result]);
}

// Text-completion templates have no tool role; lower native pairs before template-specific rendering.
function synth_provider_template_context(array $messages): array
{
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) !== 2) return $messages;
    $result = [];
    $pending = [];
    $seen = [];
    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'assistant' && isset($message['tool_calls'])) {
            if (is_string($message['content'] ?? null) && trim($message['content']) !== '') {
                $text = $message;
                unset($text['tool_calls']);
                $result[] = $text;
            }
            foreach ($message['tool_calls'] as $call) {
                $id = $call['id'] ?? '';
                $name = $call['function']['name'] ?? '';
                if (!is_string($id) || $id === '' || isset($seen[$id]) || !is_string($name) || $name === '') {
                    throw new RuntimeException('template_tool_call_invalid');
                }
                $arguments = json_decode($call['function']['arguments'] ?? '', false, flags: JSON_THROW_ON_ERROR);
                if (!$arguments instanceof stdClass) throw new RuntimeException('template_tool_arguments_invalid');
                $pending[$id] = synthFormatReturnMessageTemplate(getFunctionCodeName($name), (array)$arguments);
                $seen[$id] = true;
                $result[] = ['role' => 'user', 'content' => synthBuildNarratorContextLine('Action requested: '.json_encode(
                    ['tool_call_id' => $id, 'action' => $name, 'arguments' => $arguments],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
            }
        } elseif (($message['role'] ?? '') === 'tool') {
            $id = $message['tool_call_id'] ?? '';
            if (!is_string($id) || !array_key_exists($id, $pending) || !is_string($message['content'] ?? null)) {
                throw new RuntimeException('template_tool_result_unpaired');
            }
            $detail = synth_provider_tool_result($pending[$id], $message['content'], $message['_synth_action_status'] ?? null);
            $result[] = ['role' => 'user', 'content' => synthBuildNarratorContextLine(
                'Action result for '.json_encode($id, JSON_THROW_ON_ERROR).":\n".$detail)];
            $GLOBALS['PATCH_STORE_FUNC_RES'] = $detail;
            unset($pending[$id]);
        } else {
            $result[] = $message;
        }
    }
    return $result;
}

// Keep legacy/background calls and resource-based connector test doubles compatible.
function synth_provider_open(string $url, $context)
{
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return fopen($url, 'r', false, $context);
    $stream = new SynthProviderStream($url, $context);
    if ($stream->failed()) { $stream->close(); return false; }
    return $stream;
}

function synth_provider_line($stream) { return $stream instanceof SynthProviderStream ? $stream->readLine() : fgets($stream); }
function synth_provider_contents($stream) { return $stream instanceof SynthProviderStream ? $stream->contents() : stream_get_contents($stream); }
function synth_provider_metadata($stream): array { return $stream instanceof SynthProviderStream ? $stream->metadata() : stream_get_meta_data($stream); }
function synth_provider_eof($stream): bool { return $stream instanceof SynthProviderStream ? $stream->eof() : feof($stream); }
function synth_provider_close($stream): void
{
    if ($stream instanceof SynthProviderStream) $stream->close();
    elseif (is_resource($stream)) fclose($stream);
}

function synth_provider_fetch(string $url, $context, ?array &$responseHeaders = null, int $maxBytes = 16 * 1024 * 1024): string|false
{
    $responseHeaders = [];
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) {
        $body = file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        return $body;
    }
    $stream = new SynthProviderStream($url, $context, $maxBytes);
    try {
        $responseHeaders = $stream->metadata()['wrapper_data'];
        if ($stream->failed()) return false;
        $options = stream_context_get_options($context);
        $status = intval(explode(' ', $responseHeaders[0] ?? '')[1] ?? 0);
        if ($status >= 400 && empty($options['http']['ignore_errors'])) return false;
        return $stream->contents();
    } finally {
        $stream->close();
    }
}

// Speech responses can contain base64 or uncompressed audio larger than the published media limit.
function synth_speech_fetch(string $url, $context = null, ?array &$responseHeaders = null): string|false
{
    return synth_provider_fetch($url, $context ?? stream_context_get_default(), $responseHeaders, 64 * 1024 * 1024);
}

// Runs the speech drivers' RETURNTRANSFER handles without changing uploads, authentication or HTTP error semantics.
function synth_speech_curl_exec(CurlHandle $handle, int $timeoutMs = 60000, ?array &$diagnostic = null): string|false
{
    $diagnostic = ['errno' => 0, 'error' => ''];
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) {
        $body = curl_exec($handle);
        $diagnostic = ['errno' => curl_errno($handle), 'error' => curl_error($handle)];
        return $body;
    }
    synth_protocol_assert_request_active();
    $multi = curl_multi_init();
    $body = '';
    $nextGuard = 0;
    curl_setopt($handle, CURLOPT_TIMEOUT_MS, max(1, min(1200000, $timeoutMs)));
    curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body): int {
        if (strlen($body) + strlen($chunk) > 64 * 1024 * 1024) return 0;
        $body .= $chunk;
        return strlen($chunk);
    });
    curl_multi_add_handle($multi, $handle);
    try {
        while (true) {
            $now = hrtime(true);
            if ($now >= $nextGuard) {
                synth_protocol_assert_request_active();
                $nextGuard = $now + 100_000_000;
            }
            do { $code = curl_multi_exec($multi, $running); } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK) {
                $diagnostic = ['errno' => CURLE_FAILED_INIT, 'error' => 'Speech transfer failed'];
                return false;
            }
            while ($info = curl_multi_info_read($multi)) {
                if ($info['msg'] === CURLMSG_DONE) {
                    synth_protocol_assert_request_active();
                    // curl_multi reports per-transfer failure here, not reliably through curl_errno.
                    $diagnostic = ['errno' => $info['result'],
                        'error' => $info['result'] === CURLE_OK ? '' : curl_strerror($info['result'])];
                    return $info['result'] === CURLE_OK ? $body : false;
                }
            }
            $start = hrtime(true);
            $ready = curl_multi_select($multi, 0.05);
            if ($ready <= 0 && hrtime(true) - $start < 1_000_000) usleep(1000);
        }
    } finally {
        // Removing an active transfer closes it; drop our buffer callback while retaining HTTP metadata.
        curl_multi_remove_handle($multi, $handle);
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, null);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_multi_close($multi);
    }
}

<?php

declare(strict_types=1);

namespace Synth\Managers\Notification;


/**
 * The wake-up channel: a plugin-facing WebSocket listener plus a loopback-only HTTP listener on which the server's
 * own workers publish. Single process, non-blocking, no authentication and no message content: a frame only tells
 * the plugin to look again over the ordinary protocol, so a connection that gets one learns nothing.
 *
 * Wire contract (docs in protocol/v2/README.md): one `welcome` frame after the handshake, then `wake` frames.
 * Plugin frames are never interpreted; only close and ping control frames are answered.
 */
final class NotifyServer
{
    public const MAX_CLIENTS = 16;
    private const MAX_HANDSHAKE_BYTES = 8192;
    private const MAX_FRAME_BYTES = 256;
    private const MAX_HTTP_BYTES = 4096;
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource */
    private $webSocketServer;
    /** @var resource */
    private $httpServer;
    /** @var array<int, array{socket: resource, buffer: string, handshaken: bool, opened: float}> */
    private array $clients = [];
    private bool $stopped = false;

    public function __construct(string $webSocketAddress, string $httpAddress)
    {
        $httpHost = parse_url($httpAddress, PHP_URL_HOST);
        if (!is_string($httpHost) || !in_array($httpHost, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
            throw new \InvalidArgumentException('The publish listener must be bound to loopback.');
        }
        $this->webSocketServer = self::listen($webSocketAddress);
        $this->httpServer = self::listen($httpAddress);
    }

    public function webSocketPort(): int
    {
        return self::portOf($this->webSocketServer);
    }

    public function httpPort(): int
    {
        return self::portOf($this->httpServer);
    }

    public function clientCount(): int
    {
        return count(array_filter($this->clients, static fn(array $client): bool => $client['handshaken']));
    }

    /** Every open connection, including one that has not finished its handshake. */
    public function connectionCount(): int
    {
        return count($this->clients);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function run(): void
    {
        while (!$this->stopped) {
            $this->poll(1.0);
        }
        $this->close();
    }

    /** Services every ready socket once, waiting at most $seconds for the first one. */
    public function poll(float $seconds): void
    {
        $read = [$this->webSocketServer, $this->httpServer];
        foreach ($this->clients as $client) {
            $read[] = $client['socket'];
        }
        $write = null;
        $except = null;
        $microseconds = (int) ($seconds * 1_000_000);
        if (@stream_select($read, $write, $except, intdiv($microseconds, 1_000_000), $microseconds % 1_000_000) === false) {
            return;
        }
        foreach ($read as $socket) {
            if ($socket === $this->webSocketServer) {
                $this->acceptWebSocket();
            } elseif ($socket === $this->httpServer) {
                $this->acceptPublish();
            } else {
                $this->readClient((int) $socket);
            }
        }
        $this->dropStalledHandshakes();
    }

    /** Sends one wake hint to every connected plugin. Returns how many were written. */
    public function broadcast(string $topic): int
    {
        $frame = self::textFrame(NotifyHint::wake($topic));
        $sent = 0;
        foreach (array_keys($this->clients) as $key) {
            if (!$this->clients[$key]['handshaken']) {
                continue;
            }
            if ($this->write($key, $frame)) {
                $sent++;
            }
        }
        return $sent;
    }

    /** Sends one `message_available` frame (the id only) to every connected plugin. Returns how many were written. */
    public function broadcastMessage(int $messageId): int
    {
        $frame = self::textFrame(NotifyHint::messageAvailable($messageId));
        $sent = 0;
        foreach (array_keys($this->clients) as $key) {
            if ($this->clients[$key]['handshaken'] && $this->write($key, $frame)) {
                $sent++;
            }
        }
        return $sent;
    }

    public function close(): void
    {
        foreach (array_keys($this->clients) as $key) {
            $this->removeClient($key);
        }
        fclose($this->webSocketServer);
        fclose($this->httpServer);
    }

    private function acceptWebSocket(): void
    {
        $socket = @stream_socket_accept($this->webSocketServer, 0);
        if (!is_resource($socket)) {
            return;
        }
        if (count($this->clients) >= self::MAX_CLIENTS) {
            fclose($socket);
            return;
        }
        stream_set_blocking($socket, false);
        $this->clients[(int) $socket] = ['socket' => $socket, 'buffer' => '', 'handshaken' => false, 'opened' => microtime(true)];
    }

    private function acceptPublish(): void
    {
        $socket = @stream_socket_accept($this->httpServer, 0);
        if (!is_resource($socket)) {
            return;
        }
        stream_set_timeout($socket, 1);
        $request = '';
        while (strlen($request) < self::MAX_HTTP_BYTES) {
            $chunk = @fread($socket, 1024);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $request .= $chunk;
            $end = strpos($request, "\r\n\r\n");
            if ($end !== false && strlen($request) >= $end + 4 + self::declaredLength(substr($request, 0, $end))) {
                break;
            }
        }
        $status = $this->handlePublish($request);
        @fwrite($socket, 'HTTP/1.1 ' . $status . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($socket);
    }

    private function handlePublish(string $request): string
    {
        $end = strpos($request, "\r\n\r\n");
        if ($end === false || !str_starts_with($request, 'POST /notify HTTP/1.')) {
            return '400 Bad Request';
        }
        $decoded = json_decode(substr($request, $end + 4), true);
        $topic = is_array($decoded) ? ($decoded['topic'] ?? null) : null;
        if ($topic === 'message') {
            $messageId = $decoded['message_id'] ?? null;
            if (!is_int($messageId) || $messageId < 1 || count($decoded) !== 2) {
                return '400 Bad Request';
            }
            // No connected client: the caller keeps the message announced-pending and retries, the client polls meanwhile.
            return $this->broadcastMessage($messageId) > 0 ? '204 No Content' : '409 Conflict';
        }
        if (!is_string($topic) || !in_array($topic, NotifyHint::TOPICS, true)) {
            return '400 Bad Request';
        }
        $this->broadcast($topic);
        return '204 No Content';
    }

    private static function declaredLength(string $head): int
    {
        return preg_match('/^content-length:\s*([0-9]{1,5})\s*$/mi', $head, $match) === 1 ? (int) $match[1] : 0;
    }

    private function readClient(int $key): void
    {
        if (!isset($this->clients[$key])) {
            return;
        }
        $chunk = @fread($this->clients[$key]['socket'], 4096);
        if ($chunk === false || $chunk === '') {
            $this->removeClient($key);
            return;
        }
        $this->clients[$key]['buffer'] .= $chunk;
        if (!$this->clients[$key]['handshaken']) {
            $this->handshake($key);
            return;
        }
        $this->readFrames($key);
    }

    private function handshake(int $key): void
    {
        $buffer = $this->clients[$key]['buffer'];
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > self::MAX_HANDSHAKE_BYTES) {
                $this->removeClient($key);
            }
            return;
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        $webSocketKey = $headers['sec-websocket-key'] ?? '';
        if (preg_match('/^GET\s+\S+\s+HTTP\/1\.1$/', $lines[0]) !== 1
            || strtolower($headers['upgrade'] ?? '') !== 'websocket'
            || ($headers['sec-websocket-version'] ?? '') !== '13'
            || $webSocketKey === '' || strlen($webSocketKey) > 64) {
            $this->write($key, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            $this->removeClient($key);
            return;
        }
        $accept = base64_encode(sha1($webSocketKey . self::GUID, true));
        $response = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
        $this->clients[$key]['buffer'] = substr($buffer, $end + 4);
        $this->clients[$key]['handshaken'] = true;
        if (!$this->write($key, $response . self::textFrame(NotifyHint::welcome()))) {
            return;
        }
        $this->readFrames($key);
    }

    /** Plugin frames carry nothing the server uses; answer close and ping, drop anything unusual or large. */
    private function readFrames(int $key): void
    {
        while (isset($this->clients[$key]) && strlen($this->clients[$key]['buffer']) >= 2) {
            $buffer = $this->clients[$key]['buffer'];
            $opcode = ord($buffer[0]) & 0x0f;
            $masked = (ord($buffer[1]) & 0x80) !== 0;
            $length = ord($buffer[1]) & 0x7f;
            if (!$masked || $length > 125 || ($opcode >= 0x8 && (ord($buffer[0]) & 0x80) === 0)) {
                $this->removeClient($key);
                return;
            }
            if (strlen($buffer) < 6 + $length) {
                return;
            }
            $payload = substr($buffer, 6, $length);
            $this->clients[$key]['buffer'] = substr($buffer, 6 + $length);
            if ($opcode === 0x8) {
                $this->write($key, "\x88\x00");
                $this->removeClient($key);
                return;
            }
            if ($opcode === 0x9) {
                $mask = substr($buffer, 2, 4);
                for ($i = 0; $i < $length; $i++) {
                    $payload[$i] = $payload[$i] ^ $mask[$i % 4];
                }
                $this->write($key, "\x8a" . chr($length) . $payload);
            }
        }
    }

    private function dropStalledHandshakes(): void
    {
        foreach ($this->clients as $key => $client) {
            if (!$client['handshaken'] && microtime(true) - $client['opened'] > 5.0) {
                $this->removeClient($key);
            }
        }
    }

    private function write(int $key, string $bytes): bool
    {
        if (!isset($this->clients[$key])) {
            return false;
        }
        $written = @fwrite($this->clients[$key]['socket'], $bytes);
        if ($written !== strlen($bytes)) {
            // A slow or dead plugin never holds the loop: a hint is tiny, so a short write means the peer is gone.
            $this->removeClient($key);
            return false;
        }
        return true;
    }

    private function removeClient(int $key): void
    {
        if (isset($this->clients[$key])) {
            @fclose($this->clients[$key]['socket']);
            unset($this->clients[$key]);
        }
    }

    private static function textFrame(string $text): string
    {
        return "\x81" . chr(strlen($text)) . $text;
    }

    /** @return resource */
    private static function listen(string $address)
    {
        $server = @stream_socket_server($address, $errorNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($server)) {
            throw new \RuntimeException("Unable to listen on {$address}: {$errorMessage} ({$errorNumber})");
        }
        stream_set_blocking($server, false);
        return $server;
    }

    /** @param resource $server */
    private static function portOf($server): int
    {
        $name = stream_socket_get_name($server, false);
        return is_string($name) ? (int) substr(strrchr($name, ':'), 1) : 0;
    }
}

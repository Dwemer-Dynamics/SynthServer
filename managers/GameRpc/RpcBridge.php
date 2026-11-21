<?php

// RpcBridge: the game-RPC half of the notify-server. It lives inside the notify-server process and has two sides.
//
//   - Game side: a plugin connection becomes an RPC connection only by sending `hello` with the token the server minted for its
//     session (protocol/v2, capability rpc.game). Requests go out as `request` frames; `response` frames come back.
//   - Server side: the server's own workers and requests call over the loopback-only publish listener
//     (`POST /rpc/session`, `POST /rpc/status`, `POST /rpc/call`). A call keeps its HTTP socket open until the game answers or the
//     deadline passes, so it holds one of a few bounded slots.
//
// Everything a plugin sends is untrusted: frames are size-capped and validated, a response is accepted only for a request this
// bridge sent on that same connection, and a wrong token closes the connection. Nothing here touches the database.

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

use Closure;

/** Bounded request/response bridge between loopback callers and bound plugin connections. */
final class RpcBridge
{
    public const SCHEMA = 'synth.rpc.v1';
    public const MAX_PENDING = 8;
    public const MAX_SESSIONS = 16;
    public const SESSION_TTL_SECONDS = 86400;
    public const MAX_FRAME_BYTES = 16384;

    /** @var array<string, array{token: string, expires: float}> session id => minted token */
    private array $sessions = [];
    /** @var array<string, int> session id => client key of the bound connection */
    private array $bound = [];
    /** @var array<int, string> client key => session id */
    private array $boundClients = [];
    /** @var array<int, array{socket: resource, client: int, deadline: float}> request id => the waiting loopback caller */
    private array $pending = [];
    private int $nextId = 1;

    /**
     * @param Closure(int, string): bool $send writes one text frame to a client; false when the client is gone
     * @param Closure(int): void $drop closes a client connection
     */
    public function __construct(private readonly Closure $send, private readonly Closure $drop)
    {
    }

    /** Whether a bound RPC connection exists for the session right now. */
    public function isConnected(string $sessionId): bool
    {
        return isset($this->bound[$sessionId]);
    }

    /** How many calls wait for a game answer. */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /** Remembers the token the server minted for a session, replacing an older one. False when the input is unusable or the table is full. */
    public function registerSession(string $sessionId, string $token, ?float $now = null): bool
    {
        $now ??= microtime(true);
        $this->forgetExpired($now);
        if ($sessionId === '' || strlen($sessionId) > 128 || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return false;
        }
        if (!isset($this->sessions[$sessionId]) && count($this->sessions) >= self::MAX_SESSIONS) {
            return false;
        }
        $this->sessions[$sessionId] = ['token' => $token, 'expires' => $now + self::SESSION_TTL_SECONDS];

        return true;
    }

    /**
     * Handles one frame from a plugin. Returns false when the connection must be closed.
     *
     * Before `hello` a client is an ordinary wake-up listener: frames that are not a hello are ignored, as they always were.
     */
    public function onClientFrame(int $client, string $text): bool
    {
        if (strlen($text) > self::MAX_FRAME_BYTES) {
            return false;
        }
        $frame = json_decode($text, true);
        if (!is_array($frame) || ($frame['schema'] ?? null) !== self::SCHEMA || !is_string($frame['type'] ?? null)) {
            return !isset($this->boundClients[$client]);
        }
        if (!isset($this->boundClients[$client])) {
            return $frame['type'] === 'hello' ? $this->hello($client, $frame) : true;
        }

        return $frame['type'] === 'response' && $this->response($client, $frame);
    }

    /** A connection ended: its calls fail with not_connected and its binding is released. */
    public function onClientGone(int $client): void
    {
        foreach ($this->pending as $id => $call) {
            if ($call['client'] === $client) {
                $this->finish($id, 200, ['ok' => false, 'error' => 'not_connected']);
            }
        }
        $session = $this->boundClients[$client] ?? null;
        if ($session !== null) {
            unset($this->boundClients[$client]);
            if (($this->bound[$session] ?? null) === $client) {
                unset($this->bound[$session]);
            }
        }
    }

    /** Answers every call whose deadline has passed with `timeout`. */
    public function expire(?float $now = null): void
    {
        $now ??= microtime(true);
        foreach ($this->pending as $id => $call) {
            if ($call['deadline'] <= $now) {
                $this->finish($id, 200, ['ok' => false, 'error' => 'timeout']);
            }
        }
    }

    /**
     * Handles one loopback request on /rpc/*. Returns the HTTP status to write now, or null when the reply is deferred (a call
     * waiting for the game); in that case the bridge owns $socket and closes it.
     *
     * @param resource $socket the accepted loopback connection
     * @return array{0: int, 1: array<string, mixed>}|null status and JSON body to write now
     */
    public function handleHttp(string $path, mixed $body, $socket): ?array
    {
        $body = is_array($body) ? $body : [];
        $session = is_string($body['session_id'] ?? null) ? $body['session_id'] : '';
        if ($path === '/rpc/session') {
            return $this->registerSession($session, is_string($body['token'] ?? null) ? $body['token'] : '') ? [204, []] : [400, []];
        }
        if ($path === '/rpc/status') {
            return [200, ['connected' => $this->isConnected($session)]];
        }
        if ($path === '/rpc/call') {
            return $this->call($session, $body, $socket);
        }

        return [400, []];
    }

    /**
     * @param array<string, mixed> $body
     * @param resource $socket
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    private function call(string $session, array $body, $socket): ?array
    {
        $method = is_string($body['method'] ?? null) ? $body['method'] : '';
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $clean = GameRpcMethods::cleanParams($method, $params);
        if ($clean === null || !is_int($body['timeout_ms'] ?? null)) {
            return [400, ['ok' => false, 'error' => 'invalid']];
        }
        $client = $this->bound[$session] ?? null;
        if ($client === null) {
            return [200, ['ok' => false, 'error' => 'not_connected']];
        }
        if (count($this->pending) >= self::MAX_PENDING) {
            return [200, ['ok' => false, 'error' => 'busy']];
        }
        $timeout = GameRpcMethods::clampTimeout($body['timeout_ms']);
        $id = $this->nextId;
        $this->nextId = $this->nextId >= 4294967295 ? 1 : $this->nextId + 1;
        $frame = json_encode(['schema' => self::SCHEMA, 'type' => 'request', 'id' => $id, 'method' => $method,
            'params' => (object) $clean, 'deadline_ms' => $timeout], JSON_THROW_ON_ERROR);
        if (!($this->send)($client, $frame)) {
            return [200, ['ok' => false, 'error' => 'not_connected']];
        }
        $this->pending[$id] = ['socket' => $socket, 'client' => $client, 'deadline' => microtime(true) + $timeout / 1000];

        return null;
    }

    /** @param array<string, mixed> $frame */
    private function hello(int $client, array $frame): bool
    {
        $session = $frame['session_id'] ?? null;
        $token = $frame['token'] ?? null;
        $known = is_string($session) ? ($this->sessions[$session] ?? null) : null;
        if ($known === null || !is_string($token) || $known['expires'] < microtime(true) || !hash_equals($known['token'], $token)) {
            return false;
        }
        $older = $this->bound[$session] ?? null;
        if ($older !== null && $older !== $client) {
            // One connection per session: the newer one wins.
            $this->onClientGone($older);
            ($this->drop)($older);
        }
        $this->bound[$session] = $client;
        $this->boundClients[$client] = $session;

        return ($this->send)($client, json_encode(['schema' => self::SCHEMA, 'type' => 'hello_ack'], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $frame */
    private function response(int $client, array $frame): bool
    {
        $id = $frame['id'] ?? null;
        $ok = $frame['ok'] ?? null;
        if (!is_int($id) || !is_bool($ok) || !$this->validOutcome($ok, $frame)) {
            return false;
        }
        $call = $this->pending[$id] ?? null;
        if ($call === null || $call['client'] !== $client) {
            // A late answer to a call that already timed out, or an id this connection was never sent: ignored.
            return true;
        }
        $this->finish($id, 200, $ok ? ['ok' => true, 'result' => $frame['result']] : ['ok' => false, 'error' => $frame['error']]);

        return true;
    }

    /** @param array<string, mixed> $frame */
    private function validOutcome(bool $ok, array $frame): bool
    {
        if ($ok) {
            return is_array($frame['result'] ?? null) && !array_key_exists('error', $frame);
        }

        return is_string($frame['error'] ?? null) && in_array($frame['error'], GameRpcMethods::ERRORS, true) && !array_key_exists('result', $frame);
    }

    /** @param array<string, mixed> $body */
    private function finish(int $id, int $status, array $body): void
    {
        $call = $this->pending[$id] ?? null;
        unset($this->pending[$id]);
        if ($call === null) {
            return;
        }
        $json = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @fwrite($call['socket'], self::httpReply($status, $json));
        @fclose($call['socket']);
    }

    /** A complete HTTP/1.1 reply with a JSON body (empty for 204). */
    public static function httpReply(int $status, string $json): string
    {
        $phrases = [200 => 'OK', 204 => 'No Content', 400 => 'Bad Request'];

        return 'HTTP/1.1 '.$status.' '.($phrases[$status] ?? 'Error')."\r\nContent-Type: application/json\r\nContent-Length: "
            .strlen($json)."\r\nConnection: close\r\n\r\n".$json;
    }

    private function forgetExpired(float $now): void
    {
        foreach ($this->sessions as $id => $session) {
            if ($session['expires'] < $now) {
                unset($this->sessions[$id]);
            }
        }
    }
}

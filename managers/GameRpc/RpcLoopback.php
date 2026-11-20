<?php

// RpcLoopback: the server's side of the notify-server's loopback listener for game RPC. One short request per call, to the
// loopback address only; a down channel is a quiet `null`, never an exception, so callers degrade to "no game reachable".

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

use Synth\Managers\Notification\NotifyConfig;

/** Posts JSON to /rpc/* on the notify-server's publish port. */
final class RpcLoopback
{
    private const CONNECT_SECONDS = 0.15;

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>}|null null when the channel is not running or did not answer in time
     */
    public static function post(string $path, array $body, float $readSeconds = 1.0): ?array
    {
        $socket = @stream_socket_client(NotifyConfig::internalAddress(), $errorNumber, $errorMessage, self::CONNECT_SECONDS);
        if (!is_resource($socket)) {
            return null;
        }
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $request = 'POST '.$path." HTTP/1.1\r\nHost: ".NotifyConfig::INTERNAL_HOST."\r\nContent-Type: application/json\r\nContent-Length: "
            .strlen($json)."\r\nConnection: close\r\n\r\n".$json;
        stream_set_timeout($socket, (int) ceil($readSeconds));
        $reply = '';
        if (@fwrite($socket, $request) === strlen($request)) {
            $deadline = microtime(true) + $readSeconds;
            while (!feof($socket) && microtime(true) < $deadline && strlen($reply) < 65536) {
                $chunk = @fread($socket, 8192);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $reply .= $chunk;
            }
        }
        fclose($socket);
        if (preg_match('#^HTTP/1\.1 ([0-9]{3}) #', $reply, $status) !== 1) {
            return null;
        }
        $end = strpos($reply, "\r\n\r\n");
        $decoded = $end === false ? null : json_decode(substr($reply, $end + 4), true);

        return ['status' => (int) $status[1], 'body' => is_array($decoded) ? $decoded : []];
    }

    /** Whether a plugin RPC connection is bound to the session right now. */
    public static function isConnected(string $sessionId): bool
    {
        $reply = self::post('/rpc/status', ['session_id' => $sessionId]);

        return $reply !== null && $reply['status'] === 200 && ($reply['body']['connected'] ?? false) === true;
    }

    /** Mints a token for a session, registers it with the channel and returns it; null when the channel is not running or refused. */
    public static function mintSessionToken(string $sessionId): ?string
    {
        $token = bin2hex(random_bytes(32));
        $reply = self::post('/rpc/session', ['session_id' => $sessionId, 'token' => $token]);

        return $reply !== null && $reply['status'] === 204 ? $token : null;
    }
}

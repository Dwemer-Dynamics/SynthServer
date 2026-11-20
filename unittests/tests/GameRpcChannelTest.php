<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Synth\Managers\Notification\NotifyServer;

foreach (['NotifyConfig', 'NotifyHint', 'NotifyPublisher', 'NotifyServer'] as $file) {
    require_once dirname(__DIR__, 2) . '/managers/Notification/' . $file . '.php';
}

// Database-free: game RPC through the real notify-server, with a fake plugin on a real WebSocket and loopback callers.
final class GameRpcChannelTest extends TestCase
{
    private const TOKEN = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

    private function post(NotifyServer $server, string $path, array $body)
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $server->httpPort(), $errorNumber, $errorMessage, 2);
        $json = json_encode($body);
        fwrite($socket, 'POST ' . $path . " HTTP/1.1\r\nHost: x\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json);
        stream_set_blocking($socket, false);

        return $socket;
    }

    private function answer(NotifyServer $server, $socket): array
    {
        for ($i = 0; $i < 60; $i++) {
            $server->poll(0.02);
            $raw = (string) stream_get_contents($socket);
            if ($raw !== '' && str_contains($raw, "\r\n\r\n") && (feof($socket) || strlen($raw) > 0)) {
                $status = (int) substr($raw, 9, 3);
                $body = substr($raw, (int) strpos($raw, "\r\n\r\n") + 4);
                return [$status, json_decode($body, true) ?? []];
            }
        }
        $this->fail('no HTTP answer');
    }

    /** A masked client text frame. */
    private function frame(string $text): string
    {
        $mask = "\x01\x02\x03\x04";
        $length = strlen($text);
        $header = "\x81" . ($length <= 125 ? chr(0x80 | $length) : "\xfe" . pack('n', $length));
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $text[$i] ^ $mask[$i % 4];
        }

        return $header . $mask . $masked;
    }

    private function connect(NotifyServer $server)
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $server->webSocketPort(), $errorNumber, $errorMessage, 2);
        fwrite($client, "GET / HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_blocking($client, false);
        for ($i = 0; $i < 5; $i++) {
            $server->poll(0.02);
        }
        $this->assertStringContainsString('101 Switching Protocols', (string) fread($client, 4096));

        return $client;
    }

    /** The next server text frame, or null. */
    private function next(NotifyServer $server, $client): ?string
    {
        for ($i = 0; $i < 20; $i++) {
            $server->poll(0.02);
            $data = (string) fread($client, 4096);
            if (strlen($data) >= 2) {
                $length = ord($data[1]) & 0x7f;
                return $length === 126 ? substr($data, 4, (ord($data[2]) << 8) | ord($data[3])) : substr($data, 2, $length);
            }
        }

        return null;
    }

    public function testACallTravelsToTheBoundPluginAndTheAnswerReturnsOverLoopback(): void
    {
        $server = new NotifyServer('tcp://127.0.0.1:0', 'tcp://127.0.0.1:0');
        try {
            $this->assertSame(204, $this->answer($server, $this->post($server, '/rpc/session', ['session_id' => 's1', 'token' => self::TOKEN]))[0]);
            $this->assertSame([200, ['connected' => false]], $this->answer($server, $this->post($server, '/rpc/status', ['session_id' => 's1'])));
            $client = $this->connect($server);
            fwrite($client, $this->frame(json_encode(['schema' => 'synth.rpc.v1', 'type' => 'hello', 'session_id' => 's1', 'token' => self::TOKEN])));
            $this->assertSame('{"schema":"synth.rpc.v1","type":"hello_ack"}', $this->next($server, $client));
            $this->assertSame([200, ['connected' => true]], $this->answer($server, $this->post($server, '/rpc/status', ['session_id' => 's1'])));

            $call = $this->post($server, '/rpc/call', ['session_id' => 's1', 'method' => 'session.info', 'params' => new stdClass(), 'timeout_ms' => 3000]);
            $request = json_decode((string) $this->next($server, $client), true);
            $this->assertSame(['request', 'session.info'], [$request['type'], $request['method']]);
            // A result larger than a small frame uses the extended length form.
            $result = ['padding' => str_repeat('x', 400)];
            fwrite($client, $this->frame(json_encode(['schema' => 'synth.rpc.v1', 'type' => 'response', 'id' => $request['id'], 'ok' => true, 'result' => $result])));
            $this->assertSame([200, ['ok' => true, 'result' => $result]], $this->answer($server, $call));
        } finally {
            $server->close();
        }
    }

    public function testAWrongTokenClosesTheConnectionAndAnUnansweredCallTimesOut(): void
    {
        $server = new NotifyServer('tcp://127.0.0.1:0', 'tcp://127.0.0.1:0');
        try {
            $this->answer($server, $this->post($server, '/rpc/session', ['session_id' => 's1', 'token' => self::TOKEN]));
            $bad = $this->connect($server);
            fwrite($bad, $this->frame(json_encode(['schema' => 'synth.rpc.v1', 'type' => 'hello', 'session_id' => 's1', 'token' => str_repeat('0', 64)])));
            for ($i = 0; $i < 5; $i++) {
                $server->poll(0.02);
            }
            $this->assertSame(0, $server->clientCount(), 'the connection with a wrong token was closed');

            $good = $this->connect($server);
            fwrite($good, $this->frame(json_encode(['schema' => 'synth.rpc.v1', 'type' => 'hello', 'session_id' => 's1', 'token' => self::TOKEN])));
            $this->next($server, $good);
            $call = $this->post($server, '/rpc/call', ['session_id' => 's1', 'method' => 'ping', 'params' => new stdClass(), 'timeout_ms' => 100]);
            $this->assertSame([200, ['ok' => false, 'error' => 'timeout']], $this->answer($server, $call));
            $this->assertSame([200, ['ok' => false, 'error' => 'not_connected']],
                $this->answer($server, $this->post($server, '/rpc/call', ['session_id' => 'nobody', 'method' => 'ping', 'params' => new stdClass(), 'timeout_ms' => 100])));
        } finally {
            $server->close();
        }
    }
}

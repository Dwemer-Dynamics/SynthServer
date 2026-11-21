<?php declare(strict_types=1);

namespace Synth\Tests\GameRpc;

use PHPUnit\Framework\TestCase;
use Synth\Managers\GameRpc\GameRpcMethods;
use Synth\Managers\GameRpc\RpcBridge;

// The game-RPC bridge without sockets to a real plugin: client frames in, sent frames recorded, loopback callers on socket pairs.
final class RpcBridgeTest extends TestCase
{
    private const TOKEN = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    /** @var list<array{int, string}> */
    private array $sent = [];
    /** @var list<int> */
    private array $dropped = [];

    private function bridge(): RpcBridge
    {
        $this->sent = [];
        $this->dropped = [];

        return new RpcBridge(function (int $client, string $text): bool {
            $this->sent[] = [$client, $text];
            return true;
        }, function (int $client): void {
            $this->dropped[] = $client;
        });
    }

    private function hello(RpcBridge $bridge, int $client, string $session = 's1', string $token = self::TOKEN): bool
    {
        return $bridge->onClientFrame($client, json_encode(['schema' => 'synth.rpc.v1', 'type' => 'hello', 'session_id' => $session, 'token' => $token]));
    }

    /** @return array{resource, resource} the bridge's end and the caller's end */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($pair[1], false);

        return $pair;
    }

    /** @param resource $socket @return array{int, array<string, mixed>} */
    private function reply($socket): array
    {
        $raw = (string) fread($socket, 65536);
        $this->assertSame(1, preg_match('#^HTTP/1\.1 ([0-9]{3}) #', $raw, $status), 'a complete HTTP reply was written');

        return [(int) $status[1], json_decode(substr($raw, (int) strpos($raw, "\r\n\r\n") + 4), true) ?? []];
    }

    private function bound(): RpcBridge
    {
        $bridge = $this->bridge();
        $this->assertTrue($bridge->registerSession('s1', self::TOKEN));
        $this->assertTrue($this->hello($bridge, 7));
        $this->assertSame('{"schema":"synth.rpc.v1","type":"hello_ack"}', $this->sent[0][1]);

        return $bridge;
    }

    public function testOnlyTheMintedTokenBindsAConnection(): void
    {
        $bridge = $this->bridge();
        $bridge->registerSession('s1', self::TOKEN);
        $this->assertFalse($this->hello($bridge, 1, 's1', str_repeat('b', 64)), 'a wrong token closes the connection');
        $this->assertFalse($this->hello($bridge, 1, 'unknown'), 'an unknown session closes the connection');
        $this->assertFalse($bridge->isConnected('s1'));
        $this->assertTrue($this->hello($bridge, 2));
        $this->assertTrue($bridge->isConnected('s1'));
        $this->assertFalse($bridge->registerSession('s2', 'short'), 'a token must be 64 hex digits');
        $this->assertFalse($bridge->registerSession('', self::TOKEN));
    }

    public function testClientsThatNeverSayHelloStayOrdinaryListeners(): void
    {
        $bridge = $this->bridge();
        $this->assertTrue($bridge->onClientFrame(1, 'not json'));
        $this->assertTrue($bridge->onClientFrame(1, '{"schema":"synth.rpc.v1","type":"response","id":1,"ok":true,"result":{}}'));
        $this->assertTrue($bridge->onClientFrame(1, '{"schema":"other","type":"hello"}'));
        $this->assertFalse($bridge->onClientFrame(1, str_repeat('x', RpcBridge::MAX_FRAME_BYTES + 1)));
        $this->assertSame([], $this->sent);
    }

    public function testACallReachesTheBoundGameAndTheAnswerComesBack(): void
    {
        $bridge = $this->bound();
        [$ours, $caller] = $this->pair();
        $this->assertNull($bridge->handleHttp('/rpc/call', ['session_id' => 's1', 'method' => 'ping', 'params' => [], 'timeout_ms' => 2000], $ours));
        $request = json_decode($this->sent[1][1], true);
        $this->assertSame([7, 'request', 'ping', 2000], [$this->sent[1][0], $request['type'], $request['method'], $request['deadline_ms']]);
        $this->assertSame(1, $bridge->pendingCount());
        $this->assertTrue($bridge->onClientFrame(7, json_encode(['schema' => 'synth.rpc.v1', 'type' => 'response', 'id' => $request['id'], 'ok' => true, 'result' => ['pong' => true]])));
        $this->assertSame([200, ['ok' => true, 'result' => ['pong' => true]]], $this->reply($caller));
        $this->assertSame(0, $bridge->pendingCount());
    }

    public function testRefusalsNeverReachTheGame(): void
    {
        $bridge = $this->bound();
        [$ours] = $this->pair();
        $sent = count($this->sent);
        foreach ([
            ['session_id' => 's1', 'method' => 'game.console', 'params' => [], 'timeout_ms' => 1000],
            ['session_id' => 's1', 'method' => 'ping', 'params' => ['x' => 1], 'timeout_ms' => 1000],
            ['session_id' => 's1', 'method' => 'world.nearby_items', 'params' => ['radius' => 99999], 'timeout_ms' => 1000],
            ['session_id' => 's1', 'method' => 'ping', 'params' => []],
        ] as $body) {
            $this->assertSame(400, $bridge->handleHttp('/rpc/call', $body, $ours)[0]);
        }
        $this->assertSame([200, ['ok' => false, 'error' => 'not_connected']],
            $bridge->handleHttp('/rpc/call', ['session_id' => 'other', 'method' => 'ping', 'params' => [], 'timeout_ms' => 1000], $ours));
        $this->assertSame(400, $bridge->handleHttp('/rpc/unknown', [], $ours)[0]);
        $this->assertSame($sent, count($this->sent));
        $this->assertSame([200, ['connected' => true]], $bridge->handleHttp('/rpc/status', ['session_id' => 's1'], $ours));
    }

    public function testPendingSlotsAreBoundedAndTimeoutsAnswer(): void
    {
        $bridge = $this->bound();
        $callers = [];
        for ($i = 0; $i < RpcBridge::MAX_PENDING; $i++) {
            [$ours, $callers[]] = $this->pair();
            $this->assertNull($bridge->handleHttp('/rpc/call', ['session_id' => 's1', 'method' => 'session.info', 'params' => [], 'timeout_ms' => 100], $ours));
        }
        [$extra] = $this->pair();
        $this->assertSame([200, ['ok' => false, 'error' => 'busy']],
            $bridge->handleHttp('/rpc/call', ['session_id' => 's1', 'method' => 'ping', 'params' => [], 'timeout_ms' => 100], $extra));
        $bridge->expire(microtime(true) + 5);
        $this->assertSame(0, $bridge->pendingCount());
        $this->assertSame([200, ['ok' => false, 'error' => 'timeout']], $this->reply($callers[0]));
    }

    public function testResponsesAreCheckedAgainstWhatWasSent(): void
    {
        $bridge = $this->bound();
        [$ours, $caller] = $this->pair();
        $bridge->handleHttp('/rpc/call', ['session_id' => 's1', 'method' => 'ping', 'params' => [], 'timeout_ms' => 1000], $ours);
        $id = json_decode($this->sent[1][1], true)['id'];
        $frame = static fn (array $extra): string => json_encode(['schema' => 'synth.rpc.v1', 'type' => 'response'] + $extra);

        $this->assertTrue($bridge->onClientFrame(7, $frame(['id' => $id + 100, 'ok' => true, 'result' => []])), 'an id nobody sent is ignored');
        $this->assertSame(1, $bridge->pendingCount());
        foreach ([['id' => $id, 'ok' => false, 'error' => 'it broke'], ['id' => $id, 'ok' => true], ['id' => $id, 'ok' => false, 'error' => 'failed', 'result' => []],
            ['id' => 'x', 'ok' => true, 'result' => []]] as $bad) {
            $this->assertFalse($bridge->onClientFrame(7, $frame($bad)), 'a malformed response closes the connection');
        }
        $this->assertFalse($bridge->onClientFrame(7, '{"schema":"synth.rpc.v1","type":"request","id":1}'), 'a bound client sends responses only');

        $bridge->onClientGone(7);
        $this->assertSame([200, ['ok' => false, 'error' => 'not_connected']], $this->reply($caller));
        $this->assertFalse($bridge->isConnected('s1'));
    }

    public function testAnErrorCodeFromTheGameIsPassedOn(): void
    {
        $bridge = $this->bound();
        [$ours, $caller] = $this->pair();
        $bridge->handleHttp('/rpc/call', ['session_id' => 's1', 'method' => 'inventory.player', 'params' => [], 'timeout_ms' => 1000], $ours);
        $id = json_decode($this->sent[1][1], true)['id'];
        $this->assertTrue($bridge->onClientFrame(7, json_encode(['schema' => 'synth.rpc.v1', 'type' => 'response', 'id' => $id, 'ok' => false, 'error' => 'method_unavailable'])));
        $this->assertSame([200, ['ok' => false, 'error' => 'method_unavailable']], $this->reply($caller));
    }

    public function testANewerConnectionReplacesTheOlderOne(): void
    {
        $bridge = $this->bound();
        $this->assertTrue($this->hello($bridge, 9));
        $this->assertSame([7], $this->dropped);
        [$ours] = $this->pair();
        $bridge->handleHttp('/rpc/call', ['session_id' => 's1', 'method' => 'ping', 'params' => [], 'timeout_ms' => 1000], $ours);
        $this->assertSame(9, end($this->sent)[0]);
    }

    public function testMethodAllowlistAndParameterBounds(): void
    {
        $this->assertSame(['ping', 'session.info', 'inventory.player', 'world.nearby_items'], GameRpcMethods::METHODS);
        $this->assertSame(['radius' => 500, 'limit' => 10], GameRpcMethods::cleanParams('world.nearby_items', ['radius' => 500, 'limit' => 10]));
        $this->assertNull(GameRpcMethods::cleanParams('world.nearby_items', ['limit' => 51]));
        $this->assertNull(GameRpcMethods::cleanParams('world.nearby_items', ['extra' => 1]));
        $this->assertSame([], GameRpcMethods::cleanParams('inventory.player', []));
        $this->assertNull(GameRpcMethods::cleanParams('shell', []));
        $this->assertSame([100, 10000], [GameRpcMethods::clampTimeout(1), GameRpcMethods::clampTimeout(99999)]);
    }
}

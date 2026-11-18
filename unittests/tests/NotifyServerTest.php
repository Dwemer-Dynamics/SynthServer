<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Synth\Managers\Notification\NotifyConfig;
use Synth\Managers\Notification\NotifyHint;
use Synth\Managers\Notification\NotifyPublisher;
use Synth\Managers\Notification\NotifyServer;

foreach (['NotifyConfig', 'NotifyHint', 'NotifyPublisher', 'NotifyServer'] as $file) {
    require_once dirname(__DIR__, 2) . '/managers/Notification/' . $file . '.php';
}

// Database-free: the wake-up channel in process on ephemeral loopback ports, plus the real bin script as a process.
final class NotifyServerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];
    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        foreach (['SYNTH_NOTIFY_HOST', 'SYNTH_NOTIFY_PORT', 'SYNTH_NOTIFY_INTERNAL_PORT'] as $name) {
            $this->environment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testHintsAreClosedAndCarryNoMessageContent(): void
    {
        $this->assertSame('{"schema":"synth.notify.hint.v1","type":"welcome"}', NotifyHint::welcome());
        $this->assertSame('{"schema":"synth.notify.hint.v1","type":"wake","topic":"speech"}', NotifyHint::wake('speech'));
        $this->expectException(InvalidArgumentException::class);
        NotifyHint::wake('dialogue');
    }

    public function testConfigDefaultsToLoopbackAndRefusesUnusableValues(): void
    {
        putenv('SYNTH_NOTIFY_HOST');
        putenv('SYNTH_NOTIFY_PORT');
        putenv('SYNTH_NOTIFY_INTERNAL_PORT');
        $this->assertSame('tcp://127.0.0.1:8091', NotifyConfig::webSocketAddress());
        $this->assertSame('tcp://127.0.0.1:8092', NotifyConfig::internalAddress());
        putenv('SYNTH_NOTIFY_HOST=0.0.0.0');
        putenv('SYNTH_NOTIFY_PORT=9100');
        $this->assertSame('tcp://0.0.0.0:9100', NotifyConfig::webSocketAddress());
        putenv('SYNTH_NOTIFY_HOST=not a host; rm');
        putenv('SYNTH_NOTIFY_PORT=80');
        $this->assertSame('tcp://127.0.0.1:8091', NotifyConfig::webSocketAddress());
        // The publish side is loopback whatever the plugin-facing host says.
        $this->assertSame('127.0.0.1', NotifyConfig::INTERNAL_HOST);
    }

    public function testPublishListenerMustBeLoopback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NotifyServer('tcp://127.0.0.1:0', 'tcp://0.0.0.0:0');
    }

    public function testHandshakeSendsWelcomeThenAWakeReachesEveryClientWithoutContent(): void
    {
        $server = new NotifyServer('tcp://127.0.0.1:0', 'tcp://127.0.0.1:0');
        try {
            $first = $this->connect($server);
            $second = $this->connect($server);
            $this->assertSame(2, $server->clientCount());
            $this->assertSame(NotifyHint::welcome(), $this->readFrame($server, $first));
            $this->assertSame(NotifyHint::welcome(), $this->readFrame($server, $second));
            $this->assertSame(2, $server->broadcast('speech'));
            $this->assertSame(NotifyHint::wake('speech'), $this->readFrame($server, $first));
            $this->assertSame(NotifyHint::wake('speech'), $this->readFrame($server, $second));
        } finally {
            $server->close();
        }
    }

    public function testPublishEndpointAcceptsOnlyAKnownTopicOnPostNotify(): void
    {
        $server = new NotifyServer('tcp://127.0.0.1:0', 'tcp://127.0.0.1:0');
        try {
            $client = $this->connect($server);
            $this->readFrame($server, $client);
            $this->assertSame('204', $this->publish($server, 'POST /notify', '{"topic":"speech"}'));
            $this->assertSame(NotifyHint::wake('speech'), $this->readFrame($server, $client));
            foreach ([['POST /notify', '{"topic":"dialogue"}'], ['POST /notify', 'not json'], ['GET /notify', ''],
                ['POST /other', '{"topic":"speech"}']] as [$line, $body]) {
                $this->assertSame('400', $this->publish($server, $line, $body));
            }
            $server->poll(0.05);
            $this->assertNull($this->tryReadFrame($client), 'a refused publish must not wake anyone');
        } finally {
            $server->close();
        }
    }

    public function testMalformedHandshakeAndUnmaskedClientFramesDropTheConnection(): void
    {
        $server = new NotifyServer('tcp://127.0.0.1:0', 'tcp://127.0.0.1:0');
        try {
            $bad = stream_socket_client('tcp://127.0.0.1:' . $server->webSocketPort());
            fwrite($bad, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
            $this->pump($server, 3);
            $this->assertSame(0, $server->clientCount());
            $this->assertStringStartsWith('HTTP/1.1 400', (string) fread($bad, 64));

            $client = $this->connect($server);
            $this->readFrame($server, $client);
            fwrite($client, "\x81\x01x"); // clients must mask
            $this->pump($server, 3);
            $this->assertSame(0, $server->clientCount());
        } finally {
            $server->close();
        }
    }

    public function testClientsBeyondTheCapAreRefused(): void
    {
        $server = new NotifyServer('tcp://127.0.0.1:0', 'tcp://127.0.0.1:0');
        try {
            $sockets = [];
            for ($i = 0; $i < NotifyServer::MAX_CLIENTS + 2; $i++) {
                $sockets[] = stream_socket_client('tcp://127.0.0.1:' . $server->webSocketPort());
                $this->pump($server, 2);
            }
            $this->assertSame(NotifyServer::MAX_CLIENTS, $server->connectionCount());
        } finally {
            $server->close();
        }
    }

    public function testPublisherDegradesCleanlyWhenTheChannelIsNotRunning(): void
    {
        putenv('SYNTH_NOTIFY_INTERNAL_PORT=' . $this->freePort());
        $this->assertFalse(NotifyPublisher::isRunning());
        $this->assertNull(NotifyPublisher::acknowledgedPort());
        $this->assertFalse(NotifyPublisher::publish('speech'));
        $this->assertFalse(NotifyPublisher::publish('unknown-topic'));
    }

    public function testBinScriptServesAPublishedWakeToAConnectedPlugin(): void
    {
        $webSocketPort = $this->freePort();
        $internalPort = $this->freePort();
        putenv('SYNTH_NOTIFY_PORT=' . $webSocketPort);
        putenv('SYNTH_NOTIFY_INTERNAL_PORT=' . $internalPort);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            $this->markTestSkipped('vendor/autoload.php is required to run bin/notify-server.php');
        }
        $this->process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/notify-server.php', '--port=' . $webSocketPort, '--internal-port=' . $internalPort],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($this->process);
        $deadline = microtime(true) + 5.0;
        while (!NotifyPublisher::isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $this->assertSame($webSocketPort, NotifyPublisher::acknowledgedPort());
        $client = stream_socket_client('tcp://127.0.0.1:' . $webSocketPort, $errorNumber, $errorMessage, 2);
        $this->assertIsResource($client);
        fwrite($client, $this->upgradeRequest());
        stream_set_timeout($client, 2);
        $this->assertStringContainsString('101 Switching Protocols', (string) fread($client, 4096));
        usleep(100_000);
        $this->assertTrue(NotifyPublisher::publish('speech'));
        $received = '';
        $deadline = microtime(true) + 3.0;
        while (!str_contains($received, 'wake') && microtime(true) < $deadline) {
            $received .= (string) fread($client, 4096);
        }
        $this->assertStringContainsString(NotifyHint::wake('speech'), $received);
    }

    /** @return resource */
    private function connect(NotifyServer $server)
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $server->webSocketPort(), $errorNumber, $errorMessage, 2);
        $this->assertIsResource($client);
        fwrite($client, $this->upgradeRequest());
        $this->pump($server, 3);
        $reply = '';
        stream_set_blocking($client, false);
        $reply = (string) fread($client, 4096);
        $this->assertStringContainsString('101 Switching Protocols', $reply);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $reply);
        // Whatever followed the handshake in the same read is the welcome frame; keep it for readFrame().
        $GLOBALS['notify_test_pending'][(int) $client] = substr($reply, (int) strpos($reply, "\r\n\r\n") + 4);
        return $client;
    }

    private function upgradeRequest(): string
    {
        return "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n";
    }

    /** @param resource $client */
    private function readFrame(NotifyServer $server, $client): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $frame = $this->tryReadFrame($client);
            if ($frame !== null) {
                return $frame;
            }
            $server->poll(0.02);
        }
        $this->fail('no frame arrived');
    }

    /** @param resource $client */
    private function tryReadFrame($client): ?string
    {
        $buffer = ($GLOBALS['notify_test_pending'][(int) $client] ?? '');
        stream_set_blocking($client, false);
        $buffer .= (string) fread($client, 4096);
        if (strlen($buffer) < 2) {
            $GLOBALS['notify_test_pending'][(int) $client] = $buffer;
            return null;
        }
        $length = ord($buffer[1]) & 0x7f;
        if (strlen($buffer) < 2 + $length) {
            $GLOBALS['notify_test_pending'][(int) $client] = $buffer;
            return null;
        }
        $GLOBALS['notify_test_pending'][(int) $client] = substr($buffer, 2 + $length);
        return substr($buffer, 2, $length);
    }

    private function publish(NotifyServer $server, string $requestLine, string $body): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $server->httpPort(), $errorNumber, $errorMessage, 2);
        fwrite($socket, $requestLine . " HTTP/1.1\r\nHost: x\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        $this->pump($server, 3);
        stream_set_blocking($socket, false);
        $reply = (string) fread($socket, 256);
        fclose($socket);
        return substr($reply, 9, 3);
    }

    private function pump(NotifyServer $server, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $server->poll(0.02);
        }
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr((string) $name, ':'), 1);
    }
}

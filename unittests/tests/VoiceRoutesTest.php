<?php

use PHPUnit\Framework\TestCase;

// Boundary checks of the voice routes against a throwaway php -S on loopback. These answers are decided before any
// database, provider or bootstrap work, so no database is needed.
final class VoiceRoutesTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $base = '';

    protected function setUp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::markTestSkipped('No loopback port available');
        }
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);
        $root = dirname(__DIR__, 2);
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
            $pipes,
            $root
        );
        $this->base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port);
            if ($probe) {
                fclose($probe);
                return;
            }
            usleep(100000);
        }
        self::fail('The test server did not start');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function post(string $path, string $body, array $headers = ['Content-Type: application/json']): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($raw, true) ?: []];
    }

    public function testPreviewWithoutTheDashboardTokenIsRefused(): void
    {
        [$code, $body] = $this->post('/api/v1/voice/preview.php', json_encode(['npc_id' => 1, 'text' => 'Hello']));
        self::assertSame(403, $code);
        self::assertSame('csrf', $body['code'] ?? null);
        [$code] = $this->post('/api/v1/voice/preview.php', json_encode(['npc_id' => 1, 'csrf_token' => str_repeat('a', 64)]));
        self::assertSame(403, $code);
    }

    public function testPreviewOverTheBodyLimitIsRefusedBeforeTheBodyIsRead(): void
    {
        [$code, $body] = $this->post('/api/v1/voice/preview.php', json_encode(['npc_id' => 1, 'text' => str_repeat('a', 5000)]));
        self::assertSame(413, $code);
        self::assertSame('too_large', $body['code'] ?? null);
    }

    public function testSampleListNeedsThePostAndTheToken(): void
    {
        [$code] = $this->post('/api/v1/voice/samples.php', json_encode(['voice_id' => 'x']));
        self::assertSame(403, $code);
        $ch = curl_init($this->base . '/api/v1/voice/samples.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        curl_exec($ch);
        self::assertSame(405, curl_getinfo($ch, CURLINFO_HTTP_CODE), 'a GET must not list transcripts');
        curl_close($ch);
    }
}

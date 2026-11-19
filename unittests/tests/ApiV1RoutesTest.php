<?php declare(strict_types=1);

namespace Synth\Tests\Api\V1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// api/index.php -> api/routes.php -> api/v1/<area>/ against a throwaway php -S on loopback, served through
// router.php like the development server. Each adapter route must reach the same implementation as its legacy
// root entry point: the same request gets the same answer from both. The probes are invalid requests the legacy
// script refuses itself, so the comparison needs no game data.
final class ApiV1RoutesTest extends TestCase
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
        // The legacy entry points treat PHPUNIT_TEST as an in-process test run; the server must not inherit it.
        $env = getenv();
        unset($env['PHPUNIT_TEST']);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, dirname(__DIR__) . '/fixtures/legacy_reference_router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $root,
            $env
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

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string, 2: string} status, normalized body, Allow header
     */
    private function call(string $method, string $path, ?string $body = null, array $headers = ['Content-Type: application/json']): array
    {
        $ch = curl_init($this->base . $path);
        $allow = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$allow): int {
                if (stripos($line, 'Allow:') === 0) {
                    $allow = trim(substr($line, 6));
                }
                return strlen($line);
            }]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // Request ids and clocks differ between two calls; everything else must match.
        $raw = (string) preg_replace('/"(request_id|timestamp)":"[^"]*"/', '"$1":"-"', $raw);
        return [$status, $raw, $allow];
    }

    /** @return array<string, array{0: string, 1: string}> route, a wire type that belongs to it */
    public static function eventRoutes(): array
    {
        return [
            'context/event' => ['context/event', 'context'],
            'dialogue/request' => ['dialogue/request', 'activate'],
            'dialogue/spoken' => ['dialogue/spoken', 'speech_receipt'],
            'dialogue/cancel' => ['dialogue/cancel', 'cancel'],
            'user/input' => ['user/input', 'input_text'],
            'save/loaded' => ['save/loaded', 'init'],
            'locations/save' => ['locations/save', 'location_catalog'],
        ];
    }

    #[DataProvider('eventRoutes')]
    public function testEventRouteReachesMainPhpWithTheSameAnswer(string $route, string $type): void
    {
        $body = json_encode(['type' => $type]);
        $legacy = $this->call('POST', '/main.php', $body);
        self::assertGreaterThanOrEqual(400, $legacy[0]);
        self::assertLessThan(500, $legacy[0]);
        self::assertSame($legacy, $this->call('POST', "/api/v1/$route", $body));
        // Method, content type and malformed JSON are refused by the shared decoder with the legacy answers.
        self::assertSame($this->call('POST', '/main.php', '{bad'), $this->call('POST', "/api/v1/$route", '{bad'));
        self::assertSame($this->call('POST', '/main.php', $body, ['Content-Type: text/plain']),
            $this->call('POST', "/api/v1/$route", $body, ['Content-Type: text/plain']));
    }

    #[DataProvider('eventRoutes')]
    public function testEventRouteRefusesAnotherRoutesType(string $route): void
    {
        [$status, $body] = $this->call('POST', "/api/v1/$route", json_encode(['type' => 'equipment']));
        self::assertSame(422, $status);
        self::assertSame('route_type_mismatch', json_decode($body, true)['code'] ?? null);
    }

    public function testBooksReadNeedsAReadingEvent(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/books/read', json_encode(['type' => 'context', 'payload' => ['world' => []]]));
        self::assertSame(422, $status);
        self::assertSame('route_type_mismatch', json_decode($body, true)['code'] ?? null);
        $event = json_encode(['type' => 'context', 'payload' => ['reading_event' => []]]);
        self::assertSame($this->call('POST', '/main.php', $event), $this->call('POST', '/api/v1/books/read', $event));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function gameDataRoutes(): array
    {
        return ['npc/add' => ['npc/add', 'actor_profile'], 'plugins/manifest' => ['plugins/manifest', 'loaded_plugins']];
    }

    #[DataProvider('gameDataRoutes')]
    public function testGameDataRouteReachesGamedataPhp(string $route, string $type): void
    {
        $body = json_encode(['type' => $type]);
        $legacy = $this->call('POST', '/gamedata.php', $body);
        self::assertLessThan(500, $legacy[0]);
        self::assertSame($legacy, $this->call('POST', "/api/v1/$route", $body));
        // A body gamedata.php refuses on its own is left for it to refuse.
        self::assertSame($this->call('POST', '/gamedata.php', '{bad'), $this->call('POST', "/api/v1/$route", '{bad'));
        [$status, $answer] = $this->call('POST', "/api/v1/$route", json_encode(['type' => 'equipment']));
        self::assertSame(422, $status);
        self::assertSame('route_type_mismatch', json_decode($answer, true)['code'] ?? null);
    }

    public function testSpeechAndVisionReachTheirLegacyScripts(): void
    {
        self::assertSame($this->call('POST', '/stt.php', 'x', ['Content-Type: text/plain']),
            $this->call('POST', '/api/v1/speech/transcribe', 'x', ['Content-Type: text/plain']));
        self::assertSame($this->call('POST', '/itt.php', '{bad'), $this->call('POST', '/api/v1/vision/describe', '{bad'));
    }

    public function testHealthReachesHealthPhp(): void
    {
        self::assertSame($this->call('GET', '/health.php?check=live'), $this->call('GET', '/api/v1/health/live'));
        self::assertSame($this->call('GET', '/health.php?check=db'), $this->call('GET', '/api/v1/health/db'));
        self::assertSame($this->call('GET', '/health.php'), $this->call('GET', '/api/v1/health'));
    }

    public function testMessagesReachTtsStatusPhp(): void
    {
        self::assertSame($this->call('GET', '/tts_status.php?cache_key=not-a-key'), $this->call('GET', '/api/v1/messages/not-a-key'));
    }

    public function testWrongMethodIsRefusedWithAllow(): void
    {
        [$status, $body, $allow] = $this->call('GET', '/api/v1/user/input');
        self::assertSame(405, $status);
        self::assertSame('POST', $allow);
        self::assertSame('Method not allowed', json_decode($body, true)['error'] ?? null);
        [$status, , $allow] = $this->call('POST', '/api/v1/health');
        self::assertSame(405, $status);
        self::assertSame('GET, HEAD', $allow);
    }

    public function testUnknownRouteIs404WithAGenericError(): void
    {
        [$status, $body] = $this->call('GET', '/api/v1/no/such/route');
        self::assertSame(404, $status);
        self::assertSame('Route not found', json_decode($body, true)['error'] ?? null);
    }

    public function testUnimplementedRoutesAnswer501(): void
    {
        foreach ([['GET', '/api/v1/edit'], ['PUT', '/api/v1/edit/npc/1'], ['GET', '/api/v1/extensions'], ['POST', '/api/v1/save/created']] as [$method, $path]) {
            [$status, $body] = $this->call($method, $path, $method === 'GET' ? null : '{}');
            self::assertSame(501, $status, $path);
            self::assertSame('not_implemented', json_decode($body, true)['code'] ?? null, $path);
        }
    }

    public function testPathInfoFormReachesTheSameRoute(): void
    {
        self::assertSame($this->call('GET', '/api/v1/health/live'), $this->call('GET', '/api/index.php/v1/health/live'));
    }

    public function testRealScriptsUnderApiV1KeepWorking(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/voice/preview.php', json_encode(['npc_id' => 1, 'text' => 'Hello']));
        self::assertSame(403, $status);
        self::assertSame('csrf', json_decode($body, true)['code'] ?? null);
    }
}

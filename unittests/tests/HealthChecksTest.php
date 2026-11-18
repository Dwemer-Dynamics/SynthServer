<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../managers/HealthCheck/HealthCheckManager.php';

use Synth\Managers\HealthCheck\HealthCheckManager;

final class HealthChecksTest extends TestCase
{
    public function testLiveAnswersWithoutConfiguration(): void
    {
        [$code, $body] = HealthCheckManager::live('SYNTH Server', '1.2.3');
        self::assertSame(200, $code);
        self::assertSame('ok', $body['status']);
        self::assertSame('live', $body['check']);
    }

    public function testLlmTargetsCountConfiguredConnectorsAndDeduplicateEndpoints(): void
    {
        $targets = HealthCheckManager::llmTargets([
            ['url' => 'http://127.0.0.1:1234/v1/chat/completions', 'model' => 'a', 'api_badge_id' => 7],
            ['url' => 'HTTP://127.0.0.1:1234/other', 'model' => 'b'],
            ['url' => 'https://api.example.test/v1', 'model' => 'c'],
            ['url' => 'https://no-model.example.test/v1', 'model' => ' '],
            ['url' => 'ftp://files.example.test/', 'model' => 'd'],
            ['url' => '', 'model' => 'e'],
            'not a row',
        ]);
        self::assertSame(3, $targets['configured']);
        self::assertSame([['127.0.0.1', 1234]], $targets['endpoints']);
        self::assertSame(1, $targets['hosted']); // Host names are counted, never resolved or contacted.
        self::assertStringNotContainsString('api_badge', json_encode($targets));
    }

    public function testLlmTargetsAreCapped(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['url' => 'http://127.0.0.1:' . (2000 + $i) . '/v1', 'model' => 'm'];
        }
        self::assertCount(5, HealthCheckManager::llmTargets($rows)['endpoints']);
    }

    public function testProbeCountsOnlyAcceptingEndpoints(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server);
        $open = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int)substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);

        self::assertSame(1, HealthCheckManager::probeEndpoints([['127.0.0.1', $open], ['127.0.0.1', $closedPort]], 2.0));
        self::assertSame(0, HealthCheckManager::probeEndpoints([['127.0.0.1', $closedPort]], 2.0));
        self::assertSame(0, HealthCheckManager::probeEndpoints([], 2.0));
        fclose($server);
    }

    public function testWorstStatusRanksErrorAboveWarningAboveOk(): void
    {
        self::assertSame('ok', HealthCheckManager::worstStatus([['status' => 'ok']]));
        self::assertSame('warning', HealthCheckManager::worstStatus([['status' => 'ok'], ['status' => 'warning']]));
        self::assertSame('error', HealthCheckManager::worstStatus([['status' => 'warning'], ['status' => 'error']]));
        self::assertSame('error', HealthCheckManager::worstStatus([[]]));
    }

    public function testAllWithoutDatabaseSkipsTheRestWithoutTouchingProviders(): void
    {
        [$code, $body] = HealthCheckManager::all(null, 'SYNTH cannot connect to PostgreSQL.');
        self::assertSame(503, $code);
        self::assertSame('error', $body['status']);
        self::assertTrue($body['checks']['llm']['skipped']);
        self::assertTrue($body['checks']['npcs']['skipped']);
        self::assertArrayNotHasKey('llm_connectors_configured', $body['checks']['llm']);
    }

    public function testLlmWithNoConnectorIsAWarningNotAnOutage(): void
    {
        [$code, $body] = HealthCheckManager::llm(static fn(): int => 0, []);
        self::assertSame(200, $code);
        self::assertSame('warning', $body['status']);
        self::assertSame('not_configured', $body['llm']);
        self::assertSame('warning', HealthCheckManager::worstStatus([$body, ['status' => 'ok']]));
    }

    public function testLlmStatesForLocalAndHostedEndpoints(): void
    {
        $local = [['url' => 'http://127.0.0.1:1/v1', 'model' => 'm']];
        $hosted = [['url' => 'https://api.example.test/v1', 'model' => 'm']];

        [$code, $body] = HealthCheckManager::llm(static fn(): int => 1, $local);
        self::assertSame([200, 'ok'], [$code, $body['llm']]);

        [$code, $body] = HealthCheckManager::llm(static fn(): int => 0, $local);
        self::assertSame([503, 'error', 'unreachable'], [$code, $body['status'], $body['llm']]);
        self::assertArrayHasKey('error', $body);

        [$code, $body] = HealthCheckManager::llm(static fn(): int => 0, array_merge($local, $hosted));
        self::assertSame([200, 'warning', 'unreachable'], [$code, $body['status'], $body['llm']]);

        $probed = false;
        [$code, $body] = HealthCheckManager::llm(static function () use (&$probed): int {
            $probed = true;
            return 0;
        }, $hosted);
        self::assertSame([200, 'ok', 'unverified', 1], [$code, $body['status'], $body['llm'], $body['llm_hosted_unchecked']]);
        self::assertFalse($probed, 'a hosted host name is never contacted');
    }

    public function testLlmAnswersBusyInsteadOfStackingProbes(): void
    {
        $holder = fopen((new ReflectionClass(HealthCheckManager::class))->getFileName(), 'rb');
        self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        $called = false;
        [$code, $body] = HealthCheckManager::llm(static function () use (&$called): int {
            $called = true;
            return 1;
        }, [['url' => 'http://127.0.0.1:1/v1', 'model' => 'm']]);
        flock($holder, LOCK_UN);
        fclose($holder);
        self::assertSame([200, 'warning', 'busy'], [$code, $body['status'], $body['llm']]);
        self::assertFalse($called);
    }

    public function testNpcsReportsAnUnreadableStoreWithoutDriverText(): void
    {
        $db = new class {
            public function execQuery(string $sql, array $params = []): bool
            {
                throw new RuntimeException('secret-host:5432 refused');
            }
        };
        [$code, $body] = HealthCheckManager::npcs($db);
        self::assertSame(503, $code);
        self::assertSame(['check' => 'npcs', 'status' => 'error', 'npcs_available' => null], array_diff_key($body, ['error' => 1]));
        self::assertStringNotContainsString('secret-host', json_encode($body));
    }

    /** health.php served by the PHP built-in server from a stub tree: no PostgreSQL, no vendor/. */
    public function testHealthPhpWiring(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synth-health-wiring-' . bin2hex(random_bytes(4));
        mkdir($root . '/lib', 0700, true);
        mkdir($root . '/managers/HealthCheck', 0700, true);
        $source = dirname(__DIR__, 2);
        copy($source . '/health.php', $root . '/health.php');
        copy($source . '/managers/HealthCheck/HealthCheckManager.php', $root . '/managers/HealthCheck/HealthCheckManager.php');
        file_put_contents($root . '/lib/runtime_bootstrap.php', <<<'STUB'
<?php
class Logger { public static function suspendFileWrites(): void {} }
function synthRuntimeConnectDatabase(string $path, ?int $cap = null): object {
    return new class { public function query($q) { return true; } };
}
function synthRuntimeDatabaseEncoding(): string { return 'UTF8'; }
function synthRuntimeDatabaseEncodingIsSupported(?string $e = null): bool { return true; }
STUB);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            $get = static function (string $query) use ($port): array {
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
                $text = (string)@file_get_contents("http://127.0.0.1:$port/health.php$query", false, $context);
                preg_match('~^HTTP/\S+ (\d+)~', $http_response_header[0] ?? '', $match);
                return [(int)($match[1] ?? 0), json_decode($text, true) ?? []];
            };
            [$code, $body] = $get('?check=nope');
            self::assertSame([400, 'error'], [$code, $body['status'] ?? null]);
            [$code] = $get('?check[]=db');
            self::assertSame(400, $code);
            [$code, $body] = $get('?check=live');
            self::assertSame([200, 'live', 'ok'], [$code, $body['check'], $body['status']]);
            [$code, $body] = $get('?check=db');
            self::assertSame([200, 'ok', true], [$code, $body['status'], $body['database']]);
            // Without vendor/ the checks that need Composer say so, with the fixed message and 503.
            foreach (['api-keys', 'npcs', 'llm'] as $check) {
                [$code, $body] = $get("?check=$check");
                self::assertSame([503, $check, 'error'], [$code, $body['check'], $body['status']]);
                self::assertStringContainsString('composer install', $body['error']);
            }
            [$code, $body] = $get('?check=all');
            self::assertSame(503, $code);
            self::assertTrue($body['checks']['llm']['skipped']);
        } finally {
            proc_terminate($process);
            proc_close($process);
            foreach (['/lib/runtime_bootstrap.php', '/health.php', '/managers/HealthCheck/HealthCheckManager.php'] as $file) {
                @unlink($root . $file);
            }
            @rmdir($root . '/lib');
            @rmdir($root . '/managers/HealthCheck');
            @rmdir($root . '/managers');
            @rmdir($root);
        }
    }
}

<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/health_checks.php';

final class HealthChecksTest extends TestCase
{
    public function testLiveAnswersWithoutConfiguration(): void
    {
        [$code, $body] = synthHealthLive('SYNTH Server', '1.2.3');
        self::assertSame(200, $code);
        self::assertSame('ok', $body['status']);
        self::assertSame('live', $body['check']);
    }

    public function testLlmTargetsCountConfiguredConnectorsAndDeduplicateEndpoints(): void
    {
        $targets = synthHealthLlmTargets([
            ['url' => 'http://127.0.0.1:1234/v1/chat/completions', 'model' => 'a', 'api_badge_id' => 7],
            ['url' => 'HTTP://127.0.0.1:1234/other', 'model' => 'b'],
            ['url' => 'https://api.example.test/v1', 'model' => 'c'],
            ['url' => 'https://no-model.example.test/v1', 'model' => ' '],
            ['url' => 'ftp://files.example.test/', 'model' => 'd'],
            ['url' => '', 'model' => 'e'],
            'not a row',
        ]);
        self::assertSame(3, $targets['configured']);
        self::assertSame([['127.0.0.1', 1234], ['api.example.test', 443]], $targets['endpoints']);
        self::assertStringNotContainsString('api_badge', json_encode($targets));
    }

    public function testLlmTargetsAreCapped(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['url' => "http://host$i.example.test/v1", 'model' => 'm'];
        }
        self::assertCount(SYNTH_HEALTH_LLM_MAX_ENDPOINTS, synthHealthLlmTargets($rows)['endpoints']);
    }

    public function testProbeCountsOnlyAcceptingEndpoints(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server);
        $open = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $closedPort = (int)substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);

        self::assertSame(1, synthHealthProbeEndpoints([['127.0.0.1', $open], ['127.0.0.1', $closedPort]], 2.0));
        self::assertSame(0, synthHealthProbeEndpoints([['127.0.0.1', $closedPort]], 2.0));
        self::assertSame(0, synthHealthProbeEndpoints([], 2.0));
        fclose($server);
    }

    public function testWorstStatusRanksErrorAboveWarningAboveOk(): void
    {
        self::assertSame('ok', synthHealthWorstStatus([['status' => 'ok']]));
        self::assertSame('warning', synthHealthWorstStatus([['status' => 'ok'], ['status' => 'warning']]));
        self::assertSame('error', synthHealthWorstStatus([['status' => 'warning'], ['status' => 'error']]));
        self::assertSame('error', synthHealthWorstStatus([[]]));
    }

    public function testAllWithoutDatabaseSkipsTheRestWithoutTouchingProviders(): void
    {
        [$code, $body] = synthHealthAll(null, 'SYNTH cannot connect to PostgreSQL.');
        self::assertSame(503, $code);
        self::assertSame('error', $body['status']);
        self::assertTrue($body['checks']['llm']['skipped']);
        self::assertTrue($body['checks']['npcs']['skipped']);
        self::assertArrayNotHasKey('llm_connectors_configured', $body['checks']['llm']);
    }
}

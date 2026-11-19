<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Synth\Lib\LocalAccess;
use Synth\Lib\RequestBody;
use Synth\Lib\SameOriginRequest;

require_once dirname(__DIR__, 2) . '/lib/LocalAccess.php';
require_once dirname(__DIR__, 2) . '/lib/SameOriginRequest.php';
require_once dirname(__DIR__, 2) . '/lib/RequestBody.php';

// Deny by default: .htaccess lists what the web may reach and router.php applies the same list.
final class RequestBoundaryTest extends TestCase
{
    /** @return array{0: string, 1: int} what router.php decides for a path: 'serve', 'api', 'front' and the status */
    private function route(string $path): array
    {
        $script = '$_SERVER["REQUEST_URI"]=' . var_export($path, true) . ';'
            . 'ob_start();$r=(static function(){return require ' . var_export(dirname(__DIR__, 2) . '/router.php', true) . ';})();ob_end_clean();'
            . 'echo ($r===false?"serve":"handled"),"|",http_response_code();';
        $out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script)));
        [$verdict, $status] = explode('|', $out . '|0');

        return [$verdict, (int) $status];
    }

    /** @return iterable<string, array{string}> */
    public static function clientAndDashboardPaths(): iterable
    {
        foreach (['/main.php', '/itt.php', '/diary_status.php', '/tts_status.php', '/vsx.php', '/health.php',
            '/processor/npc_tts_play.php', '/media.php/media/' . str_repeat('a', 64), '/ui/', '/ui/home.php',
            '/ui/core/config_hub.php', '/ui/cmd/deleteRow.php', '/ui/api/audience_control.php',
            '/ui/tests/tts-test.php', '/ui/core/tests/llmtest.php', '/ui/css/main.css', '/soundcache/a.wav',
            '/data/pictures/gallery/a.png', '/Synthserver/main.php', '/Synthserver/ui/home.php',
            '/api/index.php/v1/health'] as $path) {
            yield $path => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function deniedPaths(): iterable
    {
        foreach (['/debug/db_updates.php', '/debug/rollback_middleterm_20260907008.sql', '/gamedata.php', '/stt.php',
            '/main_synth_pipeline.php', '/csv_import.php', '/player_rewrite.php', '/bootstrap.php', '/router.php',
            '/processor/npc_tts_worker.php', '/processor/runtime_status.php', '/service/manager.php', '/bin/install-db.php',
            '/tools/bootstrap-database.php', '/conf/conf.php', '/conf/conf.sample.php', '/composer.json', '/.htaccess',
            '/.git/config', '/log/synth.log', '/data/database_default.sql', '/data/voices/abc.wav', '/lib/runtime_bootstrap.php',
            '/ui/tmpl/head.html', '/ui/lib/ui/x.php', '/ui/tmp/x.php', '/ui/data/manualbackup/x.php', '/ui/profile_loader.php',
            '/ui/cmd/ai_profile_generation_helper.php', '/ui/tests/other.php', '/ui/core/tests/other.php', '/ui/cmd/install-db.php',
            '/ui/home.php/extra', '/media.php/media/not-hex', '/api/routes.php', '/api/v2/dispatch.php', '/ui/css/../../conf/conf.php',
            '/soundcache/a.php', '/data/pictures/a.php', '/Synthserver/debug/db_updates.php'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('clientAndDashboardPaths')]
    public function testAllowedPathIsServed(string $path): void
    {
        $verdict = $this->route($path);
        $this->assertSame('serve', $verdict[0], $path);
    }

    #[DataProvider('deniedPaths')]
    public function testUnlistedPathIsNotFound(string $path): void
    {
        $this->assertSame(['handled', 404], $this->route($path), $path);
    }

    public function testRootRedirectsToTheDashboardLikeApache(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::markTestSkipped('No loopback port available');
        }
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);
        $root = dirname(__DIR__, 2);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, $root . '/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root);
        try {
            for ($i = 0; $i < 50 && !($probe = @fsockopen('127.0.0.1', $port)); $i++) {
                usleep(100000);
            }
            $location = '';
            $call = static function (string $path) use ($port, &$location): int {
                $ch = curl_init("http://127.0.0.1:$port$path");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                        if (stripos($line, 'Location:') === 0) {
                            $location = trim(substr($line, 9));
                        }
                        return strlen($line);
                    }]);
                curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return $status;
            };
            $this->assertSame(302, $call('/'));
            $this->assertSame('/ui/', $location);
            $this->assertSame(404, $call('/debug/db_updates.php'));
            $this->assertSame(404, $call('/api/v2/dispatch.php'));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function testHtaccessEndsInTheDenyingFrontController(): void
    {
        $lines = array_values(array_filter(array_map('trim', file(dirname(__DIR__, 2) . '/.htaccess') ?: []),
            static fn(string $line): bool => str_starts_with($line, 'RewriteRule')));
        $this->assertSame('RewriteRule ^ index.php [QSA,L]', end($lines));
    }

    public function testNoWebScriptTurnsErrorDisplayOn(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['ui', 'api', 'lib', 'processor', 'managers'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression("~ini_set\\(\\s*['\"]display_(?:startup_)?errors['\"]\\s*,\\s*['\"]?(?:1|on|true)~i",
                    (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }

    public function testLocalAccessHostAndLoopbackRules(): void
    {
        $this->assertTrue(LocalAccess::isDirectLoopbackClient(['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertTrue(LocalAccess::isDirectLoopbackClient(['REMOTE_ADDR' => '::ffff:127.0.0.9']));
        $this->assertFalse(LocalAccess::isDirectLoopbackClient(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8']));
        $this->assertFalse(LocalAccess::isDirectLoopbackClient(['REMOTE_ADDR' => '192.168.1.5']));
        $this->assertTrue(LocalAccess::hostAllowed(['HTTP_HOST' => 'localhost:8087'], []));
        $this->assertTrue(LocalAccess::hostAllowed(['HTTP_HOST' => '[::1]'], []));
        $this->assertTrue(LocalAccess::hostAllowed(['HTTP_HOST' => 'synth.lan'], ['synth.lan']));
        $this->assertFalse(LocalAccess::hostAllowed(['HTTP_HOST' => 'evil.example'], ['synth.lan']));
        $this->assertSame(['a.lan', 'b.lan'], LocalAccess::parseAllowedHosts('a.lan, b.lan:80 10.0.0.1 *.x'));
    }

    public function testSameOriginEvidenceMustMatch(): void
    {
        $server = ['HTTP_HOST' => '127.0.0.1:8087', 'REQUEST_METHOD' => 'POST'];
        $this->assertNull(SameOriginRequest::refusal($server + ['HTTP_ORIGIN' => 'http://127.0.0.1:8087']));
        $this->assertSame(SameOriginRequest::CROSS_ORIGIN, SameOriginRequest::refusal($server + ['HTTP_ORIGIN' => 'http://127.0.0.1:9']));
        $this->assertSame(SameOriginRequest::CROSS_ORIGIN, SameOriginRequest::refusal($server + ['HTTP_ORIGIN' => 'null']));
        $this->assertSame(SameOriginRequest::CROSS_ORIGIN, SameOriginRequest::refusal($server + ['HTTP_SEC_FETCH_SITE' => 'same-site']));
        $this->assertSame(SameOriginRequest::UNIDENTIFIED, SameOriginRequest::refusal($server + ['HTTP_USER_AGENT' => 'Mozilla/5.0']));
        $this->assertNull(SameOriginRequest::refusal($server));
    }

    public function testBodyLimitStatuses(): void
    {
        $this->assertSame(411, RequestBody::refusalStatus([], 10));
        $this->assertSame(411, RequestBody::refusalStatus(['CONTENT_LENGTH' => '-1'], 10));
        $this->assertSame(413, RequestBody::refusalStatus(['CONTENT_LENGTH' => '11'], 10));
        $this->assertSame(413, RequestBody::refusalStatus(['CONTENT_LENGTH' => '99999999999999999999'], 10));
        $this->assertNull(RequestBody::refusalStatus(['CONTENT_LENGTH' => '10'], 10));
        $this->assertNull(RequestBody::refusalStatus(['CONTENT_LENGTH' => '0'], 10));
    }
}

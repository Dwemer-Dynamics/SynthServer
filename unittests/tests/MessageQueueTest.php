<?php declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

use Synth\Core\DbManager\Dao\MessageQueueDao;
use Synth\Core\DbManager\Database;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Managers\MessageHandler\MessageHandler;

// The durable message queue (core_message_queue, managers/MessageHandler): the lifecycle, persist-then-notify, the
// announcement retry, cancellation and expiry, retention, exactly-once delivery over real HTTP with 20 parallel fetches, and
// the push through the real notify-server process. The disposable PostgreSQL database holds the table; nothing here is mocked
// except the announcement callback in the lifecycle tests.
final class MessageQueueTest extends DatabaseTestCase
{
    private ?sql $session = null;
    private MessageQueueDao $dao;
    /** @var resource|null */
    private $server = null;
    /** @var resource|null */
    private $notify = null;
    private string $serverLog = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->session = new sql();
        require_once dirname(__DIR__, 2) . '/core/dbmanager/Database.php';
        Database::bootWith(EntityManagerFactory::forSharedSession($this->session));
        $this->dao = MessageQueueDao::shared();
    }

    public function tearDown(): void
    {
        foreach ([$this->server, $this->notify] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->server = $this->notify = null;
        if ($this->serverLog !== '' && is_file($this->serverLog)) {
            unlink($this->serverLog);
        }
        $this->session?->close();
        $this->session = null;
        putenv('SYNTH_MESSAGE_QUEUE');
        parent::tearDown();
    }

    private function handler(?callable $notifier = null): MessageHandler
    {
        return new MessageHandler($this->dao, $notifier ?? static fn(int $id): bool => true);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $row = $this->session->fetchOne('SELECT * FROM public.core_message_queue WHERE id = ' . $id);
        $this->assertIsArray($row);
        return $row;
    }

    private function payload(string $key = '0123456789abcdef0123456789abcdef'): array
    {
        return ['schema' => 'synth.tts_status.v1', 'cache_key' => $key, 'status' => 'failed', 'error_code' => 'synthesis_failed'];
    }

    public function testCompletionIsStoredBeforeItIsAnnouncedAndDeliveryHappensOnce(): void
    {
        $seenByAnnouncement = null;
        $handler = $this->handler(function (int $id) use (&$seenByAnnouncement): bool {
            // Persist-then-notify: when the id is announced, the payload is already stored and fetchable.
            $seenByAnnouncement = $this->row($id);
            return true;
        });
        $id = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        $this->assertSame($id, $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1'), 'admission is idempotent per utterance');
        $this->assertSame($id, $handler->idFor('session-1', 'req-1', 'utt-1'));
        $this->assertSame('pending', $handler->status($id));
        $this->assertNull($handler->deliver($id), 'a pending message is not visible');

        $this->assertTrue($handler->complete($id, $this->payload()));
        $this->assertSame('completed', $seenByAnnouncement['status']);
        $this->assertNotNull($seenByAnnouncement['payload']);
        $this->assertNotNull($this->row($id)['notified_at']);
        $this->assertFalse($handler->complete($id, $this->payload()), 'only a pending message completes');

        $first = $handler->deliver($id);
        $this->assertIsArray($first);
        $this->assertSame('speech', $first['message_type']);
        $this->assertEqualsCanonicalizing($this->payload(), json_decode((string) $first['payload'], true), 'jsonb may reorder keys');
        $this->assertNull($handler->deliver($id), 'a delivered message is never returned again');
        $this->assertSame('delivered', $handler->status($id));
        $this->assertNotNull($this->row($id)['delivered_at']);
        $this->assertNull($handler->status($id + 1000), 'an unknown id has no status');
    }

    public function testPayloadsMustBeNonEmptyJsonObjects(): void
    {
        $handler = $this->handler();
        $id = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        foreach ([[], ['a', 'b']] as $bad) {
            try {
                $handler->complete($id, $bad);
                $this->fail('A non-object payload was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $handler->complete($id, ['blob' => str_repeat('x', MessageHandler::MAXIMUM_PAYLOAD_BYTES)]);
    }

    public function testAFailedAnnouncementKeepsTheMessageAndIsRetriedAFewTimes(): void
    {
        $attempts = 0;
        $handler = $this->handler(function (int $id) use (&$attempts): bool {
            $attempts++;
            return false;
        });
        $id = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        $this->assertTrue($handler->complete($id, $this->payload()), 'a missing channel does not fail the completion');
        $this->assertSame('completed', $this->row($id)['status']);
        $this->assertNull($this->row($id)['notified_at']);
        $this->assertSame(1, intval($this->row($id)['notify_attempts']));

        for ($round = 0; $round < 10; $round++) {
            $handler->retryNotifications();
        }
        $this->assertSame(MessageHandler::MAXIMUM_NOTIFY_ATTEMPTS, $attempts, 'announcing is bounded');
        $this->assertIsArray($handler->deliver($id), 'the message is still fetchable');

        $second = $this->handler()->admit('speech', 'session-1', 'req-2', 1, 'utt-2');
        $failing = $this->handler(static fn(int $id): bool => false);
        $failing->complete($second, $this->payload());
        $this->assertSame(1, $this->handler()->retryNotifications(), 'a reachable channel announces the message again');
        $this->assertNotNull($this->row($second)['notified_at']);
        $this->assertSame(0, $this->handler()->retryNotifications());
    }

    public function testCancellationExpiryAndNewGenerationsRetireWork(): void
    {
        $handler = $this->handler();
        $cancelled = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        $done = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-2');
        $handler->complete($done, $this->payload());
        $this->assertSame(2, $handler->cancelRequest('session-1', 'req-1'));
        $this->assertSame('cancelled', $handler->status($cancelled));
        $this->assertFalse($handler->complete($cancelled, $this->payload()), 'cancelled work cannot complete');
        $this->assertNull($handler->deliver($done), 'a cancelled result is never returned');

        $stale = $handler->admit('speech', 'session-1', 'req-2', 1, 'utt-1');
        $other = $handler->admit('speech', 'session-2', 'req-9', 1, 'utt-1');
        $handler->admit('speech', 'session-1', 'req-3', 2, 'utt-1');
        $this->assertSame('cancelled', $handler->status($stale), 'a newer generation cancels older work of its session');
        $this->assertSame('pending', $handler->status($other), 'and only of its own session');

        $expiring = $handler->admit('speech', 'session-3', 'req-1', 1, 'utt-1');
        $finished = $handler->admit('speech', 'session-3', 'req-1', 1, 'utt-2');
        $handler->complete($finished, $this->payload());
        $this->session->execQuery("UPDATE public.core_message_queue SET expires_at = now() - interval '1 second' WHERE id IN ($expiring, $finished)");
        $this->assertSame('expired', $handler->status($expiring), 'expiry applies before the maintenance run records it');
        $this->assertNull($handler->deliver($finished), 'a completed message past its expiry is not returned');
        $this->assertFalse($handler->complete($expiring, $this->payload()));
        $this->assertSame(2, $handler->expire());
        $this->assertSame('expired', $this->row($finished)['status']);
        $this->assertSame(0, $handler->cancelCancelledWork(), 'no cancelled speech jobs, nothing to cancel');
    }

    public function testRetentionDeletesOnlyOldTerminalMessages(): void
    {
        $handler = $this->handler();
        $delivered = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        $handler->complete($delivered, $this->payload());
        $handler->deliver($delivered);
        $pending = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-2');
        $completed = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-3');
        $handler->complete($completed, $this->payload());
        $this->session->execQuery("UPDATE public.core_message_queue SET created_at = now() - interval '30 days'");
        $report = $handler->report(7);
        $this->assertSame(1, $report['terminal_older_than_retention']);
        $this->assertSame(['pending' => 1, 'completed' => 1, 'delivered' => 1, 'cancelled' => 0, 'expired' => 0], $report['counts']);
        $this->assertGreaterThan(0, $report['oldest_pending_seconds']);
        $this->assertSame(1, $handler->purge(7));
        $this->assertSame('pending', $handler->status($pending), 'active work is never deleted');
        $this->assertSame('completed', $handler->status($completed));
        $this->assertNull($handler->status($delivered));
    }

    public function testTheMessageSchemaIsTheMigrationsStructure(): void
    {
        // The table is built by the migration alone: the status check and the (session, request, key) uniqueness are real.
        $this->assertFalse(@$this->session->execQuery("INSERT INTO public.core_message_queue (message_type, session_id, request_id, generation, dedup_key, expires_at, status)"
            . " VALUES ('speech', 's', 'r', 1, 'k', now(), 'bogus')"));
        $this->handler()->admit('speech', 's', 'r', 1, 'k');
        $this->assertSame(1, intval($this->session->fetchOne("SELECT count(*) AS n FROM public.core_message_queue WHERE session_id = 's'")['n']));
    }

    // ---- over HTTP and the real notification process ----

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr(strrchr((string) $name, ':'), 1);
    }

    /** @param array<string,string> $extra */
    private function startServer(array $extra = []): string
    {
        $port = $this->freePort();
        $root = dirname(__DIR__, 2);
        $environment = getenv();
        unset($environment['PHPUNIT_TEST']);
        $environment += ['SYNTH_DB_HOST' => 'localhost', 'SYNTH_DB_NAME' => 'testdb', 'SYNTH_DB_USER' => 'dwemer',
            'SYNTH_DB_PASSWORD' => 'dwemer', 'SYNTH_BACKGROUND_START_SCRIPT' => '/nonexistent-disposable-background-script',
            'SYNTH_BACKGROUND_PORT' => '61999', 'SYNTH_MESSAGE_QUEUE' => '1', 'PHP_CLI_SERVER_WORKERS' => '24'];
        $environment = $extra + $environment;
        $this->serverLog = tempnam(sys_get_temp_dir(), 'synth-queue-http-');
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, dirname(__DIR__) . '/fixtures/legacy_reference_router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->serverLog, 'a'], 2 => ['file', $this->serverLog, 'a']], $pipes, $root, $environment);
        $this->assertIsResource($this->server);
        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', $port);
            if ($probe) {
                fclose($probe);
                return "http://127.0.0.1:$port";
            }
            usleep(50000);
        }
        $this->fail('The test server did not start');
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function fetch(string $base, string $path, string $method = 'GET'): array
    {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_NOBODY => $method === 'HEAD']);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, json_decode($body, true) ?? []];
    }

    public function testTwentyParallelFetchesDeliverTheMessageExactlyOnce(): void
    {
        $handler = $this->handler();
        $id = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        $base = $this->startServer();

        [$status, $body] = $this->fetch($base, "/api/v1/messages/$id");
        $this->assertSame([409, 'pending'], [$status, $body['status'] ?? null], 'a message still in production answers 409 pending');
        [$status] = $this->fetch($base, "/api/v1/messages/$id", 'HEAD');
        $this->assertSame(409, $status);
        $this->assertSame([404, 'unknown'], [($r = $this->fetch($base, '/api/v1/messages/987654'))[0], $r[1]['status'] ?? null]);

        $handler->complete($id, $this->payload());
        [$status] = $this->fetch($base, "/api/v1/messages/$id", 'HEAD');
        $this->assertSame(200, $status, 'HEAD reports readiness without consuming the message');

        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 20; $i++) {
            $ch = curl_init("$base/api/v1/messages/$id");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        do {
            $state = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0 && $state === CURLM_OK);
        $statuses = [];
        $winner = null;
        foreach ($handles as $ch) {
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statuses[] = $status;
            $body = json_decode((string) curl_multi_getcontent($ch), true);
            if ($status === 200) {
                $winner = $body;
            } else {
                $this->assertSame(['schema' => 'synth.message.v1', 'message_id' => $id, 'status' => 'delivered'], $body);
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        $counts = array_count_values($statuses);
        $this->assertSame([200 => 1, 409 => 19], $counts, 'one 200 and nineteen 409: ' . json_encode($counts) . (is_file($this->serverLog) ? substr((string) file_get_contents($this->serverLog), -1500) : ''));
        $this->assertEqualsCanonicalizing($this->payload(), $winner['payload'], 'jsonb may reorder keys');
        $this->assertSame(['id' => $id, 'session' => 'session-1', 'generation' => 1], ['id' => $winner['message_id'], 'session' => $winner['session_id'], 'generation' => $winner['generation']]);
        $this->assertSame('delivered', $winner['status']);
        [$status, $body] = $this->fetch($base, "/api/v1/messages/$id");
        $this->assertSame([409, 'delivered'], [$status, $body['status']], 'every later fetch still gets 409');

        $handler->cancelRequest('session-1', 'req-9');
        $gone = $handler->admit('speech', 'session-1', 'req-2', 1, 'utt-1');
        $handler->cancelRequest('session-1', 'req-2');
        $this->assertSame([404, 'cancelled'], [($r = $this->fetch($base, "/api/v1/messages/$gone"))[0], $r[1]['status'] ?? null]);
        $this->assertSame(400, $this->fetch($base, '/api/v1/messages/not-a-key')[0], 'a cache key still takes the legacy path');
    }

    public function testTheQueueIsOffUntilSwitchedOnAndTheLegacyPathStaysServed(): void
    {
        $id = $this->handler()->admit('speech', 'session-1', 'req-1', 1, 'utt-1');
        $this->handler()->complete($id, $this->payload());
        $base = $this->startServer(['SYNTH_MESSAGE_QUEUE' => '0']);
        $this->assertSame([404, 'unknown'], [($r = $this->fetch($base, "/api/v1/messages/$id"))[0], $r[1]['status'] ?? null]);
        $this->assertSame('completed', $this->row($id)['status'], 'a switched-off queue delivers nothing');
        [$status, $body] = $this->fetch($base, '/api/v1/messages/' . str_repeat('a', 32));
        $this->assertSame(['synth.tts_status.v1', 'unknown'], [$body['schema'] ?? null, $body['status'] ?? null], 'legacy cache-key status is unchanged');
        $this->assertSame(200, $status);
    }

    /** @param resource $socket */
    private function readFrame($socket): string
    {
        stream_set_timeout($socket, 5);
        $head = fread($socket, 2);
        $this->assertSame(2, strlen((string) $head), 'no frame arrived');
        $length = ord($head[1]) & 0x7F;
        $this->assertLessThan(126, $length);
        return (string) fread($socket, $length);
    }

    public function testCompletionPushesMessageAvailableToAConnectedClient(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
            $this->markTestSkipped('vendor/autoload.php is required to run bin/notify-server.php');
        }
        $webSocketPort = $this->freePort();
        $internalPort = $this->freePort();
        putenv('SYNTH_NOTIFY_INTERNAL_PORT=' . $internalPort);
        $this->notify = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/notify-server.php', '--port=' . $webSocketPort, '--internal-port=' . $internalPort],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($this->notify);
        for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $internalPort); $i++) {
            usleep(50000);
        }
        $handler = new MessageHandler($this->dao);
        $id = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-1');

        // Nobody connected: the message is stored, the announcement is recorded as failed and retried later.
        $handler->complete($id, $this->payload());
        $this->assertSame('completed', $this->row($id)['status']);
        $this->assertNull($this->row($id)['notified_at']);

        $client = stream_socket_client('tcp://127.0.0.1:' . $webSocketPort, $errno, $error, 2);
        $this->assertIsResource($client);
        fwrite($client, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $reply = '';
        while (!str_contains($reply, "\r\n\r\n")) {
            $reply .= (string) fread($client, 1);
        }
        $this->assertStringContainsString('101 Switching Protocols', $reply);
        $this->assertSame('{"schema":"synth.notify.hint.v1","type":"welcome"}', $this->readFrame($client));

        $this->assertSame(1, $handler->retryNotifications(), 'the retry reaches the now connected client');
        $this->assertSame('{"schema":"synth.notify.hint.v1","type":"message_available","message_id":' . $id . '}', $this->readFrame($client));
        $this->assertNotNull($this->row($id)['notified_at']);

        $second = $handler->admit('speech', 'session-1', 'req-1', 1, 'utt-2');
        $handler->complete($second, $this->payload());
        $this->assertSame('{"schema":"synth.notify.hint.v1","type":"message_available","message_id":' . $second . '}', $this->readFrame($client));
        fclose($client);
    }
}

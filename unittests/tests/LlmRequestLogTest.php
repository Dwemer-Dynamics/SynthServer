<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/drivers/llm/LlmWrapper.php';

use Synth\Core\DbManager\Dao\LlmRequestLogDao;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Drivers\Llm\LlmDriverInterface;
use Synth\Drivers\Llm\LlmWrapper;
use Synth\Drivers\Llm\Logging\DatabaseLlmRequestRecorder;
use Synth\Drivers\Llm\Logging\LlmRequestRecorder;
use Synth\Drivers\Llm\Logging\LlmRequestReplayer;
use Synth\Drivers\Llm\Logging\LlmRequestSanitizer;
use Synth\Drivers\Llm\Logging\LoggedLegacyConnector;

// The LLM request log: what is stored (redacted, bounded), how each driver shape is logged, and retention.
final class LlmRequestLogTest extends DatabaseTestCase
{
    private function boot(sql $db): LlmRequestLogDao
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2).'/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);
        DatabaseLlmRequestRecorder::resetCache();
        return LlmRequestLogDao::shared();
    }

    /** A recorder that keeps what it is given, so the shapes can be asserted without a database. */
    private function memory(): LlmRequestRecorder
    {
        return new class implements LlmRequestRecorder {
            public array $begun = [];
            public array $ended = [];
            public function begin(array $entry): ?int { $this->begun[] = $entry; return count($this->begun); }
            public function end(?int $id, array $result): void { $this->ended[$id] = $result; }
        };
    }

    public function testSanitizerRemovesSecretsButKeepsCounters(): void
    {
        $clean = new LlmRequestSanitizer(['exact-connector-key-123']);
        [$value] = $clean->clean([
            'api_key' => 'abc', 'Authorization' => 'Bearer xyz', 'max_tokens' => 512, 'player2_game_key' => 'g', 'password' => 'p',
            'messages' => [['role' => 'user', 'content' => 'use exact-connector-key-123 and Bearer abcdefghijkl and sk-abcdefghijklmnopqrstuv']],
            'url' => 'https://host/v1/models?key=SECRETVALUE&x=1',
        ]);
        $this->assertSame('[redacted]', $value['api_key']);
        $this->assertSame('[redacted]', $value['Authorization']);
        $this->assertSame('[redacted]', $value['player2_game_key']);
        $this->assertSame('[redacted]', $value['password']);
        $this->assertSame(512, $value['max_tokens'], 'a counter is not a secret');
        $text = $value['messages'][0]['content'];
        $this->assertStringNotContainsString('exact-connector-key-123', $text);
        $this->assertStringNotContainsString('abcdefghijkl', $text);
        $this->assertStringNotContainsString('sk-abcdefgh', $text);
        $this->assertStringNotContainsString('SECRETVALUE', $value['url']);
        $this->assertStringContainsString('x=1', $value['url']);
    }

    public function testSanitizerBoundsStringsAndWholePayloads(): void
    {
        $clean = new LlmRequestSanitizer();
        [$value, $cut] = $clean->clean(['messages' => [['content' => str_repeat('é', LlmRequestSanitizer::MAX_STRING)]]]);
        $this->assertTrue($cut);
        $this->assertLessThan(LlmRequestSanitizer::MAX_STRING + 64, strlen($value['messages'][0]['content']));
        $this->assertTrue(mb_check_encoding($value['messages'][0]['content'], 'UTF-8'), 'a cut never splits a character');
        $many = ['messages' => array_fill(0, 40, ['content' => str_repeat('x', 60000)])];
        [$whole, $oversized] = $clean->clean($many);
        $this->assertTrue($oversized);
        $this->assertTrue($whole['_oversized']);
        [$text, $textCut] = $clean->cleanText(str_repeat('r', LlmRequestSanitizer::MAX_RESPONSE + 10));
        $this->assertTrue($textCut);
        $this->assertSame([false], [$clean->clean(['a' => 'small'])[1]]);
    }

    public function testStreamPathLogsMessagesReplyAndOutcome(): void
    {
        $driver = new class implements LlmDriverInterface {
            public bool $fail = false;
            public function getName(): string { return 'fake'; }
            public function supports(array $connector): bool { return true; }
            public function stream(array $connector, array $messages, callable $onText): void
            {
                $onText('Hel');
                if ($this->fail) throw new RuntimeException('boom');
                $onText('lo');
            }
        };
        $recorder = $this->memory();
        $wrapper = new LlmWrapper([$driver], $recorder);
        $heard = '';
        $wrapper->stream(['id' => 7, 'driver' => 'openaijson', 'model' => 'm', 'api_key' => 'topsecretkey1'], [['role' => 'user', 'content' => 'hi']],
            static function (string $t) use (&$heard): void { $heard .= $t; });
        $this->assertSame('Hello', $heard, 'the caller still receives every delta');
        $this->assertSame(['stream', 'fake', 'm', 7], [$recorder->begun[0]['call_kind'], $recorder->begun[0]['driver'], $recorder->begun[0]['model'], $recorder->begun[0]['connector_id']]);
        $this->assertArrayNotHasKey('api_key', $recorder->begun[0]['request']['connector'], 'the connector summary carries no key');
        $this->assertSame(['completed', 'Hello'], [$recorder->ended[1]['status'], $recorder->ended[1]['response']]);

        $driver->fail = true;
        try {
            $wrapper->stream(['driver' => 'openaijson'], [], static fn(string $t) => null);
            $this->fail('The provider failure must reach the caller');
        } catch (RuntimeException $error) {
            $this->assertSame('boom', $error->getMessage());
        }
        $this->assertSame(['failed', 'Hel'], [$recorder->ended[2]['status'], $recorder->ended[2]['response']]);
        $this->assertInstanceOf(RuntimeException::class, $recorder->ended[2]['error']);
    }

    public function testLegacyConnectorLogsStreamAndFastRequestsAndPassesEverythingThrough(): void
    {
        $inner = new class {
            public $primary_handler = 'handle';
            public $name = 'openaijson';
            public bool $failClose = false;
            public function open($contextData, $customParms) { $this->primary_handler = 'opened'; return true; }
            public function process() { return 'chunk'; }
            public function isDone() { return false; }
            public function close($callName = '') { return 'streamed text'; }
            public function fast_request($contextData, $customParms, $callName = '') { return $callName === 'empty' ? '' : 'fast text'; }
        };
        $recorder = $this->memory();
        $logged = new LoggedLegacyConnector($inner, 'openaijson', ['id' => 3, 'model' => 'gpt-x'], $recorder);
        $this->assertSame('handle', $logged->primary_handler);
        $logged->primary_handler = 'replaced';
        $this->assertSame('replaced', $inner->primary_handler, 'property writes reach the driver');
        $this->assertFalse($logged->isDone(), 'other methods pass through');

        $messages = [['role' => 'user', 'content' => 'hello']];
        $this->assertSame('fast text', $logged->fast_request($messages, ['MAX_TOKENS' => 64, 'API_KEY' => 'keykeykey1'], 'summary'));
        $this->assertSame(['fast', 'openaijson', 'gpt-x', 3, 'summary'], [$recorder->begun[0]['call_kind'], $recorder->begun[0]['driver'], $recorder->begun[0]['model'], $recorder->begun[0]['connector_id'], $recorder->begun[0]['purpose']]);
        $this->assertSame($messages, $recorder->begun[0]['request']['messages'], 'the replay input is the driver input');
        $this->assertSame(['completed', 'fast text'], [$recorder->ended[1]['status'], $recorder->ended[1]['response']]);

        $this->assertSame('', $logged->fast_request($messages, [], 'empty'));
        $this->assertSame('failed', $recorder->ended[2]['status'], 'an empty reply is a failed request');

        $logged->open($messages, []);
        $this->assertSame('chunk', $logged->process());
        $this->assertSame('streamed text', $logged->close());
        $this->assertSame(['stream', 'completed', 'streamed text'], [$recorder->begun[2]['call_kind'], $recorder->ended[3]['status'], $recorder->ended[3]['response']]);

        $logged->open($messages, []);
        $logged->close('failed-stream-before-fallback');
        $this->assertSame('failed', $recorder->ended[4]['status']);

        $logged->open($messages, []);
        unset($logged);
        $this->assertSame('failed', $recorder->ended[5]['status'], 'a request that was never closed is ended as failed');
    }

    public function testRowsAreStoredCompletedAndPrunedByRetention(): void
    {
        $db = new sql();
        try {
            $dao = $this->boot($db);
            $recorder = new DatabaseLlmRequestRecorder($dao);
            $id = $recorder->begin([
                'call_kind' => 'fast', 'driver' => 'openaijson', 'model' => 'm', 'purpose' => 'summary', 'secrets' => ['key-for-this-test-1'],
                'request' => ['call' => 'fast_request', 'messages' => [['role' => 'user', 'content' => 'say key-for-this-test-1']], 'parameters' => ['MAX_TOKENS' => 8, 'API_KEY' => 'key-for-this-test-1']],
            ]);
            $this->assertNotNull($id);
            $this->assertSame('running', $dao->find($id)->getStatus());
            $recorder->end($id, [
                'status' => 'completed', 'duration_ms' => 12, 'http_status' => 200, 'url' => 'https://x.test/v1/chat?key=abc12345',
                'request_body' => json_encode(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']], 'api_key' => 'zzz']),
                'response' => json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12]]),
                'secrets' => ['key-for-this-test-1'],
            ]);
            $row = $dao->find($id);
            $this->assertSame(['completed', 12, 200, 5, 7, 12], [$row->getStatus(), $row->getDurationMs(), $row->getHttpStatus(), $row->getPromptTokens(), $row->getCompletionTokens(), $row->getTotalTokens()]);
            $this->assertStringNotContainsString('key-for-this-test-1', json_encode($row->getRequest()), 'the connector key never reaches the row');
            $this->assertSame('[redacted]', $row->getRequestBody()['api_key']);
            $this->assertStringNotContainsString('abc12345', (string) $row->getUrl());
            $this->assertFalse($row->isRequestTruncated());

            // A row that already ended cannot be rewritten.
            $this->assertFalse($dao->finish($id, ['status' => 'failed']));

            // Retention: abandoned runners end, old rows go, the cap keeps the newest.
            $stale = $dao->start(['call_kind' => 'stream', 'driver' => 'd', 'request' => ['messages' => []]]);
            $db->query("UPDATE public.core_llm_request_log SET created_at = now() - interval '3 hours' WHERE id = {$stale}");
            $old = $dao->start(['call_kind' => 'fast', 'driver' => 'd', 'request' => ['messages' => []], 'replay_of_id' => $id]);
            $db->query("UPDATE public.core_llm_request_log SET created_at = now() - interval '30 days' WHERE id = {$old}");
            $pruned = $dao->prune(14, 5000);
            $this->assertSame(['abandoned' => 2, 'expired' => 1, 'capped' => 0], $pruned);
            $this->assertSame('failed', $dao->find($stale)->getStatus());
            $this->assertNull($dao->find($old));
        } finally {
            $db->close();
        }
    }

    public function testReplayRefusesACutRequestAndAMissingOne(): void
    {
        $db = new sql();
        try {
            $dao = $this->boot($db);
            $cut = $dao->start(['call_kind' => 'fast', 'driver' => 'd', 'request' => ['messages' => [['content' => 'x']]], 'request_truncated' => true]);
            $replayer = new LlmRequestReplayer($dao, new class { public function getById($id) { return ['id' => $id, 'driver' => 'openaijson']; } });
            try {
                $replayer->plan($cut);
                $this->fail('A cut request must not be replayed');
            } catch (DomainException $error) {
                $this->assertStringContainsString('cut for size', $error->getMessage());
            }
            $this->expectException(InvalidArgumentException::class);
            $replayer->plan(999999999);
        } finally {
            $db->close();
        }
    }
}

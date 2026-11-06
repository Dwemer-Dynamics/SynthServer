<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

final class DiaryConnectorTimeoutTest extends TestCase
{
    public static function timeoutCases(): array
    {
        return [
            'worker-default' => [15, false, 30],
            'explicit-longer' => [120, false, 120],
            'reasoning-worker-default' => [15, true, 90],
            'reasoning-explicit-longer' => [120, true, 120],
            'unset' => [null, false, 30],
            'zero' => [0, false, 30],
        ];
    }

    #[DataProvider('timeoutCases')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDiaryFastRequestUsesConnectorTimeoutFloor(?int $configured, bool $reasoning, int $expected): void
    {
        ini_set('error_log', '/dev/null');
        // No runtime configuration or network: exercise the actual fast_request
        // transformation and inspect its HTTP context at the existing mock boundary.
        $GLOBALS['CONNECTOR']['openrouterjson'] = [
            'url' => 'https://provider.invalid/chat/completions',
            'model' => 'fixture/diary', 'API_KEY' => 'fixture-only',
            'reasoning_model' => $reasoning,
            'max_tokens' => 750, 'temperature' => 0.7, 'presence_penalty' => 0,
            'frequency_penalty' => 0, 'repetition_penalty' => 1, 'top_p' => 1,
            'top_k' => 0, 'min_p' => 0, 'top_a' => 0,
            'ENFORCE_JSON' => false, 'PREFILL_JSON' => false, 'json_schema' => false,
        ];
        if ($configured === null) unset($GLOBALS['HTTP_TIMEOUT']);
        else $GLOBALS['HTTP_TIMEOUT'] = $configured;
        $GLOBALS['EMOTEMOODS'] = 'neutral';
        $GLOBALS['LANG_LLM_XTTS'] = false;
        $GLOBALS['MAX_WORDS_LIMIT'] = 0;
        $GLOBALS['FEATURES'] = [];
        $GLOBALS['PLAYER_NAME'] = 'Fixture Player';
        $GLOBALS['SYNTH_NAME'] = 'Fixture NPC';
        $GLOBALS['IS_NPC'] = true;
        $GLOBALS['SYNTH_NO_EXAMPLES'] = true;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
        $GLOBALS['COMMAND_PROMPT'] = '';
        $GLOBALS['BASE_FUNCTIONS'] = [];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = [];
        $GLOBALS['DIRECT_NARRATOR_DIALOGUE'] = false;
        $GLOBALS['db'] = null;
        $GLOBALS['gameRequest'] = ['diary', 1, 1, 'Native manual diary'];
        require_once dirname(__DIR__, 2).'/lib/logger.php';
        restore_error_handler();
        require_once dirname(__DIR__, 2).'/lib/data_functions.php';
        require_once dirname(__DIR__, 2).'/lib/core/llm_connector.class.php';
        require_once dirname(__DIR__, 2).'/functions/functions.php';
        require_once dirname(__DIR__, 2).'/connector/openrouterjson.php';
        $messages = [['role' => 'system', 'content' => 'Write a fixture diary.'],
            ['role' => 'user', 'content' => 'Only fixture facts.']];
        $calls = [];
        $GLOBALS['mockConnectorSend'] = static function ($url, $context) use (&$calls): string {
            $calls[] = [$url, stream_context_get_options($context)['http']];
            return '{"choices":[{"message":{"content":"Fixture diary."}}],"usage":{}}';
        };
        $connector = new openrouterjson();
        self::assertSame('Fixture diary.', $connector->fast_request($messages, ['MAX_TOKENS' => 750], 'diary'));
        self::assertCount(1, $calls);
        [$url, $http] = $calls[0];
        self::assertSame('https://provider.invalid/chat/completions', $url);
        self::assertSame($expected, $http['timeout']);
        $request = json_decode($http['content'], true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($request['stream']);
        self::assertSame(750, $request['max_tokens']);
        self::assertSame($messages, $request['messages']);
    }
}

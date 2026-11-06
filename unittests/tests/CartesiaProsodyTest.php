<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Records the real driver's stream request without contacting a provider. */
final class CartesiaFixtureHttpsStream
{
    public $context;
    public static array $requests = [];
    private int $position = 0;
    public static function audio(): string
    {
        return 'RIFF'.pack('V', 38).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16)
            .'data'.pack('V', 2)."\0\0";
    }
    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        self::$requests[] = ['url'=>$path, 'options'=>stream_context_get_options($this->context)];
        return true;
    }
    public function stream_read($count): string
    {
        $data = substr(self::audio(), $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }
    public function stream_eof(): bool { return $this->position >= strlen(self::audio()); }
    public function stream_stat(): array { return []; }
}

#[PreserveGlobalState(false)]
final class CartesiaProsodyTest extends TestCase
{
    private array $previous = [];
    private bool $httpsReplaced = false;
    private bool $loggerHandlerInstalled = false;
    private const TEST_GLOBALS = ['TTS', 'SYNTH_ACTIVE_TTS_PLAN', 'PATCH_OVERRIDE_TTS_LANGUAGE',
        'SYNTH_PROTOCOL_REQUEST_CLAIMED', 'CARTESIA_LAST_ERROR_MESSAGE'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/lib/cartesia_generation.php';
        foreach (self::TEST_GLOBALS as $key) {
            if (array_key_exists($key, $GLOBALS)) $this->previous[$key] = $GLOBALS[$key];
            unset($GLOBALS[$key]);
        }
        CartesiaFixtureHttpsStream::$requests = [];
    }
    protected function tearDown(): void
    {
        if ($this->httpsReplaced) {
            stream_wrapper_unregister('https');
            stream_wrapper_restore('https');
        }
        foreach (self::TEST_GLOBALS as $key) {
            unset($GLOBALS[$key]);
            if (array_key_exists($key, $this->previous)) $GLOBALS[$key] = $this->previous[$key];
        }
        if ($this->loggerHandlerInstalled) restore_error_handler();
    }

    private function loadDriver(): void
    {
        // Run driver tests in their own process: registrations and tts() cannot be unloaded.
        $this->loggerHandlerInstalled = !class_exists('Logger', false);
        require_once dirname(__DIR__, 2).'/lib/synth_tts_plan.php';
        require_once dirname(__DIR__, 2).'/tts/tts-cartesia.php';
    }

    public static function modernModels(): array
    {
        return [['sonic-3'], ['sonic-3.5'], ['sonic-3.6'], ['sonic-latest'], ['sonic-3-2025-10-27']];
    }
    #[DataProvider('modernModels')]
    public function testModernWireRequestUsesDocumentedControls(string $model): void
    {
        $text = 'Hello, Stu! <this stays as spoken text>';
        $request = synth_cartesia_generation_request($text, 'fixture-voice', 'angry|high',
            ['model_id'=>$model, 'speed'=>'slow', 'use_emotions'=>true], 'en');
        $this->assertSame('2026-08-14', $request['api_version']);
        $this->assertSame($text, $request['body']['transcript']);
        $this->assertSame('fixture-voice', $request['body']['voice']);
        $this->assertSame(['speed'=>0.85, 'emotion'=>'angry'], $request['body']['generation_config']);
        $this->assertArrayNotHasKey('speed', $request['body']);
        $this->assertSame(['container'=>'wav', 'encoding'=>'pcm_s16le', 'sample_rate'=>22050],
            $request['body']['output_format']);
    }
    public static function legacyModels(): array
    {
        return [['sonic-english'], ['sonic-multilingual'], ['sonic-2'], ['sonic-30']];
    }
    #[DataProvider('legacyModels')]
    public function testLegacyModelWireBehaviorIsPreserved(string $model): void
    {
        $request = synth_cartesia_generation_request('Legacy line', 'same-voice', 'sad',
            ['model_id'=>$model, 'speed'=>'fast', 'use_emotions'=>true], 'en');
        $this->assertSame('2024-11-13', $request['api_version']);
        $this->assertSame(['mode'=>'id', 'id'=>'same-voice'], $request['body']['voice']);
        $this->assertSame('fast', $request['body']['speed']);
        $this->assertArrayNotHasKey('generation_config', $request['body']);
    }
    public static function naturalEmotionCases(): array
    {
        return [
            [[], 'angry', 'en'], [['use_emotions'=>false], 'angry', 'en'],
            [['use_emotions'=>'false'], 'angry', 'en'], [['use_emotions'=>'0'], 'sad', 'en'],
            [['use_emotions'=>true], 'angry', 'fr'], [['use_emotions'=>true], 'sad', 'de'],
            [['use_emotions'=>true], 'normal', 'en'], [['use_emotions'=>true], 'auto', 'en'],
            [['use_emotions'=>true], 'default', 'en'], [['use_emotions'=>true], 'wary', 'en'],
            [['use_emotions'=>true], '<emotion value="angry">', 'en'],
            [['use_emotions'=>true], str_repeat('angry', 30), 'en'],
            [['use_emotions'=>true], ['angry'], 'en'],
        ];
    }
    #[DataProvider('naturalEmotionCases')]
    public function testUnsupportedOrDisabledGuidanceLeavesNaturalDelivery(array $settings, mixed $mood, string $language): void
    {
        $request = synth_cartesia_generation_request('Natural delivery.', 'fixture', $mood, $settings, $language);
        $this->assertArrayNotHasKey('emotion', $request['body']['generation_config']);
        $this->assertSame('Natural delivery.', $request['body']['transcript']);
    }
    public static function speedCases(): array
    {
        return [['slowest',0.6], ['FAST',1.15], ['fastest',1.5], ['1.23',1.23],
            [0.1,0.6], [8,1.5], [NAN,1.0], [INF,1.0], [[],1.0], [null,1.0], ['nonsense',1.0]];
    }
    #[DataProvider('speedCases')]
    public function testSpeedsStayInsideProviderBounds(mixed $input, float $expected): void
    {
        $request = synth_cartesia_generation_request('Test', 'fixture', 'normal', ['speed'=>$input], 'en');
        $this->assertSame($expected, $request['body']['generation_config']['speed']);
        $this->assertNotFalse(json_encode($request['body']));
    }
    public function testMoodAliasesUseEnglishProviderVocabulary(): void
    {
        $request = synth_cartesia_generation_request('Oh, really?', 'fixture', 'Annoyed|medium',
            ['use_emotions'=>'true'], 'en-US');
        $this->assertSame('frustrated', $request['body']['generation_config']['emotion']);
        $this->assertSame('happy', synth_cartesia_generation_emotion('joking/comedic'));
    }
    private function interceptHttps(): void
    {
        $this->assertTrue(stream_wrapper_unregister('https'));
        $this->httpsReplaced = true;
        $this->assertTrue(stream_wrapper_register('https', CartesiaFixtureHttpsStream::class));
    }
    private function plan(array $settings, string $mood): array
    {
        $plan = ['version'=>1, 'speaker'=>'Fixture NPC', 'text'=>'Fixture speech.',
            'cache_text'=>'Fixture speech.', 'mood'=>$mood, 'samples'=>[],
            'inputs'=>['SYNTH_NAME'=>'Fixture NPC', 'TTSFUNCTION'=>'cartesia',
                'TTS'=>['CARTESIA'=>$settings + ['API_KEY'=>'fixture-key']]]];
        $plan['cache_seed'] = synth_tts_plan_seed($plan);
        return $plan;
    }
    #[RunInSeparateProcess]
    public function testRealDriverUsesCapturedSettingsAndRestoresWorkerState(): void
    {
        $this->loadDriver();
        $this->interceptHttps();
        $GLOBALS['TTS'] = ['CARTESIA'=>['model_id'=>'sonic-english', 'speed'=>'fastest',
            'language'=>'fr', 'use_emotions'=>false, 'API_KEY'=>'worker-fixture-key']];
        $GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE'] = 'de';
        $before = $GLOBALS['TTS'];
        $plan = $this->plan(['model_id'=>'sonic-3.6', 'speed'=>'slow', 'language'=>'en', 'use_emotions'=>true], 'sad');
        $audio = synth_with_tts_plan($plan, $plan['cache_seed'], 'Fixture NPC', dirname(__DIR__, 2),
            static fn() => generateCartesiaTTS($plan['text'], 'captured-voice', $plan['mood']));
        $this->assertSame(CartesiaFixtureHttpsStream::audio(), $audio);
        $this->assertCount(1, CartesiaFixtureHttpsStream::$requests);
        $request = CartesiaFixtureHttpsStream::$requests[0];
        $this->assertSame('https://api.cartesia.ai/tts/bytes', $request['url']);
        $this->assertSame('POST', $request['options']['http']['method']);
        $this->assertStringContainsString('Authorization: Bearer fixture-key', $request['options']['http']['header']);
        $this->assertStringContainsString('Cartesia-Version: 2026-08-14', $request['options']['http']['header']);
        $body = json_decode($request['options']['http']['content'], true, flags:JSON_THROW_ON_ERROR);
        $this->assertSame('captured-voice', $body['voice']);
        $this->assertSame('en', $body['language']);
        $this->assertSame(['speed'=>0.85, 'emotion'=>'sad'], $body['generation_config']);
        $this->assertSame($before, $GLOBALS['TTS']);
        $this->assertSame('de', $GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE']);
        $this->assertArrayNotHasKey('SYNTH_ACTIVE_TTS_PLAN', $GLOBALS);
    }
    #[RunInSeparateProcess]
    public function testActualLegacyDriverKeepsLegacyHeadersAndBody(): void
    {
        $this->loadDriver();
        $this->interceptHttps();
        $plan = $this->plan(['model_id'=>'sonic-multilingual', 'speed'=>'fast', 'language'=>'en', 'use_emotions'=>true], 'angry');
        synth_with_tts_plan($plan, $plan['cache_seed'], 'Fixture NPC', dirname(__DIR__, 2),
            static fn() => generateCartesiaTTS($plan['text'], 'legacy-voice', $plan['mood']));
        $request = CartesiaFixtureHttpsStream::$requests[0]['options']['http'];
        $this->assertStringContainsString('X-API-Key: fixture-key', $request['header']);
        $this->assertStringContainsString('Cartesia-Version: 2024-11-13', $request['header']);
        $body = json_decode($request['content'], true, flags:JSON_THROW_ON_ERROR);
        $this->assertSame(['mode'=>'id', 'id'=>'legacy-voice'], $body['voice']);
        $this->assertSame('fast', $body['speed']);
        $this->assertArrayNotHasKey('generation_config', $body);
    }
    #[RunInSeparateProcess]
    public function testMoodAndProsodySettingsSeparateSpeechCacheIdentities(): void
    {
        $this->loadDriver();
        $base = $this->plan(['model_id'=>'sonic-3', 'use_emotions'=>false], 'sad');
        $changed = $base;
        $changed['inputs']['TTS']['CARTESIA']['use_emotions'] = true;
        $this->assertNotSame($base['cache_seed'], synth_tts_plan_seed($changed));
        $changed = $base;
        $changed['mood'] = 'angry';
        $this->assertNotSame($base['cache_seed'], synth_tts_plan_seed($changed));
        $changed = $base;
        $changed['inputs']['TTS']['CARTESIA']['speed'] = 'slow';
        $this->assertNotSame($base['cache_seed'], synth_tts_plan_seed($changed));
        $this->assertStringNotContainsString('fixture-key', $base['cache_seed']);
    }
}

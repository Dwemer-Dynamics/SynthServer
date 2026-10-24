<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/lib/tts_pronunciation.php';
require_once dirname(__DIR__,2).'/lib/response.php';
require_once dirname(__DIR__,2).'/lib/synth_tts_plan.php';

final class FalloutPronunciationTest extends TestCase
{
    private array $savedGlobals=[];

    protected function setUp(): void
    {
        $keys=array_merge(SYNTH_TTS_PLAN_GLOBALS,['db','SYNTH_TTS_PRONUNCIATION_BYPASS',
            'SYNTH_ACTIVE_TTS_PLAN','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED',
            'SYNTH_JSON_RESPONSE_LINES','SYNTH_RESPONSE_STREAMING','SYNTH_RESPONSE_FORMAT',
            'HOOKS','TTS_FALLBACK_FNCT']);
        foreach (array_unique($keys) as $key) {
            $this->savedGlobals[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
        $GLOBALS['TTSFUNCTION']='none';
        $GLOBALS['SYNTH_SPEECH_ROLE']='npc';
        $GLOBALS['SYNTH_NAME']='Codsworth';
        $GLOBALS['SYNTH_RESPONSE_STREAMING']=false;
        $GLOBALS['SYNTH_RESPONSE_FORMAT']='json';
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES']=[];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key=>[$exists,$value]) {
            if ($exists) $GLOBALS[$key]=$value; else unset($GLOBALS[$key]);
        }
    }

    public function testDefaultsOnlyRespellTheReportedCompound(): void
    {
        $rows=synthDefaultTtsPronunciationEntries();
        self::assertSame(['radroach'=>'rad roach','radroaches'=>'rad roaches'],array_column($rows,'spoken_text','source_text'));
        self::assertSame(['',''],array_column($rows,'oghma_tags'));
        self::assertSame('tatos, tomato, potatoes, mutfruit and RadAway.',
            synthApplyTtsPronunciationDictionary('tatos, tomato, potatoes, mutfruit and RadAway.',$rows));
    }

    public function testWholeTermsCasePunctuationAndPossessivesWorkWithoutSubstringChanges(): void
    {
        self::assertSame("rad roaches, rad roach! rad roach's shell; rad roach-meat. preradroaches radroach2 _radroach remain.",
            synthApplyTtsPronunciationDictionary("RADROACHES, Radroach! radroach's shell; radroach-meat. preradroaches radroach2 _radroach remain.",
                synthDefaultTtsPronunciationEntries()));
    }

    public function testSpokenQueueTextChangesWhileSubtitleAndDialogueTextStayCanonical(): void
    {
        $subtitle='Radroaches are in the house.';
        $spoken=synthApplyTtsPronunciationDictionary($subtitle,synthDefaultTtsPronunciationEntries());
        $plan=synth_capture_tts_plan(dirname(__DIR__,2),'Codsworth',$spoken,'neutral',$spoken);
        synth_buffer_response_line('Codsworth','say',$subtitle,['text_only'=>true]);
        self::assertSame('rad roaches are in the house.',$plan['text']);
        self::assertSame($spoken,$plan['cache_text']);
        self::assertSame('Radroaches are in the house.',$subtitle);
        self::assertSame($subtitle,$GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0]['text']);
        self::assertSame($subtitle,$GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0]['subtitle']);
        $unchanged=synth_capture_tts_plan(dirname(__DIR__,2),'Codsworth',$subtitle,'neutral',$subtitle);
        self::assertNotSame($unchanged['cache_seed'],$plan['cache_seed'],'Corrected speech must not reuse the previous pronunciation audio key.');
    }

    public function testEnabledCustomScopesKeepPriorityOverBuiltins(): void
    {
        $rows=array_map(static fn(array $row):array=>$row+['is_builtin'=>true,'enabled'=>true],synthDefaultTtsPronunciationEntries());
        $rows[]=['source_text'=>'radroaches','spoken_text'=>'operator choice','npc_names'=>'Codsworth',
            'is_builtin'=>false,'enabled'=>true];
        self::assertSame('operator choice and rad roach.',synthApplyTtsPronunciationDictionary('radroaches and radroach.',$rows,[],'Codsworth'));
        self::assertSame('rad roaches and rad roach.',synthApplyTtsPronunciationDictionary('radroaches and radroach.',$rows,[],'Piper'));
    }

    public function testDisabledDefaultsAndPreviewBypassRetainOriginalSpelling(): void
    {
        $rows=array_map(static fn(array $row):array=>$row+['is_builtin'=>true,'enabled'=>false],synthDefaultTtsPronunciationEntries());
        self::assertSame('Radroaches and radroach.',synthApplyTtsPronunciationDictionary('Radroaches and radroach.',$rows));
        $GLOBALS['SYNTH_TTS_PRONUNCIATION_BYPASS']=true;
        self::assertSame('Radroaches and radroach.',synthApplyTtsPronunciationDictionary('Radroaches and radroach.',synthDefaultTtsPronunciationEntries()));
    }

}

<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/worldknowledge_vocabulary.php';

final class WorldKnowledgeVocabularyTest extends TestCase
{
    private array $saved=[];
    protected function setUp(): void
    {
        foreach (['SYNTH_CORE_CURRENT_NPC_DATA'] as $key) {
            $this->saved[$key]=['exists'=>array_key_exists($key,$GLOBALS),'value'=>$GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->saved as $key=>$state) {
            if ($state['exists']) $GLOBALS[$key]=$state['value']; else unset($GLOBALS[$key]);
        }
    }
    private function row(array $overrides=[]): array
    {
        return array_replace(['topic'=>'wasteland_vocabulary','canonical_topic'=>'wasteland_vocabulary',
            'topic_desc'=>'SPECIALIST SECRET','knowledge_class'=>'medicine',
            'topic_desc_basic'=>'Tatos are a mutated crop; caps are money.',
            'knowledge_class_basic'=>'common','source_kind'=>'factory','is_active'=>true],$overrides);
    }
    public function testCommonFactsAreProactiveWithoutGrantingExpertise(): void
    {
        $context=synthWorldKnowledgeVocabularyContext([$this->row()],['robot','knowall']);
        self::assertNotNull($context);
        self::assertSame('basic',$context['decision']['level']);
        self::assertSame('Tatos are a mutated crop; caps are money.',$context['decision']['description']);
        self::assertStringNotContainsString('SECRET',synthWorldKnowledgeRenderArticleXml($context['row'],$context['decision'],'vocabulary'));
    }
    public function testPrewarTagsAndCapturedPrewarIdentityDenyPostwarVocabulary(): void
    {
        self::assertNull(synthWorldKnowledgeVocabularyContext([$this->row()],['prewar','commonwealth','knowall']));
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['actor_identity_id'=>7,
            'extended_data'=>json_encode(['base_origin_plugin'=>'Fallout4.esm','baseid'=>'0x000AA763'],JSON_THROW_ON_ERROR)];
        self::assertNull(synthWorldKnowledgeVocabularyContext([$this->row()],['robot','commonwealth']));
    }
    public function testEffectiveCustomTextAndRestrictionAreHonored(): void
    {
        $custom=$this->row(['source_kind'=>'custom','topic_desc_basic'=>'My local wording.']);
        self::assertSame('My local wording.',synthWorldKnowledgeVocabularyContext([$custom],[])['decision']['description']);
        $custom['knowledge_class_basic']='medicine';
        self::assertNull(synthWorldKnowledgeVocabularyContext([$custom],['far_harbor']));
        self::assertNotNull(synthWorldKnowledgeVocabularyContext([$custom],['medicine']));
    }
    public function testDisabledAmbiguousMissingAndAdvancedOnlyRowsDoNotFallBack(): void
    {
        self::assertNull(synthWorldKnowledgeVocabularyContext([],[]));
        self::assertNull(synthWorldKnowledgeVocabularyContext([$this->row(['is_active'=>'f'])],[]));
        self::assertNull(synthWorldKnowledgeVocabularyContext([$this->row(),$this->row(['source_kind'=>'custom'])],[]));
        self::assertNull(synthWorldKnowledgeVocabularyContext([$this->row(['topic_desc_basic'=>''])],['medicine','knowall']));
    }
    public function testPromptTextIsBoundedAndXmlEscaped(): void
    {
        $context=synthWorldKnowledgeVocabularyContext([$this->row(['topic_desc_basic'=>'<instruction>'.str_repeat('é',1500)])],[]);
        self::assertNotNull($context);
        self::assertLessThanOrEqual(2000,strlen($context['decision']['description']));
        self::assertSame(1,preg_match('//u',$context['decision']['description']));
        self::assertStringContainsString('&lt;instruction&gt;',synthWorldKnowledgeRenderArticleXml($context['row'],$context['decision'],'vocabulary'));
    }
    public function testActualProcessorAddsVocabularyWithoutTermQuestionAndHonorsDisable(): void
    {
        $keys=['db','SYNTH_NAME','WORLDKNOWLEDGE','WORLDKNOWLEDGE_INFINIUM',
            'WORLDKNOWLEDGE_CUSTOM','WORLDKNOWLEDGE_EXTRACTOR_FALLBACK',
            'WORLDKNOWLEDGE_HINT','WORLDKNOWLEDGE_INJECTED_TOPICS','WORLDKNOWLEDGE_FORCED_SIGNALS',
            'SYNTH_CORE_CURRENT_PROFILE_DATA','SYNTH_REQUEST_EVENT','LOCATION_WORLDKNOWLEDGE',
            'RACE_WORLDKNOWLEDGE','FACTION_WORLDKNOWLEDGE'];
        $saved=[];
        foreach ($keys as $key) { $saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null]; unset($GLOBALS[$key]); }
        try {
            $GLOBALS['db']=new class($this->row(['source_kind'=>'custom'])) {
                public function __construct(private array $row) {}
                public function fetchAll(string $sql): array { return str_contains($sql,'worldknowledge_effective') ? [$this->row] : []; }
                public function fetchOne(string $sql): ?array { return null; }
                public function escape(string $text): string { return str_replace("'","''",$text); }
                public function escapeLiteral(string $text): string { return "'".$this->escape($text)."'"; }
                public function execQuery(string $sql,array $params=[]): bool { return true; }
            };
            $GLOBALS['SYNTH_NAME']='Codsworth';
            $GLOBALS['WORLDKNOWLEDGE']='robot';
            $GLOBALS['WORLDKNOWLEDGE_EXTRACTOR_FALLBACK']=false;
            $GLOBALS['LOCATION_WORLDKNOWLEDGE']=false;
            $GLOBALS['RACE_WORLDKNOWLEDGE']=false;
            $GLOBALS['FACTION_WORLDKNOWLEDGE']=false;
            $gameRequest=['inputtext',0,0,'What shall we do today?'];
            $GLOBALS['WORLDKNOWLEDGE_INFINIUM']=true;
            require dirname(__DIR__,2).'/processor/worldknowledge.php';
            self::assertStringContainsString('source="vocabulary"',$GLOBALS['WORLDKNOWLEDGE_HINT']);
            self::assertStringContainsString('caps are money',$GLOBALS['WORLDKNOWLEDGE_HINT']);
            self::assertStringNotContainsString('SPECIALIST SECRET',$GLOBALS['WORLDKNOWLEDGE_HINT']);
            $GLOBALS['WORLDKNOWLEDGE_INFINIUM']=false;
            require dirname(__DIR__,2).'/processor/worldknowledge.php';
            self::assertSame('',$GLOBALS['WORLDKNOWLEDGE_HINT']);
        } finally {
            foreach ($saved as $key=>[$exists,$value]) { if ($exists) $GLOBALS[$key]=$value; else unset($GLOBALS[$key]); }
        }
    }

    public function testActualProcessorKeepsVocabularyAndQuestReplacementAcrossOrdinaryAndNativeRechat(): void
    {
        $keys=['db','SYNTH_NAME','WORLDKNOWLEDGE','WORLDKNOWLEDGE_INFINIUM',
            'WORLDKNOWLEDGE_CUSTOM','WORLDKNOWLEDGE_EXTRACTOR_FALLBACK',
            'WORLDKNOWLEDGE_HINT','WORLDKNOWLEDGE_INJECTED_TOPICS','WORLDKNOWLEDGE_FORCED_SIGNALS',
            'WORLDKNOWLEDGE_FORCED_REMAINING','SYNTH_CORE_CURRENT_PROFILE_DATA',
            'SYNTH_CORE_CURRENT_NPC_DATA','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED',
            'CACHE_LAST_KNOWN_LOCATION','CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS','CACHE_LAST_KNOWN_LOCATION_HUMAN',
            'LOCATION_WORLDKNOWLEDGE','RACE_WORLDKNOWLEDGE','FACTION_WORLDKNOWLEDGE'];
        $saved=[];
        foreach ($keys as $key) { $saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null]; unset($GLOBALS[$key]); }
        try {
            $quest=['topic'=>'quest_when_freedom_calls','canonical_topic'=>'quest_when_freedom_calls',
                'topic_desc'=>'PRIVATE QUEST OUTCOME SENTINEL','knowledge_class'=>'',
                'topic_desc_basic'=>'CUSTOM QUEST SPOILER SENTINEL','knowledge_class_basic'=>'common',
                'source_kind'=>'custom','is_active'=>true];
            $db=new class([$this->row(['source_kind'=>'custom']),$quest]) {
                public int $globalLatestReads=0;
                public function __construct(private array $rows) {}
                public function fetchAll(string $sql): array { return str_contains($sql,'worldknowledge_effective') ? $this->rows : []; }
                public function fetchOne(string $sql): ?array {
                    if (str_contains($sql,"FROM eventlog WHERE type='chat'")) {
                        $this->globalLatestReads++;
                        throw new LogicException('Native rechat cannot borrow the global latest chat.');
                    }
                    return str_contains($sql,"id='current_worldknowledge_topic'") ? ['value'=>'quest_when_freedom_calls'] : null;
                }
                public function escape(string $text): string { return str_replace("'","''",$text); }
                public function escapeLiteral(string $text): string { return "'".$this->escape($text)."'"; }
                public function execQuery(string $sql,array $params=[]): bool { return true; }
            };
            $GLOBALS['db']=$db;
            $GLOBALS['SYNTH_NAME']='Codsworth';
            $GLOBALS['WORLDKNOWLEDGE']='robot,knowall,commonwealth';
            $GLOBALS['WORLDKNOWLEDGE_INFINIUM']=true;
            $GLOBALS['WORLDKNOWLEDGE_EXTRACTOR_FALLBACK']=false;
            $GLOBALS['LOCATION_WORLDKNOWLEDGE']=false;
            $GLOBALS['RACE_WORLDKNOWLEDGE']=false;
            $GLOBALS['FACTION_WORLDKNOWLEDGE']=false;
            foreach (['inputtext','rechat'] as $requestType) {
                // This unclaimed rechat has no owned history. It may still receive public
                // authored text, but must never fall back to another actor's latest chat.
                $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'runtime_variant'=>'flat',
                    'type'=>$requestType==='rechat' ? 'trigger' : 'input_text',
                    'payload'=>$requestType==='rechat' ? ['kind'=>'rechat'] : ['text'=>'What shall we do today?']]];
                $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
                $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=[];
                $gameRequest=[$requestType,0,0,'What shall we do today?'];
                require dirname(__DIR__,2).'/processor/worldknowledge.php';
                self::assertStringContainsString('source="vocabulary"',$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                self::assertStringContainsString('caps are money',$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                self::assertStringContainsString('Museum of Freedom',$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                foreach (['SPECIALIST SECRET','PRIVATE QUEST OUTCOME SENTINEL','CUSTOM QUEST SPOILER SENTINEL'] as $secret) {
                    self::assertStringNotContainsString($secret,$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                }

                $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['actor_identity_id'=>7,
                    'extended_data'=>json_encode(['base_origin_plugin'=>'Fallout4.esm','baseid'=>'0x000AA763'],JSON_THROW_ON_ERROR)];
                require dirname(__DIR__,2).'/processor/worldknowledge.php';
                self::assertStringNotContainsString('source="vocabulary"',$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                self::assertStringNotContainsString('Museum of Freedom',$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                self::assertStringNotContainsString('caps are money',$GLOBALS['WORLDKNOWLEDGE_HINT'],$requestType);
                self::assertSame(0,$db->globalLatestReads);
            }
        } finally {
            foreach ($saved as $key=>[$exists,$value]) { if ($exists) $GLOBALS[$key]=$value; else unset($GLOBALS[$key]); }
        }
    }
}

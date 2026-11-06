<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/worldknowledge_catalog.php';
require_once dirname(__DIR__,2).'/lib/worldknowledge_vocabulary.php';

final class FalloutEverydayLoreTest extends TestCase
{
    private array $rows=[];
    private array $saved=[];
    protected function setUp(): void
    {
        $this->saved=[array_key_exists('SYNTH_CORE_CURRENT_NPC_DATA',$GLOBALS),$GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] ?? null];
        unset($GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']);
        foreach (synthWorldKnowledgeLoadFactoryCatalog(dirname(__DIR__,2))['rows'] as $row) $this->rows[$row['topic']]=$row;
    }
    protected function tearDown(): void
    {
        if ($this->saved[0]) $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=$this->saved[1]; else unset($GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']);
    }
    public function testActualCatalogProvidesBaselineForDlcAndRobotsWithoutExpertise(): void
    {
        self::assertCount(125,$this->rows);
        foreach ([['robot'],['far_harbor'],['nuka_world'],[]] as $tags) {
            $context=synthWorldKnowledgeVocabularyContext(array_values($this->rows),$tags);
            self::assertNotNull($context);
            self::assertSame('basic',$context['decision']['level']);
            self::assertStringContainsString('not simply ordinary tomatoes',$context['decision']['description']);
            self::assertStringContainsString('bottle caps used as money',$context['decision']['description']);
        }
        self::assertNull(synthWorldKnowledgeVocabularyContext(array_values($this->rows),['prewar','robot','knowall']));
    }
    public function testPostwarBasicsAndPrewarRestrictionsUseActualRows(): void
    {
        foreach (['tato','mutfruit','razorgrain','tarberry','bottle_caps','jet','smoothskin','addictol'] as $topic) {
            self::assertSame('basic',synthWorldKnowledgeAccessDecision($this->rows[$topic],['robot'])['level'],$topic);
            self::assertSame('denied',synthWorldKnowledgeAccessDecision($this->rows[$topic],['prewar'])['level'],$topic);
        }
        foreach (['prewar_money','prewar_american_culture','med_x','rad_x'] as $topic) {
            self::assertSame('basic',synthWorldKnowledgeAccessDecision($this->rows[$topic],['prewar'])['level'],$topic);
        }
        self::assertStringNotContainsString('Jet',$this->rows['addictol']['topic_desc_basic']);
        self::assertSame('advanced',synthWorldKnowledgeAccessDecision($this->rows['tato'],['farmer'])['level']);
    }
    public function testDefinitionQuestionsRetrieveShortCanonTermsWithoutTomatoRewriting(): void
    {
        $retriever=new SynthWorldKnowledgeRetriever(array_values($this->rows));
        foreach (['What is a tato?'=>'tato','What does Jet do?'=>'jet','Tell me about caps.'=>'bottle_caps',
            'Are tatos tomatoes?'=>'tato','Could we grow razorgrain?'=>'razorgrain'] as $text=>$topic) {
            self::assertContains($topic,$retriever->extract($text)['topics'],$text);
        }
        self::assertNotContains('tato',$retriever->extract('I am cooking tomatoes before work.')['topics']);
        self::assertNotContains('jet',$retriever->extract('I saw a jet overhead.')['topics']);
        self::assertSame([], $retriever->extract('Do not tell me about Jet.')['topics']);
    }
    public function testRadiationMedicinesRemainDistinctInAccessibleText(): void
    {
        $x=synthWorldKnowledgeAccessDecision($this->rows['rad_x'],['robot']);
        $away=synthWorldKnowledgeAccessDecision($this->rows['radaway'],['commonwealth']);
        self::assertNotSame('denied',$x['level']);
        self::assertNotSame('denied',$away['level']);
        self::assertStringContainsString('resistance',strtolower($x['description']));
        self::assertStringContainsString('radiation',strtolower($away['description']));
    }
}

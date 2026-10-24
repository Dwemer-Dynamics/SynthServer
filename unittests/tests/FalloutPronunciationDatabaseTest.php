<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/tts_pronunciation.php';

final class FalloutPronunciationDatabaseTest extends DatabaseTestCase
{
    public function testSeedingIsIdempotentAndPreservesCustomRulesAndDisabledDefaults(): void
    {
        $db=$GLOBALS['db'] ??= new sql();
        self::assertTrue(synthEnsureTtsPronunciationDictionary());
        // This class runs only in DatabaseTestCase's disposable database.
        $db->execQuery("DELETE FROM synth.core_tts_pronunciation WHERE LOWER(source_text) IN ('radroach','radroaches')");
        $db->execQuery("INSERT INTO synth.core_tts_pronunciation
            (source_text,spoken_text,npc_names,races,oghma_tags,is_builtin,enabled)
            VALUES ('radroach','old operator choice','','','',FALSE,FALSE),
                   ('radroaches','operator choice','Piper','','',FALSE,TRUE)");
        $customBefore=$db->fetchAll('SELECT id,source_text,spoken_text,npc_names,enabled FROM synth.core_tts_pronunciation WHERE is_builtin=FALSE ORDER BY id');
        self::assertTrue(synthEnsureTtsPronunciationDictionary());
        $builtins=$db->fetchAll("SELECT source_text,enabled FROM synth.core_tts_pronunciation WHERE is_builtin=TRUE AND LOWER(source_text) IN ('radroach','radroaches') ORDER BY source_text");
        self::assertCount(2,$builtins);
        self::assertFalse(synthTtsPronunciationBoolean($builtins[0]['enabled']),'A prior global custom opt-out must seed the new default disabled.');
        self::assertTrue(synthTtsPronunciationBoolean($builtins[1]['enabled']));
        $manager=new SynthTtsPronunciationDictionary();
        self::assertSame('operator choice and radroach.',synthApplyTtsPronunciationDictionary('radroaches and radroach.',$manager->getRows(),[],'Piper'));
        self::assertSame('rad roaches and radroach.',synthApplyTtsPronunciationDictionary('radroaches and radroach.',$manager->getRows(),[],'Codsworth'));
        $db->execQuery("UPDATE synth.core_tts_pronunciation SET enabled=FALSE WHERE is_builtin=TRUE AND source_text='radroaches'");
        self::assertTrue(synthEnsureTtsPronunciationDictionary());
        self::assertTrue(synthEnsureTtsPronunciationDictionary());
        self::assertFalse(synthTtsPronunciationBoolean($db->fetchOne("SELECT enabled FROM synth.core_tts_pronunciation WHERE is_builtin=TRUE AND source_text='radroaches'")['enabled']));
        self::assertSame($customBefore,$db->fetchAll('SELECT id,source_text,spoken_text,npc_names,enabled FROM synth.core_tts_pronunciation WHERE is_builtin=FALSE ORDER BY id'));
        self::assertSame(2,intval($db->fetchOne("SELECT COUNT(*) AS count FROM synth.core_tts_pronunciation WHERE is_builtin=TRUE AND LOWER(source_text) IN ('radroach','radroaches')")['count']));
    }
}

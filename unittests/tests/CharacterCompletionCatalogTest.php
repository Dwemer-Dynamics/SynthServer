<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/fallout_content_pack.php';

final class CharacterCompletionCatalogTest extends TestCase
{
    public function testUploadCsvMatchesAllAuthoritativeTextWithoutHiddenOverrides(): void
    {
        $root=dirname(__DIR__,2);$pack=synthLoadFalloutContentPack($root);
        $file=fopen($root.'/data/fallout4_named_bios.csv','rb');
        self::assertIsResource($file);
        try {
            $header=fgetcsv($file,0,',','"','');
            self::assertSame(['npc_name','core','worldknowledge_tags','npc_static_bio','personality','appearance','relationships',
                'occupation','skills','speechstyle','goals','voiceid','gender','race','refid'],$header);
            $actual=[];
            while (($row=fgetcsv($file,0,',','"',''))!==false) {
                self::assertCount(15,$row);
                self::assertArrayNotHasKey($row[0],$actual);
                $actual[$row[0]]=array_combine($header,$row);
            }
            self::assertCount(count($pack['npcs']),$actual);
            foreach ($pack['npcs'] as $npc) {
                self::assertArrayHasKey($npc['npc_name'],$actual);
                foreach ($npc['fields'] as $key=>$text) self::assertSame($text,$actual[$npc['npc_name']][$key]);
                foreach (['relationships','voiceid','gender','race','refid'] as $optional)
                    self::assertSame('',$actual[$npc['npc_name']][$optional]);
            }
        } finally { fclose($file); }
    }
    public function testReviewDraftsCannotAccidentallyBecomeLiveActorsAndTagsAreScoped(): void
    {
        $root=dirname(__DIR__,2);$pack=synthLoadFalloutContentPack($root);
        $review=json_decode(file_get_contents($root.'/data/fallout4_character_completion_review.json'),true,64,JSON_THROW_ON_ERROR);
        $vocabulary=json_decode(file_get_contents($root.'/data/fallout_worldknowledge_vocabulary.json'),true,64,JSON_THROW_ON_ERROR)['knowledge_classes'];
        // This existing pre-war location tag is contextual, rather than a grant
        // to a post-war knowledge class; it remains guarded by pre-war access.
        $vocabulary[]='sanctuary_prewar';
        $names=array_column($pack['npcs'],'npc_name');
        $held=array_column($review['withheld_live_bindings'],'npc_name');
        self::assertEqualsCanonicalizing(['fo4_julianna_riggs','fo4_john_caleb_bradberton','fo4_redeye','fo4_jezebel'],$held);
        foreach ($held as $name) self::assertNotContains($name,$names);
        foreach ($pack['npcs'] as $npc) {
            foreach (explode(',',$npc['fields']['worldknowledge_tags']) as $tag) self::assertContains($tag,$vocabulary);
            self::assertStringNotContainsString('knowall',$npc['fields']['worldknowledge_tags']);
        }
        $rows=array_column($pack['npcs'],null,'npc_name');
        self::assertSame(['00027686','001647C6'],$rows['fo4_curie']['base_aliases']);
        self::assertSame(['00168B89'],$rows['fo4_virgil']['base_aliases']);
        foreach (['fo4_ivey','fo4_security_chief_andersen','fo4_julian_vault88'] as $name) {
            self::assertSame('reference',$rows[$name]['mapping_kind']);
            self::assertArrayNotHasKey('baseid',$rows[$name]);
        }
        self::assertFalse($rows['fo4_tony']['is_nonverbal_creature']);
        self::assertStringContainsString('Nonverbal',$rows['fo4_tony']['fields']['speechstyle']);
    }
}

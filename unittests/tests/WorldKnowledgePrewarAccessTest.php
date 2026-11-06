<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/worldknowledge_runtime.php';
require_once __DIR__ . '/../../lib/worldknowledge_catalog.php';
require_once __DIR__ . '/../../lib/fallout_content_pack.php';

final class WorldKnowledgePrewarAccessTest extends TestCase
{
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['SYNTH_CORE_CURRENT_NPC_DATA', 'WORLDKNOWLEDGE', 'SYNTH_NAME', 'db',
            'SYNTH_REQUEST_EVENT', 'SYNTH_PROTOCOL_REQUEST_CLAIMED', 'CACHE_LAST_KNOWN_LOCATION',
            'CACHE_LAST_KNOWN_LOCATION_CONTEXT_PARTS', 'CACHE_LAST_KNOWN_LOCATION_HUMAN'] as $key) {
            $this->savedGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => [$exists, $value]) {
            if ($exists) $GLOBALS[$key] = $value;
            else unset($GLOBALS[$key]);
        }
    }

    private function nativeProfile(string $baseId, string $plugin = 'Fallout4.esm'): array
    {
        return [
            'actor_identity_id' => 41,
            'npc_name' => 'Codsworth',
            'npc_codename' => 'codsworth',
            'extended_data' => json_encode(['baseid' => $baseId, 'base_origin_plugin' => $plugin]),
        ];
    }

    public function testCapturedPrewarActorsDiscardFutureProfileAndContextAccess(): void
    {
        $db = new class {
            public int $queries = 0;
            public function fetchOne(string $sql): array { ++$this->queries; throw new RuntimeException('Future template lookup'); }
            public function fetchAll(string $sql): array { ++$this->queries; throw new RuntimeException('Future location lookup'); }
            public function escape(string $value): string { return $value; }
        };
        $GLOBALS['db'] = $db;
        $GLOBALS['WORLDKNOWLEDGE'] = 'commonwealth,knowall,sanctuary_postwar,institute';
        $GLOBALS['SYNTH_NAME'] = 'Postwar Codsworth';
        foreach (['0x000AA763', '000ABF9E'] as $baseId) {
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $this->nativeProfile($baseId);
            $this->assertTrue(synthWorldKnowledgeCurrentNpcIsPrewar());
            $this->assertSame(['prewar'], synthWorldKnowledgeKnowledgeTags());
        }
        $this->assertSame(0, $db->queries, 'Future location/factory lookups are skipped for captured 2077 actors.');
    }

    public function testPrewarIdentityRequiresBoundExactActorBaseAndOrigin(): void
    {
        $profile = $this->nativeProfile('0x000AA763');
        $cases = [
            [],
            ['npc_name' => 'Prewar Codsworth', 'era' => 'prewar'],
            array_replace($profile, ['actor_identity_id' => null]),
            array_replace($profile, ['actor_identity_id' => -1]),
            array_replace($profile, ['actor_identity_id' => '41garbage']),
            array_replace($profile, ['extended_data' => '{broken json']),
            $this->nativeProfile('0x000AA763', 'SomeMod.esp'),
            $this->nativeProfile('0x000179FF'),
            $this->nativeProfile('0x00031FB3'),
            $this->nativeProfile('0x000AA763garbage'),
        ];
        foreach ($cases as $candidate) {
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $candidate;
            $this->assertFalse(synthWorldKnowledgeCurrentNpcIsPrewar(), json_encode($candidate));
        }
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $this->nativeProfile('0x000aa763', 'fallout4.esm');
        $this->assertTrue(synthWorldKnowledgeCurrentNpcIsPrewar());
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']['extended_data'] = ['baseid' => '000ABF9E', 'base_origin_plugin' => 'Fallout4.esm'];
        $this->assertTrue(synthWorldKnowledgeCurrentNpcIsPrewar());
    }

    public function testExplicitPrewarRejectsFutureBlankCommonAndKnowallArticles(): void
    {
        foreach (['', 'common', 'commonwealth', 'sanctuary_postwar', 'prewar_culture'] as $classes) {
            $row = ['topic' => 'future_lore', 'topic_desc' => 'Protected future details.', 'knowledge_class' => $classes,
                'topic_desc_basic' => 'Protected future summary.', 'knowledge_class_basic' => $classes];
            $decision = synthWorldKnowledgeAccessDecision($row, ['prewar', 'knowall', 'commonwealth', 'sanctuary_postwar']);
            $this->assertSame('denied', $decision['level'], $classes);
            $this->assertSame('era_not_authorized', $decision['reason']);
            $this->assertSame('', $decision['description']);
            $xml = synthWorldKnowledgeRenderArticleXml($row, $decision, 'test');
            $this->assertStringNotContainsString('Protected future', $xml);
        }
    }

    public function testCapturedPrewarActorCannotBeOverriddenByCallerTags(): void
    {
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $this->nativeProfile('0x000AA763');
        $row = ['topic' => 'institute', 'topic_desc' => 'Future secret.', 'knowledge_class' => 'institute',
            'topic_desc_basic' => 'Postwar rumor.', 'knowledge_class_basic' => 'commonwealth'];
        $this->assertSame('denied', synthWorldKnowledgeAccessDecision($row, ['knowall', 'institute', 'commonwealth'])['level']);
    }

    public function testPrewarCanReceiveExplicitlySafeTierButNotFutureAdvancedTier(): void
    {
        $row = ['topic' => 'sanctuary_hills', 'topic_desc' => 'Postwar rebuilding.', 'knowledge_class' => 'sanctuary_postwar',
            'topic_desc_basic' => 'A neighborhood near Concord.', 'knowledge_class_basic' => 'prewar,commonwealth'];
        $decision = synthWorldKnowledgeAccessDecision($row, ['prewar', 'commonwealth', 'sanctuary_postwar']);
        $this->assertSame('basic', $decision['level']);
        $this->assertSame('A neighborhood near Concord.', $decision['description']);
        $row['knowledge_class'] = 'prewar';
        $row['topic_desc'] = 'Explicitly era-safe advanced knowledge.';
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['prewar'])['level']);
        $row['knowledge_class'] = 'prewar,!prewar';
        $row['knowledge_class_basic'] = 'prewar,!prewar';
        $this->assertSame('negative_class', synthWorldKnowledgeAccessDecision($row, ['prewar'])['reason']);
    }

    public function testUnknownAndPostwarPreserveExistingAccessSemantics(): void
    {
        $row = ['topic' => 'lore', 'topic_desc' => 'Advanced.', 'knowledge_class' => '',
            'topic_desc_basic' => 'Basic.', 'knowledge_class_basic' => 'common'];
        foreach ([[], $this->nativeProfile('0x000179FF'), $this->nativeProfile('0x00031FB3')] as $profile) {
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $profile;
            $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, [])['level']);
            $row['knowledge_class'] = 'institute';
            $this->assertSame('basic', synthWorldKnowledgeAccessDecision($row, [])['level']);
            $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
            $row['knowledge_class'] = '';
        }
    }

    public function testFactoryCatalogOnlyExposesExplicitPrewarSafeArticles(): void
    {
        $catalog = synthWorldKnowledgeLoadFactoryCatalog(dirname(__DIR__, 2));
        $decisions = [];
        foreach ($catalog['rows'] as $row) {
            $decision = synthWorldKnowledgeAccessDecision($row, ['prewar', 'knowall', 'commonwealth']);
            $decisions[$row['topic']] = $decision;
            if ($decision['level'] !== 'denied') {
                $classes = $decision['level'] === 'advanced' ? $row['knowledge_class'] : $row['knowledge_class_basic'];
                $this->assertContains('prewar', synthWorldKnowledgeParseAccessRule($classes)['allowed']);
            }
        }
        foreach (['sanctuary_hills', 'vault_111'] as $topic) $this->assertSame('basic', $decisions[$topic]['level']);
        foreach (['commonwealth', 'diamond_city', 'the_institute', 'the_railroad', 'brotherhood_of_steel', 'the_minutemen'] as $topic) {
            $this->assertSame('denied', $decisions[$topic]['level'], $topic);
        }
        $this->assertStringNotContainsString('cryogenic', $decisions['vault_111']['description']);
        $this->assertStringNotContainsString('post-war', $decisions['sanctuary_hills']['description']);
    }

    public function testLiveVault118BackgroundDoesNotApply2077ChronologyGate(): void
    {
        // Authored Vault 118 tags are the only evidence in this test. Optional
        // factory/location readers receive an explicit empty fixture, never a
        // previous test's database handle or cached native/legacy world context.
        $GLOBALS['db'] = new class {
            public function fetchOne(string $sql): ?array {
                if (!str_contains($sql, 'synth.combined_bio_templates')) {
                    throw new LogicException('Unexpected profile fixture query');
                }
                return null;
            }
            public function fetchAll(string $sql): array {
                if (!str_contains($sql, 'FROM eventlog') || !str_contains($sql, "type='world_context'")) {
                    throw new LogicException('Unexpected world fixture query');
                }
                return [];
            }
            public function escape(string $value): string { return str_replace("'", "''", $value); }
        };
        $root = dirname(__DIR__, 2);
        $pack = synthLoadFalloutContentPack($root);
        $actors = array_column($pack['npcs'], null, 'npc_name');
        $review = json_decode(file_get_contents($root . '/data/fallout4_character_completion_review.json'), true, 64, JSON_THROW_ON_ERROR);
        $reviewedActors = array_column($review['rows'], null, 'npc_name');
        $articles = array_column(synthWorldKnowledgeLoadFactoryCatalog($root)['rows'], null, 'topic');
        foreach (['fo4_pearl', 'fo4_maxwell', 'fo4_bert_riggs', 'fo4_gilda_broscoe',
            'fo4_keith_mckinney', 'fo4_santiago_avida'] as $name) {
            $actor = $actors[$name];
            $profile = $this->nativeProfile($actor['baseid'], $actor['plugin']);
            $profile['npc_name'] = $actor['display_name'];
            $profile['npc_codename'] = $name;
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $profile;
            $GLOBALS['WORLDKNOWLEDGE'] = $actor['fields']['worldknowledge_tags'];
            $this->assertFalse(synthWorldKnowledgeCurrentNpcIsPrewar(), $name);
            $tags = synthWorldKnowledgeKnowledgeTags();
            $this->assertNotContains('prewar', $tags, $name);
            $this->assertContains('robobrain_observed', $tags, $name);
            $this->assertSame($actor['fields'], $reviewedActors[$name]['fields'], $name . ' review must retain canonical authored fields.');
            // Current factory common vocabulary and expertise still use their existing access tiers.
            $this->assertSame('basic', synthWorldKnowledgeAccessDecision($articles['jet'], $tags)['level'], $name);
            $this->assertSame('basic', synthWorldKnowledgeAccessDecision($articles['addictol'], $tags)['level'], $name);
            $this->assertSame($name === 'fo4_bert_riggs' ? 'advanced' : 'basic',
                synthWorldKnowledgeAccessDecision($articles['robobrain'], $tags)['level'], $name);
            if ($name !== 'fo4_bert_riggs') {
                $this->assertContains('prewar_culture', $tags, $name);
                $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($articles['prewar_american_culture'], $tags)['level'], $name);
            }
            // Removing a chronology restriction does not grant extra Island expertise.
            $this->assertSame('denied', synthWorldKnowledgeAccessDecision($articles['the_island'], $tags)['level'], $name);
        }
        foreach (['fo4_codsworth_prewar', 'fo4_vault_tec_rep_prewar'] as $name) {
            $actor = $actors[$name];
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $this->nativeProfile($actor['baseid'], $actor['plugin']);
            $tags = explode(',', $actor['fields']['worldknowledge_tags']);
            $this->assertContains('prewar', $tags, $name);
            $this->assertTrue(synthWorldKnowledgeCurrentNpcIsPrewar(), $name);
            $this->assertSame('denied', synthWorldKnowledgeAccessDecision($articles['jet'], $tags)['level'], $name);
            $this->assertSame('denied', synthWorldKnowledgeAccessDecision($articles['addictol'], $tags)['level'], $name);
        }
    }
}

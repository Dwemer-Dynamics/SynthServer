<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/worldknowledge_runtime.php';
require_once __DIR__ . '/../../lib/worldknowledge_catalog.php';
require_once __DIR__ . '/../../lib/fallout_content_pack.php';

final class FalloutDlcWorldKnowledgeTest extends TestCase
{
    private array $savedGlobals = [];
    private array $articles = [];
    private array $templates = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SYNTH_CORE_CURRENT_NPC_DATA', 'WORLDKNOWLEDGE', 'SYNTH_NAME', 'db'] as $key) {
            $this->savedGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }

        // Load the shipped files through their validators; no database or copied article fixtures.
        $root = dirname(__DIR__, 2);
        foreach (synthWorldKnowledgeLoadFactoryCatalog($root)['rows'] as $row) {
            $this->articles[$row['topic']] = $row;
        }
        foreach (synthLoadFalloutContentPack($root)['npcs'] as $row) {
            $this->templates[$row['npc_name']] = $row;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => [$exists, $value]) {
            if ($exists) {
                $GLOBALS[$key] = $value;
            } else {
                unset($GLOBALS[$key]);
            }
        }
        parent::tearDown();
    }

    private function regionalTopics(): array
    {
        return [
            'far_harbor' => [
                'the_island', 'far_harbor', 'harbormen', 'acadia', 'the_nucleus',
                'island_children_of_atom', 'island_fog', 'fog_condensers', 'island_trappers',
                'vim', 'vim_pop_factory', 'island_angler', 'island_gulper', 'island_fog_crawler',
            ],
            'nuka_world' => [
                'nuka_world', 'nuka_town_usa', 'nuka_world_traders', 'nuka_world_operators',
                'nuka_world_disciples', 'nuka_world_pack', 'galactic_zone', 'dry_rock_gulch',
                'safari_adventure', 'kiddie_kingdom', 'world_of_refreshment', 'nuka_express',
            ],
        ];
    }

    private function observedTopics(): array
    {
        return [
            'automatron_observed' => ['robot_workbench', 'rust_devils', 'robobrain'],
            'vault88_observed' => ['vault_88', 'modular_vault_construction'],
            'contraptions_observed' => ['contraptions_manufacturing', 'conveyor_belts'],
            'wasteland_workshop_observed' => ['decontamination_arch', 'wasteland_capture_cages'],
        ];
    }

    private function dlcTopics(): array
    {
        return array_merge(...array_values(array_merge($this->regionalTopics(), $this->observedTopics())));
    }

    private function template(string $name): array
    {
        self::assertArrayHasKey($name, $this->templates, 'The shipped NPC template must exist: ' . $name);
        return $this->templates[$name];
    }

    private function templateTags(string $name): array
    {
        $template = $this->template($name);
        return array_values(array_filter(array_map(
            'synthWorldKnowledgeNormalizeAccessTag',
            explode(',', $template['fields']['worldknowledge_tags'])
        )));
    }

    private function assertArticleLevel(string $topic, array $tags, string $level, string $context = ''): void
    {
        self::assertArrayHasKey($topic, $this->articles, 'The shipped expansion topic must exist: ' . $topic);
        $row = $this->articles[$topic];
        $decision = synthWorldKnowledgeAccessDecision($row, $tags);
        $message = ($context === '' ? '' : $context . ': ') . $topic;
        self::assertSame($topic, $decision['topic'], $message);
        self::assertSame($level, $decision['level'], $message);

        $xml = synthWorldKnowledgeRenderArticleXml($row, $decision, 'factory');
        if ($level === 'denied') {
            self::assertSame('', $decision['description'], $message);
            self::assertStringNotContainsString('<content>', $xml, $message);
            return;
        }

        $field = $level === 'advanced' ? 'topic_desc' : 'topic_desc_basic';
        self::assertSame(trim($row[$field]), $decision['description'], $message);
        self::assertNotEmpty($decision['matched'], $message . ' must have a positive access match');
        self::assertStringContainsString(
            htmlspecialchars(trim($row[$field]), ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $xml,
            $message
        );
        if ($level === 'basic') {
            self::assertStringNotContainsString(
                htmlspecialchars(trim($row['topic_desc']), ENT_QUOTES | ENT_XML1, 'UTF-8'),
                $xml,
                $message . ' must not expose the advanced passage'
            );
        }
    }

    public function testCommonwealthStrangerCannotReceiveDlcOrObservedWorkshopLore(): void
    {
        foreach ($this->dlcTopics() as $topic) {
            $this->assertArticleLevel($topic, ['commonwealth'], 'denied', 'Commonwealth stranger');
        }
    }

    public function testDlcWorldspaceGrantsLocalBasicsWithoutCrossRegionOrWorkshopAccess(): void
    {
        $regional = $this->regionalTopics();
        foreach (['far_harbor' => 'Far Harbor', 'nuka_world' => 'Nuka-World'] as $region => $worldspace) {
            // The runtime adds commonwealth as well as the local DLC region.
            $tags = synthWorldKnowledgeNormalizeRegionTags($worldspace);
            self::assertContains('commonwealth', $tags);
            self::assertContains($region, $tags);
            foreach ($regional[$region] as $topic) {
                $this->assertArticleLevel($topic, $tags, 'basic', $worldspace . ' novice');
            }
            foreach ($regional as $otherRegion => $topics) {
                if ($otherRegion === $region) {
                    continue;
                }
                foreach ($topics as $topic) {
                    $this->assertArticleLevel($topic, $tags, 'denied', $worldspace . ' cross-region');
                }
            }
            foreach ($this->observedTopics() as $topics) {
                foreach ($topics as $topic) {
                    $this->assertArticleLevel($topic, $tags, 'denied', $worldspace . ' workshop stranger');
                }
            }
        }
    }

    public function testObservedEquipmentFamiliarityGrantsOnlyItsOwnBasicTier(): void
    {
        $observed = $this->observedTopics();
        foreach ($observed as $class => $topics) {
            $tags = ['commonwealth', $class];
            foreach ($topics as $topic) {
                $this->assertArticleLevel($topic, $tags, 'basic', $class);
            }
            foreach ($observed as $otherClass => $otherTopics) {
                if ($otherClass === $class) {
                    continue;
                }
                foreach ($otherTopics as $topic) {
                    $this->assertArticleLevel($topic, $tags, 'denied', $class . ' unrelated equipment');
                }
            }
        }
    }

    public function testShippedRegionalAndTechnicalExpertsReceiveAppropriateTiers(): void
    {
        $cases = [
            ['fo4_old_longfellow', 'the_island', 'advanced'],
            ['fo4_old_longfellow', 'island_angler', 'advanced'],
            ['fo4_old_longfellow', 'fog_condensers', 'basic'],
            ['fo4_faraday', 'acadia', 'advanced'],
            ['fo4_faraday', 'fog_condensers', 'advanced'],
            ['fo4_faraday', 'the_nucleus', 'basic'],
            ['fo4_high_confessor_tektus', 'the_nucleus', 'advanced'],
            ['fo4_high_confessor_tektus', 'island_children_of_atom', 'advanced'],
            ['fo4_high_confessor_tektus', 'fog_condensers', 'basic'],
            ['fo4_fritsch', 'galactic_zone', 'advanced'],
            ['fo4_fritsch', 'world_of_refreshment', 'advanced'],
            ['fo4_fritsch', 'nuka_world_operators', 'basic'],
            ['fo4_porter_gage', 'nuka_world_operators', 'advanced'],
            ['fo4_ada', 'robot_workbench', 'advanced'],
            ['fo4_ada', 'rust_devils', 'advanced'],
            ['fo4_ada', 'robobrain', 'advanced'],
            ['fo4_ada', 'vault_88', 'denied'],
            ['fo4_overseer_barstow', 'vault_88', 'advanced'],
            ['fo4_overseer_barstow', 'modular_vault_construction', 'advanced'],
            ['fo4_overseer_barstow', 'robot_workbench', 'denied'],
            ['fo4_clem', 'vault_88', 'basic'],
            ['fo4_clem', 'modular_vault_construction', 'basic'],
            ['fo4_clem', 'robot_workbench', 'denied'],
        ];
        foreach ($cases as [$name, $topic, $level]) {
            $this->assertArticleLevel($topic, $this->templateTags($name), $level, $name);
        }
    }

    public function testCapturedPrewarCodsworthCannotReceivePostwarDlcEvenWithFutureExpertTags(): void
    {
        $prewar = $this->template('fo4_codsworth_prewar');
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = [
            'actor_identity_id' => 41,
            'npc_name' => $prewar['display_name'],
            'npc_codename' => $prewar['npc_name'],
            'extended_data' => json_encode([
                'baseid' => $prewar['baseid'],
                'base_origin_plugin' => $prewar['plugin'],
            ], JSON_THROW_ON_ERROR),
        ];

        $futureTags = ['commonwealth', 'knowall'];
        foreach ($this->dlcTopics() as $topic) {
            self::assertArrayHasKey($topic, $this->articles);
            foreach (['knowledge_class', 'knowledge_class_basic'] as $field) {
                $futureTags = array_merge(
                    $futureTags,
                    synthWorldKnowledgeParseAccessRule($this->articles[$topic][$field])['allowed']
                );
            }
        }
        $futureTags = array_values(array_unique($futureTags));
        $GLOBALS['WORLDKNOWLEDGE'] = implode(',', $futureTags);
        self::assertTrue(synthWorldKnowledgeCurrentNpcIsPrewar());
        self::assertSame(['prewar'], synthWorldKnowledgeKnowledgeTags());
        foreach ($this->dlcTopics() as $topic) {
            $this->assertArticleLevel($topic, $futureTags, 'denied', 'Native pre-war Codsworth');
            self::assertSame(
                'era_not_authorized',
                synthWorldKnowledgeAccessDecision($this->articles[$topic], $futureTags)['reason'],
                $topic
            );
        }

        // Era protection still allows a separately reviewed pre-war fictional character.
        $this->assertArticleLevel('mechanist_fiction', $futureTags, 'basic', 'Pre-war fictional recognition');
    }

    public function testIrmasShippedHostTemplateDoesNotGrantMedicalOrMechanicalExpertise(): void
    {
        $tags = $this->templateTags('fo4_irma');
        foreach (['medicine', 'doctor', 'technical_knowledge', 'mechanic'] as $expertClass) {
            self::assertNotContains($expertClass, $tags, 'Irma is a client host, not this expert: ' . $expertClass);
        }
        $this->assertArticleLevel('commonwealth_doctors', $tags, 'basic', 'Irma');
        $this->assertArticleLevel('power_armor_maintenance', $tags, 'basic', 'Irma');
    }
}

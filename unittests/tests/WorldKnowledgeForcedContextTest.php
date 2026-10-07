<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/settings.php';
require_once __DIR__ . '/../../lib/worldknowledge_forced_context.php';

final class WorldKnowledgeForcedContextTest extends TestCase
{
    public function testForcedContextDefaultsEnabledWhileConnectorFallbackIsOptIn(): void
    {
        foreach (['LOCATION_WORLDKNOWLEDGE', 'RACE_WORLDKNOWLEDGE', 'FACTION_WORLDKNOWLEDGE', 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK'] as $setting) {
            $this->assertContains($setting, synthGetManagedGeneralSettingIds());
            $definition = synthGetSchemaDefinition($setting);
            $this->assertSame($setting !== 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK', $definition['default'] ?? false);
            $this->assertSame('global', $definition['scope'] ?? null);
            $this->assertSame('World Knowledge', synthGetOverrideableGeneralSettingCategory($setting));
        }
    }

    public function testUnsetResultLimitUsesTheSharedThreeArticleDefault(): void
    {
        $savedLimit = ['exists' => array_key_exists('WORLDKNOWLEDGE_RESULT_LIMIT', $GLOBALS),
            'value' => $GLOBALS['WORLDKNOWLEDGE_RESULT_LIMIT'] ?? null];
        $savedAmount = ['exists' => array_key_exists('WORLDKNOWLEDGE_AMOUNT', $GLOBALS),
            'value' => $GLOBALS['WORLDKNOWLEDGE_AMOUNT'] ?? null];
        try {
            unset($GLOBALS['WORLDKNOWLEDGE_RESULT_LIMIT']);
            $GLOBALS['WORLDKNOWLEDGE_AMOUNT'] = 1;
            $this->assertSame(3, synthWorldKnowledgeEffectiveSettings()['values']['result_limit']);
        } finally {
            if ($savedLimit['exists']) $GLOBALS['WORLDKNOWLEDGE_RESULT_LIMIT'] = $savedLimit['value'];
            else unset($GLOBALS['WORLDKNOWLEDGE_RESULT_LIMIT']);
            if ($savedAmount['exists']) $GLOBALS['WORLDKNOWLEDGE_AMOUNT'] = $savedAmount['value'];
            else unset($GLOBALS['WORLDKNOWLEDGE_AMOUNT']);
        }
    }

    public function testFullConversationBudgetSkipsEveryForcedLookup(): void
    {
        $db = new class {
            public function fetchAll(string $query): array
            {
                throw new RuntimeException('forced lookup should not run');
            }
            public function fetchOne(string $query): array
            {
                throw new RuntimeException('forced lookup should not run');
            }
        };
        $GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] = 0;
        try {
            $this->assertSame(0, synthWorldKnowledgeInjectForcedLocationContext($db));
            $this->assertSame(0, synthWorldKnowledgeInjectForcedActorContext($db));
        } finally {
            unset($GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING']);
        }
    }

    public function testInteriorLocationAndWorldspaceProduceSeparateSignals(): void
    {
        $this->assertSame(
            [
                'location' => ['diamond city market', 'diamond city'],
                'worldspace' => ['commonwealth'],
            ],
            synthWorldKnowledgeBuildLocationSignalGroups(
                'Diamond City Market',
                'Commonwealth',
                [['name' => 'Diamond City Market', 'worldspace' => 'Commonwealth']]
            )
        );
    }

    public function testDlcWorldspacesRetainLocalAndParentRegionTags(): void
    {
        $this->assertSame(['far_harbor', 'commonwealth'], synthWorldKnowledgeNormalizeRegionTags('Far Harbor'));
        $this->assertSame(
            ['far_harbor', 'commonwealth'],
            synthWorldKnowledgeNormalizeRegionTags('The Island')
        );
        $this->assertSame(
            ['nuka_world', 'commonwealth'],
            synthWorldKnowledgeNormalizeRegionTags('Nuka-World')
        );
        $this->assertSame('commonwealth', synthWorldKnowledgeNormalizeRegionTag('Boston'));
    }

    public function testAdvancedAndBasicKnowledgePermissionsArePreserved(): void
    {
        $row = [
            'topic_desc' => 'Restricted advanced lore.',
            'knowledge_class' => 'scholar,!raider',
            'topic_desc_basic' => 'Common basic lore.',
            'knowledge_class_basic' => '',
        ];

        $this->assertSame(
            ['level' => 'advanced', 'description' => 'Restricted advanced lore.'],
            synthWorldKnowledgeResolveKnowledgePayload($row, ['scholar'])
        );
        $this->assertSame(
            ['level' => 'basic', 'description' => 'Common basic lore.'],
            synthWorldKnowledgeResolveKnowledgePayload($row, ['scholar', 'raider'])
        );
    }

    public function testParityAccessDecisionSupportsAdvancedBasicDeniedAndKnowall(): void
    {
        $row = [
            'topic' => 'enclave_archive',
            'aliases' => 'Enclave Archive',
            'source_kind' => 'factory',
            'topic_desc' => 'Restricted advanced lore.',
            'knowledge_class' => 'enclave,!raider',
            'topic_desc_basic' => 'Common basic lore.',
            'knowledge_class_basic' => 'common,!raider',
        ];

        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['enclave'])['level']);
        $basic = synthWorldKnowledgeAccessDecision($row, []);
        $this->assertSame('basic', $basic['level']);
        $this->assertSame('common', $basic['reason']);
        $this->assertSame('basic', synthWorldKnowledgeAccessDecision($row, ['wastelander'])['level']);
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['knowall', 'raider'])['level']);
    }

    public function testAccessRulesGrantOnAnyFlatClass(): void
    {
        $row = [
            'topic' => 'alien',
            'topic_desc' => 'Confirmed technical details.',
            'knowledge_class' => 'scientist,xenotechnology,sole_survivor',
            'topic_desc_basic' => 'Some wastelanders tell stories about strange lights.',
            'knowledge_class_basic' => 'commonwealth',
        ];

        $this->assertSame('denied', synthWorldKnowledgeAccessDecision($row, ['common'])['level']);
        $this->assertSame('basic', synthWorldKnowledgeAccessDecision($row, ['region:commonwealth'])['level']);
        $advanced = synthWorldKnowledgeAccessDecision($row, ['role:scientist']);
        $this->assertSame('advanced', $advanced['level']);
        $this->assertSame(['scientist'], $advanced['matched']);
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['person:sole_survivor'])['level']);
    }

    public function testNamespacedFallout4AccessTagsNormalizeToFlatClasses(): void
    {
        $this->assertSame('minutemen', synthWorldKnowledgeNormalizeAccessTag('faction:minutemen'));
        $this->assertSame('raider', synthWorldKnowledgeNormalizeAccessTag('raiders'));
        $this->assertSame('diamond_city', synthWorldKnowledgeNormalizeAccessTag('community:diamond_city'));
        $this->assertSame('traveler', synthWorldKnowledgeNormalizeAccessTag('role:caravaner'));
        $this->assertSame('sole_survivor', synthWorldKnowledgeNormalizeAccessTag('person:sole_survivor'));
        $this->assertSame('vault_dweller', synthWorldKnowledgeNormalizeAccessTag('person:vault_dweller'));
        $this->assertSame('vault_dweller', synthWorldKnowledgeNormalizeAccessTag('role:vault_dweller'));
        $this->assertSame(
            'scientist,xenotechnology,sole_survivor',
            synthWorldKnowledgeNormalizeAccessRule('role:scientist,domain:xenotechnology,person:sole_survivor')
        );
    }

    public function testNegativeRulesDenyBeforePositiveClasses(): void
    {
        $denied = synthWorldKnowledgeClassDecision('common,!raider', ['common', 'raider']);
        $this->assertFalse($denied['allowed']);
        $this->assertSame('negative_class', $denied['reason']);
        $this->assertFalse(synthWorldKnowledgeClassDecision('!raider', ['common'])['allowed']);
        $this->assertTrue(synthWorldKnowledgeClassDecision('', ['common'])['allowed']);
    }

    public function testCommonIsPublicOnlyForBasicArticles(): void
    {
        $row = [
            'topic' => 'public_archive',
            'topic_desc' => 'Advanced details must not be synth.',
            'knowledge_class' => 'common',
            'topic_desc_basic' => 'Public basic details.',
            'knowledge_class_basic' => 'common,!raider',
        ];

        $this->assertSame('basic', synthWorldKnowledgeAccessDecision($row, [])['level']);
        $this->assertSame('denied', synthWorldKnowledgeAccessDecision($row, ['raider'])['level']);
    }

    public function testPersonAndRegionalRulesDoNotLeakAcrossFallout4Regions(): void
    {
        $nick = [
            'topic' => 'nick_valentine',
            'topic_desc' => 'Private details known to close associates.',
            'knowledge_class' => 'nick_valentine,ellie_perkins,sole_survivor',
            'topic_desc_basic' => 'Nick Valentine is a detective in Diamond City.',
            'knowledge_class_basic' => 'diamond_city,nick_valentine,sole_survivor',
        ];
        $farHarbor = [
            'topic' => 'far_harbor',
            'topic_desc' => 'Detailed local history.',
            'knowledge_class' => 'historian',
            'topic_desc_basic' => 'Far Harbor is a settlement on the Island.',
            'knowledge_class_basic' => 'far_harbor,traveler',
        ];

        $this->assertSame('denied', synthWorldKnowledgeAccessDecision($nick, ['common', 'region:far_harbor'])['level']);
        $this->assertSame('basic', synthWorldKnowledgeAccessDecision($nick, ['community:diamond_city'])['level']);
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($nick, ['person:nick_valentine'])['level']);
        $this->assertSame('denied', synthWorldKnowledgeAccessDecision($farHarbor, ['common', 'region:nuka_world'])['level']);
        $this->assertSame('basic', synthWorldKnowledgeAccessDecision($farHarbor, ['common', 'region:far_harbor'])['level']);
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($farHarbor, ['role:historian', 'region:far_harbor'])['level']);
    }

    public function testRestrictedFactoryArticleUsesFlatClassesAndKnowallOverride(): void
    {
        $row = [
            'topic' => 'restricted_enclave_archive',
            'source_kind' => 'factory',
            'catalog_id' => 'synth-fallout4-parity-v1',
            'topic_desc' => 'Restricted operational details known only to the archive custodian and authorized Enclave researchers.',
            'knowledge_class' => 'archive_custodian,enclave,researcher,prewar_archives',
            'topic_desc_basic' => '',
            'knowledge_class_basic' => 'archive_custodian',
        ];

        foreach ([
            ['common'],
            ['common', 'region:commonwealth'],
        ] as $unauthorizedTags) {
            $decision = synthWorldKnowledgeAccessDecision($row, $unauthorizedTags);
            $this->assertSame('denied', $decision['level']);
            $this->assertSame('', $decision['description']);
        }

        $this->assertSame(
            'advanced',
            synthWorldKnowledgeAccessDecision($row, ['person:archive_custodian'])['level']
        );
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['faction:enclave'])['level']);
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['knowall'])['level']);
    }

    public function testFactoryTemplateTagsAugmentExistingProfilesWithoutReplacingUserTags(): void
    {
        $previousDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = new class {
            public function escape(string $value): string
            {
                return str_replace("'", "''", $value);
            }

            public function fetchOne(string $query): array
            {
                return ['worldknowledge_tags' => 'community:diamond_city,region:commonwealth,role:doctor'];
            }

            public function fetchAll(string $query): array
            {
                return [];
            }
        };
        $GLOBALS['WORLDKNOWLEDGE'] = 'domain:medicine';
        $GLOBALS['SYNTH_NAME'] = 'Nick Valentine';
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = ['npc_codename' => 'nick_valentine'];

        try {
            $tags = synthWorldKnowledgeKnowledgeTags();
            $this->assertContains('medicine', $tags);
            $this->assertContains('diamond_city', $tags);
            $this->assertContains('commonwealth', $tags);
            $this->assertContains('nick_valentine', $tags);
            $this->assertNotContains('common', $tags);
        } finally {
            $GLOBALS['db'] = $previousDb;
        }
    }

    public function testInjectedAliasesDeduplicateNormalMatching(): void
    {
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
        synthWorldKnowledgeMarkTopicInjected('brotherhood_of_steel,Brotherhood');

        $this->assertTrue(synthWorldKnowledgeTopicWasInjected('Brotherhood of Steel'));
        $this->assertTrue(synthWorldKnowledgeTopicWasInjected('Brotherhood'));
        $this->assertFalse(synthWorldKnowledgeTopicWasInjected('The Institute'));
    }

    public function testForcedPromptUsesCanonicalTopicInsteadOfAliasList(): void
    {
        $GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
        $added = synthWorldKnowledgeAppendForcedRows(
            [[
                'topic' => 'commonwealth,post-nuclear commonwealth',
                'topic_desc' => '',
                'knowledge_class' => '',
                'topic_desc_basic' => 'The Commonwealth surrounds Fallout 4.',
                'knowledge_class_basic' => '',
            ]],
            [],
            'worldspace',
            1
        );

        $this->assertSame(1, $added);
        $this->assertStringContainsString('topic="commonwealth"', $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $this->assertStringNotContainsString('commonwealth,post-nuclear commonwealth', $GLOBALS['WORLDKNOWLEDGE_HINT']);
    }
}

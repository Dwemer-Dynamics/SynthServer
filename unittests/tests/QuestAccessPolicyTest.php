<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/lib/synth_protocol_state.php';
require_once dirname(__DIR__, 2).'/lib/worldknowledge_forced_context.php';

/** Authored replacements must protect every ordinary catalogue access path. */
final class QuestAccessPolicyTest extends TestCase
{
    private array $savedGlobals = [];
    private const ADVANCED_SECRET = 'UNREVIEWED FUTURE OUTCOME SENTINEL';
    private const BASIC_SECRET = 'LEGACY BASIC SPOILER SENTINEL';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['db', 'SYNTH_REQUEST_EVENT', 'SYNTH_PROTOCOL_REQUEST_CLAIMED', 'SYNTH_NAME',
            'WORLDKNOWLEDGE', 'SYNTH_CORE_CURRENT_NPC_DATA', 'WORLDKNOWLEDGE_HINT',
            'WORLDKNOWLEDGE_FORCED_SIGNALS', 'WORLDKNOWLEDGE_INJECTED_TOPICS',
            'WORLDKNOWLEDGE_FORCED_REMAINING'] as $key) {
            $this->savedGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
        $GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
        $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] = [];
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => [$exists, $value]) {
            if ($exists) $GLOBALS[$key] = $value;
            else unset($GLOBALS[$key]);
        }
        parent::tearDown();
    }

    private function row(string $topic = 'quest_when_freedom_calls'): array
    {
        return ['topic' => $topic, 'canonical_topic' => $topic, 'source_kind' => 'custom',
            'topic_desc' => self::ADVANCED_SECRET, 'knowledge_class' => '',
            'topic_desc_basic' => self::BASIC_SECRET, 'knowledge_class_basic' => 'common'];
    }

    private function event(): array
    {
        return ['protocol_version' => 2, 'runtime_variant' => 'flat', 'type' => 'input_text',
            'session_id' => 'quest-access-policy-session', 'generation' => 1,
            'context_sequence' => 1, 'request_id' => 'quest-access-policy-request',
            'turn_id' => 'quest-access-policy-turn', 'payload' => ['text' => 'Tell me about the quest.']];
    }

    private function participantView(): array
    {
        return ['schema' => 'synth.quest.access.v1', 'perspective' => 'npc', 'observation' => 'complete',
            'roles' => [['quest_key' => 'fallout4.esm|001A001C', 'quest_name' => 'When Freedom Calls',
                'actor_key' => 'fallout4.esm|0001A4D7', 'role' => 'quest_giver',
                'facts' => [self::ADVANCED_SECRET]]]];
    }

    private function assertNoSpoiler(array $decision): void
    {
        $encoded = json_encode($decision, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::ADVANCED_SECRET, $encoded);
        $this->assertStringNotContainsString(self::BASIC_SECRET, $encoded);
    }

    public function testOrdinaryArticleRetainsExistingKnowallAndClassBehavior(): void
    {
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $this->event()];
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
        $GLOBALS['db'] = new class {
            public int $locks = 0;
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $query): ?array { throw new LogicException('Unexpected quest query'); }
            public function withNativeRequestLock(callable $operation, int $timeout = 0): array
            {
                $this->locks++;
                throw new LogicException('Ordinary articles must not read quest ownership');
            }
        };
        $row = $this->row('diamond_city');
        $row['knowledge_class'] = 'historian,!raider';
        $this->assertSame('advanced', synthWorldKnowledgeAccessDecision($row, ['knowall', 'raider'])['level']);
        $this->assertSame(self::ADVANCED_SECRET, synthWorldKnowledgeAccessDecision($row, ['historian'])['description']);
        $this->assertSame('basic', synthWorldKnowledgeAccessDecision($row, ['raider'])['level']);
        $this->assertSame(0, $GLOBALS['db']->locks);
    }

    public function testRuntimeCatalogueMergesReviewedTopicsAndProtectsCustomDuplicates(): void
    {
        $custom = $this->row();
        $db = new class($custom) {
            public function __construct(private array $row) {}
            public function fetchAll(string $query): array { return [$this->row]; }
        };
        $rows = synthWorldKnowledgeFetchEffectiveCatalog($db);
        $topics = array_column($rows, 'canonical_topic');
        $this->assertSame(count($topics), count(array_unique($topics)));
        $this->assertSame(count(synthQuestAccessCatalogRows()), count($rows));
        $kept = array_values(array_filter($rows, static fn(array $row): bool => $row['canonical_topic'] === $custom['canonical_topic']));
        $this->assertCount(1, $kept);
        $this->assertSame('custom', $kept[0]['source_kind']);
        $this->assertNoSpoiler(synthWorldKnowledgeAccessDecision($kept[0], ['knowall']));
    }

    public function testCatalogueCancellationNeverReadsLegacyFallback(): void
    {
        $db = new class {
            public int $calls = 0;
            public function fetchAll(string $query): array
            {
                $this->calls++;
                throw new SYNTHProtocolCancelled('Quest policy catalogue cancelled');
            }
        };
        try {
            synthWorldKnowledgeFetchEffectiveCatalog($db);
            $this->fail('Cancelled catalogue read continued');
        } catch (SYNTHProtocolCancelled $cancelled) {
            $this->assertSame('Quest policy catalogue cancelled', $cancelled->getMessage());
            $this->assertSame(1, $db->calls);
        }
    }

    public function testUnknownReservedTopicsDenyKnowallUnrestrictedAndCommonTiers(): void
    {
        foreach (['quest_unreviewed_future', 'fo4_quest_unreviewed_future', 'quest_', 'fo4_quest_???'] as $topic) {
            $decision = synthWorldKnowledgeAccessDecision($this->row($topic), ['knowall', 'common', $topic]);
            $this->assertSame('denied', $decision['level']);
            $this->assertSame('', $decision['description']);
            $this->assertNotSame('knowall', $decision['reason']);
            $this->assertNoSpoiler($decision);
            $xml = synthWorldKnowledgeRenderArticleXml($this->row($topic), $decision, 'conversation');
            $this->assertStringContainsString('<denial ', $xml);
            $this->assertStringNotContainsString(self::ADVANCED_SECRET, $xml);
            $this->assertStringNotContainsString(self::BASIC_SECRET, $xml);
        }
    }

    public function testKnownQuestPublicTierUsesReviewedReplacementWithoutReader(): void
    {
        foreach (['quest_when_freedom_calls', 'when_freedom_calls'] as $topic) {
            $decision = synthWorldKnowledgeAccessDecision($this->row($topic), ['knowall']);
            $this->assertSame('basic', $decision['level']);
            $this->assertSame('quest_public_premise', $decision['reason']);
            $this->assertSame(synthQuestAccessTopicPolicy($topic)['safe_basic'], $decision['description']);
            $this->assertNotSame('', $decision['description']);
            $this->assertNoSpoiler($decision);
        }
    }

    public function testProtectedReplacementStillRespectsNormalClassDenials(): void
    {
        $row = $this->row();
        $row['knowledge_class_basic'] = 'common,!raider';
        $decision = synthWorldKnowledgeAccessDecision($row, ['knowall', 'raider']);
        $this->assertSame('denied', $decision['level']);
        $this->assertSame('negative_class', $decision['reason']);
        $this->assertSame('', $decision['description']);
        $this->assertNoSpoiler($decision);
    }

    public function testProtectedQuestPremiseRespectsPrewarEraGateDespiteKnowall(): void
    {
        $row = $this->row();
        $tags = ['prewar', 'knowall', 'commonwealth', 'preston_garvey'];
        foreach ([[], ['actor_identity_id' => 41,
            'extended_data' => ['base_origin_plugin' => 'Fallout4.esm', 'baseid' => '0x000AA763']]] as $profile) {
            $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = $profile;
            $callerTags = $profile ? array_values(array_diff($tags, ['prewar'])) : $tags;
            $decision = synthWorldKnowledgeAccessDecision($row, $callerTags);
            $this->assertSame('denied', $decision['level']);
            $this->assertSame('era_not_authorized', $decision['reason']);
            $this->assertSame('', $decision['description']);
            $this->assertNoSpoiler($decision);
            $xml = synthWorldKnowledgeRenderArticleXml($row, $decision, 'conversation');
            $this->assertStringNotContainsString(synthQuestAccessTopicPolicy($row['topic'])['safe_basic'], $xml);
            $this->assertNull(synthWorldKnowledgeResolveKnowledgePayload($row, $callerTags));

            $GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
            $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] = [];
            $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
            $GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] = 3;
            $this->assertSame(0, synthWorldKnowledgeAppendForcedRows([$row], $callerTags, 'location', 1));
            $this->assertSame('', $GLOBALS['WORLDKNOWLEDGE_HINT']);
            $this->assertSame('denied', $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'][0]['level']);
        }
    }

    public function testUnreviewedAndConflictingQuestIdentityRemainDeniedForPrewarReaders(): void
    {
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = ['actor_identity_id' => 41,
            'extended_data' => ['base_origin_plugin' => 'Fallout4.esm', 'baseid' => '0x000ABF9E']];
        $unknown = $this->row('quest_unreviewed_future');
        $conflicting = $this->row();
        $conflicting['canonical_topic'] = 'diamond_city';
        // The reserved namespace has an explicit deny policy, so its pure decision
        // reports quest_rule_denied rather than the runtime's missing-policy fallback.
        foreach ([[$unknown, 'quest_rule_denied'], [$conflicting, 'quest_topic_identity_mismatch']] as [$row, $reason]) {
            $decision = synthWorldKnowledgeAccessDecision($row, ['knowall', 'commonwealth']);
            $this->assertSame('denied', $decision['level']);
            $this->assertSame($reason, $decision['reason']);
            $this->assertSame('', $decision['description']);
            $this->assertNoSpoiler($decision);
        }
    }

    public function testProtectedConflictingCanonicalIdentityFailsClosedForOverrides(): void
    {
        foreach (['custom', 'factory'] as $kind) {
            foreach ([['quest_when_freedom_calls', 'diamond_city'], ['diamond_city', 'quest_when_freedom_calls']] as [$topic, $canonical]) {
                $row = $this->row($topic);
                $row['canonical_topic'] = $canonical;
                $row['source_kind'] = $kind;
                $decision = synthWorldKnowledgeAccessDecision($row, ['knowall']);
                $this->assertSame('denied', $decision['level']);
                $this->assertSame('quest_topic_identity_mismatch', $decision['reason']);
                $this->assertNoSpoiler($decision);
            }
        }
    }

    public function testCanonicalAliasesDoNotBypassQuestPolicy(): void
    {
        $row = $this->row();
        $row['topic'] .= ',When Freedom Calls,Concord rescue';
        $decision = synthWorldKnowledgeAccessDecision($row, ['knowall']);
        $this->assertSame('basic', $decision['level']);
        $this->assertNoSpoiler($decision);
    }

    public function testNarratorNameProfileTagsAndMalformedNativeReaderGrantNoAdvancedTier(): void
    {
        $GLOBALS['SYNTH_NAME'] = 'The Narrator';
        $GLOBALS['WORLDKNOWLEDGE'] = 'knowall,quest_when_freedom_calls,preston_garvey';
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] = ['npc_codename' => 'preston_garvey'];
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $this->event()];
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
        $GLOBALS['db'] = new stdClass();
        $decision = synthWorldKnowledgeAccessDecision($this->row(), ['knowall', 'preston_garvey', 'quest_when_freedom_calls']);
        $this->assertSame('basic', $decision['level']);
        $this->assertNoSpoiler($decision);
    }

    public function testForcedContextAndResolvedPayloadUseSameSafeReplacement(): void
    {
        $GLOBALS['WORLDKNOWLEDGE_FORCED_REMAINING'] = 3;
        $row = $this->row();
        $this->assertSame(1, synthWorldKnowledgeAppendForcedRows([$row], ['knowall'], 'location', 1));
        $this->assertSame('basic', $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'][0]['level']);
        $this->assertStringNotContainsString(self::ADVANCED_SECRET, $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $this->assertStringNotContainsString(self::BASIC_SECRET, $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $resolved = synthWorldKnowledgeResolveKnowledgePayload($row, ['knowall']);
        $this->assertSame('basic', $resolved['level']);
        $this->assertSame(synthQuestAccessTopicPolicy($row['topic'])['safe_basic'], $resolved['description']);

        $GLOBALS['WORLDKNOWLEDGE_HINT'] = '';
        $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'] = [];
        $GLOBALS['WORLDKNOWLEDGE_INJECTED_TOPICS'] = [];
        $unknown = $this->row('quest_unreviewed_future');
        $this->assertSame(0, synthWorldKnowledgeAppendForcedRows([$unknown], ['knowall'], 'faction', 1));
        $this->assertSame('denied', $GLOBALS['WORLDKNOWLEDGE_FORCED_SIGNALS'][0]['level']);
        $this->assertSame('', $GLOBALS['WORLDKNOWLEDGE_HINT']);
        $this->assertNull(synthWorldKnowledgeResolveKnowledgePayload($unknown, ['knowall']));
    }

    public function testAuditDecisionsContainOnlyApprovedText(): void
    {
        $db = new class {
            public string $query = '';
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function execQuery(string $query): bool { $this->query = $query; return true; }
        };
        $decisions = [synthWorldKnowledgeAccessDecision($this->row(), ['knowall']),
            synthWorldKnowledgeAccessDecision($this->row('quest_unreviewed_future'), ['knowall'])];
        synthWorldKnowledgeRecordAudit($db, ['access_decisions' => $decisions]);
        $this->assertStringContainsString('INSERT INTO synth.worldknowledge_audit', $db->query);
        $this->assertStringNotContainsString(self::ADVANCED_SECRET, $db->query);
        $this->assertStringNotContainsString(self::BASIC_SECRET, $db->query);
    }

    public function testPureParticipantRuleRequiresExactReviewedQuestActorAndRole(): void
    {
        $view = $this->participantView();
        $decision = synthQuestAccessDecision('quest_when_freedom_calls', 'advanced', $view);
        $this->assertTrue($decision['allowed']);
        $this->assertNotSame('', $decision['description']);
        $this->assertNoSpoiler($decision);
        foreach (['quest_key', 'actor_key', 'role'] as $field) {
            $wrong = $view;
            $wrong['roles'][0][$field] = 'unreviewed_identity';
            $denied = synthQuestAccessDecision('quest_when_freedom_calls', 'advanced', $wrong);
            $this->assertFalse($denied['allowed'], $field);
            $this->assertSame('', $denied['description'], $field);
        }
        $view['perspective'] = 'narrator';
        $view['roles'] = [];
        $this->assertFalse(synthQuestAccessDecision('quest_when_freedom_calls', 'advanced', $view)['allowed']);
    }

    public function testMalformedParticipantViewsDenyWithoutBorrowingFacts(): void
    {
        $view = $this->participantView();
        $malformed = [array_replace($view, ['observation' => 'partial'])];
        $malformed[] = array_replace($view, ['schema' => 'untrusted.quest.view']);
        $malformed[] = array_replace($view, ['roles' => ['named_entry' => $view['roles'][0]]]);
        $malformed[] = array_replace($view, ['roles' => array_fill(0, 9, $view['roles'][0])]);
        $malformed[] = array_replace($view, ['roles' => ['not a role']]);
        foreach ($malformed as $candidate) {
            $decision = synthQuestAccessDecision('quest_when_freedom_calls', 'advanced', $candidate);
            $this->assertFalse($decision['allowed']);
            $this->assertSame('', $decision['description']);
        }
    }

    public function testNativeCancellationPropagatesInsteadOfFallingBack(): void
    {
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $this->event()];
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
        $GLOBALS['db'] = new class {
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $query): ?array { return null; }
            public function withNativeRequestLock(callable $operation, int $timeout = 0): array
            {
                throw new SYNTHProtocolCancelled('Quest policy fixture cancelled');
            }
        };
        $this->expectException(SYNTHProtocolCancelled::class);
        synthWorldKnowledgeAccessDecision($this->row(), ['knowall']);
    }
}

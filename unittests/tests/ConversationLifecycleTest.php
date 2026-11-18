<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/managers/Conversation/ConversationManager.php';
require_once dirname(__DIR__, 2) . '/api/v2/dispatch.php';

use Synth\Core\UtteranceRepository;
use Synth\Managers\ReceiptManager;
use Synth\Managers\SaveState;
use Synth\Managers\SessionManager;

// dialogue.conversation: conversation ids, the uniform line lifecycle and the scoped player cancel. Database
// behaviour on both dialogue lanes is covered end to end by the loopback conversation scenario.
final class ConversationLifecycleTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/protocol/v2/fixtures/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    private function accepted(array $event): bool
    {
        $script = 'require '.var_export(dirname(__DIR__, 2).'/lib/request.php', true).';echo json_encode(synth_protocol_validate_event(json_decode('
            .var_export(json_encode($event), true).',true))===null?"NULL":"ACCEPTED");';
        $output = strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
        return str_contains($output, 'ACCEPTED');
    }

    public function testEveryConversationFixtureIsAcceptedOrRefusedByIngress(): void
    {
        $root = dirname(__DIR__, 2).'/protocol/v2/fixtures/';
        $valid = [...glob($root.'valid/*conversation*.json'), $root.'valid/speech-receipt-skipped.json', $root.'valid/cancel-request-extended.json',
            $root.'valid/cancel-legacy.json'];
        $this->assertGreaterThanOrEqual(9, count($valid));
        foreach ($valid as $file) {
            $event = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($event, synth_protocol_validate_event($event), basename($file));
        }
        $invalid = [...glob($root.'invalid/*conversation*.json'), ...glob($root.'invalid/cancel-*.json'), $root.'invalid/speech-receipt-skipped-no-capability.json'];
        $this->assertGreaterThanOrEqual(12, count($invalid));
        foreach ($invalid as $file)
            $this->assertFalse($this->accepted(json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)), basename($file));
    }

    public function testConversationIdIsOnlyEchoedForANegotiatedCarrier(): void
    {
        $text = $this->fixture('valid/input-text-conversation.json');
        $id = $text['payload']['conversation_id'];
        $this->assertSame($id, \Synth\Managers\Conversation\ConversationManager::idOf($text));
        $this->assertSame(['server_version' => 'source', 'conversation_id' => $id], synth_protocol_start_payload($text));
        $trigger = $this->fixture('valid/trigger-ambient-conversation.json');
        $this->assertSame($id, \Synth\Managers\Conversation\ConversationManager::idOf($trigger));
        // No capability in the admitted bytes, a receipt or a cancel: nothing is echoed.
        $old = $text; $old['capabilities'] = ['dialogue.text', 'dialogue.turn_ownership'];
        $this->assertNull(\Synth\Managers\Conversation\ConversationManager::idOf($old));
        $this->assertSame(['server_version' => 'source'], synth_protocol_start_payload($old));
        $this->assertNull(\Synth\Managers\Conversation\ConversationManager::idOf($this->fixture('valid/speech-receipt-skipped.json')));
        $this->assertNull(\Synth\Managers\Conversation\ConversationManager::idOf($this->fixture('valid/cancel-conversation.json')));
        $this->assertNull(\Synth\Managers\Conversation\ConversationManager::idOf($this->fixture('valid/input-text-delivery-receipts.json')));
        // The init acknowledgement needs both the capability and its prerequisite.
        $this->assertTrue(\Synth\Managers\Conversation\ConversationManager::initGranted($this->fixture('valid/init-conversation.json')));
        $this->assertFalse(\Synth\Managers\Conversation\ConversationManager::initGranted($this->fixture('valid/init-delivery-receipts.json')));
    }

    private function receipts(array &$rows): ReceiptManager
    {
        $saves = new class extends SaveState {
            public function __construct() {}
            public function boundContext(array $event): ?array
            {
                $player = ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save-1', 'display_name' => 'Nate'];
                $target = ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save-1', 'display_name' => 'Jun'];
                return ['payload' => ['player' => $player, 'target' => $target, 'audience' => [], 'world' => ['game_time_ticks' => 42]],
                    'player' => $player, 'target' => $target];
            }
        };
        $utterances = new class($rows) extends UtteranceRepository {
            public function __construct(private array &$rows) {}
            public function recordReceipt(Synth\Core\TurnRequest $receipt, array $character, string $characterId, string $state, ?\Closure $heard): string
            {
                $this->rows[] = [$state, $heard === null ? null : $heard('Stored line.')];
                return $state;
            }
        };
        return new ReceiptManager(new SessionManager($saves), $utterances);
    }

    public function testInterruptedLineStaysInContextMarkedCutOffAndSkippedNeverCounts(): void
    {
        $base = $this->fixture('valid/speech-receipt-ordinary.json');
        $withConversation = $base;
        $withConversation['capabilities'][] = 'dialogue.conversation';
        $rows = [];
        $receipts = $this->receipts($rows);
        foreach (['interrupted', 'skipped', 'failed', 'audio_completed'] as $outcome) {
            $receipts->receipt(array_replace_recursive($base, ['payload' => ['outcome' => $outcome]]));
            $receipts->receipt(array_replace_recursive($withConversation, ['payload' => ['outcome' => $outcome]]));
        }
        // Without the capability the released behaviour is unchanged: an interrupted line is never heard.
        // With it the line is kept in context marked as cut off; a skipped line is stored unheard like a failed one.
        $this->assertSame([
            ['interrupted', null], ['interrupted', 'Jun: Stored line. [cut off] (talking to Nate)'],
            ['failed', null], ['failed', null],
            ['failed', null], ['failed', null],
            ['spoken', 'Jun: Stored line. (talking to Nate)'], ['spoken', 'Jun: Stored line. (talking to Nate)'],
        ], $rows);
        // Only spoken and presented lines are heard; the cut-off line is context, never a heard line.
        $saves = new class extends SaveState { public function __construct() {} };
        $this->assertFalse($saves->heard('interrupted'));
        $this->assertTrue($saves->heard('spoken'));
        $this->assertContains('interrupted', SaveState::CONTEXT_STATES);
        $this->assertNotContains('interrupted', SaveState::HEARD_STATES);
        require_once dirname(__DIR__, 2) . '/lib/chat_helper_functions.php';
        $this->assertContains('interrupted', synthGetVisibleChatDeliveryStates());
    }

    // A recording double of the database layer used by the claim transaction's cancel branch.
    private function recordingDb(): object
    {
        return new class {
            public array $queries = [];
            public function escapeLiteral($value): string { return "'".str_replace("'", "''", strval($value))."'"; }
            public function execQuery($sql) { $this->queries[] = $sql; return true; }
            public function fetchAll($sql) { $this->queries[] = $sql; return [['request_id' => 'req-text-3', 'turn_id' => 'turn-text-3']]; }
        };
    }

    public function testConversationCancelRevokesOnlyTheConversationsOwnRequestsAndRecordsTheReason(): void
    {
        $event = $this->fixture('valid/cancel-conversation.json');
        $db = $this->recordingDb();
        $ids = \Synth\Managers\Conversation\ConversationManager::cancel($db, $event, $db->escapeLiteral($event['session_id']), $event['generation']);
        $this->assertSame(['req-text-3'], $ids);
        $sql = implode("\n", $db->queries);
        // In-flight and completed-undelivered work only, selected by the id in the stored admitted bytes, in this
        // session and generation, never the cancel event itself, never another conversation.
        $this->assertStringContainsString("canonical_event#>>'{payload,conversation_id}'='".$event['payload']['conversation_id']."'", $sql);
        $this->assertStringContainsString("request_id<>'".$event['request_id']."'", $sql);
        $this->assertStringContainsString("state IN ('claimed','streaming','complete')", $sql);
        $this->assertStringContainsString("error_code='request_cancelled'", $sql);
        $this->assertStringContainsString("canonical_event->'capabilities' ? 'dialogue.conversation'", $sql);
        $this->assertStringContainsString("UPDATE synth_meta.worker_jobs SET state='cancelled'", $sql);
        $this->assertStringContainsString('SET active_turn_id=NULL', $sql);
        $this->assertStringContainsString('(active_turn_id, active_turn_sequence) IN', $sql, 'the turn is matched by id and sequence');
        // Completed player input stays as historical learning, exactly like a single-request cancel.
        $this->assertStringContainsString("WHEN state='complete' AND event_type IN ('input_text','input_audio') THEN 'complete'", $sql);
        $other = $this->fixture('valid/cancel-conversation.json');
        $other['payload']['conversation_id'] = 'b8e2a470-1c9d-4f63-a5e8-0d7c3b6a2f94';
        $second = $this->recordingDb();
        \Synth\Managers\Conversation\ConversationManager::cancel($second, $other, $second->escapeLiteral($other['session_id']), $other['generation']);
        $this->assertStringContainsString("'b8e2a470-1c9d-4f63-a5e8-0d7c3b6a2f94'", implode("\n", $second->queries));
        $this->assertStringNotContainsString($event['payload']['conversation_id'], implode("\n", $second->queries));
    }

    public function testHistoryRankPreferenceExistsOnlyForANegotiatedConversation(): void
    {
        $db = $this->recordingDb();
        $text = $this->fixture('valid/input-text-conversation.json');
        $rank = \Synth\Managers\Conversation\ConversationManager::rankSql($db, $text);
        $this->assertStringContainsString("conv_e.canonical_event#>>'{payload,conversation_id}'='".$text['payload']['conversation_id']."'", $rank);
        $this->assertStringContainsString("conv_h.session_id='".$text['session_id']."'", $rank);
        // Only dialogue rows are ranked; every other row type counts as preferred, so it is never crowded out.
        $this->assertStringStartsWith("(type NOT IN ('chat','inputtext','inputtext_s','backgroundchat') OR EXISTS", $rank);
        $this->assertNull(\Synth\Managers\Conversation\ConversationManager::rankSql($db, $this->fixture('valid/input-text-delivery-receipts.json')));
        $this->assertNull(\Synth\Managers\Conversation\ConversationManager::rankSql($db, null));
    }
}

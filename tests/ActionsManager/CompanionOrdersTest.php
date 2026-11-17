<?php declare(strict_types=1);

namespace Synth\Tests\ActionsManager;

use PHPUnit\Framework\TestCase;
use Synth\Managers\ActionsManager\CompanionOrders;

// Database-free: Follow, Stop Following and Take a Seat negotiation, reply-line binding and receipt checks.
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class CompanionOrdersTest extends TestCase
{
    private array $event;
    private array $actor = ['form_id' => '0x0001CA7D', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'order-save', 'display_name' => 'Codsworth'];

    protected function setUp(): void
    {
        ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $GLOBALS['PLAYER_NAME'] = 'Nora';
        $GLOBALS['db'] = null;
        require_once dirname(__DIR__, 2).'/lib/logger.php';
        require_once dirname(__DIR__, 2).'/lib/synth_protocol_state.php';
        require_once dirname(__DIR__, 2).'/lib/request.php';
        require_once dirname(__DIR__, 2).'/functions/functions.php';
        require_once dirname(__DIR__, 2).'/lib/synth_command_payload.php';
        require_once dirname(__DIR__, 2).'/managers/ActionsManager/CompanionOrders.php';
        restore_error_handler();
        $this->event = ['protocol_version' => 2, 'type' => 'input_text', 'runtime_variant' => 'flat', 'session_id' => 's1', 'request_id' => 'r1',
            'generation' => 1, 'context_sequence' => 1, 'payload' => ['text' => 'Come with me.', 'control' => ['mode' => 'STANDARD']],
            'capabilities' => ['dialogue.turn_ownership', 'action.order_verified', 'action.follow_player', 'action.stop_following', 'action.take_a_seat']];
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $this->event];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = $this->event['capabilities'];
    }

    public function testSupportRequiresOwnedFlatPlayerInputAndEveryCapability(): void
    {
        foreach (['FollowPlayer', 'StopFollowing', 'TakeASeat'] as $code) {
            $this->assertTrue(CompanionOrders::supported($this->event, $code), $code);
        }
        $this->assertFalse(CompanionOrders::supported($this->event, 'WaitHere'));
        foreach ([
            ['runtime_variant' => 'vr'], ['protocol_version' => 1], ['type' => 'trigger'],
            ['payload' => ['control' => ['mode' => 'AUTO']]],
            ['capabilities' => ['dialogue.turn_ownership', 'action.follow_player']],
            ['capabilities' => ['action.order_verified', 'action.follow_player']],
        ] as $change) {
            $this->assertFalse(CompanionOrders::supported(array_replace($this->event, $change), 'FollowPlayer'), json_encode($change));
        }
        $this->assertFalse(CompanionOrders::supported(array_replace($this->event,
            ['capabilities' => ['dialogue.turn_ownership', 'action.order_verified', 'action.stop_following']]), 'FollowPlayer'));
    }

    public function testCatalogueOffersOrdersOnlyToTheNegotiatedClient(): void
    {
        $this->assertTrue(\synthActionCodeIsNegotiated('FollowPlayer'));
        $this->assertTrue(\synthActionCodeIsNegotiated('TakeASeat'));
        $old = $this->event;
        $old['capabilities'] = ['dialogue.turn_ownership', 'action.follow_player'];
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $old];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = $old['capabilities'];
        $this->assertFalse(\synthActionCodeIsNegotiated('FollowPlayer'), 'a client without order receipts was offered orders');
        $vr = $this->event;
        $vr['runtime_variant'] = 'vr';
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $vr];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = $vr['capabilities'];
        $this->assertFalse(\synthActionCodeIsNegotiated('StopFollowing'), 'VR was offered an order');
    }

    public function testCommandPayloadAcceptsOnlyEmptyOrderParameters(): void
    {
        $line = \synthEncodeActionLine('Codsworth', 'FollowPlayer', '', '');
        $this->assertStringContainsString('FollowPlayer', $line);
        $this->assertSame('', \synthEncodeActionLine('Codsworth', 'FollowPlayer', 'Nora', ''), 'a target was accepted');
        $this->assertSame('', \synthEncodeActionLine('Codsworth', 'TakeASeat', ['item' => 'Chair'], ''), 'an argument was accepted');
        $vr = $this->event;
        $vr['runtime_variant'] = 'vr';
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $vr];
        $this->assertSame('', \synthEncodeActionLine('Codsworth', 'FollowPlayer', '', ''), 'an unsupported lane encoded an order');
    }

    public function testOrderBindsToTheFirstReplyLineOfTheSameNpcOnce(): void
    {
        $other = ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'order-save', 'display_name' => 'Jun'];
        CompanionOrders::noteReplyLine(['type' => 'dialogue', 'line_id' => 'r1:dialogue:1', 'request_id' => 'r1', 'payload' => ['speaker' => $this->actor]]);
        CompanionOrders::noteReplyLine(['type' => 'dialogue', 'line_id' => 'r1:dialogue:2', 'request_id' => 'r1', 'payload' => ['speaker' => $this->actor]]);
        CompanionOrders::noteReplyLine(['type' => 'dialogue', 'line_id' => 'r2:dialogue:1', 'request_id' => 'r2', 'payload' => ['speaker' => $other]]);
        CompanionOrders::noteReplyLine(['type' => 'dialogue', 'line_id' => 'r1:dialogue:3', 'request_id' => 'r1', 'payload' => ['speaker' => ['kind' => 'narrator']]]);
        $this->assertNull(CompanionOrders::claimReplyLine('r1', $other), 'another NPC claimed this reply');
        $this->assertNull(CompanionOrders::claimReplyLine('r9', $this->actor), 'an unknown request claimed a reply');
        $this->assertSame('r1:dialogue:1', CompanionOrders::claimReplyLine('r1', $this->actor));
        $this->assertNull(CompanionOrders::claimReplyLine('r1', $this->actor), 'one reply carried two orders');
        $this->assertSame('r2:dialogue:1', CompanionOrders::claimReplyLine('r2', $other), 'another request was blocked');
    }

    public function testOrderBeforeItsReplyHasNoLineToBindTo(): void
    {
        $this->assertNull(CompanionOrders::claimReplyLine('r1', $this->actor), 'an order preceded its reply');
        // A refused claim must not burn the request's single order.
        CompanionOrders::noteReplyLine(['type' => 'dialogue', 'line_id' => 'r1:dialogue:1', 'request_id' => 'r1', 'payload' => ['speaker' => $this->actor]]);
        $this->assertSame('r1:dialogue:1', CompanionOrders::claimReplyLine('r1', $this->actor));
    }

    private function proof(string $name, array $change = []): array
    {
        $base = [
            'follow_player' => ['following' => true, 'seated' => false, 'released' => false, 'owned_package' => '0x01000C00', 'seat' => '0x00000000'],
            'take_a_seat' => ['following' => false, 'seated' => true, 'released' => false, 'owned_package' => '0x01000C01', 'seat' => '0x00012345'],
            'stop_following' => ['following' => false, 'seated' => false, 'released' => true, 'owned_package' => '0x00000000', 'seat' => '0x00000000'],
        ][$name];
        return array_replace(['schema' => 'synth.action.order.result.v1', 'action_id' => 'action:1', 'action' => $name,
            'actor' => $this->actor, 'reply_line_id' => 'r1:dialogue:1', 'reload_policy' => 'release_owned_order'], $base, $change);
    }

    private function receipt(array $proof, string $status = 'succeeded'): array
    {
        $event = $this->event;
        $event['payload']['result'] = ['action_id' => 'action:1', 'status' => $status, 'detail' => json_encode($proof)];
        return $event;
    }

    public function testOnlyTheExactOwnedReceiptVerifiesAnOrder(): void
    {
        foreach (['follow_player', 'take_a_seat', 'stop_following'] as $name) {
            $this->assertIsArray(CompanionOrders::resultObservation($this->receipt($this->proof($name)), $name, $this->actor, 'r1:dialogue:1'), $name);
            $this->assertIsArray(CompanionOrders::resultObservation($this->receipt($this->proof($name)), $name, $this->actor, null), $name);
        }
        $this->assertNull(CompanionOrders::resultObservation($this->receipt($this->proof('follow_player'), 'failed'), 'follow_player', $this->actor, null));
        $unverified = $this->event;
        $unverified['capabilities'] = ['dialogue.turn_ownership'];
        $unverified['payload']['result'] = ['action_id' => 'action:1', 'status' => 'succeeded', 'detail' => json_encode($this->proof('follow_player'))];
        $this->assertNull(CompanionOrders::resultObservation($unverified, 'follow_player', $this->actor, null));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgedReceipts')]
    public function testForgedReceiptsAreRefused(string $name, array $change, ?string $reply = 'r1:dialogue:1'): void
    {
        $this->expectException(\DomainException::class);
        CompanionOrders::resultObservation($this->receipt($this->proof($name, $change)), $name, $this->actor, $reply);
    }

    public static function forgedReceipts(): array
    {
        return [
            'another action' => ['follow_player', ['action_id' => 'action:2']],
            'another verb' => ['follow_player', ['action' => 'take_a_seat']],
            'another actor' => ['follow_player', ['actor' => ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'order-save']]],
            'another reply' => ['follow_player', [], 'r1:dialogue:9'],
            'bad reply id' => ['follow_player', ['reply_line_id' => "x\n"], null],
            'follow without package' => ['follow_player', ['owned_package' => '0x00000000']],
            'follow while seated' => ['follow_player', ['seated' => true]],
            'sit without seat' => ['take_a_seat', ['seat' => '0x00000000']],
            'sit while following' => ['take_a_seat', ['following' => true]],
            'stop with package' => ['stop_following', ['owned_package' => '0x01000C00']],
            'stop not released' => ['stop_following', ['released' => false]],
            'extra field' => ['follow_player', ['extra' => true]],
            'wrong policy' => ['follow_player', ['reload_policy' => 'keep']],
            'numeric flag' => ['follow_player', ['following' => 1]],
        ];
    }

    public function testResultDetailReportsUnverifiedOrdersAsUnconfirmed(): void
    {
        $row = ['action_name' => 'follow_player', 'capability' => 'action.follow_player', 'actor_name' => 'Codsworth',
            'actor_form_id' => $this->actor['form_id'], 'actor_plugin' => 'Fallout4.esm', 'actor_playthrough' => 'order-save'];
        $this->assertSame('Codsworth is following the player now.',
            CompanionOrders::boundResultDetail($this->receipt($this->proof('follow_player')), $row));
        $row['action_name'] = 'TakeASeat';
        $this->assertSame('Codsworth sat down.', CompanionOrders::boundResultDetail($this->receipt($this->proof('take_a_seat')), $row));
        $this->assertStringContainsString('could not be confirmed',
            CompanionOrders::boundResultDetail($this->receipt($this->proof('take_a_seat', ['seat' => '0x00000000'])), $row));
        $this->assertStringContainsString('could not be confirmed',
            CompanionOrders::boundResultDetail($this->receipt($this->proof('take_a_seat'), 'failed'), $row));
        $row['action_name'] = 'StopFollowing';
        $this->assertStringContainsString('native follower role is unchanged',
            CompanionOrders::boundResultDetail($this->receipt($this->proof('stop_following')), $row));
    }
}

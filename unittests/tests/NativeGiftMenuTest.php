<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';

final class NativeGiftMenuTest extends TestCase
{
    private function fixture(): array {
        $player=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'menu-save','display_name'=>'Player'];
        $npc=['form_id'=>'0x00000015','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'menu-save','display_name'=>'Marcy'];
        $gun=['form_id'=>'0x00001234','origin_plugin'=>'Fallout4.esm','display_name'=>'Submachine Gun','form_type'=>43,'count'=>1,'equipped'=>false,'weight'=>8,'value'=>100];
        $ammo=['form_id'=>'0x00001235','origin_plugin'=>'Fallout4.esm','display_name'=>'.45 Round','form_type'=>44,'count'=>30,'equipped'=>false,'weight'=>0,'value'=>1];
        $event=['protocol_version'=>2,'type'=>'input_text','runtime_variant'=>'flat','payload'=>['text'=>'I have a gift for you'],
            'capabilities'=>['action.open_inventory','action.gift_menu_inventory','action.give_item_to','action.transfer_inventory','action.inventory_observation','dialogue.turn_ownership']];
        $context=['player'=>$player,'target'=>$npc,'payload'=>['actor_states'=>[
            ['actor'=>$player,'inventory'=>[$gun,$ammo],'inventory_observation'=>'complete'],
            ['actor'=>$npc,'inventory'=>[],'inventory_observation'=>'complete']]]];
        $action=['name'=>'open_inventory','capability'=>'action.open_inventory','server_code'=>'OpenInventory','actor'=>$npc,'target'=>$player,'arguments'=>[]];
        $remaining=$ammo;$remaining['count']=10;$donated=$ammo;$donated['count']=20;
        $result=['schema'=>'synth.action.transfer-result.v2','action_id'=>'menu:one','idempotency_key'=>'menu:key','status'=>'succeeded',
            'inventory'=>['actor'=>$player,'items'=>[$remaining],'observation'=>'complete'],
            'recipient_inventory'=>['actor'=>$npc,'items'=>[$gun,$donated],'observation'=>'complete']];
        return [$event,$action,$context,$result];
    }

    public function testGeneralAndNamedGiftWordingHaveDistinctRoutes(): void {
        [$event]=$this->fixture();
        foreach (['I have a gift for you','Marcy, I have a gift for you.','I brought a gift for you!'] as $text) {
            $event['payload']['text']=$text;self::assertSame('general',synth_player_gift_statement_kind($text));
            self::assertTrue(synth_gift_menu_request_allowed($event));self::assertFalse(synth_player_item_offer($text));
        }
        foreach (['I have a gift for you, a Submachine Gun','Jun, I have a gift for you: Heavy 10mm Pistol','I have a gift for you. A laser musket.'] as $text) {
            $event['payload']['text']=$text;self::assertSame('named',synth_player_gift_statement_kind($text));
            self::assertFalse(synth_gift_menu_request_allowed($event));self::assertTrue(synth_player_item_offer($text));
        }
        foreach (["I don't have a gift for you",'Do I have a gift for you?','she said I have a gift for you','say I have a gift for you','if I have a gift for you','I will have a gift for you later'] as $text)
            self::assertSame('none',synth_player_gift_statement_kind($text),$text);
    }

    public function testTrailingCapturedNpcNameOpensExchange(): void
    {
        [$event,$action,$context]=$this->fixture();
        $event['payload']['text']='I have a gift for you, Marcy.';
        $event+=['session_id'=>'menu-session','generation'=>1,'context_sequence'=>1];
        $snapshot=['player'=>$context['player'],'target'=>$context['target']]+$context['payload'];
        $db=new class($snapshot) {
            public function __construct(private array $snapshot) {}
            public function escapeLiteral(string $value): string { return "'".$value."'"; }
            public function fetchOne(string $query): array { return ['context_payload'=>$this->snapshot]; }
        };

        self::assertSame('general',synth_player_gift_statement_kind($event['payload']['text'],'marcy'));
        self::assertFalse(synth_gift_menu_request_allowed($event));
        self::assertTrue(synth_gift_menu_request_allowed($event,$db));
        self::assertTrue(synth_gift_menu_request_allowed($event,null,$context));
        synth_gift_menu_validate($event,$action,$context);

        foreach (['I have a gift for you, Jun', 'I have a gift for you, a Submachine Gun'] as $text) {
            $event['payload']['text']=$text;
            self::assertSame('named',synth_player_gift_statement_kind($text,'Marcy'));
            self::assertFalse(synth_gift_menu_request_allowed($event,$db));
        }
    }

    public function testMenuClosureProducesOnlyConservedActualGifts(): void {
        [$event,$action,$context,$result]=$this->fixture();
        synth_gift_menu_validate($event,$action,$context);
        $gift=synth_gift_menu_proof($event,$action,$context,$result);
        self::assertSame('synth.player-gift-batch.v1',$gift['schema']);self::assertSame('gift',$gift['meaning']);
        self::assertSame('recipient_decides',$gift['valuation']);self::assertCount(2,$gift['items']);
        $counts=array_column($gift['items'],'amount');sort($counts);self::assertSame([1,20],$counts);
        self::assertSame([],$gift['received_from_npc']);
        $empty=$result;$empty['inventory']['items']=$context['payload']['actor_states'][0]['inventory'];$empty['recipient_inventory']['items']=[];
        self::assertNull(synth_gift_menu_proof($event,$action,$context,$empty),'Opening/closing without giving anything is not a gift.');
    }

    public function testGiftMenuRejectsMissingCapabilitiesAndUnownedOrUnverifiedEvidence(): void {
        foreach (range(0,13) as $bad) {
            [$event,$action,$context,$result]=$this->fixture();
            if($bad===0)$event['runtime_variant']='vr';
            if($bad===1)$event['capabilities']=array_values(array_diff($event['capabilities'],['action.gift_menu_inventory']));
            if($bad===2)$event['type']='trigger';
            if($bad===3)$event['payload']['text']='I have a gift for you, a pistol';
            if($bad===4)$action['arguments']=['item'=>'Submachine Gun'];
            if($bad===5)$action['actor']=$context['player'];
            if($bad===6)$action['target']=$context['target'];
            if($bad===7)$context['payload']['actor_states'][0]['inventory_observation']='partial';
            if($bad===8)$result['status']='cancelled';
            if($bad===9)$result['inventory']['observation']='unavailable';
            if($bad===10)$result['recipient_inventory']['items'][1]['count']=21;
            if($bad===11)$result['recipient_inventory']['actor']['playthrough_id']='other-save';
            if($bad===12)$result['schema']='synth.action.result.v2';
            if($bad===13)$event['payload']['control']['mode']='NARRATOR';
            self::assertNull(synth_gift_menu_proof($event,$action,$context,$result),'case '.$bad);
        }
    }

    public function testNativeMenuManifestRequiresItsOwnDependencies(): void {
        [$event]=$this->fixture();self::assertTrue(synth_protocol_supports_gift_menu($event));
        foreach ($event['capabilities'] as $capability) {
            $bad=$event;$bad['capabilities']=array_values(array_diff($event['capabilities'],[$capability]));self::assertFalse(synth_protocol_supports_gift_menu($bad),$capability);
        }
    }
}

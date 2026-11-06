<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/lib/synth_jev_actions.php';

/** Metadata only: no database, provider, logger file, or game operation. */
final class JevDecisionTraceTest extends TestCase
{
    private function event(): array
    {
        return ['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'input_text',
            'session_id'=>'private-session-fixture','request_id'=>'private-request-fixture',
            'turn_id'=>'private-turn-fixture','generation'=>7,'context_sequence'=>12,
            'payload'=>['text'=>'PRIVATE-UTTERANCE','target'=>['display_name'=>'PRIVATE-NPC-NAME']]];
    }

    public function testDecisionTagIsStableAndScopedToSessionGenerationAndRequest(): void
    {
        $event=$this->event();
        self::assertSame('2157d008eb2d26b1',synth_jev_action_decision_tag($event));
        foreach (['session_id'=>'different-session','request_id'=>'different-request','generation'=>8] as $key=>$value) {
            $other=synth_jev_action_decision_tag(array_replace($event,[$key=>$value]));
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D',$other);
            self::assertNotSame('2157d008eb2d26b1',$other);
        }
        $event['payload']['text']='Another private utterance';
        self::assertSame('2157d008eb2d26b1',synth_jev_action_decision_tag($event),
            'The correlation tag identifies ownership, not a hash of private utterance content');
    }

    public function testUnknownOwnerCannotAcquireAValidDecisionTag(): void
    {
        foreach ([[],['session_id'=>'s','request_id'=>'r','generation'=>0],
            ['session_id'=>'s','request_id'=>'r','generation'=>'7'],
            ['session_id'=>[],'request_id'=>'r','generation'=>7]] as $event) {
            self::assertSame('',synth_jev_action_decision_tag($event));
        }
    }

    public function testSelectedMetadataHasOnlyReviewedFieldsAndBoundedConfidence(): void
    {
        $metadata=synth_jev_action_trace_metadata('selected',$this->event(),[
            'selected_code'=>'WaitHere','confidence'=>0.94,'elapsed_ms'=>42.5,'budget_ms'=>350,
            'status'=>'selected','reason'=>'selected']);
        self::assertSame(['stage'=>'selected','decision_tag'=>'2157d008eb2d26b1','generation'=>7,
            'context_sequence'=>12,'selected_code'=>'WaitHere','confidence'=>0.94,'elapsed_ms'=>42.5,
            'budget_ms'=>350,'status'=>'selected','reason'=>'selected'],$metadata);
        self::assertArrayNotHasKey('verified',$metadata);
    }

    public function testPrivateIdentifiersNamesUtterancesProviderBodiesAndKeysNeverEnterPayload(): void
    {
        $event=$this->event();
        $details=['selected_code'=>'ReleaseWait','confidence'=>0.99,'action_id'=>'private-action-fixture',
            'session_id'=>$event['session_id'],'request_id'=>$event['request_id'],'turn_id'=>$event['turn_id'],
            'actor'=>['form_id'=>'0x0001CA7D','display_name'=>'PRIVATE-NPC-NAME'],
            'utterance'=>'PRIVATE-UTTERANCE','provider_body'=>'PRIVATE-PROVIDER-BODY',
            'api_key'=>'PRIVATE-PROVIDER-KEY','detail'=>'PRIVATE-EXCEPTION-TEXT',
            'utterance_sha256'=>str_repeat('a',64)];
        $metadata=synth_jev_action_trace_metadata('dispatch',$event,$details);
        self::assertSame('2bff6d53a5d1d428',$metadata['action_tag']);
        $wire=json_encode($metadata,JSON_THROW_ON_ERROR);
        foreach (['private-session-fixture','private-request-fixture','private-turn-fixture','private-action-fixture',
            '0x0001CA7D','PRIVATE-NPC-NAME','PRIVATE-UTTERANCE','PRIVATE-PROVIDER-BODY',
            'PRIVATE-PROVIDER-KEY','PRIVATE-EXCEPTION-TEXT',str_repeat('a',64)] as $private) {
            self::assertStringNotContainsString($private,$wire);
        }
        self::assertSame(['stage','decision_tag','generation','context_sequence','selected_code','confidence','action_tag'],array_keys($metadata));
    }

    public function testInvalidValuesAreOmittedRatherThanCoercedOrEchoed(): void
    {
        $base=['stage'=>'selected','decision_tag'=>'2157d008eb2d26b1','generation'=>7,'context_sequence'=>12];
        foreach ([NAN,INF,-0.01,1.01,'0.99',true,[],null] as $confidence) {
            self::assertSame($base,synth_jev_action_trace_metadata('selected',$this->event(),['confidence'=>$confidence]));
        }
        foreach (['RunShell','PRIVATE-NPC-NAME','wait_here',['WaitHere']] as $code) {
            self::assertSame($base,synth_jev_action_trace_metadata('selected',$this->event(),['selected_code'=>$code]));
        }
        foreach (['status'=>'PRIVATE-STATUS','reason'=>'PRIVATE-PROVIDER-BODY','verified'=>'true',
            'owned_hold'=>1,'stationary'=>[],'action_id'=>"private\nidentity"] as $key=>$value) {
            self::assertSame($base,synth_jev_action_trace_metadata('selected',$this->event(),[$key=>$value]));
        }
        foreach ([0,751,350.5,'350',true] as $budget) {
            self::assertSame($base,synth_jev_action_trace_metadata('selected',$this->event(),['budget_ms'=>$budget]));
        }
        foreach (['RunShell','PRIVATE-NPC-NAME','invented_action',[]] as $code) {
            self::assertSame($base,synth_jev_action_trace_metadata('selected',$this->event(),['action_code'=>$code]));
        }
        foreach (['PRIVATE-ROUTE','provider-body',1,[]] as $route) {
            self::assertSame($base,synth_jev_action_trace_metadata('selected',$this->event(),['route'=>$route]));
        }
        foreach (['WaitHere','wait_here','release_wait','inspect_actor'] as $code) {
            self::assertSame($code,synth_jev_action_trace_metadata('selected',$this->event(),['action_code'=>$code])['action_code']);
        }
        foreach (['jev_handoff','jev_narrowed','ordinary_llm'] as $route) {
            self::assertSame($route,synth_jev_action_trace_metadata('selected',$this->event(),['route'=>$route])['route']);
        }
    }

    public function testInvalidStageAndUnboundedCorrelationNumbersDoNotProduceTrustedMetadata(): void
    {
        self::assertSame([],synth_jev_action_trace_metadata('PRIVATE-STAGE',$this->event(),['provider_body'=>'PRIVATE-BODY']));
        foreach ([-1,'12',true,9007199254740992] as $number) {
            $metadata=synth_jev_action_trace_metadata('selected',array_replace($this->event(),['context_sequence'=>$number]));
            self::assertArrayNotHasKey('context_sequence',$metadata);
        }
    }

    public function testSelectionDispatchAndHandoffDoNotInventExecutionOrPhysicalSuccess(): void
    {
        foreach ([['selected','selected'],['dispatch','pending'],['dispatch','dispatched'],['handoff','complete']] as [$stage,$status]) {
            $metadata=synth_jev_action_trace_metadata($stage,$this->event(),[
                'selected_code'=>'WaitHere','action_id'=>'private-action-fixture','status'=>$status,
                'verified'=>true,'owned_hold'=>true,'stationary'=>true]);
            self::assertSame($stage,$metadata['stage']);
            self::assertSame($status,$metadata['status']);
            foreach (['verified','owned_hold','stationary','executed','goal_pass'] as $unproven) {
                self::assertArrayNotHasKey($unproven,$metadata);
            }
        }
    }

    public function testFailedOrUnverifiedResultsStayExplicitAndDoNotBecomePhysicalSuccess(): void
    {
        foreach (['failed','rejected','timed_out','unavailable','cancelled','succeeded'] as $status) {
            $metadata=synth_jev_action_trace_metadata('result',$this->event(),[
                'status'=>$status,'reason'=>'unverified','verified'=>false,'owned_hold'=>false,'stationary'=>false]);
            self::assertSame($status,$metadata['status']);
            self::assertFalse($metadata['verified']);
            self::assertFalse($metadata['owned_hold']);
            self::assertFalse($metadata['stationary']);
        }
        $metadata=synth_jev_action_trace_metadata('result',$this->event(),['status'=>'succeeded']);
        self::assertArrayNotHasKey('verified',$metadata,'A status word is not the native physical receipt');
        foreach (['failed','rejected','timed_out','unavailable','cancelled','complete','pending'] as $status) {
            $metadata=synth_jev_action_trace_metadata('result',$this->event(),['status'=>$status,'verified'=>true]);
            self::assertFalse($metadata['verified'],'Non-success result cannot acquire verified=true');
        }
    }
}

<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/lib/synth_protocol_state.php';
require_once dirname(__DIR__, 2).'/lib/request.php';
require_once dirname(__DIR__, 2).'/lib/synth_jev_actions.php';

final class JevActionTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['JEV_ACTIONS_ENABLED','SYNTH_JEV_ACTION_POLICY','SYNTH_JEV_ACTION_DIAGNOSTIC',
            'SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','FUNCTIONS_ARE_ENABLED','CORE_CONNECTOR_DECISION'] as $name) {
            $this->saved[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
            unset($GLOBALS[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name=>[$exists,$value]) {
            unset($GLOBALS[$name]);
            if ($exists) $GLOBALS[$name] = $value;
        }
    }

    public function testDisabledAndIneligiblePathsDoNotReadContextOrCallProvider(): void
    {
        $db = new class { public function __call($name, $arguments) { throw new LogicException('Unexpected DB access'); } };
        $call = static function () { throw new LogicException('Unexpected provider/connector access'); };
        foreach ([false, 'false', 0, 'off', null] as $value) {
            $GLOBALS['JEV_ACTIONS_ENABLED'] = $value;
            synth_jev_prepare_actions($db, ['type'=>'input_text'], null, $call, $call);
            $diagnostic=$GLOBALS['SYNTH_JEV_ACTION_DIAGNOSTIC'];
            $this->assertSame('disabled',$diagnostic['reason']);
            $this->assertSame(0,$diagnostic['elapsed_ms']);
            $this->assertNull($diagnostic['selected_code']);$this->assertNull($diagnostic['confidence']);
            $this->assertSame('ordinary_llm_actions',$diagnostic['fallback_behavior']);
        }
        $GLOBALS['JEV_ACTIONS_ENABLED'] = true;
        foreach (['trigger','action_result','context','halt'] as $type) {
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
            synth_jev_prepare_actions($db, ['type'=>$type], null, $call, $call);
            $this->assertSame('ineligible', $GLOBALS['SYNTH_JEV_ACTION_DIAGNOSTIC']['reason']);
            $this->assertNull(synth_jev_action_policy());
        }
        synth_jev_prepare_actions($db, ['type'=>'input_text','payload'=>['control'=>['mode'=>'NARRATOR']]], null, $call, $call);
        $this->assertSame('ineligible', $GLOBALS['SYNTH_JEV_ACTION_DIAGNOSTIC']['reason']);
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['CORE_CONNECTOR_DECISION'] = 0;
        synth_jev_prepare_actions($db, ['protocol_version'=>2,'runtime_variant'=>'flat','type'=>'input_text',
            'capabilities'=>['dialogue.turn_ownership']], null, null, $call);
        $this->assertSame('not_configured', $GLOBALS['SYNTH_JEV_ACTION_DIAGNOSTIC']['reason']);
    }

    public function testChoiceCannotInventActionAndMalformedOrUncertainAnswersAbstain(): void
    {
        $criteria = ['WaitHere'=>'Wait', 'Talk'=>'Talk', 'abstain'=>'Unclear'];
        $answer = ['type'=>'choice', 'choice'=>'WaitHere', 'confidence'=>0.95];
        $this->assertSame('WaitHere', synth_jev_action_answer(['answers'=>['action'=>$answer]], $criteria, 0.8));
        $this->assertSame(['code'=>'WaitHere','confidence'=>0.95],synth_jev_action_answer_metadata(['answers'=>['action'=>$answer]],$criteria,0.8));
        foreach ([['choice'=>'RunShell'], ['choice'=>'abstain'], ['type'=>'score'], ['confidence'=>0.79],
            ['confidence'=>-1], ['confidence'=>1.01], ['confidence'=>'0.99'], ['confidence'=>NAN], ['choice'=>['WaitHere']]] as $change) {
            $this->assertNull(synth_jev_action_answer(['answers'=>['action'=>array_replace($answer,$change)]], $criteria, 0.8));
        }
        foreach ([[], ['answers'=>[]], ['answers'=>['action'=>'WaitHere']]] as $reply)
            $this->assertNull(synth_jev_action_answer($reply, $criteria, 0.8));
    }

    private function selectedActor(string $form='0x00000015',string $save='synthetic-save'): array
    {
        return ['form_id'=>$form,'origin_plugin'=>'Fallout4.esm','playthrough_id'=>$save,'display_name'=>'Synthetic same name'];
    }

    public function testMinimalStateContainsOnlyExactSelectedActorFreshFacts(): void
    {
        $actor=$this->selectedActor();$other=$this->selectedActor('0x00000016');
        $state=['actor'=>$actor,'teammate'=>true,'alive'=>true,'disabled'=>false,'in_combat'=>false,
            'hostile_to_player'=>false,'line_of_sight'=>'visible','life_state'=>'alive','wait_held'=>false,
            'inventory'=>['PRIVATE-INVENTORY'],'enemy_awareness'=>['PRIVATE-BYSTANDER'],'history'=>'PRIVATE-HISTORY'];
        $context=['target'=>$actor,'payload'=>['actor_states'=>[['actor'=>$other,'teammate'=>false,'in_combat'=>true],$state],
            'audience'=>[$other],'history'=>'PRIVATE-HISTORY']];
        $captured=synth_jev_action_actor_state(['capabilities'=>['context.wait_state']],$context,$actor);
        $this->assertSame(['captured'=>true,'teammate'=>true,'alive'=>true,'disabled'=>false,'in_combat'=>false,
            'hostile_to_player'=>false,'line_of_sight'=>'visible','life_state'=>'alive','wait_held'=>false],$captured);
        $this->assertStringNotContainsString('PRIVATE',json_encode($captured));
        $this->assertStringNotContainsString('Synthetic same name',json_encode($captured));
        $this->assertArrayNotHasKey('actor',$captured);
        $this->assertNull(synth_jev_action_actor_state(['capabilities'=>[]],$context,$actor)['wait_held']);
        $state['wait_held']=true;$context['payload']['actor_states']=[$state];
        $this->assertTrue(synth_jev_action_actor_state(['capabilities'=>['context.wait_state']],$context,$actor)['wait_held']);
        unset($state['wait_held']);$context['payload']['actor_states']=[$state];
        $this->assertNull(synth_jev_action_actor_state(['capabilities'=>['context.wait_state']],$context,$actor)['wait_held']);
    }

    public function testMissingAmbiguousForeignAndMalformedActorFramesRemainUnknown(): void
    {
        $actor=$this->selectedActor();$base=['actor'=>$actor,'teammate'=>true,'alive'=>true];
        foreach([[],[$base,$base],[array_replace($base,['actor'=>$this->selectedActor('0x00000016')])],
            [array_replace($base,['actor'=>$this->selectedActor('0x00000015','foreign-save')])],array_fill(0,17,$base)] as $states) {
            $state=synth_jev_action_actor_state([],['target'=>$actor,'payload'=>['actor_states'=>$states]],$actor);
            $this->assertFalse($state['captured']);$this->assertNull($state['teammate']);$this->assertNull($state['wait_held']);
        }
        $this->assertFalse(synth_jev_action_actor_state([],['target'=>$this->selectedActor('0x00000016'),'payload'=>['actor_states'=>[$base]]],$actor)['captured']);
        $base+=['disabled'=>'false','in_combat'=>0,'line_of_sight'=>'PRIVATE-NAME','life_state'=>'PRIVATE-NAME','wait_held'=>'true'];
        $state=synth_jev_action_actor_state(['capabilities'=>['context.wait_state']],['target'=>$actor,'payload'=>['actor_states'=>[$base]]],$actor);
        $this->assertTrue($state['captured']);$this->assertNull($state['disabled']);$this->assertNull($state['in_combat']);
        $this->assertNull($state['wait_held']);$this->assertSame('unavailable',$state['line_of_sight']);$this->assertSame('unknown',$state['life_state']);
    }

    public function testDecisionTagIsStablePrivateAndSeparatesRequestGenerationAndSession(): void
    {
        $event=['session_id'=>'synthetic-session','request_id'=>'synthetic-request','generation'=>3,'context_sequence'=>7,'payload'=>['text'=>'PRIVATE-UTTERANCE']];
        $tag=synth_jev_action_decision_tag($event);
        $this->assertSame(substr(hash('sha256','synthetic-session|3|synthetic-request'),0,16),$tag);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D',$tag);
        $changed=$event;$changed['payload']['text']='Different utterance';$this->assertSame($tag,synth_jev_action_decision_tag($changed));
        foreach(['session_id'=>'other-session','request_id'=>'other-request','generation'=>4] as $field=>$value)
            $this->assertNotSame($tag,synth_jev_action_decision_tag(array_replace($event,[$field=>$value])));
        foreach([[],array_replace($event,['request_id'=>'PRIVATE\nKEY']),array_replace($event,['generation'=>0])] as $invalid)
            $this->assertSame('',synth_jev_action_decision_tag($invalid));
    }

    public function testChoiceIsScopedToExactRequestAndOnlyOneOwnedActionMayEmit(): void
    {
        $event = ['session_id'=>'a','request_id'=>'b','generation'=>1,'turn_id'=>'c','context_sequence'=>2,'payload'=>['text'=>'wait']];
        $GLOBALS['SYNTH_REQUEST_EVENT']['json'] = $event;
        $GLOBALS['SYNTH_JEV_ACTION_POLICY'] = ['owner'=>synth_jev_action_owner($event), 'code'=>'WaitHere', 'emitted'=>false];
        $this->assertTrue(synth_jev_action_code_allowed('WaitHere'));
        $this->assertFalse(synth_jev_action_code_allowed('Follow'));
        $GLOBALS['SYNTH_JEV_ACTION_POLICY']['emitted'] = true;
        $this->assertFalse(synth_jev_action_code_allowed('WaitHere'));
        foreach (['session_id'=>'other','request_id'=>'other','generation'=>2,'turn_id'=>'other',
            'context_sequence'=>3,'payload'=>['text'=>'follow']] as $name=>$value) {
            $GLOBALS['SYNTH_REQUEST_EVENT']['json'] = array_replace($event, [$name=>$value]);
            $this->assertNull(synth_jev_action_policy());
            $this->assertTrue(synth_jev_action_code_allowed('Follow'), 'A different request retains ordinary routing');
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('httpCases')]
    public function testActualTransportBoundsErrorsBodiesAndSlowResponses(string $path, ?string $reason): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $directory = sys_get_temp_dir().'/synth-jev-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $router = $directory.'/router.php';
        file_put_contents($router, <<<'PHP'
<?php
switch ($_SERVER['REQUEST_URI']) {
    case '/slow_headers': usleep(400000); break;
    case '/slow_body': header('Content-Type: application/json'); echo '{'; ob_flush(); flush(); usleep(400000); break;
    case '/redirect': header('Location: /success', true, 302); exit;
    case '/http_error': http_response_code(429); echo 'synthetic-key must not be logged'; exit;
    case '/oversized': echo str_repeat('x', 16385); exit;
    case '/malformed': echo '{bad'; exit;
}
header('Content-Type: application/json');
echo json_encode(['answers'=>['action'=>['type'=>'choice','choice'=>'WaitHere','confidence'=>0.95]]]);
PHP);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', $address, $router],
            [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
        $this->assertIsResource($process);
        try {
            fclose($pipes[0]);
            $ready = false;
            for ($i=0; $i<100; ++$i) {
                $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.01);
                if ($probe) { fclose($probe); $ready = true; break; }
                usleep(10000);
            }
            $this->assertTrue($ready, 'Loopback fixture must start');
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = false;
            $start = hrtime(true);
            $caught = null;
            try {
                $reply = synth_jev_action_http(['model'=>'fixture','state'=>'synthetic'], 'synthetic-key', 50, 'http://'.$address.'/'.$path);
                $this->assertSame('WaitHere', $reply['answers']['action']['choice']);
            } catch (Throwable $error) { $caught = $error; }
            $elapsed = (hrtime(true) - $start) / 1000000;
            if ($reason === null) $this->assertNull($caught);
            elseif ($reason === 'malformed') $this->assertInstanceOf(JsonException::class, $caught);
            else $this->assertSame($reason, $caught?->getMessage());
            $this->assertLessThan(250, $elapsed, 'The real transport must not wait for a stalled provider');
            fwrite(STDERR, sprintf("JEV fixture %s: %.2f ms (budget 50 ms)\n", $path, $elapsed));
        } finally {
            proc_terminate($process);
            proc_close($process);
            unlink($router);
            rmdir($directory);
        }
    }

    public static function httpCases(): array
    {
        return ['success'=>['success',null], 'headers stalled'=>['slow_headers','timeout'],
            'body stalled'=>['slow_body','timeout'], 'no redirect'=>['redirect','http_error'],
            'HTTP failure'=>['http_error','http_error'], 'size cap'=>['oversized','oversized'], 'bad JSON'=>['malformed','malformed']];
    }
}

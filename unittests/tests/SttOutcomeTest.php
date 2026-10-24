<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SttOutcomeTest extends TestCase
{
    public static function responses(): array
    {
        return [
            'empty text success'=>[200,'{"text":""}','speech_not_recognized',null],
            'whitespace transcription success'=>[201,"{\"transcription\":\" \\n\\t \"}",'speech_not_recognized',null],
            'first field remains authoritative'=>[200,'{"text":"","transcription":"Do not substitute this"}','speech_not_recognized',null],
            'short recognized speech'=>[200,'{"text":" Hi "}','continue','Hi'],
            'unicode recognized speech'=>[200,'{"transcription":" café "}','continue','café'],
            'maximum transcript'=>[200,json_encode(['text'=>str_repeat('x',4096)]),'continue',str_repeat('x',4096)],
            'oversized transcript'=>[200,json_encode(['text'=>str_repeat('x',4097)]),'stt_transcript_too_large',null],
            'http error empty'=>[400,'{"text":""}','stt_http_error',null],
            'http error text'=>[503,'{"text":"Not a transcript"}','stt_http_error',null],
            'invalid json'=>[200,'<html>private-response-marker</html>','stt_invalid_response',null],
            'missing fields'=>[200,'{"error":"private-response-marker"}','stt_invalid_response',null],
            'number field'=>[200,'{"text":0}','stt_invalid_response',null],
            'boolean field'=>[200,'{"text":false}','stt_invalid_response',null],
            'array field'=>[200,'{"text":[]}','stt_invalid_response',null],
            'object field'=>[200,'{"text":{}}','stt_invalid_response',null],
            'null field'=>[200,'{"text":null}','stt_invalid_response',null],
            'wrong first field'=>[200,'{"text":false,"transcription":"Do not coerce"}','stt_invalid_response',null],
            'transport failure'=>[0,'','stt_transport_failed',null],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('responses')]
    public function testActualAudioBranchStopsWithoutWorkOnlyForRecognizedEmptySuccess(int $status,string $body,string $expected,?string $text): void
    {
        $result=$this->runAudio($status,$body);
        $this->assertSame(1,$result['diagnostic']['http_calls']);
        $this->assertSame(0,$result['diagnostic']['temporary_files_left']);
        $this->assertTrue($result['diagnostic']['canonical_unchanged']);
        $this->assertSame(['request:stt','turn:stt',2,7],$result['diagnostic']['owner']);
        if ($expected==='continue') {
            $this->assertSame(['continue'=>$text],json_decode($result['stdout'],true,flags:JSON_THROW_ON_ERROR));
            $this->assertSame([], $result['diagnostic']['completions']);
            return;
        }
        $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),explode("\n",trim($result['stdout'])));
        $this->assertSame(['response_start','status','response_end'],array_column($lines,'type'));
        $this->assertSame($expected==='speech_not_recognized'?'speech_not_recognized':'stt_failed',$lines[1]['payload']['code']);
        $this->assertSame($expected==='speech_not_recognized'?'complete':'error',$lines[2]['payload']['status']);
        if ($expected!=='speech_not_recognized') $this->assertSame($expected,$lines[1]['payload']['detail']);
        foreach ($lines as $line) {
            $this->assertSame('request:stt',$line['request_id']);
            $this->assertSame('turn:stt',$line['turn_id']);
            $this->assertSame(2,$line['generation']);
            $this->assertSame(7,$line['context_sequence']);
        }
        $this->assertSame(0,$result['diagnostic']['later_work']);
        $this->assertCount(1,$result['diagnostic']['completions']);
        $this->assertStringContainsString($expected==='speech_not_recognized'?"state='complete'":"state='failed'",$result['diagnostic']['completions'][0]);
        $this->assertStringNotContainsString('private-response-marker',$result['stderr']);
        $this->assertStringNotContainsString('private-response-marker',$result['stdout']);
        $this->assertStringNotContainsString('http://',$result['stdout']);
    }

    public function testCancellationAfterEmptyTranscriptionCannotAcknowledgeOrRunWork(): void
    {
        $result=$this->runAudio(200,'{"text":""}',true);
        $this->assertSame('cancelled',$result['stdout']);
        $this->assertSame(0,$result['diagnostic']['later_work']);
        $this->assertSame([],$result['diagnostic']['completions']);
        $this->assertSame(0,$result['diagnostic']['temporary_files_left']);
    }

    public function testOtherConnectorEmptyCannotReusePreviousLocalWhisperSuccess(): void
    {
        $result=$this->runAudio(200,'{"text":""}',false,true);
        $this->assertSame(0,$result['diagnostic']['http_calls']);
        $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),explode("\n",trim($result['stdout'])));
        $this->assertSame('stt_failed',$lines[1]['payload']['code']);
        $this->assertSame('stt_empty_transcript',$lines[1]['payload']['detail']);
        $this->assertSame('error',$lines[2]['payload']['status']);
        $this->assertSame([],$result['diagnostic']['stt_diagnostic']);
    }

    public function testAdapterEarlyFailureClearsPreviousSuccessfulEmptyDiagnostic(): void
    {
        $root=dirname(__DIR__,2);
        $script='function synthRuntimeBootstrapIfNeeded(...$args): void {} require '.var_export($root.'/stt/stt-localwhisper.php',true).';'. <<<'PHP'
$result=[];
foreach(['config','input'] as $stage) {
    $GLOBALS['STT_LAST_DIAGNOSTIC']=['driver'=>'localwhisper','stage'=>'recognized_empty','http_status_code'=>200,'response_key'=>'text','response_value_type'=>'string'];
    $GLOBALS['STT']=['LOCALWHISPER'=>['URL'=>$stage==='config'?'':'http://fixture.invalid:9876/api/v0/transcribe']];
    $value=stt('/nonexistent-synthetic-audio.wav');
    $result[]=[$value,$GLOBALS['STT_LAST_DIAGNOSTIC']];
}
echo json_encode($result);
PHP;
        $process=proc_open([PHP_BINARY,'-d','allow_url_fopen=0','-d','disable_functions=curl_init','-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $this->assertIsResource($process);fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $this->assertSame(0,proc_close($process),$stderr);
        $result=json_decode($stdout,true,flags:JSON_THROW_ON_ERROR);
        foreach(['config','input'] as $index=>$stage) {
            $this->assertSame('',$result[$index][0]);$this->assertSame($stage,$result[$index][1]['stage']);
            $this->assertArrayNotHasKey('http_status_code',$result[$index][1]);
            $this->assertArrayNotHasKey('response_value_type',$result[$index][1]);
        }
    }

    /** Synthetic WAV, fake loopback HTTP, real connector/media pipeline/response emitters and production audio branch. */
    private function runAudio(int $status,string $body,bool $cancel=false,bool $other=false): array
    {
        $root=dirname(__DIR__,2);
        $fixture=sys_get_temp_dir().'/synth-stt-outcome-'.bin2hex(random_bytes(8));
        mkdir($fixture.'/lib',0700,true);mkdir($fixture.'/stt');mkdir($fixture.'/soundcache');
        foreach (['lib/synth_stt_pipeline.php','stt/stt-localwhisper.php'] as $file) copy($root.'/'.$file,$fixture.'/'.$file);
        foreach (['media_store.php','provider_stream.php'] as $file) file_put_contents($fixture.'/lib/'.$file,'<?php require_once '.var_export($root.'/lib/'.$file,true).';');
        file_put_contents($fixture.'/lib/runtime_bootstrap.php','<?php function synthRuntimeBootstrapIfNeeded(...$args): void {}');
        if ($other) file_put_contents($fixture.'/stt/stt-fixture.php','<?php function stt($file) { return ""; }');
        $pipeline=file_get_contents($root.'/main_synth_pipeline.php');
        $start=strpos($pipeline,'    $transcript = null;');
        $end=strpos($pipeline,"    if (in_array(\$wireEvent['type'] ?? '', ['input_text', 'input_audio'], true))",$start);
        $this->assertIsInt($start);$this->assertIsInt($end);
        file_put_contents($fixture.'/audio-branch.php',"<?php\n".substr($pipeline,$start,$end-$start));
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        $this->assertIsResource($socket,$error);
        $url='http://'.stream_socket_get_name($socket,false).'/api/v0/transcribe';
        $bootstrap='$root='.var_export($root,true).';$fixture='.var_export($fixture,true).';$url='.var_export($url,true).';$cancel='.var_export($cancel,true).';$other='.var_export($other,true).';'. <<<'PHP'
require $root.'/lib/response.php';
require $fixture.'/lib/synth_stt_pipeline.php';
$pcm=str_repeat("\x00\x00",160);
$wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',strlen($pcm)).$pcm;
$published=synth_media_publish_bytes($fixture,$wav,'audio/wav');
$media=array_intersect_key($published,array_flip(['media_id','sha256','content_type','size']));
$wireEvent=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'input_audio','request_id'=>'request:stt','turn_id'=>'turn:stt','generation'=>2,'context_sequence'=>7,'session_id'=>'session:stt','runtime_variant'=>'flat','capabilities'=>['dialogue.turn_ownership'],'payload'=>['media'=>$media]];
$original=json_encode($wireEvent);
$GLOBALS['SYNTH_REQUEST_EVENT']=$wireEvent+['json'=>$wireEvent];
$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
$GLOBALS['STTFUNCTION']=$other?'fixture':'localwhisper';
$GLOBALS['STT']=['LOCALWHISPER'=>['URL'=>$url,'FORMFIELD'=>'file','TIMEOUT'=>5]];
$GLOBALS['STT_LAST_DIAGNOSTIC']=['driver'=>'localwhisper','stage'=>'recognized_empty','http_status_code'=>200,'response_key'=>'text','response_value_type'=>'string'];
$GLOBALS['later_work']=0;
$db=new class($published,$cancel) {
    public array $completions=[];
    public function __construct(private array $published,private bool $cancel) {}
    public function escapeLiteral($v): string {return "'".str_replace("'","''",strval($v))."'";}
    public function execQuery($sql) {if(str_contains($sql,'UPDATE synth_meta.request_executions'))$this->completions[]=$sql;return true;}
    public function fetchOne($sql) {
        if(str_contains($sql,'FROM synth_meta.media_objects'))return ['state'=>'ready','content_type'=>'audio/wav','byte_length'=>$this->published['size'],'sha256'=>$this->published['sha256']];
        $cancelled=$this->cancel && ($GLOBALS['STT_LAST_DIAGNOSTIC']['stage']??'')==='recognized_empty';
        return ['state'=>$cancelled?'cancelled':'claimed','error_code'=>$cancelled?'turn_cancelled':null,'runtime_state'=>'active','active_generation'=>2,'active_turn_id'=>'turn:stt','active_turn_sequence'=>7];
    }
};
$GLOBALS['db']=$db;
$path=$fixture.'/';
register_shutdown_function(static function() use($db,$fixture,$wireEvent,$original) {
    $diag=['later_work'=>$GLOBALS['later_work'],'completions'=>$db->completions,'temporary_files_left'=>count(glob($fixture.'/soundcache/_synth-stt-*')),'canonical_unchanged'=>json_encode($wireEvent)===$original,'owner'=>[$wireEvent['request_id'],$wireEvent['turn_id'],$wireEvent['generation'],$wireEvent['context_sequence']],'stt_diagnostic'=>$GLOBALS['STT_LAST_DIAGNOSTIC']??[]];
    fwrite(STDERR,"\nSTT_TEST_RESULT:".json_encode($diag)."\n");
});
try { require $fixture.'/audio-branch.php'; }
catch(SYNTHProtocolCancelled) { echo 'cancelled';exit; }
// Canary for every later binder/model/action/TTS path: the production audio branch must exit first.
$GLOBALS['later_work']++;
echo json_encode(['continue'=>$transcript]);
PHP;
        $process=proc_open([PHP_BINARY,'-d','display_errors=stderr','-r',$bootstrap],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $this->assertIsResource($process);fclose($pipes[0]);
        $calls=0;
        try {
            if (!$other) {
                $connection=stream_socket_accept($socket,5);
                $this->assertIsResource($connection,'The actual connector did not call the fake HTTP endpoint.');
                stream_set_timeout($connection,5);
                $headers='';
                while (!str_contains($headers,"\r\n\r\n")) {$chunk=fread($connection,1);if($chunk==='')break;$headers.=$chunk;}
                preg_match('/Content-Length:\s*(\d+)/i',$headers,$length);
                $remaining=(int)($length[1]??0);$upload='';
                while($remaining>0){$chunk=fread($connection,$remaining);if($chunk==='')break;$upload.=$chunk;$remaining-=strlen($chunk);}
                $this->assertStringStartsWith('POST /api/v0/transcribe HTTP/',$headers);
                $this->assertStringContainsString('name="file"; filename="',$upload);
                $this->assertStringContainsString('Content-Type: audio/wav',$upload);
                $this->assertStringContainsString('RIFF',$upload);
                $calls++;
                if ($status>0) fwrite($connection,"HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
                fclose($connection);
            }
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            $exit=proc_close($process);$process=null;
            $this->assertSame(0,$exit,$stderr);
            $this->assertMatchesRegularExpression('/STT_TEST_RESULT:(\{.*\})/',$stderr);
            preg_match('/STT_TEST_RESULT:(\{.*\})/',$stderr,$match);
            $diagnostic=json_decode($match[1],true,flags:JSON_THROW_ON_ERROR);$diagnostic['http_calls']=$calls;
            return compact('stdout','stderr','diagnostic');
        } finally {
            fclose($socket);
            if(is_resource($process)) {proc_terminate($process);proc_close($process);}
            $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($entries as $entry) {if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}
            rmdir($fixture);
        }
    }
}

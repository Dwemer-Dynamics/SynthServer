<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResponseFailureTest extends TestCase
{
    private function response(string $before, string $after = ''): array
    {
        $root = dirname(__DIR__, 2);
        $code = 'require '.var_export($root.'/lib/response.php', true).';'
            .'$event=json_decode(file_get_contents('.var_export($root.'/protocol/v2/fixtures/valid/input-text.json', true).'),true);'
            .'$GLOBALS["SYNTH_REQUEST_EVENT"]=["json"=>$event]+$event;'
            .'$GLOBALS["SYNTH_RESPONSE_FORMAT"]="json";'
            .$before
            .'try {throw new RuntimeException("PRIVATE_PROMPT_AND_KEY");}'
            .'catch(Throwable $error) {synth_emit_failed_json_response();}'
            .$after;
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
            [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $diagnostics = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $diagnostics);
        $this->assertStringNotContainsString('PRIVATE_PROMPT_AND_KEY', $body);
        return array_map(static fn(string $line): array => json_decode($line, true, flags:JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($body)))));
    }

    public function testPreResponseFailureHasACompleteErrorEnvelope(): void
    {
        $lines = $this->response('');
        $this->assertSame(['response_start','status','response_end'], array_column($lines, 'type'));
        $this->assertSame(['code'=>'backend_request_failed'], $lines[1]['payload']);
        $this->assertSame(['status'=>'error'], $lines[2]['payload']);
        $this->assertSame($lines[0]['request_id'], $lines[2]['request_id']);
    }

    public function testStartedStreamIsClosedOnceWithoutADuplicateStart(): void
    {
        $lines = $this->response('synth_protocol_ensure_response_started();', 'synth_emit_failed_json_response();');
        $this->assertSame(['response_start','status','response_end'], array_column($lines, 'type'));
        $this->assertSame('error', $lines[2]['payload']['status']);
    }

    public function testFailureAfterACompleteResponseDoesNotReopenIt(): void
    {
        $lines = $this->response('synth_protocol_ensure_response_started();'
            .'synth_protocol_write_response_line("response_end",["status"=>"complete"],"end");'
            .'$GLOBALS["SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED"]=true;');
        $this->assertSame(['response_start','response_end'], array_column($lines, 'type'));
        $this->assertSame('complete', $lines[1]['payload']['status']);
    }

    public function testLegacyBufferedOutputIsDiscardedOnFailure(): void
    {
        $lines = $this->response('ob_start();$GLOBALS["SYNTH_JSON_RESPONSE_BUFFER_LEVEL"]=ob_get_level();'
            .'echo "PRIVATE_PROMPT_AND_KEY";');
        $this->assertSame(['response_start','status','response_end'], array_column($lines, 'type'));
    }
}

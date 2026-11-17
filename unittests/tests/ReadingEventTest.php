<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/lib/request.php';
require_once dirname(__DIR__, 2).'/managers/BookManager/ReadingEvent.php';

use Synth\Managers\BookManager\ReadingEvent;

// Trust boundary of context.reading_event: closed shape, flat-only capability, bounded copied text.
final class ReadingEventTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/'.$name.'.json'),true,flags:JSON_THROW_ON_ERROR);
    }

    public function testValidFixturesPassIngressAndKeepTheirText(): void
    {
        foreach (['context-reading-event','context-reading-event-holotape-title-only'] as $name) {
            $event=$this->fixture($name);
            $this->assertSame($event,synth_protocol_validate_event($event),$name);
        }
        $this->assertSame("Dear Nate,\nThe key is under the mat.",$this->fixture('context-reading-event')['payload']['reading_event']['text']);
        $this->assertArrayNotHasKey('text',$this->fixture('context-reading-event-holotape-title-only')['payload']['reading_event']);
    }

    public function testInvalidFixturesAreRejectedAtIngress(): void
    {
        $root=dirname(__DIR__,2);
        $files=glob($root.'/protocol/v2/fixtures/invalid/context-reading-event-*.json');
        $this->assertGreaterThanOrEqual(6,count($files));
        foreach ($files as $file) {
            $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event(synth_protocol_decode_json(file_get_contents('.var_export($file,true).'))); echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,basename($file));
            $this->assertFalse(json_decode($output,true)['ok']??true,basename($file));
        }
    }

    public function testValidatorRejectsMalformedReadings(): void
    {
        $valid=$this->fixture('context-reading-event')['payload']['reading_event'];
        $this->assertSame($valid,ReadingEvent::validate($valid));
        $bad=[['kind'=>'scroll'],['event_id'=>'reading:0'],['event_id'=>'reading:9007199254740992'],['title'=>''],['title'=>"a\nb"],
            ['title'=>str_repeat('t',129)],['text'=>''],['text'=>str_repeat('x',4001)],['text'=>"a\x01b"],['text'=>"\xC3\x28"],
            ['text_truncated'=>1],['age_ms'=>-1],['age_ms'=>'5'],['source'=>['form_id'=>'0x00000000','origin_plugin'=>'Fallout4.esm']],
            ['source'=>['form_id'=>'0x0001F2A3','origin_plugin'=>'../Fallout4.esm']],['source'=>['form_id'=>'0x0001F2A3']],
            ['extra'=>true]];
        foreach ($bad as $change) {
            try {ReadingEvent::validate(array_replace($valid,$change));$this->fail('Accepted '.json_encode($change));}
            catch (DomainException $error) {$this->assertSame('invalid_reading_event',$error->getMessage());}
        }
        $noText=$valid;unset($noText['text']);
        $this->assertSame($noText,ReadingEvent::validate($noText));
        try {ReadingEvent::validate(array_replace($noText,['text_truncated'=>true]));$this->fail('Truncated flag without text');}
        catch (DomainException $error) {$this->assertSame('invalid_reading_event',$error->getMessage());}
        // The exact byte limit is inclusive and counted in characters, not bytes.
        $edge=array_replace($valid,['text'=>str_repeat("\u{00E9}",4000)]);
        $this->assertSame($edge,ReadingEvent::validate($edge));
    }

    public function testStatusAcknowledgementIsFlatOnlyAndCapabilityGated(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        $init=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/init-flat.json'),true,flags:JSON_THROW_ON_ERROR);
        $statusOf=function(array $event): array {
            $lines=array_map(fn($line)=>json_decode($line,true),array_filter(explode("\n",synth_protocol_encode_ack('init_accepted',[],$event))));
            foreach ($lines as $line) if (($line['type'] ?? '')==='status') return $line['payload'];
            $this->fail('No status line');
        };
        $this->assertArrayNotHasKey('reading_events',$statusOf($init));
        $with=$init;$with['capabilities'][]='context.reading_events';
        $this->assertTrue($statusOf($with)['reading_events']);
        $vr=$with;$vr['runtime_variant']='vr';
        $this->assertArrayNotHasKey('reading_events',$statusOf($vr));
    }
}

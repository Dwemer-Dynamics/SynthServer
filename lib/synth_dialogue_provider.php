<?php

require_once __DIR__.'/provider_stream.php';

// Stage 1 provider boundary: explicit config in, plain text deltas out. Credentials stay in memory.
interface SynthDialogueProvider
{
    /** @param callable(string):void $onText */
    public function stream(array $messages, callable $onText): void;
}

// Only plain OpenAI-compatible chat streaming is owned here. Reasoning, quickstart-managed and
// other drivers keep their legacy connector semantics and therefore stay on the legacy route.
function synth_dialogue_provider_supported(array $connector): bool
{
    $metadata=json_decode(strval($connector['metadata'] ?? '{}'),true);
    return ($connector['driver'] ?? '')==='openaijson'
        && !filter_var($connector['reasoning_model'] ?? false,FILTER_VALIDATE_BOOLEAN)
        && !filter_var(is_array($metadata) ? ($metadata['quickstart_managed'] ?? false) : false,FILTER_VALIDATE_BOOLEAN)
        && trim(strval($connector['url'] ?? ''))!=='' && trim(strval($connector['model'] ?? ''))!=='';
}

final class SynthOpenAiCompatibleProvider implements SynthDialogueProvider
{
    public function __construct(private array $config, private int $maxBytes=2*1024*1024) {}

    public function requestBody(array $messages): string
    {
        $body=['model'=>$this->config['model'],'messages'=>$messages,'stream'=>true,
            'max_tokens'=>max(16,min(2048,intval($this->config['max_tokens'])))];
        foreach (['temperature','top_p','presence_penalty','frequency_penalty'] as $key)
            if (isset($this->config[$key])) $body[$key]=$this->config[$key];
        return json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    public function stream(array $messages, callable $onText): void
    {
        $headers=['Content-Type: application/json','Accept: text/event-stream'];
        if ($this->config['api_key']!=='') $headers[]='Authorization: Bearer '.$this->config['api_key'];
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,
            'content'=>$this->requestBody($messages),'timeout'=>60]]);
        $stream=new SynthProviderStream($this->config['url'],$context,$this->maxBytes);
        try {
            synth_dialogue_parse_sse(static fn()=>$stream->readLine(),$onText);
            if ($stream->failed()) throw new RuntimeException('provider_failed');
        } finally { $stream->close(); }
    }
}

// A stream succeeds only with finish_reason "stop" or [DONE]. EOF, malformed chunks, other finish
// reasons and data after the terminal chunk fail; cancellation is checked at every provider line.
function synth_dialogue_parse_sse(callable $readLine, callable $onText): void
{
    $finished=false;
    while (($line=$readLine())!==false) {
        synth_protocol_assert_request_active();
        $line=trim(strval($line));
        if ($line==='' || str_starts_with($line,':')) continue;
        if (!str_starts_with($line,'data:')) {
            if (preg_match('/^(event|id|retry):/',$line)===1) continue;
            throw new RuntimeException('provider_stream_malformed');
        }
        $data=trim(substr($line,5));
        if ($data==='[DONE]') return;
        if ($finished) throw new RuntimeException('provider_stream_malformed');
        $chunk=json_decode($data,true);
        if (!is_array($chunk) || isset($chunk['error']) || !is_array($chunk['choices'] ?? null)) throw new RuntimeException('provider_stream_malformed');
        $choice=$chunk['choices'][0] ?? [];
        $delta=$choice['delta']['content'] ?? '';
        if (!is_string($delta)) throw new RuntimeException('provider_stream_malformed');
        if ($delta!=='') $onText($delta);
        $reason=$choice['finish_reason'] ?? null;
        if ($reason!==null) {
            if ($reason!=='stop') throw new RuntimeException('provider_stream_incomplete');
            $finished=true;
        }
    }
    if (!$finished) throw new RuntimeException('provider_stream_incomplete');
}

function synth_dialogue_provider_for(array $connector): SynthDialogueProvider
{
    // Tests install a controlled provider; production always builds from the stored connector.
    if (getenv('PHPUNIT_TEST') && ($GLOBALS['SYNTH_DIALOGUE_PROVIDER_OVERRIDE'] ?? null) instanceof SynthDialogueProvider) {
        return $GLOBALS['SYNTH_DIALOGUE_PROVIDER_OVERRIDE'];
    }
    if (!synth_dialogue_provider_supported($connector)) throw new DomainException('provider_unsupported');
    require_once __DIR__.'/core/llm_connector.class.php';
    return new SynthOpenAiCompatibleProvider((new LLMConnector())->dialogueProviderConfig($connector));
}

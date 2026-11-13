<?php declare(strict_types=1);
// Disposable loopback chat-completions provider for unittests/loopback. It appends each JSON request body to
// <dir>/requests.ndjson and answers with server-sent events: one "data: {choices:[{delta:{content}}]}" chunk carrying
// the text the test last wrote to <dir>/reply, a chunk with finish_reason "stop", then "data: [DONE]".
$dir=getenv('SYNTH_LOOPBACK_LLM_DIR');
if (PHP_SAPI!=='cli-server' || !is_string($dir) || !is_dir($dir)) {
    http_response_code(503);exit;
}
file_put_contents($dir.'/requests.ndjson',json_encode(json_decode(strval(file_get_contents('php://input')),true))."\n",
    FILE_APPEND|LOCK_EX);
$chunk=static fn(string $text,?string $finish=null)=>'data: '.json_encode(['choices'=>[['delta'=>['content'=>$text],
    'finish_reason'=>$finish]]])."\n\n";
header('Content-Type: text/event-stream');
echo $chunk(trim(strval(@file_get_contents($dir.'/reply')))).$chunk('','stop')."data: [DONE]\n\n";

<?php declare(strict_types=1);
// Isolated fake provider: no connector file, network, application DB or production state is used.
function synthRuntimeBootstrapIfNeeded(string $root,array $options=[]): void {}
require_once $argv[1].'/lib/synth_tts.php';
$root=sys_get_temp_dir().'/synth-canonical-tts-'.bin2hex(random_bytes(8));
mkdir($root.'/lib',0700,true);mkdir($root.'/soundcache',0700,true);
file_put_contents($root.'/lib/chat_helper_functions.php', <<<'PHP'
<?php
function callConfiguredTts($text,$mood,$seed) {
    $GLOBALS['scope_observed']=[
        'planned'=>isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN']),
        'voice'=>$GLOBALS['PATCH_OVERRIDE_VOICE']??null,
        'profile'=>isset($GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']),
        'fallback'=>isset($GLOBALS['TTS_NPC_FALLBACK_VOICE'])||isset($GLOBALS['TTS_FALLBACK_FNCT']),
        'history'=>isset($GLOBALS['LAST_LLM_RESPONSE']),
    ];
    $path=$GLOBALS['fixture_root'].'/soundcache/'.md5($seed).'.wav';
    file_put_contents($path,'RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0");
    return $path;
}
PHP);
$GLOBALS['fixture_root']=$root;$GLOBALS['TTSFUNCTION']='fixture';$GLOBALS['SYNTH_NAME']='Other actor';
$GLOBALS['PATCH_OVERRIDE_VOICE']='wrong_voice';$GLOBALS['TTS_NPC_FALLBACK_VOICE']='wrong_fallback';
$GLOBALS['TTS_FALLBACK_FNCT']='untrusted_previous_fallback';$GLOBALS['LAST_LLM_RESPONSE']='private prior briefing';
$GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['npc_name'=>'Settler','voiceid'=>'wrong_voice'];
$GLOBALS['db']=new class {
    public array $queries=[];
    public function escapeLiteral(string $value): string{return "'".str_replace("'","''",$value)."'";}
    public function fetchOne(string $query): ?array {
        $this->queries[]=$query;
        if(str_contains($query,'npc_name'))throw new LogicException('Forbidden name lookup');
        return ['voiceid'=>'canonical_voice','extended_data'=>'{}'];
    }
};
try {
    $ok=synth_tts_generate_for_response($root,'Settler','Only the authorized topic.',[
        'form_id'=>'0x00000015','origin_plugin'=>'Actors.esm','playthrough_id'=>'fixture-save']);
    echo json_encode(['generated'=>$ok,'observed'=>$GLOBALS['scope_observed']??null,
        'restored'=>($GLOBALS['PATCH_OVERRIDE_VOICE']==='wrong_voice'&&$GLOBALS['SYNTH_NAME']==='Other actor'
            &&$GLOBALS['TTS_NPC_FALLBACK_VOICE']==='wrong_fallback'&&$GLOBALS['LAST_LLM_RESPONSE']==='private prior briefing'
            &&$GLOBALS['TTS_FALLBACK_FNCT']==='untrusted_previous_fallback'&&!isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])),
        'queries'=>count($GLOBALS['db']->queries)],JSON_THROW_ON_ERROR);
} finally {
    foreach(glob($root.'/soundcache/*')?:[] as $file)unlink($file);
    unlink($root.'/lib/chat_helper_functions.php');rmdir($root.'/soundcache');rmdir($root.'/lib');rmdir($root);
}

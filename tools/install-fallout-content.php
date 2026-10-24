<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$root=dirname(__DIR__);
require_once $root.'/lib/fallout_content_pack.php';
require_once $root.'/lib/worldknowledge_catalog.php';
$pack=synthLoadFalloutContentPack($root);
$world=synthWorldKnowledgeLoadFactoryCatalog($root);
synthWorldKnowledgeLoadNpcAccessTags($root);
if (!in_array('--apply',$argv,true)) {
    echo json_encode(['mode'=>'validate_only','npcs'=>count($pack['npcs']),
        'items'=>count($pack['items']),'world_articles'=>count($world['rows'])],JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
require_once $root.'/lib/runtime_bootstrap.php';
synthRuntimeBootstrap($root,['run_db_updates'=>false,'load_general_settings'=>false,
    'load_stt_connector'=>false,'load_tts_connector'=>false,'load_narrator'=>false]);
$report=synthInstallFalloutContentBundle($GLOBALS['db'],$root,in_array('--fill-empty-profiles',$argv,true));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;

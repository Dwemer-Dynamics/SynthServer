<?php
declare(strict_types=1);
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true)) {
    header('Allow: GET, HEAD');http_response_code(405);exit;
}
if (session_status()===PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store');
$enginePath=dirname(__DIR__).DIRECTORY_SEPARATOR;
require_once $enginePath.'lib/runtime_bootstrap.php';
require_once $enginePath.'lib/synth_diary_browser.php';
synthRuntimeBootstrap($enginePath,['load_general_settings'=>true,'load_stt_connector'=>false,'load_tts_connector'=>false]);
require_once __DIR__.'/profile_loader.php';
$scriptPath=$_SERVER['SCRIPT_NAME'] ?? '';
$position=strpos($scriptPath,'/ui/');
$webRoot=$position===false ? '' : rtrim(substr($scriptPath,0,$position),'/');
$isEmbed=($_GET['embed'] ?? '')==='1';
$TITLE='SYNTH Diaries';
$error='';$view=null;
try {
    $view=synth_diary_browser_load($GLOBALS['db'],$_GET);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);$error='Choose valid diary filters and try again.';
} catch (Throwable $exception) {
    http_response_code(503);$error='Diaries could not be loaded. Please try again.';
    if (class_exists('Logger')) Logger::warn('Diary archive read failed.');
}
include __DIR__.'/tmpl/head.html';
?>
<link rel="stylesheet" href="<?php echo synth_diary_browser_h($webRoot); ?>/ui/css/main.css">
<link rel="stylesheet" href="<?php echo synth_diary_browser_h($webRoot); ?>/ui/css/diaries.css?v=<?php echo (int)filemtime(__DIR__.'/css/diaries.css'); ?>">
<?php include __DIR__.'/tmpl/diary_entries.php'; ?>
</body></html>

<?php

// Validate the user-confirmed POST before loading configuration or opening a database connection.
function synthRowDeleteRespond(array $payload, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') {
    header('Allow: POST');
    synthRowDeleteRespond(['ok'=>false,'error'=>'Use a confirmed POST request.'],405);
}
if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE'] ?? '')[0]))!=='application/json') {
    synthRowDeleteRespond(['ok'=>false,'error'=>'Use a JSON request.'],415);
}
$raw=file_get_contents('php://input',false,null,0,4097);
if ($raw===false || strlen($raw)>4096) synthRowDeleteRespond(['ok'=>false,'error'=>'Request is too large.'],413);
try { $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
catch (JsonException $error) { synthRowDeleteRespond(['ok'=>false,'error'=>'Invalid request.'],400); }
if (!is_array($input) || array_diff(array_keys($input),['table','rowid','csrf_token'])) {
    synthRowDeleteRespond(['ok'=>false,'error'=>'Invalid request.'],400);
}
if (session_status()===PHP_SESSION_NONE && !session_start()) {
    synthRowDeleteRespond(['ok'=>false,'error'=>'Session unavailable. Reload the page.'],500);
}
$expected=$_SESSION['synth_row_delete_csrf'] ?? null;
$token=$input['csrf_token'] ?? null;
$verified=is_string($expected) && preg_match('/^[a-f0-9]{64}$/D',$expected)
    && is_string($token) && hash_equals($expected,$token);
session_write_close();
if (!$verified) synthRowDeleteRespond(['ok'=>false,'error'=>'Reload the page and confirm the deletion again.'],403);

$enginePath=dirname(__DIR__,2).DIRECTORY_SEPARATOR;
require_once $enginePath.'lib/legacy_row_management.php';
try { synthLegacyRowTarget($input); }
catch (InvalidArgumentException $error) { synthRowDeleteRespond(['ok'=>false,'error'=>$error->getMessage()],400); }
try {
    require_once $enginePath.'lib/runtime_bootstrap.php';
    synthRuntimeBootstrap($enginePath,['load_general_settings'=>false,'load_stt_connector'=>false]);
    $deleted=synthDeleteLegacyRow($GLOBALS['db'],$input);
    if (!$deleted) synthRowDeleteRespond(['ok'=>false,'error'=>'Row not found or protected game history. No row was deleted.'],409);
    synthRowDeleteRespond(['ok'=>true,'deleted'=>true],200);
} catch (Throwable $error) {
    synthRowDeleteRespond(['ok'=>false,'error'=>'Could not confirm deletion. Reload the page before trying again.'],500);
}

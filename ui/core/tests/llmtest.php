<?php
// Core LLM Connector Test (DB-driven)

$enginePath = __DIR__ . "/../../../";

require_once($enginePath . "lib/runtime_bootstrap.php");
synthRuntimeBootstrap($enginePath, [
 'load_general_settings' => true,
 'load_player_name' => true,
 'load_narrator' => true,
]);


if (!isset($GLOBALS["db"])) { $GLOBALS["db"] = new sql(); }
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "llm_connector.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "core" . DIRECTORY_SEPARATOR . "api_badge.class.php");

// Minimal page; no navbar
require_once(__DIR__ . DIRECTORY_SEPARATOR . "../../profile_loader.php");

$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
$webRoot = rtrim($webRoot === '/' ? '' : $webRoot, '/');
$TITLE = 'LLM Connector Test';
$HIDE_NAVBAR = true;
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?><style>
 .test-wrap { max-width: 1100px; margin: 0 auto; }
 .panel { background:var(--synth-surface-2); border-radius:var(--synth-radius-md); padding:14px 16px; margin:12px 0; border:1px solid var(--synth-border); box-shadow:none; }
 .status .label { font-weight:bold; color:var(--synth-text); }
 .ok { color:var(--synth-success); }
 .error { color:var(--synth-danger); }
 pre { white-space:pre-wrap; color:var(--synth-text); background:var(--synth-surface-3); padding:10px; border-radius:var(--synth-radius-sm); border:1px solid var(--synth-border); }
 h1 { margin:0 0 10px; font-size:20px; color:var(--synth-accent); }
 </style><main class="test-wrap"><h1>LLM Connector Test</h1><?php
 ini_set('display_errors', 1);
 error_reporting(E_ALL);

 // Resolve connector id from multiple sources for robustness
 $connectorId = isset($_GET['connector_id']) ? intval($_GET['connector_id']) : 0;
 if ($connectorId <= 0 && isset($_GET['edit'])) {
 $connectorId = intval($_GET['edit']);
 }
 if ($connectorId <= 0 && !empty($_SERVER['HTTP_REFERER'])) {
 $ref = parse_url($_SERVER['HTTP_REFERER']);
 if (!empty($ref['query'])) {
 parse_str($ref['query'], $refQ);
 if (!empty($refQ['connector_id'])) $connectorId = intval($refQ['connector_id']);
 if ($connectorId <= 0 && !empty($refQ['edit'])) $connectorId = intval($refQ['edit']);
 }
 }
 if ($connectorId <= 0) {
 echo '<div class="panel"><div class="status"><span class="label">Connector:</span><span class="error">Missing or invalid connector_id</span></div></div>';
 echo '</main>';
 include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
 echo ob_get_clean();
 exit;
 }
 echo '<div class="panel"><div class="status"><span class="label">Connector:</span><span class="ok">#'.htmlspecialchars($connectorId).'</span></div></div>';

 echo '<div class="panel"><div class="status"><span class="label">Opening database connection... </span>';
 $db = new sql();
 if (!$db) {
 echo '<span class="error">Failed</span></div></div>'; // stop early
 } else {
 echo '<span class="ok">Connected</span></div></div>';
 }

 $buffer = '';
 $endTimeTrans = 0.0;
 if ($connectorId > 0 && $db) {
 echo '<div class="panel"><div class="status"><span class="label">Preparing test...</span></div>';
 $llm = new LLMConnector();
 $conn = $llm->getById($connectorId);
 if (!$conn) {
 echo '<div class="status"><span class="error">Connector not found.</span></div></div>';
 } else {
 // Load connector globals from DB row and set driver
 $llm->setOldGlobals($conn);
 $driver = $conn['driver'] ?? '';
 if (!$driver) {
 echo '<div class="status"><span class="error">Connector has no driver set.</span></div></div>';
 } else {
 echo '<div class="status"><span class="label">Driver:</span><span class="ok">'.htmlspecialchars($driver).'</span></div>';
 // Build a minimal in-game style test context.
 $head = [
 ['role' => 'system', 'content' => strtr(($GLOBALS["PROMPT_HEAD"] ?? '') . ($GLOBALS["SYNTH_PERS"] ?? ''), ["#PLAYER_NAME#" => ($GLOBALS["PLAYER_NAME"] ?? 'Sole Survivor')])]
 ];
 $prompt = [
 ['role' => 'user', 'content' => "Hey, ".($GLOBALS['SYNTH_NAME'] ?? 'SYNTH').", attack that monster!!"]
 ];
 $contextData = array_merge($head, $prompt);

 // Set connector globals expected by action parsing.
 $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"] = false;
 $GLOBALS["TEMPLATE_DIALOG"] = "";
 $FUNCTIONS_ARE_ENABLED = true;
 $FUNCTION_PARM_MOVETO = [$GLOBALS["PLAYER_NAME"] ?? 'Player'];
 $FUNCTION_PARM_INSPECT = [$GLOBALS["PLAYER_NAME"] ?? 'Player', 'monster'];

 require_once($enginePath . "prompts" . DIRECTORY_SEPARATOR . "command_prompt.php");
 require_once($enginePath . "functions" . DIRECTORY_SEPARATOR . "functions.php");

 // Execute with error capture
 $errors = [];
 $prevHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errors) {
 $errors[] = [ 'no' => $errno, 'str' => $errstr, 'file' => $errfile, 'line' => $errline ];
 return false; // allow default handling too
 });
 try {
 require_once($enginePath . "connector" . DIRECTORY_SEPARATOR . $driver . ".php");
 $handler = new $driver();
 $start = microtime(true);
 $handler->open($contextData, []);
 $accum = '';
 $done = false;
 while (!$done) {
 $chunk = $handler->process();
 if ($chunk === -1) { $done = true; break; }
 $accum .= $chunk;
 if ($handler->isDone()) $done = true;
 }
 $buffer = $handler->close("llmtest");
 $endTimeTrans = microtime(true) - $start;
 } catch (Throwable $e) {
 $errors[] = [ 'no' => E_ERROR, 'str' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine() ];
 $buffer = (string)($buffer ?? '');
 } finally {
 if ($prevHandler !== null) { set_error_handler($prevHandler); } else { restore_error_handler(); }
 }

 $hasBuffer = isset($buffer) && trim((string)$buffer) !== '';
 $hasErrors = !empty($errors);
 $success = ($hasBuffer && !$hasErrors);
 echo '<div class="status"><span class="label">Status:</span> ' . ($success ? '<span class="ok">Completed</span>' : '<span class="error">Failed</span>') . '</div>';
 echo '</div>'; // panel

 echo '<div class="panel">';
 echo '<div class="status"><span class="label">LLM Response:</span></div>';
 $respStr = isset($buffer) ? (string)$buffer : '';
 echo '<div class="response" style="font-size: 1.0em; color: #ffffff;">' . ($respStr !== '' ? nl2br(htmlspecialchars($respStr)) : '<span class="error">(empty)</span>') . '</div>';
 // Styled timing summary for connector diagnostics.
 $perf = 'TOO SLOW'; $color = '#dc3545';
 if ($endTimeTrans < 2) { $perf='FAST!'; $color='#28a745'; }
 else if ($endTimeTrans < 5) { $perf='GOOD'; $color='#007bff'; }
 else if ($endTimeTrans < 10) { $perf='NORMAL'; $color='#ffc107'; }
 else if ($endTimeTrans < 30) { $perf='SLOW'; $color='#fd7e14'; }
 echo '<pre><b>Response time:</b> ' . number_format($endTimeTrans, 3) . ' secs. ';
 echo '<span style="color: '.$color.'; font-weight:bold; font-size:1.1em;">'.$perf.'</span></pre>';
 echo '</div>';

 // Troubleshooting + error list when failed
 if (!$success) {
 echo '<div class="panel">';
 echo '<div class="status"><span class="label" style="color: #ffc107;">TROUBLESHOOTING FIXES</span></div>';
 echo '<ul style="margin-top: 10px; list-style-type: none; padding-left: 0; color:#cfd8e3;">';
 echo '<li style="margin-bottom: 10px;"><strong>401 = Unauthorized</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>Check your API key.</li><li>Ensure you have enough credits on your account.</li></ul></li>';
 echo '<li style="margin-bottom: 10px;"><strong>402 = Payment Required</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>Make sure your account has credits.</li></ul></li>';
 echo '<li style="margin-bottom: 10px;"><strong>403 = Forbidden</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>Your prompt may be flagged for moderation.</li></ul></li>';
 echo '<li style="margin-bottom: 10px;"><strong>404 = Not Found</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>Check if your connector URL is correct.</li></ul></li>';
 echo '<li style="margin-bottom: 10px;"><strong>500 = Internal Server Error</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>The server is experiencing issues.</li></ul></li>';
 echo '<li style="margin-bottom: 10px;"><strong>LLM Response is Empty</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>Ensure your account has credits.</li></ul></li>';
 echo '<li style="margin-bottom: 10px;"><strong>Response fails in-game</strong><ul style="margin-left: 20px; list-style-type: disc;"><li>Check server logs for token limits.</li></ul></li>';
 echo '</ul>';
 if ($hasErrors) {
 echo '<div class="status"><span class="label">Errors captured:</span></div>';
 echo '<pre>';
 foreach ($errors as $er) {
 $line = '['.intval($er['no']).'] '.($er['str'] ?? '');
 $src = ($er['file'] ?? '?') . ':' . ($er['line'] ?? '?');
 echo htmlspecialchars($src.' - '.$line) . "\n";
 }
 echo '</pre>';
 }
 echo '</div>';
 }

 // Request sent to LLM (from connector DEBUG_DATA)
 echo '<div class="panel">';
 echo '<div class="status"><span class="label">Request Payload:</span></div>';
 if (!empty($GLOBALS['DEBUG_DATA']['full'])) {
 $reqJson = json_encode($GLOBALS['DEBUG_DATA']['full'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 echo '<pre>'.htmlspecialchars($reqJson).'</pre>';
 } else {
 echo '<pre>No request payload captured.</pre>';
 }
 echo '</div>';

 // Raw/Debug info (e.g., parsed RAW content)
 echo '<div class="panel">';
 echo '<div class="status"><span class="label">Debug (RAW/Actions):</span></div>';
 if (!empty($GLOBALS['DEBUG_DATA']['RAW'])) {
 $rawShow = is_string($GLOBALS['DEBUG_DATA']['RAW']) ? $GLOBALS['DEBUG_DATA']['RAW'] : json_encode($GLOBALS['DEBUG_DATA']['RAW'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 echo '<pre>'.htmlspecialchars($rawShow).'</pre>';
 } else {
 echo '<pre>No RAW debug available.</pre>';
 }
 echo '</div>';

 // Any accumulated buffers/errors emitted by the connector.
 if (!empty($GLOBALS['ALREADY_SENT_BUFFER'])) {
 echo '<div class="panel">';
 echo '<div class="status"><span class="label">Internal Buffers:</span></div>';
 $bufDump = is_string($GLOBALS['ALREADY_SENT_BUFFER']) ? $GLOBALS['ALREADY_SENT_BUFFER'] : print_r($GLOBALS['ALREADY_SENT_BUFFER'], true);
 echo '<pre>'.htmlspecialchars($bufDump).'</pre>';
 echo '</div>';
 }
 }
 }
 }
 ?></main><?php
include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
echo ob_get_clean();
?>



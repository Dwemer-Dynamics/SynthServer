<?php

header('Content-Type: text/html; charset=utf-8');

$provider = $_GET['provider'] ?? $_POST['provider'] ?? '';
$apiKey = $_POST['api_key'] ?? '';

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES); }

// A test is a POST from the API Keys page carrying that page's session token. Both are checked before any
// database or provider call; a missing or wrong token ends the request here.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
 http_response_code(405);
 header('Allow: POST');
 header('Cache-Control: no-store');
 echo 'Use the Test button on the API Keys page.';
 exit;
}
if (session_status() === PHP_SESSION_NONE) @session_start();
$apiKeyCsrf = $_SESSION['synth_api_key_csrf'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (!is_string($apiKeyCsrf) || !preg_match('/^[a-f0-9]{64}$/D', $apiKeyCsrf)
 || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($apiKeyCsrf, $_POST['csrf_token'])) {
 http_response_code(403);
 header('Cache-Control: no-store');
 echo 'Reload the API Keys page, then test again.';
 exit;
}

// Tests go only to these server-known endpoints. A typed key is tested as sent; with no typed key the saved key
// is read here from the badge whose label matches the provider, so a stored key never travels through the page.
// Provider response bodies are never echoed: they can quote the key back.
$apiKeyTestEndpoints = [
 'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
 'openai' => 'https://api.openai.com/v1/chat/completions',
];
$apiKeyTestLabels = [ 'openrouter' => 'OpenRouter', 'openai' => 'OpenAI' ];
$provider = is_string($_POST['provider'] ?? null) ? strtolower(trim($_POST['provider'])) : '';
$apiKey = is_string($apiKey) ? trim($apiKey) : '';
// The badge id uses the API Keys page's row id grammar exactly; a padded or signed value names no row.
$badgeId = is_string($_POST['badge_id'] ?? null) ? $_POST['badge_id'] : '';
header('Cache-Control: no-store');
$testProblem = '';
if (!array_key_exists($provider, $apiKeyTestEndpoints)) {
 $provider = '';
 $testProblem = 'Unsupported provider.';
} else if ($apiKey === '' && preg_match('/^[1-9][0-9]{0,9}$/D', $badgeId) && intval($badgeId) <= 2147483647) {
 $savedLabel = '';
 try {
 $enginePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
 require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php');
 synthRuntimeBootstrap($enginePath, []);
 $savedResult = $GLOBALS['db']->execQuery('SELECT label, api_key FROM core_api_badge WHERE id = $1', [ intval($badgeId) ]);
 $savedRow = $savedResult ? pg_fetch_assoc($savedResult) : false;
 if (is_array($savedRow)) {
 // The stored label must equal the provider label apart from letter case; a padded label is a custom entry.
 $savedLabel = strval($savedRow['label'] ?? '');
 if (strcasecmp($savedLabel, $apiKeyTestLabels[$provider]) === 0) $apiKey = trim(strval($savedRow['api_key'] ?? ''));
 }
 } catch (Throwable $_e) {
 $testProblem = 'Could not read the saved key.';
 }
 if ($testProblem === '' && strcasecmp($savedLabel, $apiKeyTestLabels[$provider]) !== 0) {
 $testProblem = 'No saved key matches this provider.';
 } else if ($testProblem === '' && $apiKey === '') {
 $testProblem = 'No saved key for this provider.';
 }
}
if ($testProblem === '' && $apiKey === '') {
 $testProblem = 'No API key provided.';
} else if ($testProblem === '' && !preg_match('/^[\x21-\x7E]{1,4096}$/', $apiKey)) {
 $testProblem = 'This key contains characters that cannot be sent to the provider.';
}

$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
$webRoot = rtrim($webRoot === '/' ? '' : $webRoot, '/');
$TITLE = 'API Key Test';
$HIDE_NAVBAR = true;
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
echo "<style>
 .wrap{max-width:900px; margin:0 auto;}
 .panel{background:var(--synth-surface-2); border:1px solid var(--synth-border); border-radius:var(--synth-radius-md); padding:12px; margin:10px 0;}
 .muted{color:var(--synth-text-muted);}
 .ok{color:var(--synth-success);}
 .warn{color:var(--synth-warning);}
 .err{color:var(--synth-danger);}
</style><main class='wrap'>";

echo "<div class='panel'>";
echo "<div style='display:flex; align-items:center; gap:10px; margin-bottom:6px;'>";
echo "<div style='font-weight:600; font-size:18px; color: var(--synth-accent);'>API Key Test</div>";
echo "<div class='muted' style='opacity:0.85; font-size:13px;'>".h(strtoupper($provider))."</div>";
echo "</div>";

if ($testProblem !== ''){
 echo "<div class='warn'>".h($testProblem)."</div>";
 echo "</div></main>";
 include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
 echo ob_get_clean();
 exit;
}

try {
 $ch = curl_init();
 if (!$ch) throw new Exception('Failed to init curl');
 $url = $apiKeyTestEndpoints[$provider];
 $headers = [ 'Content-Type: application/json', 'Authorization: Bearer ' . $apiKey ];
 $payload = [
 'model' => $provider === 'openrouter' ? 'openrouter/auto' : 'gpt-4o-mini',
 'messages' => [ ['role'=>'user','content'=>'ping'] ],
 'max_tokens' => 1
 ];
 if ($provider === 'openrouter'){
 // Optional hints recommended by OpenRouter (won't break if omitted)
 $headers[] = 'HTTP-Referer: http://localhost';
 $headers[] = 'X-Title: SYNTH';
 }
 curl_setopt_array($ch, [
 CURLOPT_URL => $url,
 CURLOPT_RETURNTRANSFER => true,
 CURLOPT_HTTPHEADER => $headers,
 CURLOPT_FOLLOWLOCATION => false,
 CURLOPT_CONNECTTIMEOUT => 10,
 CURLOPT_TIMEOUT => 20,
 CURLOPT_POST => true,
 CURLOPT_POSTFIELDS => json_encode($payload),
 ]);
 $resp = curl_exec($ch);
 if ($resp === false){
 throw new Exception('Could not reach the provider (cURL error '.intval(curl_errno($ch)).').');
 }
 $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
 curl_close($ch);
 $json = json_decode($resp, true);
 $ok = ($code >= 200 && $code < 300) && is_array($json) && (isset($json['choices']) || isset($json['id']));

 // Classify rate-limit and quota/credit conditions from the status and error text; the text itself is not shown.
 // A rate-limited request says nothing about the key, so it is never reported as valid.
 $isQuota = false;
 $isRateLimited = false;
 if (!$ok) {
 $errMsg = '';
 if (is_array($json)){
 if (isset($json['error']) && is_array($json['error'])) {
 $errMsg = $json['error']['message'] ?? ($json['error']['code'] ?? '');
 } else if (isset($json['message'])) {
 $errMsg = $json['message'];
 }
 }
 $msgL = strtolower(is_scalar($errMsg) ? (string)$errMsg : '');
 if ($code === 429 || strpos($msgL, 'rate limit') !== false) {
 $isRateLimited = true;
 } else if ($code === 402 ||
 strpos($msgL, 'quota') !== false ||
 strpos($msgL, 'credit') !== false ||
 strpos($msgL, 'payment required') !== false ||
 strpos($msgL, 'billing') !== false) {
 $isQuota = true;
 }
 }

 if ($ok) {
 echo "<div style='display:flex; align-items:center; gap:8px;' class='ok' style='font-weight:600;'>";
 echo "<span style='font-size:18px;'></span><span>Key is valid</span>";
 echo "</div>";
 echo "<div class='muted' style='margin-top:6px; font-size:12px;'>HTTP ".h($code)." Provider reachable</div>";
 } else if ($isRateLimited) {
 echo "<div style='display:flex; align-items:center; gap:8px;' class='warn' style='font-weight:600;'>";
 echo "<span style='font-size:18px;'></span><span>The provider limited this request. Try again later to check the key.</span>";
 echo "</div>";
 echo "<div class='warn' style='margin-top:6px; font-size:12px;'>HTTP ".h($code)."</div>";
 } else if ($isQuota) {
 echo "<div style='display:flex; align-items:center; gap:8px;' class='warn' style='font-weight:600;'>";
 echo "<span style='font-size:18px;'></span><span>Key valid, but no credits/quota</span>";
 echo "</div>";
 echo "<div class='warn' style='margin-top:6px; font-size:12px;'>HTTP ".h($code)."</div>";
 } else if ($code === 401 || $code === 403) {
 echo "<div style='display:flex; align-items:center; gap:8px;' class='err' style='font-weight:600;'>";
 echo "<span style='font-size:18px;'></span><span>Key rejected by the provider</span>";
 echo "</div>";
 echo "<div class='warn' style='margin-top:6px; font-size:12px;'>HTTP ".h($code)."</div>";
 } else {
 echo "<div style='display:flex; align-items:center; gap:8px;' class='err' style='font-weight:600;'>";
 echo "<span style='font-size:18px;'></span><span>Key failed</span>";
 echo "</div>";
 echo "<div class='warn' style='margin-top:6px; font-size:12px;'>HTTP ".h($code)."</div>";
 }
} catch (Throwable $e){
 echo "<div style='display:flex; align-items:center; gap:8px;' class='err' style='font-weight:600;'>";
 echo "<span style='font-size:18px;'></span><span>Test error</span>";
 echo "</div>";
 echo "<div class='muted' style='margin-top:6px; font-size:13px;'>".h($e->getMessage())."</div>";
}
echo "</div></main>";
include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
echo ob_get_clean();
?>



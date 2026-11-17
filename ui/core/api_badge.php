<?php

$enginePath = __DIR__.DIRECTORY_SEPARATOR."../../";

// Custom key delete: confirmed same-origin POSTs with the dashboard form token; former GET links are refused before bootstrap.
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "management_request_guard.php");
$apiBadgeAction = synthManagementActionGate(['delete']);

require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."runtime_bootstrap.php");

// Clear is the only request that empties a saved key. It must carry this session's API Keys token, checked
// here before bootstrap opens the database. A rejected Clear reads only an existing session and mints no token.
if (($_SERVER["REQUEST_METHOD"] ?? '') === "POST" && isset($_POST["clear_key"])) {
 if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) @session_start();
 $clearCsrf = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['synth_api_key_csrf'] ?? null) : null;
 if (!is_string($clearCsrf) || !preg_match('/^[a-f0-9]{64}$/D', $clearCsrf)
  || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($clearCsrf, $_POST['csrf_token'])) {
  http_response_code(403);
  header('Cache-Control: no-store');
  echo 'Reload the API Keys page, then try again.';
  exit;
 }
}

synthRuntimeBootstrap($enginePath, [
 'load_general_settings' => true,
 'load_player_name' => true,
 'load_narrator' => true,
]);

require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."model_dynmodel.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."chat_helper_functions.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."data_functions.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."logger.php");

require_once "{$enginePath}/lib/core/api_badge.class.php";

// Web root detection to match site-wide includes (consistent with worldknowledge_upload)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
 $webRoot = substr($scriptPath, 0, $uiPos);
} else {
 $webRoot = '';
}
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

// Site chrome
require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");
$TITLE = " SYNTH - API Keys";
ob_start();
include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/head.html");
?><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css"><style>
 /* Match worldknowledge_upload page layout and colors */
 main {
 /* Compact responsive gutter instead of a fixed 10% on every viewport. */
 padding: 10px clamp(10px, 2.5vw, 34px) 24px;
 width: 100%;
 margin: 0;
 }

 footer {
 position: fixed;
 bottom: 0;
 width: 100%;
 height: 20px;
 background: #031633;
 z-index: 100;
 }

 /* Page header is the shared compact inline row (.synth-page-head in synth-theme.css). */


 .content-grid {
 display: grid;
 grid-template-columns: 1fr 1fr;
 gap: 30px;
 margin-bottom: 30px;
 }
 .content-section {
 background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 padding: 25px;
 border-radius: 10px;
 border: 1px solid #3a3a3a;
 box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
 transition: border-color 0.3s ease, box-shadow 0.3s ease;
 }
 .content-section:hover {
 border-color: #4a4a4a;
 box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
 }
 .content-section h2, .content-section h3 {
 font-family: 'Gothic821', serif;
 color: var(--synth-accent);
 text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
 word-spacing: 6px;
 margin-bottom: 15px;
 font-size: 1.4em;
 }
 .section-header {
 display: flex;
 align-items: center;
 justify-content: space-between;
 margin-bottom: 15px;
 }
 .section-header h2 {
 margin: 0;
 }
 .full-width-section { grid-column: 1 / -1; }
 @media (max-width: 900px) {
 .content-grid { grid-template-columns: 1fr; }
 }

 /* Cards and grids styled like worldknowledge sections */
 .provider-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px; }
 .provider-card {
 background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 border:1px solid #3a3a3a;
 border-radius:10px;
 padding:14px;
 box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
 transition: all 0.2s ease;
 }
 .provider-card:hover {
 border-color: #4a4a4a;
 box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
 transform: translateY(-1px);
 }
 .provider-card .provider-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
 .provider-card .provider-title { display:flex; align-items:center; gap:10px; color:#e0e0e0; }
 .provider-card .provider-icon {
 width:28px;
 height:28px;
 border-radius:6px;
 background: linear-gradient(135deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 0.9));
 display:flex;
 align-items:center;
 justify-content:center;
 font-size:16px;
 box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
 }
 .provider-card .provider-links { display:flex; gap:10px; }
 .provider-card .provider-links a { font-size:12px; color: var(--synth-accent); text-decoration: underline; }
 .provider-card .provider-body { display:flex; gap:8px; align-items:center; }
 .provider-card input[type="password"], .provider-card input[type="text"] {
 flex:1;
 background: rgba(26, 26, 26, 0.8);
 color: #e9efff;
 border: 1px solid #3a3a3a;
 border-radius: 6px;
 padding: 10px 12px;
 transition: all 0.2s ease;
 }
 .provider-card input[type="password"]:focus, .provider-card input[type="text"]:focus {
 border-color: rgba(var(--synth-accent-rgb), 0.5);
 outline: none;
 box-shadow: 0 0 0 3px rgba(var(--synth-accent-rgb), 0.1);
 background: rgba(34, 34, 34, 0.9);
 }
 .provider-card .provider-subtext { margin-top:8px; color:#bdbdbd; font-size:12px; }
 .provider-card .provider-subtext ul { margin:4px 0 0 18px; padding:0; }
 .provider-card .provider-subtext li { line-height:1.4; }
 .provider-card .provider-subtext .desc { margin:0 0 2px 0; color:#cfcfcf; }

 #custom-keys { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px; }
 .custom-card {
 background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 border:1px solid #3a3a3a;
 border-radius:10px;
 padding:14px;
 box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
 transition: all 0.2s ease;
 }
 .custom-card:hover {
 border-color: #4a4a4a;
 box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
 transform: translateY(-1px);
 }
 .custom-card.has-key {
 box-shadow: 0 0 0 1px rgba(45,106,79,0.35) inset, 0 1px 4px rgba(0, 0, 0, 0.1);
 border-color:#2d6a4f;
 }
 .custom-card.has-key:hover {
 box-shadow: 0 0 0 1px rgba(45,106,79,0.5) inset, 0 3px 8px rgba(0, 0, 0, 0.2);
 }
 .custom-card .provider-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
 .custom-card .provider-title { display:flex; align-items:center; gap:10px; color:#e0e0e0; }
 .custom-card .provider-icon {
 width:28px;
 height:28px;
 border-radius:6px;
 background: linear-gradient(135deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 0.9));
 display:flex;
 align-items:center;
 justify-content:center;
 font-size:16px;
 box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
 }
 .custom-card .provider-body { display:flex; gap:8px; align-items:center; }
 .custom-card input[type="password"], .custom-card input[type="text"] {
 flex:1;
 background: rgba(26, 26, 26, 0.8);
 color: #e9efff;
 border: 1px solid #3a3a3a;
 border-radius: 6px;
 padding: 10px 12px;
 transition: all 0.2s ease;
 }
 .custom-card input[type="password"]:focus, .custom-card input[type="text"]:focus {
 border-color: rgba(var(--synth-accent-rgb), 0.5);
 outline: none;
 box-shadow: 0 0 0 3px rgba(var(--synth-accent-rgb), 0.1);
 background: rgba(34, 34, 34, 0.9);
 }
 .custom-card label {
 color: var(--synth-accent);
 font-weight: 600;
 display: block;
 margin-top: 10px;
 margin-bottom: 6px;
 }

 .provider-card.has-key {
 box-shadow: 0 0 0 1px rgba(45,106,79,0.35) inset, 0 1px 4px rgba(0, 0, 0, 0.1);
 border-color:#2d6a4f;
 }
 .key-state { font-size:11px; color:#8fd1ae; border:1px solid rgba(45,106,79,0.6); border-radius:4px; padding:1px 6px; }
 .key-state[hidden], .btn-clear-key[hidden] { display:none; }
 .section-note { margin:-8px 0 12px; color:#bdbdbd; font-size:12px; }
 @media (max-width: 600px) {
 .provider-card .provider-body, .custom-card .provider-body { flex-wrap:wrap; }
 .provider-card .provider-body input, .custom-card .provider-body input { flex:1 1 100%; min-width:0; }
 }

 @media (max-width: 900px) {
 .provider-grid { grid-template-columns: 1fr; }
 #custom-keys { grid-template-columns: 1fr; }
 }
</style><main><div class="page-header synth-page-head"><h1 class="api-title synth-page-head-title">API Keys</h1><p class="page-subtitle synth-page-head-note">Manage API keys for LLM, TTS, and other service connectors</p></div><div id="toast" class="toast-notification"><span class="message"></span></div><form method="post" action="api_badge.php"><?php
$GLOBALS["db"] = $GLOBALS["db"] ?? new sql();
$apiBadge = new ApiBadge();

// Preset providers (case-insensitive matching)
$presetMap = [
 'openrouter' => 'OpenRouter',
 'openai' => 'OpenAI',
 'deepgram' => 'Deepgram',
 'google' => 'Google',
 'azure' => 'Azure',
 'elevenlabs' => 'ElevenLabs',
 'cartesia' => 'Cartesia',
 'inworld' => 'Inworld',
 'replicate' => 'Replicate',
 'groq' => 'Groq',
 'nano-gpt' => 'Nano-GPT'
];

// Provider key/dashboard links
$providerLinks = [
 'openrouter' => 'https://openrouter.ai/keys',
 'openai' => 'https://platform.openai.com/api-keys',
 'deepgram' => 'https://console.deepgram.com/',
 'google' => 'https://console.cloud.google.com/apis/credentials',
 'azure' => 'https://ai.azure.com/',
 'elevenlabs' => 'https://elevenlabs.io/app/settings/api-keys',
 'cartesia' => 'https://play.cartesia.ai/console',
 'inworld' => 'https://studio.inworld.ai/',
 'replicate' => 'https://replicate.com/account/api-tokens',
 'groq' => 'https://console.groq.com/keys',
 'nano-gpt' => 'https://nano-gpt.com/'
];

// Subtext bullet points per provider
$providerSubtext = [
 'openrouter' => ['LLM'],
 'deepgram' => ['STT'],
 'openai' => ['LLM', 'STT'],
 'azure' => ['STT'],
 'google' => ['LLM'],
 'elevenlabs' => ['TTS'],
 'cartesia' => ['TTS'],
 'inworld' => ['TTS'],
 'replicate' => ['Gallery Processor'],
 'groq' => ['LLM'],
 'nano-gpt' => ['LLM'],
];

// Reserved labels (both slugs and pretty names), used to keep presets distinct from customs
$reservedLabelsLower = array_values(array_unique(array_merge(
 array_keys($presetMap),
 array_map('strtolower', array_values($presetMap))
)));

// This page seeds no preset rows when viewed: a missing preset row is created by label when Save Keys stores
// its key.

// One session token shared by this page and its key Test page. Clear (checked above) and Test refuse a request
// without it.
if (session_status() === PHP_SESSION_NONE) @session_start();
$apiKeyCsrf = $_SESSION['synth_api_key_csrf'] ?? null;
if (!is_string($apiKeyCsrf) || !preg_match('/^[a-f0-9]{64}$/D', $apiKeyCsrf)) {
 $apiKeyCsrf = bin2hex(random_bytes(32));
 if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['synth_api_key_csrf'] = $apiKeyCsrf;
}

// Keys are write-only from the browser: a blank field keeps the saved key and only the confirmed Clear
// action empties one. Control characters are refused so a key can never split a provider request header.
// Returns '' for blank, the trimmed key, or null when the submitted value is not a usable key.
function synthApiBadgeSubmittedKey($raw): ?string {
 if (!is_string($raw)) return null;
 $key = trim($raw);
 if ($key === '') return '';
 if (strlen($key) > 4096 || !preg_match('//u', $key) || preg_match('/[\x00-\x1F\x7F]/', $key)) return null;
 return $key;
}

// A row id is a positive PostgreSQL integer (core_api_badge.id is INT, max 2147483647). Returns 0 otherwise.
function synthApiBadgeRowId($raw): int {
 if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) return 0;
 $id = intval($raw);
 return $id <= 2147483647 ? $id : 0;
}

function synthApiBadgeIsPresetRow($row, array $reservedLabelsLower): bool {
 return in_array(strtolower(strval($row['label'] ?? '')), $reservedLabelsLower, true);
}

// Handle Save All (batch update). Every submitted key is checked before anything is written, so a malformed
// value changes nothing; each write is then checked and a failure is reported instead of a silent success.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_all"])) {
 $writes = [];
 // 1) Presets: the row is the provider's current lowest-id entry, found by its fixed label. The posted id is the
 // row the page displayed and only confirms that row: if it no longer matches (another entry became the
 // provider's row, or one was added after a page shown without one), nothing is written and the page asks for
 // a reload, so a key never lands in an entry the user did not see.
 if (isset($_POST['presets']) && is_array($_POST['presets'])) {
 foreach ($presetMap as $slug => $pretty) {
 $posted = $_POST['presets'][$slug] ?? null;
 if (!is_array($posted)) continue;
 $apiKey = synthApiBadgeSubmittedKey($posted['api_key'] ?? '');
 if ($apiKey === null) { header("Location: api_badge.php?err=invalid_key"); exit; }
 if ($apiKey === '') continue;
 $postedId = $posted['id'] ?? null;
 if (!is_string($postedId)) { header("Location: api_badge.php?err=stale"); exit; }
 $shownId = $postedId === '' ? 0 : synthApiBadgeRowId($postedId);
 if ($postedId !== '' && $shownId === 0) { header("Location: api_badge.php?err=stale"); exit; }
 $currentRow = $apiBadge->getByLabel($pretty);
 if (intval($currentRow['id'] ?? 0) !== $shownId) { header("Location: api_badge.php?err=stale"); exit; }
 $writes[] = [ 'preset', [ $pretty, $shownId ], [ 'api_key' => $apiKey ] ];
 }
 }

 // 1b) Other saved entries whose label matches a preset in another letter case. Each is its own row, named by
 // id; the id must still be such an entry and never the provider's lowest-id row, which only the card above
 // writes.
 if (isset($_POST['preset_variants']) && is_array($_POST['preset_variants'])) {
 foreach ($_POST['preset_variants'] as $rawId => $posted) {
 $vid = synthApiBadgeRowId(strval($rawId));
 if ($vid === 0 || !is_array($posted)) { header("Location: api_badge.php?err=not_found"); exit; }
 $apiKey = synthApiBadgeSubmittedKey($posted['api_key'] ?? '');
 if ($apiKey === null) { header("Location: api_badge.php?err=invalid_key"); exit; }
 if ($apiKey === '') continue;
 $variant = $apiBadge->getById($vid);
 $canonical = !empty($variant['id']) ? $apiBadge->getByLabel(strval($variant['label'])) : null;
 if (empty($variant['id']) || !synthApiBadgeIsPresetRow($variant, $reservedLabelsLower)
  || intval($canonical['id'] ?? 0) === $vid) {
 header("Location: api_badge.php?err=not_found");
 exit;
 }
 $writes[] = [ 'variant', $vid, [ 'api_key' => $apiKey ] ];
 }
 }

 // 2) Custom keys
 if (isset($_POST['custom']) && is_array($_POST['custom'])) {
 // Align arrays: ids[], labels[], api_keys[]
 $ids = is_array($_POST['custom']['id'] ?? null) ? $_POST['custom']['id'] : [];
 $labels = is_array($_POST['custom']['label'] ?? null) ? $_POST['custom']['label'] : [];
 $keys = is_array($_POST['custom']['api_key'] ?? null) ? $_POST['custom']['api_key'] : [];
 $count = max(count($ids), count($labels), count($keys));
 for ($i = 0; $i < $count; $i++) {
 // A blank id is a new row; any other id must name a row, so a bad one never creates an extra row.
 $rawId = $ids[$i] ?? '';
 $cid = $rawId === '' ? null : synthApiBadgeRowId($rawId);
 if ($cid === 0) { header("Location: api_badge.php?err=not_found"); exit; }
 $clabel = is_string($labels[$i] ?? null) ? trim($labels[$i]) : '';
 $ckey = synthApiBadgeSubmittedKey($keys[$i] ?? '');
 if ($ckey === null) { header("Location: api_badge.php?err=invalid_key"); exit; }
 // Prevent using preset labels for customs
 if (in_array(strtolower($clabel), $reservedLabelsLower, true)) continue;
 $payload = [];
 if ($clabel !== '') $payload['label'] = $clabel;
 if ($ckey !== '') $payload['api_key'] = $ckey;
 if (!$payload) continue; // skip empty rows; a blank key keeps the saved key
 if ($cid) {
 $writes[] = [ 'custom', $cid, $payload ];
 } else {
 $writes[] = [ 'new', null, [ 'label' => $clabel !== '' ? $clabel : 'Custom Key', 'api_key' => $ckey ] ];
 }
 }
 }

 $saveFailed = false;
 foreach ($writes as [$kind, $target, $payload]) {
 if ($kind === 'preset') {
 // Re-checked at write time: the provider's row must still be the one validated above.
 [ $pretty, $shownId ] = $target;
 $presetRow = $apiBadge->getByLabel($pretty);
 if (intval($presetRow['id'] ?? 0) !== $shownId) {
 $saved = false;
 } else {
 $saved = $shownId > 0
 ? $apiBadge->update($shownId, $payload)
 : $apiBadge->create([ 'label' => $pretty ] + $payload) > 0;
 }
 } else if ($kind === 'custom') {
 $current = $apiBadge->getById($target);
 if (empty($current['id']) || synthApiBadgeIsPresetRow($current, $reservedLabelsLower)) continue;
 $saved = $apiBadge->update($target, $payload);
 } else if ($kind === 'variant') {
 $saved = $apiBadge->update($target, $payload);
 } else {
 $saved = $apiBadge->create($payload) > 0;
 }
 if (!$saved) $saveFailed = true;
 }

 header("Location: api_badge.php" . ($saveFailed ? "?err=save_failed" : ""));
 exit;
}

// Handle Create
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create"])) {
 $incomingLabel = is_string($_POST['label'] ?? null) ? trim($_POST['label']) : '';
 if (in_array(strtolower($incomingLabel), $reservedLabelsLower, true)) {
 header("Location: api_badge.php?err=reserved_label");
 exit;
 }
 $incomingKey = synthApiBadgeSubmittedKey($_POST['api_key'] ?? '');
 if ($incomingKey === null) {
 header("Location: api_badge.php?err=invalid_key");
 exit;
 }
 // Assign custom IDs starting from 100 to leave room for preset IDs
 $allRows = $apiBadge->getAll();
 $maxId = 0;
 foreach ($allRows as $r) {
 $rid = isset($r['id']) ? intval($r['id']) : 0;
 if ($rid > $maxId) { $maxId = $rid; }
 }
 $newId = max(100, $maxId + 1);
 $createdId = $apiBadge->create([
 'id' => $newId,
 'label' => $incomingLabel !== '' ? $incomingLabel : 'Custom Key',
 'api_key' => $incomingKey
 ]);
 header("Location: api_badge.php?" . ($createdId > 0 ? "ok=saved" : "err=save_failed"));
 exit;
}

// Handle Update (autosave and per-card Save). Only an existing row's own label/key are written: a preset
// keeps its label, a custom row cannot take a preset label, and a blank key keeps the saved key.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update"])) {
 $uid = synthApiBadgeRowId($_POST['id'] ?? null);
 $current = $uid > 0 ? $apiBadge->getById($uid) : null;
 if (empty($current['id'])) {
 header("Location: api_badge.php?err=not_found");
 exit;
 }
 $payload = [];
 if (!synthApiBadgeIsPresetRow($current, $reservedLabelsLower) && is_string($_POST['label'] ?? null)) {
 $newLabel = trim($_POST['label']);
 if (in_array(strtolower($newLabel), $reservedLabelsLower, true)) {
 header("Location: api_badge.php?err=reserved_label");
 exit;
 }
 if ($newLabel !== '') $payload['label'] = $newLabel;
 }
 if (array_key_exists('api_key', $_POST)) {
 $newKey = synthApiBadgeSubmittedKey($_POST['api_key']);
 if ($newKey === null) {
 header("Location: api_badge.php?err=invalid_key");
 exit;
 }
 if ($newKey !== '') $payload['api_key'] = $newKey;
 }
 if ($payload && !$apiBadge->update($uid, $payload)) {
 header("Location: api_badge.php?err=save_failed");
 exit;
 }
 header("Location: api_badge.php?ok=saved");
 exit;
}

// Handle Clear: the only path that empties a saved key. The page asks the operator to confirm first; the
// session token was checked at the top of this file.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["clear_key"])) {
 $cid = synthApiBadgeRowId($_POST['id'] ?? null);
 $current = $cid > 0 && ($_POST['clear_key'] ?? '') === 'confirmed' ? $apiBadge->getById($cid) : null;
 if (empty($current['id'])) {
 header("Location: api_badge.php?err=not_found");
 exit;
 }
 $cleared = $apiBadge->update($cid, [ 'api_key' => '' ]);
 header("Location: api_badge.php?" . ($cleared ? "ok=cleared" : "err=save_failed"));
 exit;
}

// Handle Delete
if ($apiBadgeAction && isset($_POST["delete"])) {
 $item = $apiBadge->getById($_POST["delete"]);
 $labelLower = strtolower($item["label"] ?? '');
 if (!array_key_exists($labelLower, $presetMap)) {
 $apiBadge->delete($_POST["delete"]);
 }
 header("Location: api_badge.php");
 exit;
}

// Fetch Data. Render only whether a key is saved; the stored value never reaches the page.
$data = array_map(function($row) {
 return [
 'id' => $row['id'] ?? '',
 'label' => $row['label'] ?? '',
 'configured' => trim(strval($row['api_key'] ?? '')) !== '',
 ];
}, $apiBadge->getAll() ?: []);
$editItem = null;

if (isset($_GET["edit"])) {
 $editItem = $apiBadge->getById($_GET["edit"]);
}
?><?php
echo synthManagementPostScript();
// A provider's preset card is its lowest-id row whose label matches case-insensitively, the same row Save Keys,
// Quickstart and getByLabel() write. Other rows whose label matches in another letter case are kept as their own
// entries and listed under the provider with their actual label and id.
$presetRows = [];
$presetVariants = [];
foreach ($presetMap as $slug => $pretty) {
 $presetRows[$slug] = [ 'id' => '', 'label' => $pretty, 'configured' => false ];
 $presetVariants[$slug] = [];
}
foreach ($data as $row) {
 $slug = strtolower(strval($row['label'] ?? ''));
 if (!array_key_exists($slug, $presetMap) || intval($row['id']) <= 0) continue;
 $presetVariants[$slug][] = $row;
}
foreach ($presetVariants as $slug => $rows) {
 usort($rows, function($a, $b) { return intval($a['id']) <=> intval($b['id']); });
 if ($rows) $presetRows[$slug] = array_shift($rows);
 $presetVariants[$slug] = $rows;
}
$savedKeyPlaceholder = 'Key saved - leave blank to keep, paste to replace';
$customRows = array_filter($data, function($row) use ($presetMap) {
 return !array_key_exists(strtolower($row['label'] ?? ''), $presetMap);
});
?><input type="hidden" name="csrf_token" id="api_key_csrf" value="<?= htmlspecialchars($apiKeyCsrf) ?>"><div class="content-grid"><div class="content-section full-width-section"><div class="section-header"><h2>Preset Keys</h2><button type="submit" name="save_all" value="1" class="button btn-save">Save Keys</button></div>
<p class="section-note">Existing entries save when you leave the field. New presets need Save Keys.</p>
<div class="provider-grid">
<?php foreach ($presetRows as $slug => $presetRow):
 foreach (array_merge([ $presetRow ], $presetVariants[$slug]) as $variantIndex => $row):
  $isVariant = $variantIndex > 0;
  $rowId = strval($row['id']);
  $fieldName = $isVariant ? 'preset_variants[' . $rowId . '][api_key]' : 'presets[' . $slug . '][api_key]';
  // With other entries listed, every card shows its saved label and id so the letter case each entry uses is clear.
  $entryName = $presetVariants[$slug] ? strval($row['label']) . ' (ID ' . $rowId . ')' : $presetMap[$slug];
?>
<div class="provider-card<?= $row['configured'] ? ' has-key' : '' ?>">
 <div class="provider-head">
  <div class="provider-title"><div class="provider-icon" aria-hidden="true">&#128273;</div><div><?= htmlspecialchars($entryName) ?></div><span class="key-state"<?= $row['configured'] ? '' : ' hidden' ?>>Key saved</span></div>
  <div class="provider-links"><?php if (!$isVariant && !empty($providerLinks[$slug])): ?><a href="<?= htmlspecialchars($providerLinks[$slug]) ?>" target="_blank" rel="noopener">Create Key</a><?php endif; ?></div>
 </div>
 <?php if (!$isVariant): ?><input type="hidden" name="presets[<?= htmlspecialchars($slug) ?>][id]" value="<?= htmlspecialchars($rowId) ?>"><?php endif; ?>
 <div class="provider-body"><input type="password" name="<?= htmlspecialchars($fieldName) ?>" value="" placeholder="<?= $row['configured'] ? $savedKeyPlaceholder : ($rowId === '' ? 'Paste API key, then press Save Keys' : 'Paste API key') ?>" autocomplete="off" aria-label="<?= htmlspecialchars($entryName) ?> API key" data-autosave="preset" data-provider="<?= htmlspecialchars($slug) ?>" data-id="<?= htmlspecialchars($rowId) ?>"><button type="button" class="button" onclick="toggleVisibility(this)">Show</button><button type="button" class="button btn-clear-key" data-clear-id="<?= htmlspecialchars($rowId) ?>"<?= $row['configured'] ? '' : ' hidden' ?>>Clear</button><?php if (in_array($slug, ['openrouter','openai'])): ?><button type="button" class="btn-save" data-test-provider="<?= htmlspecialchars($slug) ?>" data-badge-id="<?= htmlspecialchars($rowId) ?>">Test</button><?php endif; ?></div>
 <div class="provider-subtext">
  <?php if ($rowId === ''): ?><div class="desc">Not saved yet: paste a key and press Save Keys. After that this field saves when you leave it.</div><?php endif; ?>
  <?php if ($isVariant): ?><div class="desc">This entry has its own key.<?php if (in_array($slug, ['openrouter','deepgram'], true)): ?> Quickstart uses ID <?= htmlspecialchars(strval($presetRow['id'])) ?>.<?php endif; ?></div><?php elseif (!empty($providerSubtext[$slug])): ?><div class="desc">This key can be used for: <?= htmlspecialchars(implode(', ', $providerSubtext[$slug])) ?></div><?php endif; ?>
 </div>
</div>
<?php endforeach; endforeach; ?>
</div></div>
<div class="content-section full-width-section"><h2>Custom Keys</h2><div id="custom-keys" style="margin-bottom:10px;"><?php foreach ($customRows as $row): ?><?php $hasKey = $row['configured']; ?><div class="custom-card <?= $hasKey ? 'has-key' : '' ?>"><div class="provider-head"><div class="provider-title"><div class="provider-icon" aria-hidden="true">&#129513;</div><div>Custom Key</div><span class="key-state"<?= $hasKey ? '' : ' hidden' ?>>Key saved</span></div><div style="display:flex; gap:6px;"><button type="button" class="button btn-save" data-save-id="<?= htmlspecialchars($row['id']) ?>">Save</button><button type="button" class="button btn-delete" data-delete-id="<?= htmlspecialchars($row['id']) ?>">Delete</button></div></div><input type="hidden" name="custom[id][]" value="<?= htmlspecialchars($row['id']) ?>"><label>Label</label><input type="text" name="custom[label][]" value="<?= htmlspecialchars($row['label']) ?>" placeholder="Provider label (e.g., MyService)" data-autosave="custom" data-field="label" data-id="<?= htmlspecialchars($row['id']) ?>"><label>API Key</label><div class="provider-body"><input type="password" name="custom[api_key][]" value="" placeholder="<?= $hasKey ? $savedKeyPlaceholder : 'Paste API key' ?>" autocomplete="off" data-autosave="custom" data-field="api_key" data-id="<?= htmlspecialchars($row['id']) ?>"><button type="button" class="button" onclick="toggleVisibility(this)">Show</button><button type="button" class="button btn-clear-key" data-clear-id="<?= htmlspecialchars($row['id']) ?>"<?= $hasKey ? '' : ' hidden' ?>>Clear</button></div></div><?php endforeach; ?></div><button type="button" class="action-button add-new" onclick="addCustomKey()">Add Custom Key</button></div></div></form><script>
function toggleVisibility(btn){
 const input = btn.parentElement.querySelector('input');
 if(!input) return;
 if(input.type === 'password'){
 input.type = 'text';
 btn.textContent = 'Hide';
 } else {
 input.type = 'password';
 btn.textContent = 'Show';
 }
}
function addCustomKey(){
 const container = document.getElementById('custom-keys');
 const wrapper = document.createElement('div');
 wrapper.className = 'custom-card';
 wrapper.innerHTML = `
 <div class="provider-head"><div class="provider-title"><div class="provider-icon" aria-hidden="true">&#129513;</div><div>Custom Key</div></div><div style="display:flex; gap:6px;"><button type="button" class="button btn-save" data-save-id="">Save</button><button type="button" class="button btn-delete" data-delete-id="">Delete</button></div></div><input type="hidden" name="custom[id][]" value=""><label>Label</label><input type="text" name="custom[label][]" value="" placeholder="Provider label (e.g., MyService)" data-autosave="custom" data-field="label" data-id=""><label>API Key</label><div class="provider-body"><input type="password" name="custom[api_key][]" value="" placeholder="Paste API key" autocomplete="off" data-autosave="custom" data-field="api_key" data-id=""><button type="button" class="button" onclick="toggleVisibility(this)">Show</button></div>
 `;
 container.appendChild(wrapper);
}
</script><script>
// Autosave for presets and custom keys. A field saves once when it loses focus (change), not on every
// keystroke, and requests for one field run in order, so a partial key can never land after the full one.
// A blank key field keeps the saved key; only the confirmed Clear button removes it.
(function(){
 const RESERVED_LABELS = <?php echo json_encode($reservedLabelsLower); ?>;
 const SAVED_PLACEHOLDER = <?php echo json_encode($savedKeyPlaceholder); ?>;
 const pending = new WeakMap();
 const inFlight = new Set();
 function queuePost(owner, payload){
 const prior = pending.get(owner) || Promise.resolve(true);
 const next = prior.then(async function(){
 try {
 const res = await fetch('api_badge.php', { method:'POST', body: payload, credentials:'same-origin' });
 return res.ok && !/[?&]err=/.test(res.url || '');
 } catch(_e){ return false; }
 });
 pending.set(owner, next);
 inFlight.add(next);
 next.finally(function(){ inFlight.delete(next); });
 return next;
 }
 // Save Keys posts the whole form. It first waits for field saves already sent (leaving a field to click the
 // button sends one), so an earlier field save can never land after the full save.
 const saveAllButton = document.querySelector('button[name="save_all"]');
 const pageForm = saveAllButton ? saveAllButton.form : null;
 if (pageForm) pageForm.addEventListener('submit', function(e){
 if (!inFlight.size) return;
 e.preventDefault();
 if (saveAllButton) saveAllButton.disabled = true;
 Promise.allSettled(Array.from(inFlight)).then(function(){
 if (!pageForm.querySelector('input[type="hidden"][name="save_all"]')) {
 const marker = document.createElement('input'); marker.type = 'hidden'; marker.name = 'save_all'; marker.value = '1'; pageForm.appendChild(marker);
 }
 pageForm.submit();
 });
 });
 function csrfToken(){ const el = document.getElementById('api_key_csrf'); return el ? el.value : ''; }
 function settle(owner){ return owner ? (pending.get(owner) || Promise.resolve(true)) : Promise.resolve(true); }
 function keyInputOf(card){ return card ? card.querySelector('input[data-autosave="preset"], input[data-field="api_key"]') : null; }
 function markSaved(card){
 if (!card) return;
 card.classList.add('has-key');
 const state = card.querySelector('.key-state'); if (state) state.hidden = false;
 const clear = card.querySelector('.btn-clear-key'); if (clear && clear.getAttribute('data-clear-id')) clear.hidden = false;
 const input = keyInputOf(card); if (input) input.placeholder = SAVED_PLACEHOLDER;
 }
 document.addEventListener('change', function(e){
 const t = e.target;
 if (!t || !t.getAttribute) return;
 const mode = t.getAttribute('data-autosave');
 if (!mode) return;
 // Existing rows only; new custom rows require explicit Save.
 const id = t.getAttribute('data-id') || '';
 if (!id) return;
 const field = mode === 'preset' ? 'api_key' : (t.getAttribute('data-field') || '');
 if (field !== 'api_key' && field !== 'label') return;
 const value = (t.value || '').trim();
 if (value === '') return; // blank keeps the saved value
 const formData = new FormData();
 formData.append('update','1');
 formData.append('id', id);
 formData.append(field, value);
 queuePost(t, formData).then(function(ok){
 if (!ok) { alert(field === 'api_key' ? 'The API key was not saved. Check it and try again.' : 'The label was not saved.'); return; }
 // Drop the key from the page once it is stored, unless the operator has typed something new.
 if (field === 'api_key' && (t.value || '').trim() === value) {
 t.value = '';
 markSaved(t.closest('.provider-card, .custom-card'));
 }
 });
 });
 // Per-card Save handler
 document.addEventListener('click', async function(e){
 const btn = e.target && e.target.closest && e.target.closest('.btn-save');
 if (!btn) return;
 const card = btn.closest('.custom-card');
 if (!card) return;
 const id = btn.getAttribute('data-save-id') || '';
 const labelInput = card.querySelector('input[name="custom[label][]"]');
 const keyInput = card.querySelector('input[name="custom[api_key][]"]');
 const labelVal = labelInput ? labelInput.value.trim() : '';
 const keyVal = keyInput ? keyInput.value.trim() : '';
 if (!id) {
 if (RESERVED_LABELS.indexOf(labelVal.toLowerCase()) !== -1) {
 alert('That label is reserved for presets. Please use a different name.');
 return;
 }
 }
 const fd = new FormData();
 if (id){
 fd.append('update','1');
 fd.append('id', id);
 fd.append('label', labelVal);
 fd.append('api_key', keyVal);
 } else {
 if (!labelVal && !keyVal) return; // nothing to save
 fd.append('create','1');
 fd.append('label', labelVal || 'Custom Key');
 fd.append('api_key', keyVal);
 }
 await settle(labelInput);
 const saved = await queuePost(keyInput || btn, fd);
 // Keep the typed values when the save failed; reload to reflect the persisted id/state otherwise.
 if (!saved) { alert('The API key was not saved. Check the values and try again.'); return; }
 try { window.location.reload(); } catch(_){}
 }, { passive:true });
 // Clear handler: the only way to remove a saved key. It waits for any save of the same field first.
 document.addEventListener('click', async function(e){
 const btn = e.target && e.target.closest && e.target.closest('.btn-clear-key');
 if (!btn) return;
 const id = btn.getAttribute('data-clear-id') || '';
 if (!id) return;
 if (!confirm('Clear this saved API key? Anything that uses it stops working until a new key is saved.')) return;
 const input = keyInputOf(btn.closest('.provider-card, .custom-card'));
 if (input) input.value = '';
 const fd = new FormData();
 fd.append('clear_key', 'confirmed');
 fd.append('id', id);
 fd.append('csrf_token', csrfToken());
 const ok = await queuePost(input || btn, fd);
 if (!ok) { alert('The API key was not cleared. Reload the page and try again.'); return; }
 try { window.location.reload(); } catch(_){}
 });
 // Delete handler (existing rows: server delete; new rows: remove card)
 document.addEventListener('click', function(e){
 const btn = e.target && e.target.closest && e.target.closest('.btn-delete');
 if (!btn) return;
 const id = btn.getAttribute('data-delete-id') || '';
 const card = btn.closest('.custom-card');
 if (!confirm('Delete this custom API key?')) return;
 if (!id){
 if (card && card.parentNode) card.parentNode.removeChild(card);
 return;
 }
 synthManagementPost('api_badge.php', { delete: id });
 }, { passive:true });
})();

// Modal for testing API keys
(function(){
 const MODAL_ID = 'apikeytest_modal';
 const modal = document.createElement('div');
 modal.id = MODAL_ID;
 modal.style.cssText = 'position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.65); z-index:10000;';
 modal.innerHTML = `
 <div style="width:90%; max-width:900px; height:70vh; background:#111; border:1px solid rgba(138,155,182,0.4); border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.6); position:relative; overflow:hidden;"><button id="apikeytest_close" style="position:absolute; top:8px; right:10px; background:#300; color:#fff; border:1px solid rgba(255,255,255,0.2); border-radius:6px; padding:4px 10px; cursor:pointer; z-index:3;">Close</button><div id="apikeytest_loading" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.4); z-index:2;"><div style="width:48px; height:48px; border:4px solid rgba(255,255,255,0.25); border-top-color:var(--synth-accent-bright); border-radius:50%; animation: spin 1s linear infinite;"></div></div><iframe id="apikeytest_iframe" name="apikeytest_iframe" src="about:blank" style="width:100%; height:100%; border:0; background:#0e1624; position:relative; z-index:1;"></iframe></div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>`;
 document.body.appendChild(modal);
 function openModal(url){ const iframe = document.getElementById('apikeytest_iframe'); const loader = document.getElementById('apikeytest_loading'); if (loader) loader.style.display = 'flex'; iframe.onload = function(){ if (loader) loader.style.display = 'none'; }; iframe.src = url; modal.style.display = 'flex'; }
 function closeModal(){ modal.style.display = 'none'; try { document.getElementById('apikeytest_iframe').src='about:blank'; } catch(_){} }
 document.addEventListener('click', function(e){ if (e.target && e.target.id==='apikeytest_close') closeModal(); });
 modal.addEventListener('click', function(e){ if (e.target===modal) closeModal(); });
 document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeModal(); });

 function ensurePostForm(){
 let form = document.getElementById('apikeytest_form');
 if (form) return form;
 form = document.createElement('form');
 form.id = 'apikeytest_form';
 form.method = 'POST';
 form.target = 'apikeytest_iframe';
 form.action = 'tests/apikey_test.php';
 form.style.display = 'none';
 const inProv = document.createElement('input'); inProv.type='hidden'; inProv.name='provider'; form.appendChild(inProv);
 const inKey = document.createElement('input'); inKey.type='hidden'; inKey.name='api_key'; form.appendChild(inKey);
 const inBadge = document.createElement('input'); inBadge.type='hidden'; inBadge.name='badge_id'; form.appendChild(inBadge);
 const inCsrf = document.createElement('input'); inCsrf.type='hidden'; inCsrf.name='csrf_token'; form.appendChild(inCsrf);
 document.body.appendChild(form);
 return form;
 }
 document.querySelectorAll('button[data-test-provider]').forEach(btn => {
 btn.addEventListener('click', () => {
 const provider = btn.getAttribute('data-test-provider');
 const row = btn.closest('.provider-card');
 const input = row ? row.querySelector('input[data-autosave="preset"]') : null;
 // A typed key is tested as entered; a blank field tests the saved key, which the server looks up.
 const apiKey = input ? input.value.trim() : '';
 const configured = !!(row && row.classList.contains('has-key'));
 if (!apiKey && !configured){ alert('Enter an API key or save one first.'); return; }
 openModal('about:blank');
 const form = ensurePostForm();
 form.querySelector('input[name="provider"]').value = provider;
 form.querySelector('input[name="api_key"]').value = apiKey;
 form.querySelector('input[name="badge_id"]').value = apiKey ? '' : (btn.getAttribute('data-badge-id') || '');
 const tokenField = document.getElementById('api_key_csrf');
 form.querySelector('input[name="csrf_token"]').value = tokenField ? tokenField.value : '';
 try { form.submit(); } catch(_e){}
 form.querySelector('input[name="api_key"]').value = '';
 });
 });
})();

// Lightweight feedback based on query params
(function(){
 try {
 const sp = new URLSearchParams(window.location.search);
 if (sp.get('err') === 'reserved_label') {
 alert('That label is reserved for a preset provider. Please choose another.');
 } else if (sp.get('err') === 'invalid_key') {
 alert('An API key was not saved: it must be one line of text without control characters, up to 4096 characters.');
 } else if (sp.get('err') === 'save_failed') {
 alert('Some changes were not saved. A label may already be in use; check the entries and try again.');
 } else if (sp.get('err') === 'stale') {
 alert('Nothing was saved: the saved API key entries changed since this page loaded. Check the reloaded page and enter the key again.');
 } else if (sp.get('err') === 'not_found') {
 alert('That API key entry no longer exists. Reload the page and try again.');
 } else if (sp.get('ok') === 'saved') {
 // no-op to avoid noisy alerts; keep silent success
 }
 } catch(_e){}
})();
</script></main><?php
include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/footer.html");
$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?>

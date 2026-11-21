<?php

// Snapshot create, restore and delete are paused (lib/playthrough_schema.php). This page only lists and
// inspects existing profiles. Any other request is refused before configuration or the database is loaded.
if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
 http_response_code(405);
 header('Allow: GET, HEAD');
 header('Cache-Control: no-store');
 header('Content-Type: text/plain; charset=UTF-8');
 echo "Playthrough snapshots are unavailable. Nothing was changed.\n";
 exit;
}

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

require_once(__DIR__.DIRECTORY_SEPARATOR."profile_loader.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . (\Synth\Bootstrap::config('DBDRIVER') . ".class.php"));
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "playthrough_schema.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");

// Determine web root (match other core pages)
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
 $webRoot = substr($scriptPath, 0, $uiPos);
} else {
 $webRoot = '';
}
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

$TITLE = " SYNTH - Playthrough Manager";
ob_start();
include(__DIR__.DIRECTORY_SEPARATOR."tmpl/head.html");
?><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css"><?php
// Embed mode and navbar (match WorldKnowledge style)
$isEmbed = (isset($_GET['embed']) && $_GET['embed'] == '1');
$debugPaneLink = false;
$dbSettings = synthDbConnectionSettings('synth');

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatFileSize($bytes) {
 if ($bytes == 0) return '0 Bytes';
 $k = 1024; $sizes = ['Bytes','KB','MB','GB','TB'];
 $i = floor(log($bytes) / log($k));
 return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Read-only connection use: readiness check and profile listing. Bootstrap owns the schema.
$metaReady = false;
$pageError = '';
$profiles = [];
$adminDb = null;
try {
 $adminDb = (\Synth\Bootstrap::has('db') && is_object(\Synth\Bootstrap::db())) ? \Synth\Bootstrap::db() : \Synth\Bootstrap::boot(rtrim($enginePath, DIRECTORY_SEPARATOR))->db;
} catch (Throwable $e) {
 $adminDb = null;
}
if ($adminDb === null) {
 $pageError = 'Could not connect to the database. Check the server log.';
} else {
 $metaReady = pts_metadata_schema_ready($adminDb->getCatalogueSqlConnection());
 if (!$metaReady) {
  $pageError = 'Playthrough metadata is unavailable. Run the SYNTH database bootstrap.';
 } else {
  try {
   $profiles = \Synth\Core\DbManager\Dao\PlaythroughProfileDao::current($adminDb)->managerRows();
  } catch (Throwable $e) {
   $profiles = [];
  }
 }
}
foreach ($profiles as $i => $p) {
 $profiles[$i]['is_active'] = in_array($p['is_active'] ?? null, ['t', 'true', '1', 1, true], true);
}

// Use profile metadata for the initial render (fast), refresh live stats via AJAX later
$activeProfileName = '';
$livePlayerName = (string)(\Synth\Bootstrap::config('PLAYER_NAME') ?? 'Unknown');
$liveGameName = 'Fallout';
$liveEventlogCount = 0; $liveWorldKnowledgeCount = 0; $liveLastGamets = 0;
foreach ($profiles as $p) {
 if ($p['is_active']) {
  $activeProfileName = (string)($p['name'] ?? '');
  $liveEventlogCount = intval($p['eventlog_count'] ?? 0);
  $liveWorldKnowledgeCount = intval($p['worldknowledge_count'] ?? 0);
  $liveLastGamets = intval($p['last_gamets'] ?? 0);
  break;
 }
}
$liveFalloutDate = ($liveLastGamets > 0) ? convert_gamets2fallout_long_date($liveLastGamets) : '';

// Prepare timeline items based on last_gamets
$timelineItems = [];
foreach ($profiles as $p) {
 $isActive = $p['is_active'];
 $lgMeta = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
 $lg = $lgMeta;
 if ($lg <= 0 && $isActive && $liveLastGamets > 0) { $lg = $liveLastGamets; }
 if ($lg <= 0) { continue; }
 $timelineItems[] = [
 'id' => (int)$p['id'],
 'name' => (string)($p['name'] ?? ''),
 'last_gamets' => $lg,
 'fallout_date' => convert_gamets2fallout_long_date($lg),
 'created_at' => (string)$p['created_at'],
 'size' => formatFileSize((int)$p['size_bytes']),
 'is_active' => $isActive
 ];
}

// Timeline ticks (static notches with labels)
$timelineTicks = [];
if (!empty($timelineItems)) {
 $values = array_map(function($i){ return (int)$i['last_gamets']; }, $timelineItems);
 $minGamets = min($values);
 $maxGamets = max($values);
 $segments = min(max(count($timelineItems) - 1, 4), 12); // 4..12 ticks based on data
 if ($maxGamets === $minGamets) {
 // Degenerate: place a center tick
 $timelineTicks[] = [
 'gamets' => $minGamets,
 'date' => convert_gamets2fallout_long_date($minGamets)
 ];
 } else {
 for ($s = 0; $s <= $segments; $s++) {
 $g = (int)round($minGamets + ($s * ($maxGamets - $minGamets) / $segments));
 $timelineTicks[] = [
 'gamets' => $g,
 'date' => convert_gamets2fallout_long_date($g)
 ];
 }
 }
}

?><style>
 main { padding-top: 80px; padding-bottom: 40px; padding-left: 10%; padding-right: 10%; width: 100%; margin: 0; }
 footer { position: fixed; bottom: 0; width: 100%; height: 20px; background: #031633; z-index: 100; }

 .page-header {
 text-align: center;
 margin-bottom: 30px;
 padding: 20px;
 background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 border-radius: 10px;
 border: 1px solid #3a3a3a;
 box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
 }

 .page-header h1 {
 margin-bottom: 8px;
 font-family: 'Gothic821', serif;
 word-spacing: 8px;
 font-size: 2.0em;
 color: var(--synth-accent);
 text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
 }


 .content-section {
 margin-bottom: 20px;
 background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
 padding: 25px;
 border-radius: 10px;
 border: 1px solid #3a3a3a;
 box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
 transition: border-color 0.3s ease, box-shadow 0.3s ease;
 }

 .content-section:hover {
 border-color: rgba(var(--synth-accent-rgb), 0.3);
 box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
 }

 .content-section h2 {
 font-family: 'Gothic821', serif;
 color: var(--synth-accent);
 text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
 word-spacing: 6px;
 margin-bottom: 15px;
 font-size: 1.4em;
 }

 .full-width-section { grid-column: 1 / -1; }
 .button-group { display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap; }
 .snapshot-status { border-left: 4px solid #fbbf24; }
 .snapshot-status h2 { font-size: 1.2em; margin-bottom: 8px; }
 .snapshot-status p { margin: 0; color: #e0e0e0; }
 .snapshot-status .snapshot-error { margin-top: 8px; color: #fca5a5; }
 @media (max-width: 768px) { main { padding-left: 5%; padding-right: 5%; } .content-section { padding: 15px; } }
 /* Timeline */
 .timeline { position: relative; padding: 28px 8px 30px 8px; }
 .timeline-title { text-align:center; color:#e0e0e0; font-size: 13px; margin-bottom: 12px; }
 .timeline-track { position: relative; height: 4px; background: linear-gradient(90deg, rgba(138,155,182,0.5), rgba(var(--synth-accent-rgb), 0.6)); border-radius: 2px; }
 .timeline-nodes { position: relative; height: 0; }
 .timeline-node { position: absolute; top: -8px; width: 16px; height: 16px; border-radius: 50%; background: var(--synth-accent-bright); border: 2px solid #1a1a1a; box-shadow: 0 0 0 2px rgba(255,255,255,0.08); transform: translateX(-50%); cursor: default; }
 .timeline-node:focus-visible { outline: 2px solid #fbbf24; outline-offset: 2px; }
 .timeline-node.active { background: #2ea8ff; box-shadow: 0 0 0 2px rgba(46,168,255,0.25), 0 0 12px rgba(46,168,255,0.35); }
 .timeline-tooltip { position: absolute; display: none; max-width: 280px; overflow-wrap: anywhere; background: #111; border: 1px solid rgba(138,155,182,0.4); color: #e0e0e0; padding: 8px 10px; border-radius: 6px; font-size: 12px; z-index: 20; pointer-events: none; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
 .timeline-tooltip .name { color: var(--synth-accent-bright); font-weight: bold; }
 .timeline-legend { display:flex; justify-content:space-between; font-size: 12px; color:#9fb1c9; margin-top: 8px; }
 .timeline-notches { position: relative; height: 0; }
 .timeline-notch { position: absolute; top: -12px; width: 2px; height: 10px; background: #9fb1c9; opacity: 0.7; transform: translateX(-50%); }
 .timeline-notch.major { height: 14px; background:#e0e0e0; opacity: 0.9; }
 .timeline-tick-label { position: absolute; top: -30px; transform: translateX(-50%); color:#9fb1c9; font-size: 11px; white-space: nowrap; pointer-events: none; }
 .timeline-label { position: absolute; top: -28px; transform: translateX(-50%); color:#9fb1c9; font-size: 11px; white-space: nowrap; pointer-events: none; }
 .timeline-label.active { color:#eaee05; }
 /* Timeline Break styling */
 .backup-item.timelinebreak { background-color: #1e2a3a; }
 .backup-item.timelinebreak:hover { background-color: #223044; }
</style><?php if ($isEmbed): ?><style> main { padding-top: 20px; } </style><?php endif; ?><main><div class="page-header"><h1>Playthrough Manager</h1><div style="font-size: 0.95em; color: #ccc;">View stored playthrough snapshots.</div></div><div class="content-section full-width-section snapshot-status" role="status" aria-live="polite"><h2>Snapshots unavailable</h2><p>Creating and loading snapshots is paused. Existing snapshots are kept. Loading your Fallout saves works as normal.</p><?php if ($pageError !== '') { ?><p class="snapshot-error" role="alert"><strong>Error:</strong> <?php echo h($pageError); ?></p><?php } ?></div><div class="content-section full-width-section" style="background: linear-gradient(135deg, #1a3a2a 0%, #2a2a2a 100%); border: 2px solid #4ade80;"><h2 style="margin: 0 0 4px 0;">Active Database</h2><div style="font-size: 0.9em; color: #9fb1c9; margin-bottom: 15px;">The live database SYNTH is using.</div><div style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 6px; border-left: 4px solid #4ade80;"><div style="display:flex; gap:12px; flex-wrap:wrap; font-size: 14px; color:#ccc; align-items: center;"><?php if ($activeProfileName !== '') { ?><div style="color: #4ade80; font-weight: bold;">Marked active: <?php echo h($activeProfileName); ?></div><?php } ?><div><strong style="color:#f8f9fa;">Player:</strong> <span id="live-player"><?php echo h($livePlayerName); ?></span></div><div><strong style="color:#f8f9fa;">Game:</strong> <span id="live-game"><?php echo h($liveGameName); ?></span></div><div><strong style="color:#f8f9fa;">Events:</strong> <span id="live-eventlog"><?php echo intval($liveEventlogCount); ?></span></div><div><strong style="color:#f8f9fa;">WorldKnowledge:</strong> <span id="live-worldknowledge"><?php echo intval($liveWorldKnowledgeCount); ?></span></div><div><strong style="color:#f8f9fa;">Last in-game date:</strong> <span id="live-last"><?php echo h($liveFalloutDate !== '' ? $liveFalloutDate : 'n/a'); ?></span></div></div></div><?php if (!empty($timelineItems)) { ?><div class="timeline" id="pt-timeline"><div class="timeline-title" id="pt-title"></div><div class="timeline-track"></div><div class="timeline-notches" id="pt-timeline-notches" aria-hidden="true"></div><div class="timeline-nodes" id="pt-timeline-nodes"></div><div class="timeline-legend"><span id="pt-min"></span><span id="pt-max"></span></div><div class="timeline-tooltip" id="pt-tooltip" aria-hidden="true"></div></div><?php } ?></div><div class="content-section full-width-section"><h2>Stored Snapshots</h2><?php if (empty($profiles)) { ?><div style="text-align:center; color:#ccc; padding: 12px;">No stored snapshots.</div><?php } else { ?><ul class="backup-list" aria-label="Stored snapshots" style="list-style: none; max-height: 420px; overflow-y:auto; padding: 0; margin: 0; border: 1px solid #333333; border-radius: 8px; background-color: #1a1a1a;"><?php foreach ($profiles as $p) {
 $nm = strtolower((string)($p['name'] ?? ''));
 $nt = strtolower((string)($p['notes'] ?? ''));
 $isTimeline = (strpos($nm,'timeline') !== false) || (strpos($nt,'timeline') !== false);
 ?><li class="backup-item<?php echo $isTimeline ? ' timelinebreak' : ''; ?>" style="padding: 12px; border-bottom: 1px solid #333333; <?php if ($p['is_active']) { echo 'background: rgba(74, 222, 128, 0.1); border-left: 4px solid #4ade80;'; } ?>"><div style="font-weight:bold; font-size: 14px; word-break: break-word;"><?php if ($p['is_active']) { ?><span style="background:#4ade80; color:#000; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:bold; margin-right:6px;">ACTIVE</span><?php } ?><?php echo h($p['name']); ?><?php
 $lg = isset($p['last_gamets']) ? intval($p['last_gamets']) : 0;
 if ($liveLastGamets > 0 && $lg > 0) {
 if ($lg === $liveLastGamets) {
 echo '<span style="color:#9fb1c9; margin-left:6px;">| Current Time</span>';
 } elseif ($lg < $liveLastGamets) {
 $d = gamets2days_between($lg, $liveLastGamets);
 $txt = $d.' '.($d===1?'day':'days').' behind';
 echo '<span style="color:#f87171; margin-left:6px;">| '.h($txt).'</span>';
 } else {
 $d = gamets2days_between($liveLastGamets, $lg);
 $txt = $d.' '.($d===1?'day':'days').' ahead';
 echo '<span style="color:#4ade80; margin-left:6px;">| '.h($txt).'</span>';
 }
 }
 $skDate = $lg > 0 ? convert_gamets2fallout_long_date($lg) : '';
 ?></div><div style="font-size: 12px; color:#ccc; display:flex; gap:10px; flex-wrap:wrap;"><span><?php echo h($p['created_at']); ?></span><span><?php echo h(formatFileSize((int)$p['size_bytes'])); ?></span><span>Player: <?php echo h($p['player_name'] ?? ''); ?></span><span>Game: <?php echo h($p['game'] ?? 'Fallout'); ?></span><span>eventlog: <?php echo intval($p['eventlog_count'] ?? 0); ?></span><span>worldknowledge: <?php echo intval($p['worldknowledge_count'] ?? 0); ?></span><span>last in-game: <?php echo h($skDate !== '' ? $skDate : 'n/a'); ?></span></div><?php if (!empty($p['notes'])) { ?><div style="font-size: 12px; color:#9fb1c9; margin-top: 4px; word-break: break-word;"><?php echo h($p['notes']); ?></div><?php } ?></li><?php } ?></ul><?php } ?></div></main><?php
$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?><script>
(function(){
 const items = <?php echo json_encode($timelineItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
 const ticks = <?php echo json_encode($timelineTicks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
 if (!items || !items.length) return;
 const nodesEl = document.getElementById('pt-timeline-nodes');
 const notchesEl = document.getElementById('pt-timeline-notches');
 const trackEl = document.querySelector('#pt-timeline .timeline-track');
 const tooltip = document.getElementById('pt-tooltip');
 const minEl = document.getElementById('pt-min');
 const maxEl = document.getElementById('pt-max');
 const titleEl = document.getElementById('pt-title');
 if (!nodesEl || !trackEl) return;

 const values = items.map(i => i.last_gamets);
 const min = Math.min.apply(null, values);
 const max = Math.max.apply(null, values);
 const minItem = items.find(i => i.last_gamets === min);
 const maxItem = items.find(i => i.last_gamets === max);
 const minLabel = minItem ? minItem.fallout_date : String(min);
 const maxLabel = maxItem ? maxItem.fallout_date : String(max);
 minEl && (minEl.textContent = 'Earliest: ' + minLabel);
 maxEl && (maxEl.textContent = 'Latest: ' + maxLabel);

 function pct(x){
 if (max === min) return 50; // collapse to center if identical
 return ((x - min) / (max - min)) * 100;
 }

 function showTipAt(clientX, clientY, html){
 if (!tooltip) return;
 tooltip.innerHTML = html;
 tooltip.style.display = 'block';
 // Offsets are relative to the tooltip's containing block (.timeline), clamped so it never leaves the timeline.
 const box = tooltip.offsetParent || nodesEl;
 const rect = box.getBoundingClientRect();
 tooltip.style.left = '0px'; // measure the unshrunk width before clamping
 const maxLeft = Math.max(0, box.clientWidth - tooltip.offsetWidth);
 tooltip.style.left = Math.min(Math.max(0, clientX - rect.left - box.clientLeft + 10), maxLeft) + 'px';
 tooltip.style.top = (clientY - rect.top - box.clientTop + 14) + 'px';
 }
 function showTip(e, html){ showTipAt(e.clientX, e.clientY, html); }
 function hideTip(){ if (tooltip) tooltip.style.display = 'none'; }

 items.sort((a,b) => a.last_gamets - b.last_gamets);
 items.forEach(it => {
 const node = document.createElement('div');
 node.className = 'timeline-node' + (it.is_active ? ' active' : '');
 node.style.left = pct(it.last_gamets) + '%';
 node.setAttribute('role','img');
 node.setAttribute('tabindex','0');
 node.setAttribute('aria-label', it.name + ', Fallout date ' + it.fallout_date + (it.is_active ? ', active' : ''));
 const tip = `<div class="name">${escapeHtml(it.name)}</div><div>Fallout date: ${escapeHtml(it.fallout_date)}</div><div>Created: ${escapeHtml(it.created_at)}</div><div>Size: ${escapeHtml(it.size)}</div>`;
 node.addEventListener('mouseenter', (e)=>showTip(e, tip));
 node.addEventListener('mousemove', (e)=>showTip(e, tip));
 node.addEventListener('mouseleave', hideTip);
 node.addEventListener('focus', ()=>{ const r = node.getBoundingClientRect(); showTipAt(r.left + r.width / 2, r.top + r.height / 2, tip); });
 node.addEventListener('blur', hideTip);
 node.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') hideTip(); });
 nodesEl.appendChild(node);
 });

 // Position "You are here" marker at the active profile's position
 // Blue active node styling remains; no arrow/label needed

 // Static ticks (major/minor) with labels aligned to gamets scale
 if (notchesEl && ticks && ticks.length) {
 const values = items.map(i => i.last_gamets);
 const min = Math.min.apply(null, values);
 const max = Math.max.apply(null, values);
 const isDegenerate = (max === min);
 const pct = (x) => isDegenerate ? 50 : ((x - min) / (max - min)) * 100;
 ticks.forEach((t, idx) => {
 const notch = document.createElement('div');
 notch.className = 'timeline-notch' + ((idx % 2 === 0) ? ' major' : '');
 notch.style.left = pct(t.gamets) + '%';
 notchesEl.appendChild(notch);
 // Add labels for major interior ticks only (skip first and last)
 if (idx % 2 === 0 && idx > 0 && idx < (ticks.length - 1)) {
 const lbl = document.createElement('div');
 lbl.className = 'timeline-tick-label';
 lbl.style.left = pct(t.gamets) + '%';
 lbl.textContent = t.date;
 notchesEl.appendChild(lbl);
 }
 });
 }

 document.addEventListener('scroll', hideTip, { passive:true });
 window.addEventListener('resize', hideTip, { passive:true });

 function escapeHtml(s){
 return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
 }
 // Async refresh of live stats to avoid heavy COUNT(*) on render
 try {
 fetch('<?php echo $webRoot; ?>/ui/playthrough_stats.php', { credentials:'same-origin' })
 .then(r => r.ok ? r.json() : null)
 .then(j => {
 if (!j) return;
 const ev = document.getElementById('live-eventlog');
 const og = document.getElementById('live-worldknowledge');
 const la = document.getElementById('live-last');
 if (typeof j.eventlog === 'number' && ev) ev.textContent = String(j.eventlog);
 if (typeof j.worldknowledge === 'number' && og) og.textContent = String(j.worldknowledge);
 if (typeof j.last_fallout_date === 'string' && la) la.textContent = j.last_fallout_date || 'n/a';
 })
 .catch(()=>{});
 } catch (_e) {}
})();
</script>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "footer.html"); ?>

<?php
// Dashboard index. GET only reads tables or redirects; schema updates, worker start, log and backup maintenance
// belong to their own owners, and conf/conf.php is optional (the runtime reads the tracked sample, the environment
// and an operator override when present) and is never copied, backed up or emptied here.
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "management_request_guard.php");
error_reporting(E_ERROR);

// The bare landing URL goes straight to Home, before any session, configuration or database work.
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && empty($_GET)) {
 header("Location: home.php");
 exit;
}

// Former destructive GET links are refused and the confirmed POST controls below must carry the session token,
// both before bootstrap. Reinstall has no web route at all (bin/install-db.php is command-line only).
synthManagementActionGate(['clean', 'reset', 'cleanlog', 'delete_last', 'delete_memory', 'reinstall'], ['index_action'], ['rowid']);

if (session_status() === PHP_SESSION_NONE) {
 session_start();
}

if (!function_exists('pg_connect')) {
 require_once(__DIR__ . DIRECTORY_SEPARATOR . "synth_setup.php");
 exit;
}

// Get the relative web path from document root to our application
$scriptPath = $_SERVER['SCRIPT_NAME'];
$webRoot = dirname(dirname($scriptPath)); // Go up two levels from the script location
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

// Define base paths
define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'conf');
define('LIB_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'lib');
define('LOG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'log');

// Load profiles through the centralized profile loader
require_once(__DIR__.DIRECTORY_SEPARATOR."profile_loader.php");
require_once(LIB_PATH . DIRECTORY_SEPARATOR . "synth_command_payload.php");

$TITLE = "SYNTH";

ob_start();

include(__DIR__.DIRECTORY_SEPARATOR."tmpl/head.html");
?><link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css"><style>
 /* Override main container styles */
 main {
 padding-top: <?php echo ((isset($_GET['embed']) && $_GET['embed'])) ? '20' : '160'; ?>px; /* Space for navbar */
 padding-bottom: 40px; /* Reduced space for footer */
 padding-left: 10px;
 }

 /* Override footer styles */
 footer {
 <?php if (isset($_GET['embed']) && $_GET['embed']) { echo 'display:none;'; } else { echo 'position: fixed; bottom: 0; width: 100%; height: 20px; background: #031633; z-index: 100;'; } ?>
 }

 /* Additional index-specific styles */
 .container {
 max-width: 1200px;
 margin: 0 auto;
 padding: 20px;
 }

 /* Table Container Styles */
 .table-container {
 background-color: #2a2a2a;
 border-radius: 5px;
 padding: 15px;
 margin-bottom: 20px;
 overflow-x: auto;
 box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
 }

 /* Table Styles */
 table {
 width: 100%;
 border-collapse: collapse;
 background-color: #3a3a3a;
 margin-bottom: 20px;
 font-size: small;
 }

 /* Header Cells */
 th {
 background-color: #1a1a1a;
 color: #fff;
 font-weight: bold;
 padding: 12px;
 text-align: left;
 border-bottom: 2px solid #444;
 }

 /* Data Cells */
 td {
 padding: 10px;
 text-align: left;
 border-bottom: 1px solid #444;
 color: #f8f9fa;
 }

 /* Row Alternating Colors */
 tr:nth-child(even) {
 background-color:rgb(77, 77, 77);
 }

 /* Button Cell Alignment */
 td:has(button), td:has(.btn-base) {
 text-align: center;
 }

 /* Responsive Table */
 @media (max-width: 768px) {
 .table-container {
 margin: 10px -15px;
 border-radius: 0;
 }

 table {
 font-size: smaller;
 }

 th, td {
 padding: 8px;
 }
 }
</style><?php

$hide_navbar = ((isset($_GET["navbar"])) && ($_GET["navbar"] == "hidden"));
if (isset($_GET['embed']) && $_GET['embed']) { $hide_navbar = true; }
// Remove redundant profile loading code here and go straight to lib loading
require_once(LIB_PATH .DIRECTORY_SEPARATOR."logger.php");
require_once(LIB_PATH .DIRECTORY_SEPARATOR."{$GLOBALS["DBDRIVER"]}.class.php");
require_once(LIB_PATH .DIRECTORY_SEPARATOR."misc_ui_functions.php");
require_once(LIB_PATH .DIRECTORY_SEPARATOR."chat_helper_functions.php");
require_once(LIB_PATH .DIRECTORY_SEPARATOR."background_processor.php");

$db = new sql();

/* Destructive log actions: confirmed same-origin POSTs whose token synthManagementActionGate() checked above. */
$indexAction = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? strval($_POST['index_action'] ?? '') : '';
if ($indexAction !== '') {
 $deleteCount = intval($_POST['delete_last'] ?? 0);
 if ($indexAction === 'reset_events') {
  $db->delete("core_context_event", "true");
  $redirect = "index.php?table=eventlog";
 } elseif ($indexAction === 'delete_last' && in_array($deleteCount, [20, 50, 100], true)) {
  // Count comes from the allowlist above.
  $db->query("
 DELETE FROM core_context_event
 WHERE rowid IN (
 SELECT rowid
 FROM core_context_event
 WHERE type NOT IN ('prechat','rechat','request','nearby_actors')
 ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC
 LIMIT $deleteCount
 )
 ");
  $redirect = "index.php?table=eventlog";
 } elseif ($indexAction === 'delete_memory' && intval($_POST['rowid'] ?? 0) > 0) {
  $db->delete('core_memory', "rowid = " . intval($_POST['rowid']));
  $redirect = "index.php?table=memory_summary&deleted=1";
 } elseif ($indexAction === 'clean_log') {
  $db->delete("log", "true");
  $redirect = "index.php?table=log";
 } else {
  while (@ob_end_clean());
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  exit("Unknown dashboard action.\n");
 }
 while (@ob_end_clean());
 header("Location: $redirect", true, 303);
 exit;
}
$indexCsrfField = "<input type='hidden' name='csrf_token' value='" . htmlspecialchars(synthManagementCsrfToken(), ENT_QUOTES) . "'>";

if (isset($_GET["export"]) && ($_GET["export"] == "log")) {
 while (@ob_end_clean());

 header("Content-Type: text/csv");
 header("Content-Disposition: attachment; filename=log.csv");

 $data = $db->fetchAll("select response,url,prompt,rowid from log order by rowid desc");
 $n = 0;
 foreach ($data as $row) {
 if ($n == 0) {
 echo "'" . implode("'\t'", array_keys($row)) . "'\n";
 $n++;
 }
 $rowCleaned = [];
 foreach ($row as $cellname => $cell) {
 if ($cellname == "prompt")
 $cell = base64_encode(br2nl($cell));
 $rowCleaned[] = strtr($cell, array("\n" => " ", "\r" => " ", "'" => "\""));
 }

 echo "'" . implode("'\t'", ($rowCleaned)) . "'\n";
 }
 die();
}

if (isset($_GET["export"]) && ($_GET["export"] == "diary")) {
 while (@ob_end_clean());

 header("Content-Type: text/csv");
 header("Content-Disposition: attachment; filename=diarylog.txt");

 $data = $db->fetchAll("select topic,summary AS content from core_memory where source_type='diary' order by rowid desc");
 $n = 1;
 foreach ($data as $row) {
 if ($n == 0) {
 echo "'" . implode("'\t'", array_keys($row)) . "'\n";
 $n++;
 }
 $rowCleaned = [];
 foreach ($row as $cellname => $cell) {
 if ($cellname == "prompt")
 $cell = base64_encode(br2nl($cell));
 $rowCleaned[] = strtr($cell, array("\n" => " ", "\r" => " ", "'" => "\""));
 }

 echo "'" . implode("'\t'", ($rowCleaned)) . "'\n";
 }
 die();
}

if (isset($_POST["command"])) {
 $commandPayload = synthEncodeCommandAction(
 trim(strval($_POST["command"] ?? "")),
 trim(strval($_POST["parameter"] ?? "")) !== "" ? [trim(strval($_POST["parameter"]))] : []
 );
 $db->insert(
 'responselog',
 array(
 'localts' => time(),
 'sent' => 0,
 'text' => $commandPayload,
 'actor' => "{$GLOBALS["SYNTH_NAME"]}",
 'action' => 'command'
 )
 );
 header("Location: index.php?table=response");
}

if (isset($_POST["animation"])) {
 $db->insert(
 'responselog',
 array(
 'localts' => time(),
 'sent' => 0,
 'text' => trim($_POST["animation"]),
 'actor' => "{$GLOBALS["SYNTH_NAME"]}",
 'action' => 'animation'
 )
 );
 header("Location: index.php?table=response");
}

?><!-- navbar --><?php
?><!--<a href='index.php?openai=true' >OpenAI API Usage</a> --><div class="clearfix"></div><div class="container-fluid"><!-- auto info --><?php
 if (isset($_GET["autorefresh"])) {
 echo '<script>document.body.classList.add("auto-refresh");</script>';
 ?><p class="my-2"><small class='text-body-secondary fs-5'>Autorefreshes every 5 secs</small></p><?php
 }

 /* Actions */
 if (file_exists("index_custom.php")) include_once("index_custom.php"); // custom actions extension

 if (isset($_GET["table"]) && ($_GET["table"] == "responselog")) {
 $results = $db->fetchAll("select A.*,ROWID FROM responselog a order by ROWID asc");
 echo "<h1 class='my-2'>Response Queue</h1>";
 print_array_as_table($results);
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "eventlog")) {

 // Include game timestamp utilities if not already included
 require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."utils_game_timestamp.php");


 // 1) "Delete Last X" and "Delete ALL" are handled as POST actions near the top of this page.

 // 2) Continue with regular fetch/display logic
 $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 100;
 $page = isset($_GET["page"]) ? max(1, intval($_GET["page"])) : 1;
 $offset = ($page - 1) * $limit;

 $results = $db->fetchAll(
 "SELECT type, data, gamets, localts, ts, ROWID
 FROM core_context_event a
 WHERE type NOT IN ('prechat','rechat','request','user_input','infosave','init','playerinfo','world_context','nearby_actors','nearby_items','points_of_interest')
 ORDER BY gamets DESC, ts DESC, localts DESC, rowid DESC
 LIMIT $limit OFFSET $offset"
 );

 $columnHeaders = [
 'type' => 'Event',
 'data' => 'Data',
 'gamets' => '<a href="https://fallout.fandom.com/wiki/Timeline" target="_blank" style="color: yellow;">Fallout Time</a>',
 'localts' => 'Time (UTC)',
 'ts' => 'TS',
 ];

 $mappedResults = array_map(function ($row) use ($columnHeaders) {
 $mappedRow = [];
 foreach ($row as $key => $value) {
 if ($key === 'gamets' && !empty($value)) {
 // Convert gamets to Fallout date format
 $value = convert_gamets2fallout_long_date2($value);
 }
 else if ($key === 'localts' && !empty($value)) {
 // Format localts to match adventure log format
 $dt = new DateTime("@$value");
 $dt->setTimezone(new DateTimeZone('UTC'));
 $value = $dt->format('d-m-Y H:i:s');
 }

 // Special handling for chat events
 if ($row['type'] === 'chat' && ($key === 'data' || $key === 'type')) {
 $value = '<span style="color:rgb(255, 255, 255);">' . htmlspecialchars($value) . '</span>';
 } else {
 $value = htmlspecialchars($value);
 }
 $mappedRow[$columnHeaders[$key] ?? $key] = $value;
 }
 return $mappedRow;
 }, $results);

 // Event Log title with integrated monitor toggle
 $isAutoRefresh = isset($_GET["autorefresh"]) && $_GET["autorefresh"];
 echo "<div style='display: flex; align-items: center; margin: 20px 0;'>";
 echo "<h1 class='my-2' style='margin-right: 15px;'>Event Log</h1>";

 if ($isAutoRefresh) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=1'\" class='btn-base btn-secondary' style='padding: 8px 12px; font-size: 0.9em;' title='Stop monitoring events'> Stop Live</button>";
 echo "<span style='margin-left: 10px; color: #28a745; font-weight: bold; font-size: 0.9em;'> LIVE</span>";
 } else {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=1&autorefresh=true'\" class='btn-base btn-primary' style='padding: 8px 12px; font-size: 0.9em;' title='Start monitoring events with auto-refresh'> Monitor Live</button>";
 }
 echo "</div>";

 // 3) Generate pagination buttons
 $prevPage = max(1, $page - 1);
 $nextPage = $page + 1;

 // Get total count for pagination
 $countQuery = "SELECT COUNT(*) as total FROM core_context_event WHERE type NOT IN ('prechat','rechat','request','user_input','infosave','init','world_context','nearby_actors','nearby_items','points_of_interest')";
 $countResult = $db->fetchAll($countQuery);
 $totalRecords = $countResult[0]['total'];
 $totalPages = ceil($totalRecords / $limit);

 echo "<div class='pagination-buttons' style='margin: 10px 0;'>";

 // Previous button
 if ($page > 1) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$prevPage&limit=$limit'\" class='btn-base btn-primary'>Previous</button> ";
 }

 // Smart pagination: show current page and surrounding pages
 if ($totalPages <= 10) {
 // Show all pages if 10 or fewer
 for ($i = 1; $i <= $totalPages; $i++) {
 if ($i == $page) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$i&limit=$limit'\" class='btn-base btn-secondary' style='background-color: #6c757d;'>$i</button> ";
 } else {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$i&limit=$limit'\" class='btn-base btn-primary'>$i</button> ";
 }
 }
 } else {
 // Always show first page
 if ($page == 1) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=1&limit=$limit'\" class='btn-base btn-secondary' style='background-color: #6c757d;'>1</button> ";
 } else {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=1&limit=$limit'\" class='btn-base btn-primary'>1</button> ";
 }

 // Show ellipsis if current page is far from start
 if ($page > 4) {
 echo "<span style='margin: 0 5px; color: #fff;'>...</span>";
 }

 // Show pages around current page
 $start = max(2, $page - 2);
 $end = min($totalPages - 1, $page + 2);

 for ($i = $start; $i <= $end; $i++) {
 if ($i == $page) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$i&limit=$limit'\" class='btn-base btn-secondary' style='background-color: #6c757d;'>$i</button> ";
 } else {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$i&limit=$limit'\" class='btn-base btn-primary'>$i</button> ";
 }
 }

 // Show ellipsis if current page is far from end
 if ($page < $totalPages - 3) {
 echo "<span style='margin: 0 5px; color: #fff;'>...</span>";
 }

 // Always show last page
 if ($page == $totalPages) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$totalPages&limit=$limit'\" class='btn-base btn-secondary' style='background-color: #6c757d;'>$totalPages</button> ";
 } else {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$totalPages&limit=$limit'\" class='btn-base btn-primary'>$totalPages</button> ";
 }
 }

 // Next button - only show if not on last page
 if ($page < $totalPages) {
 echo "<button onclick=\"window.location.href='?table=eventlog&page=$nextPage&limit=$limit'\" class='btn-base btn-primary'>Next</button>";
 }

 echo "</div>";

 // 4) Display the "Delete Last X" buttons and "Delete All Events" button
 echo "<form method='post' action='index.php?table=eventlog' style='margin: 10px 0;'>$indexCsrfField";
 foreach ([20, 50, 100] as $deleteOption) {
 echo "<button type='submit' name='index_action' value='delete_last'
 onclick=\"if(confirm('Are you sure you want to delete the last $deleteOption events?')) { this.form.delete_last.value='$deleteOption'; return true; } return false;\"
 class='btn-base btn-danger'>
 Delete Latest $deleteOption
 </button> ";
 }
 echo "<input type='hidden' name='delete_last' value=''>";
 echo "<button type='submit' name='index_action' value='reset_events'
 onclick=\"return deleteAllEventsConfirm()\"
 class='btn-base btn-danger' style='margin-left: 20px; background-color: #dc2626; font-weight: bold;'>
 Delete ALL Events
 </button>";

 // Add JavaScript function for secure confirmation
 echo "<script>
 function deleteAllEventsConfirm() {
 var userInput = prompt('THIS WILL DELETE ALL EVENTS IN THE EVENT LOG!\\n\\nEvents are used for AI context. This action cannot be undone.\\n\\nTo confirm this dangerous operation, please type exactly: Delete');
 if (userInput === 'Delete') {
 return true;
 } else if (userInput !== null) {
 alert('Operation cancelled. You must type exactly \"Delete\" to confirm.');
 }
 return false;
 }
 </script>";
 echo "</form>";

 // 5) Print the table using the modified headers
 print_array_as_table($mappedResults);

 // 6) Optional auto-refresh
 if (isset($_GET["autorefresh"]) && $_GET["autorefresh"]) {
 header("Refresh:5");
 }
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "cache")) {
 $results = $db->fetchAll("select A.*,ROWID FROM core_context_event a order by ts desc");
 echo "<h1 class='my-2'>Event Log</h1>";
 print_array_as_table($results);
 }
 if (isset($_GET["table"]) && ($_GET["table"] == "log")) {
 $limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 50;
 $page = isset($_GET["page"]) ? max(1, intval($_GET["page"])) : 1;
 $offset = ($page - 1) * $limit;

 // Add function to determine color based on time value - moved outside
 function getTimeColor($time) {
 if ($time <= 2) return "#88cc88"; // green
 if ($time <= 5) return "#ffff00"; // yellow
 if ($time <= 8) return "#ffa500"; // orange
 return "#ff6666"; // red
 }

 // Add modal HTML structure at the top
 echo '
 <div id="contentModal" class="modal"><div class="modal-content"><span class="close">&times;</span><div id="modalText"></div></div></div><style>
 /* Modal styles */
 .modal {
 display: none;
 position: fixed;
 z-index: 100000;
 left: 0;
 top: 0;
 width: 100%;
 height: 100%;
 background-color: rgba(0,0,0,0.5);
 backdrop-filter: blur(5px);
 -webkit-backdrop-filter: blur(5px);
 }

 .modal-content {
 background-color: #2a2a2a;
 margin: 5% auto;
 padding: 20px;
 border: 1px solid #444;
 width: 80%;
 max-width: 1200px;
 max-height: 80vh;
 overflow-y: auto;
 border-radius: 5px;
 color: #fff;
 position: relative;
 box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
 }

 .close {
 color: #aaa;
 float: right;
 font-size: 28px;
 font-weight: bold;
 cursor: pointer;
 position: sticky;
 z-index: 1;
 }

 .close:hover,
 .close:focus {
 color: #fff;
 text-decoration: none;
 }

 #modalText {
 white-space: pre-wrap;
 word-wrap: break-word;
 line-height: 1.6;
 padding: 10px 0;
 font-size: 12px;
 }

 /* Prevent background interaction when modal is open */
 body.modal-open {
 overflow: hidden;
 }
 </style><script>
 // Modal functionality
 document.addEventListener("DOMContentLoaded", function() {
 var modal = document.getElementById("contentModal");
 var modalText = document.getElementById("modalText");
 var span = document.getElementsByClassName("close")[0];

 // When the user clicks on <span> (x), close the modal
 span.onclick = function() {
 modal.style.display = "none";
 document.body.classList.remove("modal-open");
 };

 // When the user clicks anywhere outside of the modal, close it
 window.onclick = function(event) {
 if (event.target == modal) {
 modal.style.display = "none";
 document.body.classList.remove("modal-open");
 }
 };

 // Add click handlers to all cell contents
 document.querySelectorAll(".view-contents-btn").forEach(function(element) {
 element.addEventListener("click", function() {
 modalText.innerHTML = this.getAttribute("data-full-content");
 modal.style.display = "block";
 document.body.classList.add("modal-open");
 });
 });
 });
 </script>';

 $results = $db->fetchAll(
 "SELECT A.*, ROWID
 FROM log a
 ORDER BY localts DESC, rowid DESC
 LIMIT $limit OFFSET $offset"
 );

 $columnHeaders = [
 'localts' => 'Time (UTC)',
 'response' => 'AI Response',
 'prompt' => 'Prompt',
 'url' => 'HTTP Request'
 ];

 $mappedResults = array_map(function ($row) use ($columnHeaders) {
 $mappedRow = [];
 foreach ($row as $key => $value) {
 if ($key === 'prompt') {
 // For prompt column, show as a button
 $escapedContent = htmlspecialchars($value, ENT_QUOTES);
 $mappedRow[$columnHeaders[$key] ?? $key] = '<button class="view-contents-btn" data-full-content="' . $escapedContent . '"></button>';
 } else if ($key === 'response') {
 // For response column, show full content directly
 $mappedRow[$columnHeaders[$key] ?? $key] = '<div class="full-content">' . nl2br(htmlspecialchars($value)) . '</div>';
 } else if ($key === 'localts' && !empty($value)) {
 // Format localts to UTC time
 $dt = new DateTime("@$value");
 $dt->setTimezone(new DateTimeZone('UTC'));
 $mappedRow[$columnHeaders[$key]] = $dt->format('d-m-Y H:i:s');
 } else if ($key === 'url') {
 // Check if response starts with Array and contains "in X secs"
 if (strpos($row['response'], 'Array') === 0) {
 // Strip the "in X secs" from the end
 $mappedRow[$columnHeaders[$key] ?? $key] = preg_replace('/ in \d+\.?\d* secs$/', '', $value);
 }
 // Process timing info for non-Array responses
 else if (strpos($value, '[AI secs]') !== false) {
 $pattern = '/\[AI secs\]\s+([\d.]+)\s+\[TTS secs\]\s+([\d.]+)/';
 if (preg_match($pattern, $value, $matches)) {
 $aiTime = floatval($matches[1]);
 $totalTtsTime = floatval($matches[2]);
 $actualTtsTime = $totalTtsTime - $aiTime;

 // Format numbers
 $aiTimeFormatted = number_format($aiTime, 2);
 $ttsTimeFormatted = number_format($actualTtsTime, 2);

 // Get colors based on times
 $aiColor = getTimeColor($aiTime);
 $ttsColor = getTimeColor($actualTtsTime);
 $totalColor = getTimeColor($totalTtsTime);

 // Get everything before [AI secs]
 $baseText = substr($value, 0, strpos($value, '[AI secs]'));

 $mappedRow[$columnHeaders[$key] ?? $key] =
 $baseText .
 "<br>[LLM] <span style='color: " . $aiColor . "'>" . $aiTimeFormatted . "</span>" .
 " [TTS] <span style='color: " . $ttsColor . "'>" . $ttsTimeFormatted . "</span>" .
 " [Total]: <span style='color: " . $totalColor . "'>" . $totalTtsTime . "</span>";
 } else {
 $mappedRow[$columnHeaders[$key] ?? $key] = $value;
 }
 } else {
 $mappedRow[$columnHeaders[$key] ?? $key] = $value;
 }
 } else {
 $mappedRow[$columnHeaders[$key] ?? $key] = $value;
 }
 }
 return $mappedRow;
 }, $results);

 echo "<h1 class='my-2'>Response Log</h1>";

 // Add Clean and Export buttons
 echo "<div class='response-log-actions' style='margin: 15px 0;'>";
 echo "<form method='post' action='index.php?table=log' style='display: inline;'>$indexCsrfField<button type='submit' name='index_action' value='clean_log' onclick=\"return confirm('This will clear all the entries in the Response Log. ARE YOU SURE?')\" class='btn-base btn-danger' style='margin-right: 10px;'>Clean Response Log</button></form>";
 echo "<button onclick=\"window.open('?export=log', '_blank')\" class='btn-base btn-primary'>Export Response Log</button>";
 echo "</div>";

 $prevPage = max(1, $page - 1);
 $nextPage = $page + 1;

 echo "<div class='pagination-buttons' style='margin: 10px 0;'>";
 if ($page > 1) {
 echo "<button onclick=\"window.location.href='?table=log&page=$prevPage&limit=$limit'\" class='btn-base btn-primary'>Previous</button> ";
 }
 echo "<button onclick=\"window.location.href='?table=log&page=$nextPage&limit=$limit'\" class='btn-base btn-primary'>Next</button>";
 echo "</div>";

 print_array_as_table($mappedResults);
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "quests")) {
 $results = $db->fetchAll("SELECT name, id_quest, briefing, briefing2, data from core_quest");

 // Define column headers mapping
 $columnHeaders = [
 'name' => 'Name',
 'id_quest' => 'Quest ID',
 'briefing' => 'Briefing',
 'briefing2' => 'Briefing2',
 'data' => 'Data'
 ];

 $finalRow = [];
 foreach ($results as $row) {
 if (isset($finalRow[$row["id_quest"]]))
 continue;
 else
 $finalRow[$row["id_quest"]] = array_combine(
 array_values($columnHeaders),
 array_values($row)
 );
 }

 echo "<h1 class='my-2'>Current Active Quests</h1>";
 echo "<p>Note: These quests are only known by your followers. We only track quests which you have active in your journal.</p>";

 print_array_as_table(array_values($finalRow));
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "diarylog")) {
 // Include game timestamp utilities if not already included
 require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."utils_game_timestamp.php");

 $results = $db->fetchAll("select A.topic, A.summary AS content, A.tags, A.companions AS people, A.location, A.localts, A.gamets, A.ts, A.sess, A.rowid FROM core_memory A where A.source_type='diary' order by gamets desc,rowid desc limit 150 offset 0");

 // Define column headers mapping
 $columnHeaders = [
 'ts' => 'TS',
 'gamets' => '<a href="https://fallout.fandom.com/wiki/Timeline" target="_blank" style="color: yellow;">Fallout Time</a>',
 'localts' => 'Time (UTC)',
 'topic' => 'Topic',
 'content' => 'Content',
 'people' => 'People',
 'location' => 'Locations'
 ];

 $mappedResults = [];
 foreach ($results as $row) {
 $newRow = [];
 foreach ($columnHeaders as $oldKey => $newKey) {
 $value = isset($row[$oldKey]) ? $row[$oldKey] : '';

 // Convert timestamps
 if ($oldKey === 'localts' && !empty($value)) {
 $dt = new DateTime("@".$value);
 $dt->setTimezone(new DateTimeZone('UTC'));
 $value = $dt->format('d-m-Y H:i:s');
 }
 else if ($oldKey === 'gamets' && !empty($value)) {
 $value = convert_gamets2fallout_long_date2($value);
 }

 $newRow[$newKey] = $value;
 }
 $mappedResults[] = $newRow;
 }

 echo "<h1 class='my-2'>Diary Entries</h1>";
 print_array_as_table($mappedResults);
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "audit_request")) {
 $limit = isset($_GET["limit"]) ? max(10, intval($_GET["limit"])) : 50;
 $page = isset($_GET["page"]) ? max(1, intval($_GET["page"])) : 1;
 $params = [
 "page" => $page,
 "limit" => $limit,
 ];

 if (isset($_GET["embed"]) && $_GET["embed"]) {
 $params["embed"] = "1";
 }

 $requestLogUrl = $webRoot . "/ui/request_logs.php?" . http_build_query($params);
 $escapedRequestLogUrl = htmlspecialchars($requestLogUrl, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");

 echo "<div class='table-container'>";
 echo "<h1 class='my-2'>Request to LLM Services Log</h1>";
 echo "<p>This view moved to the dedicated request log page.</p>";
 echo "<p><a class='btn-base btn-primary' href='{$escapedRequestLogUrl}'>Open Request Logs</a></p>";
 echo "<script>window.location.replace(" . json_encode($requestLogUrl) . ");</script>";
 echo "</div>";
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "openai_token_count")) {
 $results = $db->fetchAll("select A.*,ROWID FROM openai_token_count A order by rowid desc limit 150 offset 0");
 echo "<h1 class='my-2'>OpenAI Token Pricing</h1>";
 echo ($results);
 }


 if (isset($_GET["table"]) && ($_GET["table"] == "memory")) {
 // Include game timestamp utilities if not already included
 require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."utils_game_timestamp.php");

 echo "<style>
 .table-container table td:nth-child(2), /* Fallout Time */
 .table-container table th:nth-child(2) {
 min-width: 200px;
 }
 .table-container table td:nth-child(3), /* Time (UTC) */
 .table-container table th:nth-child(3) {
 min-width: 150px;
 }
 .table-container table td:nth-child(5), /* Message */
 .table-container table th:nth-child(5) {
 min-width: 300px;
 }
 </style>";

 $results = $db->fetchAll("select A.*, ROWID as rowid FROM core_memory_log A order by gamets desc,rowid desc limit 150 offset 0");

 // Define column headers mapping
 $columnHeaders = [
 'ts' => 'TS',
 'gamets' => '<a href="https://fallout.fandom.com/wiki/Timeline" target="_blank" style="color: yellow;">Fallout Time</a>',
 'localts' => 'Time (UTC)',
 'speaker' => 'Speaker',
 'message' => 'Message',
 'listener' => 'Listener',
 'event' => 'Event',
 'momentum' => 'Momentum'
 ];

 $mappedResults = [];
 foreach ($results as $row) {
 $newRow = [];
 foreach ($columnHeaders as $oldKey => $newKey) {
 $value = isset($row[$oldKey]) ? $row[$oldKey] : '';

 // Convert timestamps
 if ($oldKey === 'localts' && !empty($value)) {
 $dt = new DateTime("@".$value);
 $dt->setTimezone(new DateTimeZone('UTC'));
 $value = $dt->format('d-m-Y H:i:s');
 }
 else if ($oldKey === 'gamets' && !empty($value)) {
 $value = convert_gamets2fallout_long_date2($value);
 }

 $newRow[$newKey] = $value;
 }
 $mappedResults[] = $newRow;
 }

 echo "<h1 class='my-2'>Memories Log</h1>";
 print_array_as_table($mappedResults);
 }

 if (isset($_GET["table"]) && ($_GET["table"] == "memory_summary")) {
 // Include game timestamp utilities if not already included
 require_once(dirname(__DIR__).DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."utils_game_timestamp.php");

 // 1. Handle save edits via POST
 if (isset($_POST['save_memory_edit'])) {
 $rowid = intval($_POST['rowid']);
 $summary = $_POST['summary'];
 $tags = $_POST['tags'];
 $companions = $_POST['companions'];

 // Update the database
 $db->update(
 'core_memory',
 "summary = '" . $db->escape($summary) . "',
 tags = '" . $db->escape($tags) . "',
 companions = '" . $db->escape($companions) . "'",
 "rowid = " . $rowid
 );

 // Redirect to refresh the page
 header("Location: ?table=memory_summary&updated=1");
 exit;
 }

 // Single-summary delete is a POST action near the top of this page.

 // Show success/delete messages
 if (isset($_GET['updated'])) {
 echo "<div class='alert alert-success'>Memory summary updated successfully!</div>";
 }
 if (isset($_GET['deleted'])) {
 echo "<div class='alert alert-danger'>Memory summary deleted successfully!</div>";
 }

 // 3. Fetch data from database
 $results = $db->fetchAll("SELECT gamets_truncated, n, summary, companions, tags, classifier, uid, ROWID as rowid, packed_message, native_vec
 FROM core_memory
 WHERE source_type='summary'
 ORDER BY gamets_truncated DESC, rowid DESC
 LIMIT 150");

 // 4. Process each row for display
 $processedResults = [];
 foreach ($results as $row) {
 // Create the display HTML
 $displayHtml = "<div id='display-{$row['rowid']}'><div class='summary-section'><span class='summary-content'>" . nl2br(htmlspecialchars($row['summary'])) . "</span></div><div class='summary-section'><span class='summary-label'>People:</span><span class='summary-content'>" . htmlspecialchars($row['companions']) . "</span></div><div class='subcategory-section'><span class='summary-label subcategory-label'>Tags:</span><span class='summary-content subcategory-content'>" . htmlspecialchars($row['tags']) . "</span></div><div class='subcategory-section'><span class='summary-label subcategory-label'>Embedding:</span><span class='summary-content subcategory-content'>" . htmlspecialchars($row['native_vec'] ?? '') . "</span></div><div class='button-group' style='margin-top: 10px;'><button class='btn-base action-button edit' onclick='toggleEdit({$row['rowid']})'>Edit</button><form method='post' action='index.php?table=memory_summary' style='display: inline;'>$indexCsrfField<input type='hidden' name='rowid' value='" . intval($row['rowid']) . "'><button type='submit' name='index_action' value='delete_memory' class='btn-base btn-danger' onclick=\"return confirm('Are you sure you want to delete this memory summary?')\">Delete</button></form></div><div class='mt-2'><span class='summary-label'>Packed Memory Content:</span></div><div class='memory-cell'><textarea readonly class='memory-content'>" . htmlspecialchars($row['packed_message']) . "</textarea></div></div>";

 // Create the edit form HTML
 $displayHtml .= "<form id='edit-form-{$row['rowid']}' class='edit-form' method='post' action='?table=memory_summary'><input type='hidden' name='rowid' value='{$row['rowid']}'><input type='hidden' name='save_memory_edit' value='1'><label>Summary:</label><textarea name='summary' class='edit-textarea form-control'>" . htmlspecialchars($row['summary']) . "</textarea><label>Tags:</label><input type='text' name='tags' class='edit-input form-control' value='" . htmlspecialchars($row['tags']) . "'><label>People:</label><input type='text' name='companions' class='edit-input form-control' value='" . htmlspecialchars($row['companions']) . "'><div class='button-group' style='margin-top: 10px;'><button type='submit' class='btn-base action-button add-new'>Save</button><button type='button' class='btn-base btn-cancel' onclick='cancelEdit({$row['rowid']})'>Cancel</button></div></form>";

 // Create the processed row with rowid included
 $processedRow = [
 'RowID' => $row['rowid'],
 '<a href="https://fallout.fandom.com/wiki/Timeline" target="_blank" style="color: yellow;">Fallout Time</a>' => !empty($row['gamets_truncated']) ? convert_gamets2fallout_long_date2($row['gamets_truncated']) : '',
 'ID' => $row['n'],
 'Classifier' => $row['classifier'],
 'Summary' => $displayHtml
 ];

 $processedResults[] = $processedRow;
 }

 // 5. Output the page header
 echo "<h1 class='my-2'>Summarized Memories Log</h1>";
 echo "<h3>(Enable AUTO_CREATE_SUMMARYS in the default profile)</h3>";

 // Add Memory Management buttons
 echo "<div class='memory-management-actions' style='margin: 15px 0;'>";
 echo "<button onclick=\"syncMemoriesConfirm()\" class='btn-base btn-primary' style='margin-right: 10px;'> Sync & Create Memory Summaries</button>";
 echo "<button onclick=\"deleteAllMemoriesConfirm()\" class='btn-base btn-danger' style='background-color: #dc2626; font-weight: bold;'> Delete All Memory Summaries</button>";
 echo "</div>";

 // Add JavaScript functions for confirmations
 $memoryToolsRoot = json_encode($webRoot . '/ui/tests/', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
 echo synthManagementPostScript() . "<script>
 function syncMemoriesConfirm() {
 if (confirm('Will use tokens from your current AI connector. May take a few minutes to process. DO NOT REFRESH THE WEBPAGE!')) {
 synthManagementPost($memoryToolsRoot + 'vector-compact-chromadb.php', {});
 }
 }

 function deleteAllMemoriesConfirm() {
 var userInput = prompt('THIS WILL DELETE ALL SUMMARIZED MEMORIES!\\n\\nThis action cannot be undone and will remove all AI memory summaries.\\n\\nTo confirm this dangerous operation, please type exactly: Delete');
 if (userInput === 'Delete') {
 synthManagementPost($memoryToolsRoot + 'vector-delete-memory_summary.php', {});
 } else if (userInput !== null) {
 alert('Operation cancelled. You must type exactly \"Delete\" to confirm.');
 }
 }
 </script>";

 // 6. Add the necessary styles
 echo "<style>
 .edit-form {
 display: none;
 padding: 15px;
 border-radius: 5px;
 margin: 10px 0;
 background-color: #2a2a2a;
 }
 .edit-textarea {
 width: 100%;
 min-height: 100px;
 margin-bottom: 5px;
 height: 300px;
 background-color: #333;
 color: #fff;
 border: 1px solid #444;
 }
 .edit-input {
 width: 100%;
 margin-bottom: 5px;
 background-color: #333;
 color: #fff;
 border: 1px solid #444;
 padding: 5px;
 }
 .memory-content {
 height: 100%;
 min-height: 150px;
 overflow-y: auto;
 padding: 5px;
 white-space: pre-wrap;
 word-wrap: break-word;
 border: 1px solid #444;
 background-color: #333;
 color: #fff;
 width: 100%;
 }
 .summary-section {
 margin-bottom: 8px;
 padding: 5px;
 border-bottom: 1px solid #444;
 }
 .subcategory-section {
 margin-bottom: 6px;
 padding: 3px 5px 3px 15px;
 border-bottom: 1px dotted #555;
 font-size: 0.85em;
 }
 .subcategory-label {
 color: #aaa;
 font-size: 0.9em;
 }
 .subcategory-content {
 color: #ddd;
 font-size: 0.9em;
 }
 .summary-label {
 font-weight: bold;
 margin-right: 5px;
 color: #fff;
 }
 .summary-content {
 color: #fff;
 }
 </style>";

 // 7. Add the JavaScript for edit functionality
 echo "<script>
 function toggleEdit(rowid) {
 const displayDiv = document.getElementById('display-' + rowid);
 const editForm = document.getElementById('edit-form-' + rowid);
 displayDiv.style.display = 'none';
 editForm.style.display = 'block';
 }

 function cancelEdit(rowid) {
 const displayDiv = document.getElementById('display-' + rowid);
 const editForm = document.getElementById('edit-form-' + rowid);
 displayDiv.style.display = 'block';
 editForm.style.display = 'none';
 }
 </script>";

 // 8. Display the table
 print_array_as_table($processedResults);
 }

 if (isset($_GET["notes"])) {
 echo file_get_contents(__DIR__."/notes.html");
 }

 ?></div><!-- close main container --><?php

include(__DIR__.DIRECTORY_SEPARATOR."tmpl/footer.html");

$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;

?>


<?php

// Deletes every memory summary: only a confirmed same-origin POST carrying the dashboard form token, refused
// before bootstrap otherwise.
require_once(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "management_request_guard.php");
synthManagementRequirePostToken("Use Delete All Memory Summaries on the Memories page and confirm it.");

require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");

$TITLE = "Delete Memory Summaries";

ob_start();

include("../tmpl/head.html");

$debugPaneLink = false;
$enginePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
synthRuntimeBootstrapIfNeeded($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_tts_connector' => false,
    'load_player_name' => true,
    'load_narrator' => true,
]);
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");

error_reporting(E_ALL);

echo '<link rel="stylesheet" type="text/css" href="../css/main.css">';
echo '<main style="padding-top: 20px; padding-left: 20px; padding-right: 20px;">';

try {
 $GLOBALS["db"]->execQuery("DELETE FROM synth.memory_summary");
 echo "<h1>All entries in the memory summary table have been deleted successfully.</h1>";
} catch (Throwable $e) {
 echo "<h1>Error deleting entries from memory summary: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</h1>";
}
echo '</main>';
include(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
echo ob_get_clean();
?>

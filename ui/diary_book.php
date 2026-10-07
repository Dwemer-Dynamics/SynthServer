<?php
session_start();

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($rootPath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");
require_once($rootPath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");

$dbSettings = synthDbConnectionSettings('synth');
$host = $dbSettings['host'];
$port = $dbSettings['port'];
$dbname = $dbSettings['dbname'];
$schema = $dbSettings['schema'];
$username = $dbSettings['username'];
$password = $dbSettings['password'];

$scriptPath = $_SERVER['SCRIPT_NAME'];
$webRoot = dirname(dirname($scriptPath));
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

$conn = pg_connect(synthPgConnectionString($dbSettings));
if (!$conn) {
    http_response_code(500);
    echo "<p>DB connection failed.</p>";
    exit;
}

$person = isset($_GET['person']) ? trim(urldecode($_GET['person'])) : '';
if ($person === '') {
    echo "<p>Missing person.</p>";
    exit;
}

$result = pg_query_params($conn, "SELECT rowid, content, people, location, localts, gamets FROM {$schema}.diarylog WHERE people LIKE $1", ['%'.$person.'%']);
$entries = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) { $entries[] = $row; }
}

usort($entries, function($a, $b) { return ((int)$a['localts']) - ((int)$b['localts']); });

pg_close($conn);

$safeTitle = htmlspecialchars($person);
$TITLE = 'The Diary of ' . $person;
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
    <link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
    <link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/diary_adventure.css">
    <style>
        .book { max-width: 980px; margin: 0 auto; padding: 30px 16px 60px; }
        .book-header { text-align: center; padding: 20px 0 10px; }
        .book-header h1 { font-family: 'Gothic821', serif; color: var(--synth-accent); text-shadow: 2px 2px 4px rgba(0,0,0,0.5); margin: 0; font-size: 32px; letter-spacing: 1px; }
        .actions { position: sticky; top: 72px; background: rgba(26,26,26,.92); padding: 8px 0; border-bottom: 1px solid var(--synth-border); display: flex; gap: 8px; justify-content: flex-end; z-index: 2; }

        /* Entry parchment block, matching SYNTH modal look */
        .entry { page-break-inside: avoid; margin: 18px auto; max-width: 900px; }
        .entry .paper {
            background: url('<?php echo $webRoot; ?>/ui/images/paper.jpg') center/cover;
            padding: 36px;
            border-radius: 6px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.5);
            color: #000;
            font-family: SYNTHHandwritten, Arial, sans-serif !important;
            font-size: 1.2em;
            line-height: 1.75;
            white-space: pre-wrap;
        }

        @media print {
            .synth-navbar-wrapper,
            .actions { display: none; }
            body { background: #fff; color: #000; }
            .entry .paper { box-shadow: none; }
        }
    </style>
    <main>
    <div class="book">
        <div class="actions">
            <button class="btn synth-btn" onclick="window.print()">Print / Save as PDF</button>
        </div>
        <div class="book-header">
            <h1>The Diary of <?php echo $safeTitle; ?></h1>
        </div>
        <?php foreach ($entries as $e): ?>
            <?php $content = (string)$e['content']; ?>
            <div class="entry">
                <div class="paper"><?php echo nl2br(htmlspecialchars($content)); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    </main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
echo $buffer;
?>



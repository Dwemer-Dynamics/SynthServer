<?php
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
$webRoot = $webRoot === '/' ? '' : rtrim($webRoot, '/');
$TITLE = 'Server Setup';
$HIDE_NAVBAR = true;
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
<main>
    <header class="synth-page-head">
        <h1 class="synth-page-head-title">SYNTH Server setup required</h1>
        <p class="synth-page-head-note">Complete the local PHP and PostgreSQL prerequisites before opening persisted configuration and profile pages.</p>
    </header>
    <section class="synth-panel">
        <p>The Fallout 4 and Fallout 4 VR client endpoint is <code>main.php</code> beneath the configured Synthserver URL.</p>
        <h2>Required next steps</h2>
        <ol>
            <li>Enable PHP's PostgreSQL extension so <code>pg_connect()</code> is available.</li>
            <li>Create or select the local PostgreSQL database configured for Synthserver.</li>
            <li>Run the Synthserver database bootstrap and schema update tooling.</li>
            <li>Return to <a href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/quickstart.php">Quickstart</a> to finish provider and client configuration.</li>
        </ol>
    </section>
</main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
echo $buffer;
?>

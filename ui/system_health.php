<?php

declare(strict_types=1);

$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos === false ? '' : substr($scriptPath, 0, $uiPos);
$webRoot = $webRoot === '/' ? '' : rtrim($webRoot, '/');
$isEmbedded = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
$TITLE = 'System Health';
$BODY_CLASS = 'hub-page';

ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') ?>/ui/css/main.css">
<style>
main { padding: <?= $isEmbedded ? '18px' : '0 18px 24px' ?>; }
.health-shell { max-width: 1200px; margin: 0 auto; }
.health-heading { color: var(--synth-accent); font-family: 'Gothic821', serif; margin: 0 0 8px; }
.health-summary { color: #aaa; margin-bottom: 18px; }
.health-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
.health-card { background: linear-gradient(135deg, rgba(42,42,42,.96), rgba(30,30,30,.98)); border: 1px solid #444; border-radius: 8px; padding: 16px; }
.health-label { color: #aaa; font-size: 13px; margin-bottom: 5px; }
.health-value { color: #f8f9fa; font-size: 20px; overflow-wrap: anywhere; }
.health-value.good { color: #9be49f; }
.health-value.bad { color: #ffb0b0; }
.health-error { margin-top: 14px; color: #ffb0b0; background: rgba(171,58,58,.16); border: 1px solid rgba(171,58,58,.45); border-radius: 6px; padding: 12px; }
.health-actions { margin-top: 16px; }
.health-actions button { color: #111; background: var(--synth-accent); border: 0; border-radius: 6px; padding: 8px 14px; font-weight: 700; cursor: pointer; }
</style>
<main>
    <section class="health-shell" aria-labelledby="health-heading">
        <h1 id="health-heading" class="health-heading">SYNTH Runtime Health</h1>
        <div id="health-summary" class="health-summary" aria-live="polite">Loading the canonical health endpoint...</div>
        <div id="health-grid" class="health-grid"></div>
        <div id="health-error" class="health-error" hidden></div>
        <div class="health-actions"><button id="refresh-health" type="button">Refresh health</button></div>
    </section>
</main>
<script>
(function () {
    const endpoint = <?= json_encode($webRoot . '/health.php', JSON_UNESCAPED_SLASHES) ?>;
    const grid = document.getElementById('health-grid');
    const summary = document.getElementById('health-summary');
    const error = document.getElementById('health-error');
    const labels = {
        service: 'Service', status: 'Overall status', version: 'Version', build: 'Build',
        database: 'Database', database_encoding: 'Database encoding',
        database_encoding_supported: 'Encoding supported', schema: 'Schema current',
        background_processor: 'Background worker', background_port: 'Background port',
        timestamp: 'Checked at'
    };

    function statusClass(value) {
        if (value === true || value === 'ok') return 'good';
        if (value === false || value === 'unhealthy' || value === 'degraded') return 'bad';
        return '';
    }

    function render(data, responseOk) {
        grid.replaceChildren();
        Object.keys(labels).forEach(function (key) {
            const card = document.createElement('div');
            card.className = 'health-card';
            const label = document.createElement('div');
            label.className = 'health-label';
            label.textContent = labels[key];
            const value = document.createElement('div');
            value.className = 'health-value ' + statusClass(data[key]);
            value.textContent = typeof data[key] === 'boolean' ? (data[key] ? 'Ready' : 'Unavailable') : String(data[key] ?? 'Unknown');
            card.append(label, value);
            grid.append(card);
        });
        summary.textContent = responseOk ? 'Live status from health.php.' : 'The health endpoint reported a degraded state.';
        error.hidden = !data.error;
        error.textContent = data.error || '';
    }

    async function refresh() {
        summary.textContent = 'Checking the canonical health endpoint...';
        error.hidden = true;
        try {
            const response = await fetch(endpoint, {cache: 'no-store', headers: {'Accept': 'application/json'}});
            const data = await response.json();
            render(data, response.ok);
        } catch (reason) {
            summary.textContent = 'Health check failed.';
            error.hidden = false;
            error.textContent = reason instanceof Error ? reason.message : String(reason);
        }
    }

    document.getElementById('refresh-health').addEventListener('click', refresh);
    refresh();
    window.setInterval(refresh, 10000);
})();
</script>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = (string)ob_get_clean();
echo $buffer;

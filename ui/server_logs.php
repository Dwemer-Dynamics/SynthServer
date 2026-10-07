<?php

declare(strict_types=1);

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos === false ? '' : substr($scriptPath, 0, $uiPos);
$webRoot = $webRoot === '/' ? '' : rtrim($webRoot, '/');
$isEmbedded = isset($_GET['embed']) && (string)$_GET['embed'] === '1';

// Only expose application-owned logs by opaque names; request paths are never accepted.
$logFiles = [
    'synth' => ['label' => 'SYNTH Runtime', 'file' => 'synth.log'],
    'manager' => ['label' => 'Background Manager', 'file' => 'manager.log'],
    'monitor' => ['label' => 'Background Monitor', 'file' => 'monitor.log'],
    'plugin-output' => ['label' => 'Output to Plugin', 'file' => 'output_to_plugin.log'],
    'llm-output' => ['label' => 'LLM Output', 'file' => 'output_from_llm.log'],
    'llm-context' => ['label' => 'LLM Context', 'file' => 'context_sent_to_llm.log'],
    'stream' => ['label' => 'Raw Stream', 'file' => 'debugStream.log'],
    'parsed-stream' => ['label' => 'Parsed Stream', 'file' => 'debugStreamParsed.log'],
];

$selectedKey = trim((string)($_GET['log'] ?? 'synth'));
if (!isset($logFiles[$selectedKey])) {
    $selectedKey = 'synth';
}

// Read a bounded tail so opening this page cannot load an arbitrarily large log into memory.
function synthReadLogTail(string $path, int $maxBytes = 262144): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return '';
    }

    try {
        $size = (int)(@filesize($path) ?: 0);
        $offset = max(0, $size - $maxBytes);
        if ($offset > 0) {
            @fseek($handle, $offset);
            fgets($handle);
        }
        $content = stream_get_contents($handle, $maxBytes);
        return is_string($content) ? $content : '';
    } finally {
        fclose($handle);
    }
}

function synthLogUrl(string $key, bool $embedded): string
{
    return 'server_logs.php?' . http_build_query(array_filter([
        'log' => $key,
        'embed' => $embedded ? '1' : null,
    ], static fn($value): bool => $value !== null));
}

$selected = $logFiles[$selectedKey];
$selectedPath = $enginePath . 'log' . DIRECTORY_SEPARATOR . $selected['file'];
$content = synthReadLogTail($selectedPath);
$size = is_file($selectedPath) ? (int)(filesize($selectedPath) ?: 0) : 0;
$modified = is_file($selectedPath) ? (int)(filemtime($selectedPath) ?: 0) : 0;
$TITLE = 'Server Logs';
$AUTO_REFRESH_SECONDS = 10;
$BODY_CLASS = 'hub-page';

ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') ?>/ui/css/main.css">
<style>
main { padding: <?= $isEmbedded ? '18px' : '0 18px 24px' ?>; }
.log-shell { max-width: 1500px; margin: 0 auto; }
.log-heading { color: var(--synth-accent); font-family: 'Gothic821', serif; margin: 0 0 8px; }
.log-meta { color: #aaa; margin-bottom: 14px; }
.log-picker { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.log-picker a { color: #f8f9fa; background: #333; border: 1px solid #555; border-radius: 6px; padding: 7px 11px; text-decoration: none; }
.log-picker a:hover, .log-picker a:focus-visible, .log-picker a.active { color: var(--synth-accent); border-color: rgba(var(--synth-accent-rgb), .65); background: rgba(var(--synth-accent-rgb), .12); outline: none; }
.log-output { min-height: 480px; max-height: calc(100vh - 260px); overflow: auto; margin: 0; padding: 16px; color: #e8e8e8; background: #171717; border: 1px solid #444; border-radius: 8px; white-space: pre-wrap; overflow-wrap: anywhere; font: 12px/1.5 Consolas, 'Courier New', monospace; }
.empty-state { color: #aaa; }
@media (max-width: 640px) { main { padding-left: 10px; padding-right: 10px; } .log-output { min-height: 360px; max-height: none; } }
</style>
<main>
    <section class="log-shell" aria-labelledby="server-log-heading">
        <h1 id="server-log-heading" class="log-heading">SYNTH Server Logs</h1>
        <div class="log-meta">
            <?= htmlspecialchars($selected['label'], ENT_QUOTES, 'UTF-8') ?>
            &middot; <?= number_format($size) ?> bytes
            <?php if ($modified > 0): ?>&middot; updated <?= htmlspecialchars(gmdate('Y-m-d H:i:s', $modified), ENT_QUOTES, 'UTF-8') ?> UTC<?php endif; ?>
            &middot; refreshes every 10 seconds
        </div>
        <nav class="log-picker" aria-label="Server log selection">
            <?php foreach ($logFiles as $key => $details): ?>
                <a class="<?= $key === $selectedKey ? 'active' : '' ?>" href="<?= htmlspecialchars(synthLogUrl($key, $isEmbedded), ENT_QUOTES, 'UTF-8') ?>" <?= $key === $selectedKey ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($details['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($content === ''): ?>
            <div class="log-output empty-state">No entries have been written to this log yet.</div>
        <?php else: ?>
            <pre class="log-output" aria-label="<?= htmlspecialchars($selected['label'], ENT_QUOTES, 'UTF-8') ?> log tail"><?= htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
        <?php endif; ?>
    </section>
</main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = (string)ob_get_clean();
echo $buffer;

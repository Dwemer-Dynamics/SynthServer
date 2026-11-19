<?php

// Minimal extensions page: lists installed extensions and switches them on or off through /api/v1/extensions.
// It reads nothing itself; the API owns validation, the allowlist and the state file.

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'management_request_guard.php';

$scriptPath = strval($_SERVER['SCRIPT_NAME'] ?? '');
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = $uiPos !== false ? substr($scriptPath, 0, $uiPos) : '';
$webRoot = rtrim($webRoot === '/' ? '' : $webRoot, '/');
$isEmbedded = isset($_GET['embed']) && strval($_GET['embed']) === '1';

$TITLE = 'SYNTH - Extensions';
$BODY_CLASS = 'synth-extensions';
ob_start();
include __DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html';
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
<style>
main { padding: <?php echo $isEmbedded ? '10px 12px 24px' : '80px 12px 24px'; ?>; }
.ext-shell { max-width: 1080px; margin: 0 auto; }
body .ext-heading { margin: 0 0 10px; padding: 0 0 8px; border-bottom: 1px solid #3a3a3a; }
.ext-heading h1 { margin: 0; color: var(--synth-accent) !important; font-family: 'Gothic821', sans-serif; font-weight: normal; font-size: 1.3rem; }
.ext-heading p { margin: 0; color: #bdbdbd; }
.ext-status { min-height: 54px; border: 1px solid #3a3a3a; background: #242424; border-radius: 6px; padding: 10px 12px; color: #ddd; }
.ext-list { display: grid; gap: 8px; }
.ext-row { display: grid; grid-template-columns: minmax(180px, 1fr) auto auto auto; gap: 18px; align-items: center; border: 1px solid #3a3a3a; background: #242424; border-radius: 6px; padding: 12px 14px; }
.ext-name { color: #fff; font-weight: 600; }
.ext-desc { color: #aaa; font-size: 0.9rem; font-weight: normal; }
.ext-version { color: var(--synth-accent); white-space: nowrap; }
.ext-state { color: #aaa; white-space: nowrap; font-size: 0.9rem; }
.ext-state.is-enabled { color: #8fd18f; }
.ext-state.is-problem { color: #ff8d8d; }
.ext-toggle { border: 1px solid var(--synth-accent) !important; background: var(--synth-accent) !important; color: #171717 !important; padding: 6px 12px; border-radius: 5px; cursor: pointer; min-width: 84px; }
.ext-toggle:hover { background: var(--synth-accent-border) !important; border-color: var(--synth-accent-border) !important; }
.ext-toggle:disabled { opacity: .5; cursor: default; }
.ext-error { color: #ff8d8d; }
@media (max-width: 700px) { .ext-row { grid-template-columns: 1fr; gap: 5px; } }
</style>

<main>
    <div class="ext-shell">
        <header class="ext-heading synth-page-head">
            <h1 class="synth-page-head-title">Extensions</h1>
            <p class="synth-page-head-note">Extensions add behaviour to dialogue. An extension that keeps failing is switched off automatically; enable it again once it is fixed.</p>
        </header>
        <div id="ext-status" class="ext-status" role="status" aria-live="polite">Loading extensions...</div>
    </div>
</main>

<script>
(function () {
    const base = <?php echo json_encode($webRoot . '/api/index.php/v1/extensions', JSON_UNESCAPED_SLASHES); ?>;
    const status = document.getElementById('ext-status');
    const labels = {
        enabled: 'Enabled', disabled: 'Disabled', invalid: 'Invalid manifest',
        load_failed: 'Failed to load', auto_disabled: 'Switched off after failures'
    };

    function show(message, isError) {
        status.className = 'ext-status' + (isError ? ' ext-error' : '');
        status.textContent = message;
    }

    function render(extensions) {
        if (!Array.isArray(extensions) || extensions.length === 0) {
            show('No extensions are installed.', false);
            return;
        }
        const list = document.createElement('div');
        list.className = 'ext-list';
        extensions.forEach(function (ext) {
            const row = document.createElement('div');
            row.className = 'ext-row';
            const name = document.createElement('div');
            name.className = 'ext-name';
            name.textContent = String(ext.name || ext.id);
            if (ext.description) {
                const desc = document.createElement('div');
                desc.className = 'ext-desc';
                desc.textContent = String(ext.description);
                name.appendChild(desc);
            }
            const version = document.createElement('div');
            version.className = 'ext-version';
            version.textContent = ext.version ? 'v' + ext.version : '';
            const state = document.createElement('div');
            const problem = ['invalid', 'load_failed', 'auto_disabled'].indexOf(ext.status) !== -1;
            state.className = 'ext-state' + (ext.status === 'enabled' ? ' is-enabled' : '') + (problem ? ' is-problem' : '');
            state.textContent = labels[ext.status] || 'Unknown';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ext-toggle';
            button.textContent = ext.enabled ? 'Disable' : 'Enable';
            button.disabled = ext.status === 'invalid';
            button.addEventListener('click', function () { toggle(ext, button); });
            row.append(name, version, state, button);
            list.appendChild(row);
        });
        status.className = 'ext-status';
        status.replaceChildren(list);
    }

    async function load() {
        try {
            const response = await fetch(base, { cache: 'no-store', headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error('failed');
            render(payload.extensions);
        } catch (error) {
            show('The extension list could not be loaded.', true);
        }
    }

    async function toggle(ext, button) {
        button.disabled = true;
        try {
            const response = await fetch(base + '/' + encodeURIComponent(ext.id) + '/' + (ext.enabled ? 'disable' : 'enable'),
                { method: 'POST', cache: 'no-store', headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error('failed');
            await load();
        } catch (error) {
            button.disabled = false;
            show('That change could not be applied.', true);
        }
    }

    load();
})();
</script>

<?php
include __DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html';
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;

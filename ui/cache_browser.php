<?php

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;

// Determine web root for URL building.
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';

function synthCacheFormatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function synthCacheScanFiles(string $root, array $extensions, int $limit = 300): array
{
    $items = [];
    if (!is_dir($root)) {
        return $items;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $ext = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, $extensions, true)) {
                continue;
            }
            $items[] = [
                'name' => $fileInfo->getFilename(),
                'path' => $fileInfo->getPathname(),
                'mtime' => $fileInfo->getMTime(),
                'size' => $fileInfo->getSize(),
                'ext' => $ext,
            ];
        }
    } catch (Throwable $e) {
        return $items;
    }

    usort($items, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });

    return array_slice($items, 0, $limit);
}

$soundRoot = $enginePath . 'soundcache';
$galleryRoot = $enginePath . 'data' . DIRECTORY_SEPARATOR . 'pictures' . DIRECTORY_SEPARATOR . 'gallery';

$audioFiles = synthCacheScanFiles($soundRoot, ['wav', 'mp3', 'ogg'], 300);
$imageFiles = synthCacheScanFiles($galleryRoot, ['jpg', 'jpeg', 'png', 'webp', 'mp4'], 300);

ob_start();
$TITLE = 'Audio & Image Cache';
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'head.html');
?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
    <style>
        .cache-title { margin:0; color:var(--synth-accent); font-weight:400; }
        .cache-meta { color:var(--synth-text-muted); font-size:13px; }
        .cache-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .cache-btn { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:6px 10px; border:1px solid var(--synth-border); border-radius:var(--synth-radius-sm); background:var(--synth-surface-2); color:var(--synth-text); text-decoration:none; }
        .cache-btn:hover { border-color:var(--synth-accent); color:var(--synth-accent); }
        .cache-grid { display:grid; grid-template-columns:minmax(0, 1.35fr) minmax(320px, .65fr); gap:14px; }
        .cache-panel { border:1px solid var(--synth-border); border-radius:var(--synth-radius-md); background:var(--synth-surface-1); overflow:hidden; }
        .cache-panel h2 { margin:0; padding:10px 12px; font-size:17px; font-weight:400; color:var(--synth-text); background:var(--synth-surface-2); border-bottom:1px solid var(--synth-border); }
        .cache-list { display:flex; flex-direction:column; }
        .cache-row { display:grid; grid-template-columns:minmax(220px, 1fr) 130px 140px 220px; gap:10px; align-items:center; padding:9px 12px; border-bottom:1px solid rgba(255,255,255,.06); }
        .cache-row:last-child { border-bottom:none; }
        .cache-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--synth-text); }
        .cache-small { color:var(--synth-text-muted); font-size:12px; }
        audio { width:100%; height:32px; }
        .empty { padding:16px 12px; color:var(--synth-text-muted); }
        .gallery-frame { width:100%; height:520px; border:0; background:var(--synth-bg); display:block; }
        @media (max-width: 1100px) {
            .cache-grid { grid-template-columns:1fr; }
            .cache-row { grid-template-columns:1fr; }
            audio { width:100%; }
        }
    </style>
<main>
    <div class="cache-head synth-page-head">
        <h1 class="cache-title synth-page-head-title">Audio &amp; Image Cache</h1>
        <div class="cache-meta synth-page-head-note">
            Server-managed audio cache and image gallery.
        </div>
        <div class="cache-actions synth-page-head-actions">
            <a class="cache-btn" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/soundcache/" target="_blank" rel="noopener">Open Audio Folder URL</a>
            <a class="cache-btn" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/image_gallery.php" target="_blank" rel="noopener">Open Image Gallery</a>
        </div>
    </div>

    <div class="cache-grid">
        <section class="cache-panel">
            <h2>Audio Cache</h2>
            <?php if (empty($audioFiles)): ?>
                <div class="empty">No cached audio files found.</div>
            <?php else: ?>
                <div class="cache-list">
                    <?php foreach ($audioFiles as $file): ?>
                        <?php
                            $relative = str_replace('\\', '/', substr($file['path'], strlen($soundRoot) + 1));
                            $url = $webRoot . '/soundcache/' . rawurlencode($relative);
                            $url = str_replace('%2F', '/', $url);
                        ?>
                        <div class="cache-row">
                            <div class="cache-name" title="<?php echo htmlspecialchars($relative, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="cache-small"><?php echo htmlspecialchars(strtoupper($file['ext']) . ' / ' . synthCacheFormatBytes((int)$file['size']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="cache-small"><?php echo htmlspecialchars(date('Y-m-d H:i', (int)$file['mtime']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <audio controls preload="none" src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"></audio>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="cache-panel">
            <h2>Image Cache</h2>
            <div class="cache-meta" style="padding:10px 12px;">
                <?php echo number_format(count($imageFiles)); ?> cached gallery item(s). The gallery reads from SYNTH Server data/pictures/gallery.
            </div>
            <iframe class="gallery-frame" src="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/image_gallery.php?embed=1"></iframe>
        </section>
    </div>
</main>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'footer.html');
$buffer = ob_get_clean();
echo $buffer;
?>

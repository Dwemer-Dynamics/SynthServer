<?php

// The one place the Composer (PSR-4) autoloader is loaded. Every web, CLI and worker entry reaches it through this
// file, directly or through bootstrap.php / lib/runtime_bootstrap.php. It also loads Synth\Bootstrap (bootstrap.php,
// which is not under a PSR-4 prefix), so its accessors are available wherever the autoloader is. Safe to include repeatedly. A checkout
// without vendor/ is tolerated here so health.php can still report it; classes then fail at first use.
(static function (): void {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
})();

require_once dirname(__DIR__) . '/bootstrap.php';

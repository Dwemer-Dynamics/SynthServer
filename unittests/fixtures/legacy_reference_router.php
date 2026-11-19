<?php

declare(strict_types=1);

// Test-only router for the parity checks in ApiV1RoutesTest. gamedata.php and stt.php are no longer web entry points
// (the game client never calls them; the API reaches them by include), but the test still needs them as the reference
// each adapter route is compared with. Everything else goes through the real router.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path, ['/gamedata.php', '/stt.php'], true)) {
    return false;
}

return require dirname(__DIR__, 2) . '/router.php';

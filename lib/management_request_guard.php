<?php

// Browser-origin guard for the management surface: web requests to scripts under ui/, plus web requests to the
// CLI-only maintenance entries under debug/ and tools/. It is CSRF and cross-site read defence, not authentication:
// the dashboard's only trust is the Apache loopback rule. Requiring this file enforces the policy once, before the
// including page touches configuration, the database or the filesystem. CLI runs and native API scripts outside
// these directories are never affected.

if (!function_exists('synthManagementOriginMatches')) {
    // Exact scheme, host and port match of a serialized origin against this request's Host. Forwarded headers are
    // ignored, so a TLS-terminating or Host-rewriting proxy must preserve Host and the scheme.
    function synthManagementOriginMatches(string $origin, array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? null;
        $authority = '(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?';
        $port = static fn(array $m, int $i): int => ($m[$i] ?? '') !== '' ? (int) $m[$i] : ($scheme === 'https' ? 443 : 80);
        return is_string($host)
            && preg_match("~^(https?)://$authority$~iD", $origin, $o) === 1
            && preg_match("~^$authority$~iD", $host, $h) === 1
            && strtolower($o[1]) === $scheme && strtolower($o[2]) === strtolower($h[1])
            && ($originPort = $port($o, 3)) === $port($h, 2) && $originPort >= 1 && $originPort <= 65535;
    }
}

if (!function_exists('synthManagementScriptScope')) {
    // 'browser' for dashboard scripts, 'cli-only' for maintenance scripts, null outside the management surface.
    function synthManagementScriptScope(string $scriptPath, string $root): ?string
    {
        $normalize = static fn(string $path): string => strtolower(str_replace('\\', '/', $path));
        $script = $normalize($scriptPath);
        $base = rtrim($normalize($root), '/') . '/';
        if ($script === '' || !str_starts_with($script, $base)) {
            return null;
        }
        $relative = substr($script, strlen($base));
        if (str_starts_with($relative, 'ui/')) {
            return 'browser';
        }
        if (str_starts_with($relative, 'debug/') || str_starts_with($relative, 'tools/')) {
            return 'cli-only';
        }
        return null;
    }
}

if (!function_exists('synthManagementRequestRefusal')) {
    // Returns a refusal reason, or null when the request may proceed. Browser evidence that is present must prove
    // a same-origin request; Origin "null", same-site (another local port) and unknown values fail closed. A request
    // carrying no browser evidence at all is a native or operator HTTP client, except an unsafe request with a
    // browser User-Agent: older browsers can omit Origin on form posts and a page can suppress Referer.
    // $getHasEffect marks a GET that does outbound or persistent work; it is then judged like a POST.
    function synthManagementRequestRefusal(array $server, string $scope = 'browser', bool $getHasEffect = false): ?string
    {
        if ($scope === 'cli-only') {
            return 'This maintenance entry runs only from the command line.';
        }
        $method = strtoupper(strval($server['REQUEST_METHOD'] ?? 'GET'));
        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        $origin = $server['HTTP_ORIGIN'] ?? null;
        $referer = $server['HTTP_REFERER'] ?? null;
        $crossOrigin = 'Cross-origin management requests are not allowed. Open the SYNTH dashboard directly and try again.';

        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            return $crossOrigin;
        }
        if ($origin !== null) {
            return is_string($origin) && synthManagementOriginMatches($origin, $server) ? null : $crossOrigin;
        }
        if ($site !== null) {
            return null;
        }
        if ($referer !== null) {
            $parts = is_string($referer) ? parse_url($referer) : false;
            $refererOrigin = is_array($parts) && isset($parts['scheme'], $parts['host']) && !isset($parts['user'])
                ? $parts['scheme'] . '://' . (str_contains($parts['host'], ':') && $parts['host'][0] !== '[' ? '[' . $parts['host'] . ']' : $parts['host'])
                    . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : '';
            return $refererOrigin !== '' && synthManagementOriginMatches($refererOrigin, $server) ? null : $crossOrigin;
        }
        if (($getHasEffect || !in_array($method, ['GET', 'HEAD'], true))
            && str_contains(strval($server['HTTP_USER_AGENT'] ?? ''), 'Mozilla/')) {
            return 'This browser did not identify the request origin. Reload the SYNTH dashboard page and try again.';
        }
        return null;
    }
}

if (!function_exists('synthManagementCsrfToken')) {
    // One session token for dashboard forms that have no endpoint-specific token. Call before page output.
    function synthManagementCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!is_string($_SESSION['synth_management_csrf'] ?? null)) {
            $_SESSION['synth_management_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['synth_management_csrf'];
    }

    function synthManagementCsrfValid(mixed $token): bool
    {
        return is_string($token) && hash_equals(synthManagementCsrfToken(), $token);
    }

    function synthManagementCsrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(synthManagementCsrfToken(), ENT_QUOTES) . '">';
    }

    // Defines synthManagementPost(url, fields) for controls that are not forms: it submits a hidden POST form with
    // the session token, so navigation, history and the handler's redirect behave as the old link did.
    function synthManagementPostScript(): string
    {
        $token = json_encode(synthManagementCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return "<script>window.synthManagementPost=function(url,fields){var f=document.createElement('form');"
            . "f.method='post';f.action=url;f.style.display='none';var v=Object.assign({csrf_token:$token},fields||{});"
            . "Object.keys(v).forEach(function(k){var i=document.createElement('input');i.type='hidden';i.name=k;"
            . "i.value=String(v[k]);f.appendChild(i);});document.body.appendChild(f);f.submit();};</script>";
    }
}

if (!function_exists('synthManagementRefuse')) {
    function synthManagementRefuse(int $status, string $message, ?bool $json = null): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Cache-Control: no-store');
        if ($status === 405) {
            header('Allow: POST');
        }
        $json ??= str_contains(strval($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
        echo $json ? json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES) : $message . "\n";
        exit;
    }

    // Converted management actions, checked before bootstrap. A former link carrying one of $legacyGetKeys is
    // refused; a POST carrying one of $postKeys (default: the same names) needs the session form token. Returns
    // true for a confirmed POST action. A legacy key in the query, or on a guarded POST an action key or companion
    // value key from $valueKeys, that arrives as an array (delete[]=x) is refused first, so it never casts to row 1
    // in a handler. POSTs without a guarded action key belong to the page's other handlers and are not inspected.
    function synthManagementActionGate(array $legacyGetKeys, ?array $postKeys = null, array $valueKeys = []): bool
    {
        $malformed = static fn(array $source, array $keys): bool =>
            array_filter($keys, static fn($key) => array_key_exists($key, $source) && !is_scalar($source[$key])) !== [];
        if ($malformed($_GET, $legacyGetKeys)) {
            synthManagementRefuse(400, 'Malformed dashboard action.');
        }
        foreach ($legacyGetKeys as $key) {
            if (isset($_GET[$key])) {
                synthManagementRefuse(400, 'This action is no longer available by link. Use the button on the dashboard page.');
            }
        }
        $postKeys ??= $legacyGetKeys;
        if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || array_filter($postKeys, static fn($key) => array_key_exists($key, $_POST)) === []) {
            return false;
        }
        if ($malformed($_POST, array_merge($postKeys, $valueKeys))) {
            synthManagementRefuse(400, 'Malformed dashboard action.');
        }
        foreach ($postKeys as $key) {
            if (isset($_POST[$key])) {
                if (!synthManagementCsrfValid($_POST['csrf_token'] ?? null)) {
                    synthManagementRefuse(403, 'The page expired. Reload it and try again.');
                }
                return true;
            }
        }
        return false;
    }

    // Pages that only perform one action: refuse anything but a confirmed POST carrying the token, before bootstrap.
    function synthManagementRequirePostToken(string $hint): void
    {
        if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            synthManagementRefuse(405, $hint);
        }
        if (!synthManagementCsrfValid($_POST['csrf_token'] ?? null)) {
            synthManagementRefuse(403, 'The page expired. Reload it and try again.');
        }
    }
}

if (PHP_SAPI !== 'cli' && !defined('SYNTH_MANAGEMENT_REQUEST_CHECKED')) {
    define('SYNTH_MANAGEMENT_REQUEST_CHECKED', true);
    // SCRIPT_FILENAME is the requested script, also behind the dev router; a proxy:fcgi handler value is not a
    // file, and then the first included file is the requested script.
    $synthManagementScript = '';
    foreach ([strval($_SERVER['SCRIPT_FILENAME'] ?? ''), strval(get_included_files()[0] ?? '')] as $synthManagementCandidate) {
        if ($synthManagementCandidate !== '' && is_file($synthManagementCandidate)) {
            $synthManagementScript = realpath($synthManagementCandidate) ?: '';
            break;
        }
    }
    $synthManagementScope = synthManagementScriptScope($synthManagementScript, realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $synthManagementPath = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $synthManagementScript));
    // A GET handler that reaches out to another service counts as unsafe: the Quickstart MiniMe probe.
    $synthManagementGetEffect = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['minime_probe'])
        && str_ends_with($synthManagementPath, '/ui/quickstart.php');
    $synthManagementRefusal = $synthManagementScope === null ? null
        : synthManagementRequestRefusal($_SERVER, $synthManagementScope, $synthManagementGetEffect);
    if ($synthManagementRefusal !== null) {
        header('Vary: Origin, Referer, Sec-Fetch-Site');
        synthManagementRefuse(403, $synthManagementRefusal,
            preg_match('~/ui/(api|cmd)/~', $synthManagementPath) === 1 ? true : null);
    }
    unset($synthManagementScript, $synthManagementCandidate, $synthManagementScope, $synthManagementRefusal,
        $synthManagementGetEffect, $synthManagementPath);
}

<?php

// Browser-origin guard for the management surface: web requests to scripts under ui/, plus web requests to the
// CLI-only maintenance entries under debug/ and tools/. It is CSRF and cross-site read defence, not authentication:
// the dashboard's only trust is the Apache loopback rule plus a Host-header allowlist that blocks DNS rebinding.
// Requiring this file enforces the policy once, before the
// including page touches configuration, the database or the filesystem. CLI runs and native API scripts outside
// these directories are never affected. The host, origin and address rules live in Synth\Lib\LocalAccess and
// Synth\Lib\SameOriginRequest; the functions below keep their names and delegate to them.

require_once __DIR__ . '/../lib/classes/Logger.php';
require_once __DIR__ . '/LocalAccess.php';
require_once __DIR__ . '/SameOriginRequest.php';

if (!function_exists('synthManagementOriginMatches')) {
    // Exact scheme, host and port match of a serialized origin against this request's Host. Forwarded headers are
    // ignored, so a TLS-terminating or Host-rewriting proxy must preserve Host and the scheme.
    function synthManagementOriginMatches(string $origin, array $server): bool
    {
        return \Synth\Lib\SameOriginRequest::originMatches($origin, $server);
    }
}

if (!function_exists('synthManagementHostRefusalMessage')) {
    // Generic answer for a Host the dashboard does not serve. It names no configured value.
    function synthManagementHostRefusalMessage(): string
    {
        return 'This host name is not allowed for the SYNTH dashboard. Open it by 127.0.0.1, localhost, '
            . 'this machine\'s IP address, or a name listed in SYNTH_ALLOWED_HOSTS (see docs/HANDOFF.md).';
    }

    // Splits a Host or Origin authority into a lowercase host (IPv6 keeps its brackets, trailing dot removed) and
    // is null for anything that is not a plain hostname, IPv4 literal or bracketed IPv6 literal with an optional port.
    function synthManagementHostName(mixed $authority): ?string
    {
        return \Synth\Lib\LocalAccess::hostName($authority);
    }

    // True for an IPv4 literal or a bracketed IPv6 literal (see LocalAccess::isIpLiteral).
    function synthManagementHostIsIpLiteral(string $host): bool
    {
        return \Synth\Lib\LocalAccess::isIpLiteral($host);
    }

    // Operator hostnames (not IP literals, which are always allowed) from "a,b c;d". Ports are ignored, wildcards and
    // anything that is not a plain hostname are dropped.
    function synthManagementParseAllowedHosts(mixed $value): array
    {
        return \Synth\Lib\LocalAccess::parseAllowedHosts($value);
    }

    // SYNTH_ALLOWED_HOSTS from the environment, else conf/conf.php. Read lazily, only for a non-loopback hostname.
    function synthManagementConfiguredHosts(): array
    {
        $env = getenv('SYNTH_ALLOWED_HOSTS');
        if (is_string($env) && trim($env) !== '') {
            return synthManagementParseAllowedHosts($env);
        }
        $conf = dirname(__DIR__) . '/conf/conf.php';
        if (!is_file($conf)) {
            return [];
        }
        ob_start();
        try {
            $value = (static function (string $__file): mixed {
                require $__file;
                return $SYNTH_ALLOWED_HOSTS ?? null;
            })($conf);
        } catch (Throwable) {
            $value = null;
        } finally {
            ob_end_clean();
        }
        return synthManagementParseAllowedHosts($value);
    }

    // DNS-rebinding defence: the request's Host must be loopback (localhost, *.localhost), an IP literal, this
    // machine's own host name, or an operator-listed name. $extraHosts replaces the configured list (tests).
    function synthManagementHostAllowed(array $server, ?array $extraHosts = null): bool
    {
        $host = \Synth\Lib\LocalAccess::hostName($server['HTTP_HOST'] ?? null);
        if ($host === null) {
            return false;
        }
        // The configured list is read lazily, only for a name that is not loopback, an IP literal or this machine.
        return \Synth\Lib\LocalAccess::hostAllowed($server, [])
            || \Synth\Lib\LocalAccess::hostAllowed($server, $extraHosts ?? synthManagementConfiguredHosts());
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
    function synthManagementRequestRefusal(array $server, string $scope = 'browser', bool $getHasEffect = false, ?array $extraHosts = null): ?string
    {
        if ($scope === 'cli-only') {
            return 'This maintenance entry runs only from the command line.';
        }
        if (!synthManagementHostAllowed($server, $extraHosts)) {
            return synthManagementHostRefusalMessage();
        }
        return \Synth\Lib\SameOriginRequest::refusal($server, $getHasEffect);
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
    if ($synthManagementRefusal === synthManagementHostRefusalMessage()) {
        // One structured line, no secrets: the Host value is control-character-stripped and truncated.
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'management_request_guard', json_encode([
            'event' => 'management_host_refused',
            'host' => substr(preg_replace('#[^ -~]#', '?', strval($_SERVER['HTTP_HOST'] ?? '')), 0, 100),
            'method' => substr(preg_replace('~[^A-Z]~', '', strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? ''))), 0, 10),
            'path' => substr(preg_replace('#[^!-~]#', '?', strtok(strval($_SERVER['REQUEST_URI'] ?? ''), '?')), 0, 200),
        ], JSON_UNESCAPED_SLASHES));
    }
    if ($synthManagementRefusal !== null) {
        header('Vary: Host, Origin, Referer, Sec-Fetch-Site');
        synthManagementRefuse(403, $synthManagementRefusal,
            preg_match('~/ui/(api|cmd)/~', $synthManagementPath) === 1 ? true : null);
    }
    unset($synthManagementScript, $synthManagementCandidate, $synthManagementScope, $synthManagementRefusal,
        $synthManagementGetEffect, $synthManagementPath);
}

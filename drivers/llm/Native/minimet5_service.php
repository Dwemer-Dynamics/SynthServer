<?php
/**
 * MiniMeT5 Service Functions
 *
 * These functions interface with the MiniMeT5/TXT2VEC service configured by
 * FEATURES.MEMORY_EMBEDDING.TXTAI_URL (local port 8082 by default).
 * If the service is not running or not configured, they fail gracefully
 * by returning null/empty instead of blocking on connection timeouts.
 *
 * The service availability is cached per-request to avoid repeated
 * connection attempts when the service is down.
 */

require_once dirname(__DIR__, 3).'/lib' . '/../lib/classes/Logger.php';
function _minimeServiceBaseUrl() {
    $defaultUrl = 'http://127.0.0.1:8082';
    $configuredUrl = \Synth\Bootstrap::config('FEATURES')['MEMORY_EMBEDDING']['TXTAI_URL'] ?? $defaultUrl;
    $configuredUrl = rtrim(trim((string)$configuredUrl), '/');

    if ($configuredUrl === '' || filter_var($configuredUrl, FILTER_VALIDATE_URL) === false) {
        return $defaultUrl;
    }

    $parts = parse_url($configuredUrl);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
        return $defaultUrl;
    }

    return $configuredUrl;
}

function _minimeServiceEndpoint($path, $query = []) {
    $url = _minimeServiceBaseUrl() . '/' . ltrim((string)$path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

/**
 * Check if MiniMeT5 service is available (cached per request)
 * Uses a quick socket check instead of waiting for HTTP timeout
 */
function _minimeServiceAvailable() {
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    $parts = parse_url(_minimeServiceBaseUrl());
    $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
    $host = (string)($parts['host'] ?? '127.0.0.1');
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $socketHost = ($scheme === 'https' ? 'ssl://' : '') . $host;

    // Quick socket check - 100ms timeout
    $socket = @fsockopen($socketHost, $port, $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        $available = true;
    } else {
        $available = false;
        // Only log once per request
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'MiniMeT5', "Service not available at {$host}:{$port} - skipping MiniMe calls this request");
    }

    return $available;
}

/**
 * MiniMe auto-mode:
 * - Enabled by default when service is reachable.
 * - Automatically considered off when service is unavailable.
 * - In tests, mock handlers imply enabled.
 */
function isMinimeT5Enabled() {
    if (\Synth\Bootstrap::has('mockMinimeExtract') ||
        \Synth\Bootstrap::has('mockMinimePostTopic') ||
        \Synth\Bootstrap::has('mockMinimeTask') ||
        \Synth\Bootstrap::has('mockMinimeTopic') ||
        \Synth\Bootstrap::has('mockMinimePostScene')) {
        return true;
    }
    return _minimeServiceAvailable();
}

function minimeExtract($text,$useOnlyDetector=false) {
    if (\Synth\Bootstrap::has('mockMinimeExtract')) {
        return call_user_func(\Synth\Bootstrap::config("mockMinimeExtract"), $text);
    }

    // Skip if service not available
    if (!_minimeServiceAvailable()) {
        return null;
    }

    $nativeRecall = (\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1) === 2
        && !empty(\Synth\Bootstrap::claimedRequest());
    // Detection and tag extraction have different response shapes; native text fallback must not reuse a detector result.
    $cacheKey = $nativeRecall ? 'native:'.($useOnlyDetector ? 'detect:' : 'extract:').hash('sha256',_minimeServiceBaseUrl()."\0".$text) : md5($text);
    if ($nativeRecall) {
        require_once __DIR__.'/provider_stream.php';
        synth_protocol_assert_request_active();
    }
    if (isset(\Synth\Bootstrap::config('MINIME_CACHE')[$cacheKey]))
        return \Synth\Bootstrap::config("MINIME_CACHE")[$cacheKey];

    if ($useOnlyDetector)
        $url = _minimeServiceEndpoint('detectMemory', ['text' => $text]);
    else
        $url = _minimeServiceEndpoint('extract', ['text' => $text]);

    if ($nativeRecall) {
        $headers = [];
        $result = synth_provider_fetch($url,stream_context_create(['http'=>['timeout'=>10]]),$headers,1024*1024);
    } else {
        $result = @file_get_contents($url);
    }
    \Synth\Bootstrap::setAt('MINIME_CACHE', $cacheKey, $result !== false ? $result : null);
    return \Synth\Bootstrap::config("MINIME_CACHE")[$cacheKey];
}

function minimePostTopic($text) {
    if (\Synth\Bootstrap::has('mockMinimePostTopic')) {
        return call_user_func(\Synth\Bootstrap::config("mockMinimePostTopic"), $text);
    }

    // Skip if service not available
    if (!_minimeServiceAvailable()) {
        return null;
    }

    $url = _minimeServiceEndpoint('posttopic', ['text' => $text]);
    $result = @file_get_contents($url);
    return $result !== false ? $result : null;
}

function minimeTask($text) {
    if (\Synth\Bootstrap::has('mockMinimeTask')) {
        return call_user_func(\Synth\Bootstrap::config("mockMinimeTask"), $text);
    }

    // Skip if service not available
    if (!_minimeServiceAvailable()) {
        return null;
    }

    $url = _minimeServiceEndpoint('task', ['text' => $text]);
    $result = @file_get_contents($url);
    return $result !== false ? $result : null;
}

function minimeTopic($text) {
    if (\Synth\Bootstrap::has('mockMinimeTopic')) {
        return call_user_func(\Synth\Bootstrap::config("mockMinimeTopic"), $text);
    }

    // Skip if service not available
    if (!_minimeServiceAvailable()) {
        return null;
    }

    $url = _minimeServiceEndpoint('topic', ['text' => $text]);
    $result = @file_get_contents($url);
    return $result !== false ? $result : null;
}

function minimePostScene($text) {
    if (\Synth\Bootstrap::has('mockMinimePostScene')) {
        return call_user_func(\Synth\Bootstrap::config("mockMinimePostScene"), $text);
    }

    // Skip if service not available
    if (!_minimeServiceAvailable()) {
        return null;
    }

    $url = _minimeServiceEndpoint('ambient', ['text' => $text]);
    $result = @file_get_contents($url);
    return $result !== false ? $result : null;
}

?>

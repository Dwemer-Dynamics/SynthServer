<?php

declare(strict_types=1);

// Granular, read-only health checks behind health.php?check=NAME. Every check answers a small JSON-able array and
// never throws to the caller: a failure becomes a fixed, sanitized message (driver text can carry connection
// details). Nothing here writes, migrates, takes a lock, loads settings or sends a provider request. The LLM check
// only opens a bare TCP connection to each configured endpoint host, so no key, prompt or token is ever sent.

if (!function_exists('synthHealthCheckNames')) {
    /** Checks health.php serves through ?check=. "live" needs no configuration; "all" runs the other four. */
    function synthHealthCheckNames(): array
    {
        return ['live', 'db', 'api-keys', 'npcs', 'llm', 'all'];
    }

    /** Wall-clock budget for all LLM endpoint probes together, in seconds. */
    define('SYNTH_HEALTH_LLM_PROBE_SECONDS', 2.0);
    /** Most distinct endpoints probed per request, so a large connector list cannot stretch the check. */
    define('SYNTH_HEALTH_LLM_MAX_ENDPOINTS', 5);

    /** @return array{0:int,1:array} HTTP status and JSON body of the cheapest possible liveness answer. */
    function synthHealthLive(string $service, string $version): array
    {
        return [200, ['check' => 'live', 'status' => 'ok', 'service' => $service, 'version' => $version,
            'timestamp' => gmdate('c')]];
    }

    /** @return array{0:int,1:array} */
    function synthHealthDatabase(?object $db, ?string $failure = null): array
    {
        $body = ['check' => 'db', 'status' => 'error', 'database' => false, 'database_encoding_supported' => false];
        if ($db === null || $failure !== null) {
            return [503, $body + ['error' => $failure ?? 'SYNTH cannot connect to PostgreSQL.']];
        }
        try {
            $body['database'] = (bool)$db->query('SELECT 1');
            $encoding = synthRuntimeDatabaseEncoding();
            $body['database_encoding_supported'] = synthRuntimeDatabaseEncodingIsSupported($encoding);
        } catch (Throwable) {
            $body['database'] = false;
        }
        if (!$body['database']) {
            return [503, $body + ['error' => 'SYNTH cannot query PostgreSQL; see the web server error log.']];
        }
        if (!$body['database_encoding_supported']) {
            return [503, $body + ['error' => 'The SYNTH database encoding is not supported; see health.php.']];
        }
        $body['status'] = 'ok';
        return [200, $body];
    }

    /** Rows of core_api_badge holding a non-empty key, counted through the existing facade; never the keys. */
    function synthHealthApiKeyCount(): int
    {
        require_once dirname(__DIR__) . '/lib/core/api_badge.class.php';
        $count = 0;
        foreach ((new ApiBadge())->getAll() as $row) {
            if (is_array($row) && trim(strval($row['api_key'] ?? '')) !== '') {
                $count++;
            }
        }
        return $count;
    }

    /** @return array{0:int,1:array} A count of zero is a warning, not an outage: local providers need no key. */
    function synthHealthApiKeys(): array
    {
        $body = ['check' => 'api-keys', 'status' => 'error', 'api_keys_configured' => null];
        try {
            $count = synthHealthApiKeyCount();
        } catch (Throwable) {
            return [503, $body + ['error' => 'SYNTH could not read its API key store; see the web server error log.']];
        }
        $body['api_keys_configured'] = $count;
        $body['status'] = $count > 0 ? 'ok' : 'warning';
        return [200, $body];
    }

    /** @return array{0:int,1:array} Characters (NPC profiles) the server can already answer for. */
    function synthHealthNpcs(object $db): array
    {
        $body = ['check' => 'npcs', 'status' => 'error', 'npcs_available' => null];
        try {
            require_once dirname(__DIR__) . '/core/dbmanager/repositories/CharacterRepository.php';
            $count = (new \Synth\Core\CharacterRepository($db))->availableCount();
        } catch (Throwable) {
            return [503, $body + ['error' => 'SYNTH could not read its characters; see the web server error log.']];
        }
        $body['npcs_available'] = $count;
        $body['status'] = $count > 0 ? 'ok' : 'warning';
        return [200, $body];
    }

    /**
     * Connectors that name both an http(s) endpoint and a model, and their distinct host:port endpoints (capped).
     * Pure: takes stored rows, returns counts and endpoints, and never returns a key or a full URL.
     * @return array{configured:int,endpoints:list<array{0:string,1:int}>}
     */
    function synthHealthLlmTargets(array $connectors): array
    {
        $configured = 0;
        $endpoints = [];
        foreach ($connectors as $row) {
            $url = is_array($row) ? trim(strval($row['url'] ?? '')) : '';
            $parts = $url === '' ? false : parse_url($url);
            $scheme = is_array($parts) ? strtolower(strval($parts['scheme'] ?? '')) : '';
            $host = is_array($parts) ? strval($parts['host'] ?? '') : '';
            if (($scheme !== 'http' && $scheme !== 'https') || $host === '' || trim(strval($row['model'] ?? '')) === '') {
                continue;
            }
            $configured++;
            $port = is_int($parts['port'] ?? null) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);
            $host = trim($host, '[]');
            $endpoints[strtolower($host) . ':' . $port] = [$host, $port];
        }
        return ['configured' => $configured,
            'endpoints' => array_slice(array_values($endpoints), 0, SYNTH_HEALTH_LLM_MAX_ENDPOINTS)];
    }

    /**
     * Bare TCP connect to every endpoint at once, bounded by one shared deadline. Returns how many accepted.
     * @param list<array{0:string,1:int}> $endpoints
     */
    function synthHealthProbeEndpoints(array $endpoints, float $seconds = SYNTH_HEALTH_LLM_PROBE_SECONDS): int
    {
        $pending = [];
        $reachable = 0;
        foreach ($endpoints as [$host, $port]) {
            $address = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
            $socket = @stream_socket_client('tcp://' . $address, $errno, $error, $seconds,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if (is_resource($socket)) {
                $pending[] = $socket;
            }
        }
        $deadline = microtime(true) + $seconds;
        while ($pending !== [] && ($left = $deadline - microtime(true)) > 0) {
            $write = $pending;
            $read = $except = null;
            $wait = max(1, (int)floor($left * 1000000));
            if (@stream_select($read, $write, $except, 0, $wait) === false || $write === []) {
                break;
            }
            foreach ($write as $socket) {
                // A refused or reset asynchronous connect is also "writable"; the peer name exists only when it connected.
                if (@stream_socket_get_name($socket, true) !== false) {
                    $reachable++;
                }
                $pending = array_values(array_filter($pending, static fn($open): bool => $open !== $socket));
                fclose($socket);
            }
        }
        foreach ($pending as $socket) {
            fclose($socket);
        }
        return $reachable;
    }

    /** @return array{0:int,1:array} */
    function synthHealthLlm(?callable $probe = null): array
    {
        $body = ['check' => 'llm', 'status' => 'error', 'llm' => 'unavailable', 'llm_connectors_configured' => null,
            'llm_endpoints_checked' => 0, 'llm_endpoints_reachable' => 0];
        try {
            require_once dirname(__DIR__) . '/lib/core/llm_connector.class.php';
            $targets = synthHealthLlmTargets((new LLMConnector())->readAll());
            $body['llm_connectors_configured'] = $targets['configured'];
            $body['llm_endpoints_checked'] = count($targets['endpoints']);
            $body['llm_endpoints_reachable'] = $targets['endpoints'] === []
                ? 0 : ($probe ?? 'synthHealthProbeEndpoints')($targets['endpoints']);
        } catch (Throwable) {
            return [503, $body + ['error' => 'SYNTH could not read its LLM connectors; see the web server error log.']];
        }
        if ($body['llm_connectors_configured'] === 0) {
            $body['llm'] = 'not_configured';
            return [503, $body + ['error' => 'No LLM connector with an http(s) URL and a model is configured; add one in the dashboard.']];
        }
        if ($body['llm_endpoints_reachable'] === 0) {
            $body['llm'] = 'unreachable';
            return [503, $body + ['error' => 'No configured LLM endpoint accepted a connection; start the provider or check its URL.']];
        }
        $body['llm'] = 'ok';
        $body['status'] = 'ok';
        return [200, $body];
    }

    /** Worst state of several check bodies: error > warning > ok. */
    function synthHealthWorstStatus(array $bodies): string
    {
        $worst = 'ok';
        foreach ($bodies as $body) {
            $status = strval($body['status'] ?? 'error');
            if ($status === 'error') {
                return 'error';
            }
            if ($status !== 'ok') {
                $worst = 'warning';
            }
        }
        return $worst;
    }

    /**
     * One request, one database connection, every configured check. Without a database the database check carries
     * the failure and the others are reported as skipped, so the status page still has something to show.
     * @return array{0:int,1:array}
     */
    function synthHealthAll(?object $db, ?string $failure): array
    {
        $checks = [];
        [, $checks['db']] = synthHealthDatabase($db, $db === null ? ($failure ?? 'SYNTH cannot connect to PostgreSQL.') : null);
        $skipped = static fn(string $name): array => ['check' => $name, 'status' => 'error', 'skipped' => true,
            'error' => 'Skipped because the database check failed.'];
        if ($checks['db']['status'] === 'ok') {
            [, $checks['api-keys']] = synthHealthApiKeys();
            [, $checks['npcs']] = synthHealthNpcs($db);
            [, $checks['llm']] = synthHealthLlm();
        } else {
            foreach (['api-keys', 'npcs', 'llm'] as $name) {
                $checks[$name] = $skipped($name);
            }
        }
        $status = synthHealthWorstStatus($checks);
        return [$status === 'error' ? 503 : 200, ['check' => 'all', 'status' => $status, 'checks' => $checks,
            'timestamp' => gmdate('c')]];
    }
}

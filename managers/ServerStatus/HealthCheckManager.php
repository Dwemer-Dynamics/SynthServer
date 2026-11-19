<?php

/**
 * Granular, read-only health checks behind health.php?check=NAME: liveness, database, API keys, NPCs and the LLM
 * provider. Every check answers a small array and never throws to the caller: a failure becomes a fixed, sanitized
 * message (driver text can carry connection details). Nothing here writes, migrates, takes a lock, loads settings or
 * sends a provider request; the LLM check only opens a bare TCP connection to each configured endpoint host, so no
 * key, prompt or token is ever sent. See docs/HEALTH-CHECKS.md.
 */
declare(strict_types=1);

namespace Synth\Managers\ServerStatus;

final class HealthCheckManager
{
    // Wall-clock budget for all LLM endpoint probes together, in seconds.
    private const PROBE_SECONDS = 2.0;

    // Most distinct endpoints probed per request, so a large connector list cannot stretch the check.
    private const MAX_ENDPOINTS = 5;

    /** Checks health.php serves through ?check=. "live" needs no configuration; "all" runs the other four. */
    public static function checkNames(): array
    {
        return ['live', 'db', 'api-keys', 'npcs', 'llm', 'all'];
    }

    /** @return array{0:int,1:array} HTTP status and JSON body of the cheapest possible liveness answer. */
    public static function live(string $service, string $version): array
    {
        return [200, ['check' => 'live', 'status' => 'ok', 'service' => $service, 'version' => $version,
            'timestamp' => gmdate('c')]];
    }

    /** Database check: connects, runs SELECT 1 and verifies the encoding. @return array{0:int,1:array} */
    public static function database(?object $db, ?string $failure = null): array
    {
        $body = ['check' => 'db', 'status' => 'error', 'database' => false, 'database_encoding_supported' => false];
        if ($db === null || $failure !== null) {
            return [503, $body + ['error' => $failure ?? 'SYNTH cannot connect to PostgreSQL.']];
        }
        try {
            $body['database'] = (bool)$db->query('SELECT 1');
            $encoding = \synthRuntimeDatabaseEncoding();
            $body['database_encoding_supported'] = \synthRuntimeDatabaseEncodingIsSupported($encoding);
        } catch (\Throwable) {
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

    /** Rows of core_api_badge holding a non-empty key, counted by the database through the existing facade. */
    public static function apiKeyCount(): int
    {
        require_once dirname(__DIR__, 2) . '/lib/core/api_badge.class.php';
        return (new \ApiBadge())->countWithKeys();
    }

    /** @return array{0:int,1:array} A count of zero is a warning, not an outage: local providers need no key. */
    public static function apiKeys(): array
    {
        $body = ['check' => 'api-keys', 'status' => 'error', 'api_keys_configured' => null];
        try {
            $count = self::apiKeyCount();
        } catch (\Throwable) {
            return [503, $body + ['error' => 'SYNTH could not read its API key store; see the web server error log.']];
        }
        $body['api_keys_configured'] = $count;
        $body['status'] = $count > 0 ? 'ok' : 'warning';
        return [200, $body];
    }

    /** @return array{0:int,1:array} Characters (NPC profiles) the server can already answer for. */
    public static function npcs(object $db): array
    {
        $body = ['check' => 'npcs', 'status' => 'error', 'npcs_available' => null];
        try {
            require_once dirname(__DIR__, 2) . '/core/dbmanager/repositories/CharacterRepository.php';
            $count = (new \Synth\Core\CharacterRepository($db))->availableCount();
        } catch (\Throwable) {
            return [503, $body + ['error' => 'SYNTH could not read its characters; see the web server error log.']];
        }
        $body['npcs_available'] = $count;
        $body['status'] = $count > 0 ? 'ok' : 'warning';
        return [200, $body];
    }

    /**
     * Connectors that name both an http(s) endpoint and a model, split into endpoints that can be probed without a
     * DNS lookup (IP literals and localhost, distinct, capped) and the number of distinct hosted host names, which are
     * never resolved or contacted. Pure: takes stored rows and never returns a key or a full URL.
     * @return array{configured:int,endpoints:list<array{0:string,1:int}>,hosted:int}
     */
    public static function llmTargets(array $connectors): array
    {
        $configured = 0;
        $endpoints = [];
        $hosted = [];
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
            if (strtolower($host) === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $endpoints[strtolower($host) . ':' . $port] = [$host, $port];
            } else {
                $hosted[strtolower($host) . ':' . $port] = true;
            }
        }
        return ['configured' => $configured,
            'endpoints' => array_slice(array_values($endpoints), 0, self::MAX_ENDPOINTS), 'hosted' => count($hosted)];
    }

    /**
     * Bare TCP connect to every endpoint at once, bounded by one shared deadline. Endpoints must be IP literals or
     * localhost, so no DNS lookup can run outside the deadline. Returns how many accepted.
     * @param list<array{0:string,1:int}> $endpoints
     */
    public static function probeEndpoints(array $endpoints, float $seconds = self::PROBE_SECONDS): int
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

    /**
     * Runs $work only when no other health request is probing endpoints, so polls cannot pile up behind a slow
     * connect. The lock is a non-blocking flock on this source file: nothing is created or written.
     * @return mixed what $work returns, or null when another probe holds the lock
     */
    public static function whenNoProbeRunning(callable $work): mixed
    {
        $handle = @fopen(__FILE__, 'rb');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }
            return null;
        }
        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * LLM check. Only the connector url and model are read (one query, never a key). Local endpoints (IP literals and
     * localhost) get a bounded TCP connect; hosted host names are counted but never resolved or contacted, because a
     * DNS lookup cannot be bounded. No connector is a warning, as are local endpoints that refuse (when a hosted one
     * exists) and a probe that is already running; only local endpoints that all refuse with nothing hosted is an error.
     * @param callable|null $probe replaces the TCP probe (tests)
     * @param array|null $rows replaces the stored connector rows (tests)
     * @return array{0:int,1:array}
     */
    public static function llm(?callable $probe = null, ?array $rows = null): array
    {
        $body = ['check' => 'llm', 'status' => 'error', 'llm' => 'unavailable', 'llm_connectors_configured' => null,
            'llm_endpoints_checked' => 0, 'llm_endpoints_reachable' => 0, 'llm_hosted_unchecked' => 0];
        try {
            if ($rows === null) {
                require_once dirname(__DIR__, 2) . '/lib/core/llm_connector.class.php';
                $rows = (new \LLMConnector())->endpointRows();
            }
            $targets = self::llmTargets($rows);
            $body['llm_connectors_configured'] = $targets['configured'];
            $body['llm_endpoints_checked'] = count($targets['endpoints']);
            $body['llm_hosted_unchecked'] = $targets['hosted'];
            if ($targets['endpoints'] !== []) {
                $probe ??= [self::class, 'probeEndpoints'];
                $reachable = self::whenNoProbeRunning(static fn() => $probe($targets['endpoints']));
                if ($reachable === null) {
                    return [200, ['llm' => 'busy', 'status' => 'warning',
                        'error' => 'Another health check is probing the LLM endpoints; try again shortly.'] + $body];
                }
                $body['llm_endpoints_reachable'] = intval($reachable);
            }
        } catch (\Throwable) {
            return [503, $body + ['error' => 'SYNTH could not read its LLM connectors; see the web server error log.']];
        }
        if ($body['llm_connectors_configured'] === 0) {
            return [200, ['llm' => 'not_configured', 'status' => 'warning',
                'error' => 'No LLM connector with an http(s) URL and a model is configured; add one in the dashboard.'] + $body];
        }
        if ($body['llm_endpoints_checked'] > 0 && $body['llm_endpoints_reachable'] === 0) {
            if ($body['llm_hosted_unchecked'] > 0) {
                return [200, ['llm' => 'unreachable', 'status' => 'warning',
                    'error' => 'No local LLM endpoint accepted a connection; hosted endpoints are not probed.'] + $body];
            }
            return [503, ['llm' => 'unreachable',
                'error' => 'No configured LLM endpoint accepted a connection; start the provider or check its URL.'] + $body];
        }
        $body['llm'] = $body['llm_endpoints_reachable'] > 0 ? 'ok' : 'unverified';
        $body['status'] = 'ok';
        return [200, $body];
    }

    /** Worst state of several check bodies: error > warning > ok. */
    public static function worstStatus(array $bodies): string
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
    public static function all(?object $db, ?string $failure): array
    {
        $checks = [];
        [, $checks['db']] = self::database($db, $db === null ? ($failure ?? 'SYNTH cannot connect to PostgreSQL.') : null);
        $skipped = static fn(string $name): array => ['check' => $name, 'status' => 'error', 'skipped' => true,
            'error' => 'Skipped because the database check failed.'];
        if ($checks['db']['status'] === 'ok') {
            [, $checks['api-keys']] = self::apiKeys();
            [, $checks['npcs']] = self::npcs($db);
            [, $checks['llm']] = self::llm();
        } else {
            foreach (['api-keys', 'npcs', 'llm'] as $name) {
                $checks[$name] = $skipped($name);
            }
        }
        $status = self::worstStatus($checks);
        return [$status === 'error' ? 503 : 200, ['check' => 'all', 'status' => $status, 'checks' => $checks,
            'timestamp' => gmdate('c')]];
    }
}

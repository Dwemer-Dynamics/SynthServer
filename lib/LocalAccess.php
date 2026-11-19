<?php

/**
 * Tells which requests the management surface (the dashboard under ui/) may answer.
 *
 * SYNTH has no login of its own; Apache's `Require local` limits the client address and this class limits the
 * Host header, so a page reached through a rebound DNS name is refused. Forwarding headers mean a proxy sits
 * in front, so the peer address no longer shows who the client is. Nothing a client sends grants access.
 */
declare(strict_types=1);

namespace Synth\Lib;

final class LocalAccess
{
    // Headers proxies add for the client they forward; any of them means the peer is not the browser.
    private const FORWARDING_HEADERS = [
        'HTTP_FORWARDED',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_PORT',
        'HTTP_X_FORWARDED_SERVER',
        'HTTP_X_ORIGINAL_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_X_CLIENT_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_CLIENT_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_FASTLY_CLIENT_IP',
        'HTTP_VIA',
    ];

    /**
     * Returns whether the connection comes straight from this machine: a loopback peer, no forwarding header.
     *
     * @param array<string, mixed> $server The request's $_SERVER values.
     */
    public static function isDirectLoopbackClient(array $server): bool
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            if (array_key_exists($header, $server)) {
                return false;
            }
        }

        return self::isLoopbackAddress($server['REMOTE_ADDR'] ?? null);
    }

    /**
     * Returns whether an address is IPv4 127.0.0.0/8, IPv6 ::1, or 127.0.0.0/8 mapped into IPv6.
     */
    public static function isLoopbackAddress(mixed $address): bool
    {
        if (!is_string($address)) {
            return false;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return strlen($packed) === 4 ? $packed[0] === "\x7f" : $packed === str_repeat("\0", 15) . "\x01";
    }

    /**
     * Splits a Host or Origin authority into a lowercase host, or returns null for anything that is not a plain
     * hostname, IPv4 literal or bracketed IPv6 literal with an optional port. IPv6 keeps its brackets and a
     * trailing dot is removed.
     */
    public static function hostName(mixed $authority): ?string
    {
        if (!is_string($authority) || strlen($authority) > 255
            || preg_match('~^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.?)(?::([0-9]{1,5}))?$~iD', $authority, $m) !== 1) {
            return null;
        }
        $host = strtolower(rtrim($m[1], '.'));
        if ($host === '' || (isset($m[3]) && $m[3] !== '' && (int) $m[3] > 65535)) {
            return null;
        }

        return $host;
    }

    /**
     * Returns whether a host is an IPv4 literal or a bracketed IPv6 literal. A literal cannot be rebound: the
     * browser sends it only when the page itself was opened by that address.
     */
    public static function isIpLiteral(string $host): bool
    {
        if ($host !== '' && $host[0] === '[') {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Returns the operator host names (not IP literals) from "a,b c;d" or a list. Ports are ignored; wildcards
     * and anything that is not a plain host name are dropped.
     *
     * @return list<string>
     */
    public static function parseAllowedHosts(mixed $value): array
    {
        $tokens = is_array($value) ? $value : preg_split('~[\s,;]+~', is_string($value) ? $value : '', -1, PREG_SPLIT_NO_EMPTY);
        $hosts = [];
        foreach ($tokens as $token) {
            $host = self::hostName($token);
            if ($host !== null && !self::isIpLiteral($host)) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Returns whether the Host header names this machine: localhost, *.localhost, an IP literal, the machine's
     * own name, or a name in $allowedHosts.
     *
     * @param array<string, mixed> $server The request's $_SERVER values.
     * @param list<string> $allowedHosts Operator host names, already parsed.
     */
    public static function hostAllowed(array $server, array $allowedHosts): bool
    {
        $host = self::hostName($server['HTTP_HOST'] ?? null);
        if ($host === null) {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || self::isIpLiteral($host)) {
            return true;
        }
        $own = gethostname();
        if (is_string($own) && $own !== '' && $host === strtolower($own)) {
            return true;
        }

        return in_array($host, $allowedHosts, true);
    }

    /**
     * Returns whether a request may use the development server's dashboard: a direct loopback client that
     * names this machine in the Host header.
     *
     * @param array<string, mixed> $server The request's $_SERVER values.
     */
    public static function allowsDevelopmentRouter(array $server): bool
    {
        return self::isDirectLoopbackClient($server) && self::hostAllowed($server, []);
    }
}

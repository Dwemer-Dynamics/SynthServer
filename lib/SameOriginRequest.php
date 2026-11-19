<?php

/**
 * Tells whether a browser request to the dashboard was sent by a page of this server.
 *
 * It is protection against cross-site requests, not authentication: any program that can reach the server can
 * still send matching headers. Browser evidence that is present must prove a same-origin request; Origin
 * "null", same-site (another local port) and unknown values fail closed. Forwarded headers from proxies are
 * not trusted, so a proxy must preserve Host and the scheme.
 */
declare(strict_types=1);

namespace Synth\Lib;

final class SameOriginRequest
{
    public const CROSS_ORIGIN = 'Cross-origin management requests are not allowed. Open the SYNTH dashboard directly and try again.';
    public const UNIDENTIFIED = 'This browser did not identify the request origin. Reload the SYNTH dashboard page and try again.';

    /**
     * Returns a refusal text, or null when the request carries no evidence against it.
     *
     * A request with no browser evidence at all is a native or operator HTTP client, except an unsafe request
     * with a browser User-Agent: older browsers can omit Origin on form posts and a page can suppress Referer.
     * $hasEffect marks a GET that does outbound or persistent work; it is then judged like a POST.
     *
     * @param array<string, mixed> $server The request's $_SERVER values.
     */
    public static function refusal(array $server, bool $hasEffect = false): ?string
    {
        $method = strtoupper(strval($server['REQUEST_METHOD'] ?? 'GET'));
        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        $origin = $server['HTTP_ORIGIN'] ?? null;
        $referer = $server['HTTP_REFERER'] ?? null;

        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            return self::CROSS_ORIGIN;
        }
        if ($origin !== null) {
            return is_string($origin) && self::originMatches($origin, $server) ? null : self::CROSS_ORIGIN;
        }
        if ($site !== null) {
            return null;
        }
        if ($referer !== null) {
            $refererOrigin = is_string($referer) ? self::originOfUrl($referer) : '';

            return $refererOrigin !== '' && self::originMatches($refererOrigin, $server) ? null : self::CROSS_ORIGIN;
        }
        if (($hasEffect || !in_array($method, ['GET', 'HEAD'], true))
            && str_contains(strval($server['HTTP_USER_AGENT'] ?? ''), 'Mozilla/')) {
            return self::UNIDENTIFIED;
        }

        return null;
    }

    /**
     * Returns whether a serialized origin equals the scheme, host and port this request arrived on.
     *
     * @param array<string, mixed> $server The request's $_SERVER values.
     */
    public static function originMatches(string $origin, array $server): bool
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

    /**
     * Returns a URL's origin as "scheme://host[:port]", or an empty string for anything else; user names in
     * the URL are refused.
     */
    private static function originOfUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'])) {
            return '';
        }
        $host = str_contains($parts['host'], ':') && $parts['host'][0] !== '[' ? '[' . $parts['host'] . ']' : $parts['host'];

        return $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}

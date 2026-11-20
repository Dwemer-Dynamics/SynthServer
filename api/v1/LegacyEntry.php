<?php

declare(strict_types=1);

namespace Synth\Api\V1;

/**
 * Boundary helpers shared by the version one handlers.
 *
 * A handler checks that the request belongs to its route and then includes the legacy entry point that
 * already implements it. The helpers never replace that entry point's own validation, size limits or error
 * answers: they only make sure the wire `type` matches the route, so `/api/v1/<route>` and the root script
 * cannot be used to reach each other's behaviour.
 */
final class LegacyEntry
{
    /** The server root, where the legacy entry points live. */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Path of a legacy root entry point. */
    public static function script(string $name): string
    {
        return self::root() . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Refuses unless the JSON event body carries one of the wire `types`. The body is decoded by the same
     * helper `main.php` and `itt.php` use, so the method, content type, size, UTF-8 and JSON checks and their
     * answers are the legacy ones. Optionally also requires an object at `payload.<payloadKey>`.
     *
     * @param list<string> $types
     */
    public static function requireEventType(array $types, ?string $payloadKey = null): void
    {
        require_once self::script('lib/request.php');
        $event = synth_decode_json_body();
        $payload = $event['payload'] ?? null;
        $matches = in_array($event['type'] ?? null, $types, true)
            && ($payloadKey === null || (is_array($payload) && is_array($payload[$payloadKey] ?? null)));
        if (!$matches) {
            self::mismatch();
        }
    }

    /**
     * Same check for `gamedata.php` records. A body `gamedata.php` would refuse itself (not POST, not JSON,
     * not valid JSON, no type) is left for it to answer; only a well-formed record of another type is refused.
     *
     * @param list<string> $types
     */
    public static function requireGameDataType(array $types): void
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || !str_contains($contentType, 'application/json')) {
            return;
        }
        $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($data) && isset($data['type']) && !in_array($data['type'], $types, true)) {
            self::mismatch();
        }
    }

    /**
     * Reads a small JSON object body for the read-only routes that answer from the database, bounded by $maxBytes, and
     * checks its wire `type`. The legacy session is bootstrapped without schema updates, and it is returned for the caller.
     *
     * @throws \InvalidArgumentException When the body is missing, too large, not an object or of another type.
     * @return array{0: array<string, mixed>, 1: object} the decoded body and the legacy sql session
     */
    public static function readSmallRequest(string $type, int $maxBytes = 1024): array
    {
        \Synth\Lib\RequestBody::limit($maxBytes);
        $body = \Synth\Lib\RequestBody::read();
        if (!is_string($body) || $body === '' || strlen($body) > $maxBytes || !str_starts_with(ltrim($body), '{')) {
            throw new \InvalidArgumentException('body');
        }
        $input = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($input) || ($input['type'] ?? null) !== $type) {
            throw new \InvalidArgumentException('type');
        }
        require_once self::script('lib/runtime_bootstrap.php');
        synthRuntimeBootstrap(self::root(), ['run_db_updates' => false]);
        $db = \Synth\Bootstrap::db() ?? null;
        if (!is_object($db)) {
            throw new \RuntimeException('no database');
        }

        return [$input, $db];
    }

    private static function mismatch(): never
    {
        require_once self::script('lib/request.php');
        synth_reject_json_request(422, 'The event does not belong to this route', ['code' => 'route_type_mismatch']);
    }
}

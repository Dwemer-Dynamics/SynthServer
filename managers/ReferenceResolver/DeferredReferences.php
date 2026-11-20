<?php

// DeferredReferences: the unresolved references a context event carries in its metadata until their actor exists
// (docs/RUNTIME-ACTORS.md, section 4.2). Pure functions over a metadata array: the caller reads and writes the event row, so
// resolution stays inside the registering database transaction and nothing here touches the network or a model.

declare(strict_types=1);

namespace Synth\Managers\ReferenceResolver;

/** Adds, resolves and bounds pending references. */
final class DeferredReferences
{
    public const KEY = 'unresolved_references';
    public const OVERFLOW_KEY = 'unresolved_overflow';
    public const MAX_PER_EVENT = 64;
    public const MAX_PER_PLAYTHROUGH = 4096;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * Adds pending references to an event's metadata. Malformed entries are dropped; past MAX_PER_EVENT the event keeps what it
     * has, records `unresolved_overflow: true` and stores nothing more.
     *
     * @param array<string, mixed> $metadata the event's context metadata
     * @param list<array<string, mixed>> $pending role, reference_type, rid (and runtime_actor_id or plugin), reason, recorded_manifest_id
     * @return array<string, mixed> the updated metadata
     */
    public static function add(array $metadata, array $pending): array
    {
        $current = is_array($metadata[self::KEY] ?? null) ? array_values($metadata[self::KEY]) : [];
        foreach ($pending as $entry) {
            $clean = self::clean($entry);
            if ($clean === null) {
                continue;
            }
            if (count($current) >= self::MAX_PER_EVENT) {
                $metadata[self::OVERFLOW_KEY] = true;
                break;
            }
            $current[] = $clean;
        }
        if ($current !== []) {
            $metadata[self::KEY] = $current;
        }

        return $metadata;
    }

    /**
     * Resolves what can be resolved and removes those entries.
     *
     * @param array<string, mixed> $metadata
     * @param callable(array<string, mixed>): Resolution $resolve resolves one pending entry
     * @return array{metadata: array<string, mixed>, resolved: list<array{entry: array<string, mixed>, character_id: int}>}
     */
    public static function resolve(array $metadata, callable $resolve): array
    {
        $kept = [];
        $resolved = [];
        foreach (is_array($metadata[self::KEY] ?? null) ? $metadata[self::KEY] : [] as $entry) {
            $result = is_array($entry) ? $resolve($entry) : null;
            if ($result !== null && $result->status === Resolution::RESOLVED && $result->characterId !== null) {
                $resolved[] = ['entry' => $entry, 'character_id' => $result->characterId];
            } elseif ($result !== null && $result->status === Resolution::UNRESOLVED) {
                $kept[] = $entry;
            }
        }
        unset($metadata[self::KEY]);
        if ($kept !== []) {
            $metadata[self::KEY] = $kept;
        }

        return ['metadata' => $metadata, 'resolved' => $resolved];
    }

    /** How many references are pending on an event. @param array<string, mixed> $metadata */
    public static function count(array $metadata): int
    {
        return is_array($metadata[self::KEY] ?? null) ? count($metadata[self::KEY]) : 0;
    }

    /**
     * One validated pending entry, or null.
     *
     * @return array<string, mixed>|null
     */
    private static function clean(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }
        $type = $entry['reference_type'] ?? null;
        $rid = is_string($entry['rid'] ?? null) ? strtolower(preg_replace('/^0x/i', '', $entry['rid']) ?? '') : '';
        $role = $entry['role'] ?? null;
        $reason = $entry['reason'] ?? null;
        if (!in_array($type, ReferenceResolver::KINDS, true) || preg_match('/^[0-9a-f]{8}$/D', $rid) !== 1
            || !is_string($role) || preg_match('/^[a-z_]{1,32}$/D', $role) !== 1 || !is_string($reason) || preg_match('/^[a-z_]{1,48}$/D', $reason) !== 1) {
            return null;
        }
        $clean = ['role' => $role, 'reference_type' => $type, 'rid' => $rid, 'reason' => $reason];
        if ($type === 'runtime_actor') {
            $id = is_string($entry['runtime_actor_id'] ?? null) ? strtolower($entry['runtime_actor_id']) : '';
            if (preg_match(self::UUID, $id) !== 1) {
                return null;
            }
            $clean['runtime_actor_id'] = $id;
        } elseif (is_string($entry['plugin'] ?? null) && preg_match('/^[^\/\\\\]{1,251}\.(esm|esp|esl)$/Di', $entry['plugin']) === 1) {
            $clean['plugin'] = strtolower($entry['plugin']);
        }
        if (is_int($entry['recorded_manifest_id'] ?? null)) {
            $clean['recorded_manifest_id'] = $entry['recorded_manifest_id'];
        }

        return $clean;
    }
}

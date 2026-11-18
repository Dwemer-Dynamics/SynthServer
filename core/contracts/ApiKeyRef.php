<?php

// A reference to one API key in one store: a shared key of the CHIM 4 server's api_keys table, or a SYNTH-only key of
// public.core_api_badge. It is the one handle the application passes around; a connector row keeps it as two typed columns.

declare(strict_types=1);

namespace Synth\Core\Contracts;

/** Immutable reference to an API key: the store ('shared' or 'local') and the id within it (a uuid, or an integer). */
final class ApiKeyRef
{
    public const SHARED = 'shared';
    public const LOCAL = 'local';

    /** Stores the parts of a reference that has already been validated. */
    private function __construct(private readonly string $store, private readonly string $id)
    {
    }

    /** A reference to a shared key; the id must be a canonical lower-case uuid. */
    public static function shared(string $uuid): ?self
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) === 1 ? new self(self::SHARED, $uuid) : null;
    }

    /** A reference to a SYNTH-only key; the id must be a positive integer. */
    public static function local(int $id): ?self
    {
        return $id > 0 ? new self(self::LOCAL, (string) $id) : null;
    }

    /** Parses "shared:<uuid>" or "local:<integer>" exactly (no padding, no upper case, no leading zeros); anything else is null. */
    public static function parse(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^shared:([0-9a-f-]{36})$/D', $value, $match) === 1) {
            return self::shared($match[1]);
        }
        if (preg_match('/^local:([1-9][0-9]{0,9})$/D', $value, $match) === 1 && (int) $match[1] <= 2147483647) {
            return self::local((int) $match[1]);
        }
        return null;
    }

    /** The key a connector row names, from its api_key_id (shared) or local_api_key_id (SYNTH-only) column; null when it names none. */
    public static function fromRow(array $row): ?self
    {
        $shared = $row['api_key_id'] ?? null;
        if (is_string($shared) && $shared !== '') {
            return self::shared(strtolower($shared));
        }
        $local = $row['local_api_key_id'] ?? null;
        return $local !== null && $local !== '' && ctype_digit((string) $local) ? self::local((int) $local) : null;
    }

    /** The canonical reference string of $value (a reference string or a reference), or null when it is not one. */
    public static function normalize(mixed $value): ?string
    {
        $ref = $value instanceof self ? $value : self::parse($value);
        return $ref === null ? null : $ref->toString();
    }

    /** The two typed connector columns for a reference (or for none): exactly one of them is set. */
    public static function toColumns(?self $ref): array
    {
        return [
            'api_key_id' => $ref !== null && $ref->store === self::SHARED ? $ref->id : null,
            'local_api_key_id' => $ref !== null && $ref->store === self::LOCAL ? (int) $ref->id : null,
        ];
    }

    /** The key a connector row or data array names: its api_key_id when that already is a reference string, else the typed columns. */
    public static function fromAny(array $row): ?self
    {
        $parsed = self::parse($row['api_key_id'] ?? null);
        return $parsed ?? self::fromRow($row);
    }

    /** A connector row as the application sees it: api_key_id holds the reference string (or null) and local_api_key_id is gone. */
    public static function decorateRow(array $row): array
    {
        if (!array_key_exists('api_key_id', $row) && !array_key_exists('local_api_key_id', $row)) {
            return $row;
        }
        $ref = self::fromAny($row);
        unset($row['local_api_key_id']);
        $row['api_key_id'] = $ref?->toString();
        return $row;
    }

    /** Connector data headed for the database: a reference in api_key_id becomes the two typed columns (data without api_key_id is unchanged). */
    public static function columnsFromData(array $data): array
    {
        if (!array_key_exists('api_key_id', $data) && !array_key_exists('local_api_key_id', $data)) {
            return $data;
        }
        return array_merge($data, self::toColumns(self::fromAny($data)));
    }

    /** "shared:<uuid>" or "local:<integer>". */
    public function toString(): string
    {
        return $this->store . ':' . $this->id;
    }

    /** 'shared' or 'local'. */
    public function store(): string
    {
        return $this->store;
    }

    /** The id within the store: a uuid string, or the decimal digits of an integer. */
    public function id(): string
    {
        return $this->id;
    }

    /** True for a shared key. */
    public function isShared(): bool
    {
        return $this->store === self::SHARED;
    }
}

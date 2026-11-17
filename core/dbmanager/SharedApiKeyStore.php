<?php

namespace Synth\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

// SYNTH's consumer side of the shared `dwemer` database's api_keys table (owned and migrated by the CHIM 4 server's
// shared migrations; see docs/SCHEMA-MIGRATIONS.md, "Shared API keys"). It has its own DBAL connection, never the legacy
// session: the shared store is another database. Connection values come from the same CHIM_SHARED_DB_* variables the CHIM 4
// server reads, with no defaults; when any is missing the store does not exist (fromEnvironment() returns null) and
// SYNTH keeps using its local keys without a query, a log line or a warning.
// Secrets are only ever bound parameters and are never logged. Every database failure becomes a fixed-message
// SharedApiKeyException, because a constraint error's detail can repeat the failing row.
final class SharedApiKeyStore
{
    /** The same advisory key as ChimServer's ApiKeyStore: key creation is serialized across every product. */
    private const CATALOG_LOCK_KEY = 4851020019;
    public const ACTIVE_MAX = 64;
    // Same rule as ChimServer's ApiKeyManager::SECRET_PATTERN and the table's status_shape check.
    private const SECRET_PATTERN = '/^[\x21-\x7E]{1,4096}$/D';
    private const LABEL_PATTERN = '/^[^\x00-\x1F\x7F]{1,64}$/Du';

    /** Provider => fixed origin. Providers whose origin is not fixed (azure, replicate, deepl, ploofy, custom_openai) are not migrated: SYNTH does not know the endpoint. */
    public const FIXED_ORIGINS = [
        'openrouter' => 'https://openrouter.ai:443',
        'openai' => 'https://api.openai.com:443',
        'inworld' => 'https://api.inworld.ai:443',
        'deepgram' => 'https://api.deepgram.com:443',
        'google' => 'https://generativelanguage.googleapis.com:443',
        'elevenlabs' => 'https://api.elevenlabs.io:443',
        'cartesia' => 'https://api.cartesia.ai:443',
        'nano_gpt' => 'https://nano-gpt.com:443',
        'groq' => 'https://api.groq.com:443',
        'atlascloud' => 'https://api.atlascloud.ai:443',
    ];

    /** Lower-cased SYNTH badge label => shared provider. Anything else stays local. */
    private const PROVIDER_BY_LABEL = [
        'openrouter' => 'openrouter', 'openai' => 'openai', 'inworld' => 'inworld', 'deepgram' => 'deepgram',
        'google' => 'google', 'gemini' => 'google', 'elevenlabs' => 'elevenlabs', 'cartesia' => 'cartesia',
        'nano-gpt' => 'nano_gpt', 'nano_gpt' => 'nano_gpt', 'groq' => 'groq', 'atlascloud' => 'atlascloud',
    ];

    private ?Connection $connection = null;
    private ?bool $ready = null;

    private function __construct(private readonly array $settings) {}

    /** The store configured by CHIM_SHARED_DB_*, or null when it is not (completely and validly) configured. */
    public static function fromEnvironment(): ?self
    {
        $settings = [];
        foreach (['HOST' => 'host', 'PORT' => 'port', 'NAME' => 'dbname', 'USER' => 'user'] as $name => $key) {
            $value = getenv('CHIM_SHARED_DB_'.$name, true);
            if (!is_string($value) || $value === '') return null;
            $settings[$key] = $value;
        }
        if (preg_match('/^[1-9][0-9]{0,4}$/D', $settings['port']) !== 1 || (int) $settings['port'] > 65535) return null;
        $password = getenv('CHIM_SHARED_DB_PASSWORD', true);
        $settings['password'] = is_string($password) ? $password : '';
        return new self($settings);
    }

    /** A store for explicit settings (tools and harnesses). */
    public static function forSettings(#[\SensitiveParameter] array $settings): self
    {
        return new self($settings);
    }

    public static function providerForLabel(string $label): ?string
    {
        return self::PROVIDER_BY_LABEL[strtolower(trim($label))] ?? null;
    }

    public static function secretValid(mixed $secret): bool
    {
        return is_string($secret) && preg_match(self::SECRET_PATTERN, $secret) === 1;
    }

    /** True when the shared database answers and holds the api_keys table with the columns SYNTH uses. Never throws. */
    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $found = $this->db()->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public'
                AND table_name = 'api_keys' AND column_name IN ('key_id','provider','label','origin','status','secret','revision','created_at')");
            return $this->ready = ((int) $found === 8);
        } catch (\Throwable) {
            return $this->ready = false;
        }
    }

    /**
     * Resolves keys in one query. Returns key_id => ['status' => 'active'|'deleted', 'secret' => string|null]; an id the
     * store has never held is absent. A legacy sealed row (no plain-text secret) reports a null secret.
     *
     * @param list<string> $keyIds
     * @return array<string, array{status:string, secret:?string}>
     */
    public function resolve(array $keyIds): array
    {
        $keyIds = array_values(array_unique(array_filter($keyIds, self::uuidValid(...))));
        if ($keyIds === []) return [];
        $out = [];
        try {
            $rows = $this->db()->fetchAllAssociative('SELECT key_id::text AS key_id, status, secret FROM public.api_keys WHERE key_id = ANY(?::uuid[])',
                ['{'.implode(',', $keyIds).'}']);
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lookup failed');
        }
        foreach ($rows as $row) {
            $out[$row['key_id']] = ['status' => $row['status'], 'secret' => $row['secret'] === null ? null : (string) $row['secret']];
        }
        return $out;
    }

    /**
     * Adopts the oldest active plain-text key with this provider and secret, or creates one, under the catalog lock so
     * a concurrent product cannot add a duplicate or exceed the cap. Returns ['outcome' => 'adopted'|'created'|'limit',
     * 'key_id' => ?string].
     */
    public function adoptOrCreate(string $provider, string $label, #[\SensitiveParameter] string $secret): array
    {
        $origin = self::FIXED_ORIGINS[$provider] ?? null;
        if ($origin === null || !self::secretValid($secret)) throw new SharedApiKeyException('shared key value refused');
        if (preg_match(self::LABEL_PATTERN, $label) !== 1 || $label !== trim($label)) $label = ucfirst(str_replace('_', '-', $provider));
        try {
            return $this->db()->transactional(function (Connection $db) use ($provider, $label, $origin, $secret): array {
                $db->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::CATALOG_LOCK_KEY]);
                $existing = $db->fetchOne("SELECT key_id::text FROM public.api_keys WHERE status = 'active' AND provider = ? AND secret = ?
                    ORDER BY created_at, key_id LIMIT 1", [$provider, $secret]);
                if (is_string($existing)) return ['outcome' => 'adopted', 'key_id' => $existing];
                if ((int) $db->fetchOne("SELECT count(*) FROM public.api_keys WHERE status = 'active'") >= self::ACTIVE_MAX) {
                    return ['outcome' => 'limit', 'key_id' => null];
                }
                $keyId = self::newKeyId();
                $db->executeStatement('INSERT INTO public.api_keys (key_id, provider, label, origin, secret) VALUES (?::uuid, ?, ?, ?, ?)',
                    [$keyId, $provider, $label, $origin, $secret]);
                return ['outcome' => 'created', 'key_id' => $keyId];
            });
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key write failed');
        }
    }

    /** Read-only: the key_id adoptOrCreate() would adopt for this provider and secret, or null. */
    public function findActive(string $provider, #[\SensitiveParameter] string $secret): ?string
    {
        try {
            $id = $this->db()->fetchOne("SELECT key_id::text FROM public.api_keys WHERE status = 'active' AND provider = ? AND secret = ?
                ORDER BY created_at, key_id LIMIT 1", [$provider, $secret]);
            return is_string($id) ? $id : null;
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lookup failed');
        }
    }

    /** Read-only: the number of active keys (the cap is ACTIVE_MAX). */
    public function activeCount(): int
    {
        try {
            return (int) $this->db()->fetchOne("SELECT count(*) FROM public.api_keys WHERE status = 'active'");
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lookup failed');
        }
    }

    /** Names of the shared migration versions recorded in dwemer_shared_migration_versions, or null when it cannot be read. */
    public function recordedMigrations(): ?array
    {
        try {
            return array_map('strval', $this->db()->fetchFirstColumn('SELECT version FROM public.dwemer_shared_migration_versions ORDER BY version'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Replaces the secret of an active key (also clearing any legacy sealed value), revision + 1. True when a row changed. */
    public function replaceSecret(string $keyId, #[\SensitiveParameter] string $secret): bool
    {
        if (!self::uuidValid($keyId) || !self::secretValid($secret)) throw new SharedApiKeyException('shared key value refused');
        try {
            return 1 === $this->db()->executeStatement("UPDATE public.api_keys SET secret = ?, nonce = NULL, ciphertext = NULL,
                revision = revision + 1, updated_at = clock_timestamp() WHERE key_id = ?::uuid AND status = 'active'", [$secret, $keyId]);
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key write failed');
        }
    }

    /**
     * ChimServer's bind protocol: holds FOR SHARE on an active key while $work commits a SYNTH reference to it, so a
     * concurrent delete either waits and then sees the reference or wins and the work never runs. $work must be
     * database-only, must not use this store and must commit before it returns. Returns 'locked' (with $work's result),
     * 'missing' or 'revoked'.
     *
     * @return array{outcome:string, result:mixed}
     */
    public function withShareLock(string $keyId, callable $work): array
    {
        if (!self::uuidValid($keyId)) return ['outcome' => 'missing', 'result' => null];
        try {
            return $this->db()->transactional(function (Connection $db) use ($keyId, $work): array {
                $status = $db->fetchOne('SELECT status FROM public.api_keys WHERE key_id = ?::uuid FOR SHARE', [$keyId]);
                if ($status === false) return ['outcome' => 'missing', 'result' => null];
                if ($status !== 'active') return ['outcome' => 'revoked', 'result' => null];
                return ['outcome' => 'locked', 'result' => $work()];
            });
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lock failed');
        }
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    private function db(): Connection
    {
        if ($this->connection !== null) return $this->connection;
        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pgsql',
                'host' => $this->settings['host'],
                'port' => (int) $this->settings['port'],
                'dbname' => $this->settings['dbname'],
                'user' => $this->settings['user'],
                'password' => $this->settings['password'] ?? '',
                'application_name' => 'synthserver-shared',
            ]);
            $connection->executeStatement('SET statement_timeout = 5000');
            $connection->executeStatement('SET lock_timeout = 1000');
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared database is unavailable');
        }
        return $this->connection = $connection;
    }

    private static function uuidValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private static function newKeyId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

/** Fixed, secret-free message; the driver's own message is never kept. */
final class SharedApiKeyException extends \RuntimeException {}

<?php

namespace Synth\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

// SYNTH's only key store: the shared `dwemer` database's api_keys table, owned and migrated by the CHIM 4 server's shared
// migrations (docs/SCHEMA-MIGRATIONS.md, "Shared API keys"). It has its own DBAL connection, never the legacy session: the
// store is another database. Connection values come from the same CHIM_SHARED_DB_* variables the CHIM 4 server reads, with
// no defaults. When any is missing, or the store cannot answer, SYNTH has no keys (see unavailableReason()): nothing falls
// back to another place, nothing is logged and PHP emits no warning.
// Secrets are only ever bound parameters and are never logged. Every database failure becomes a fixed-message
// SharedApiKeyException, because a constraint error's detail can repeat the failing row. The API follows ChimServer's
// ApiKeyStore: tombstones instead of deletes, a revision on every change, the catalog advisory lock and the 64 active key cap,
// and FOR UPDATE / FOR SHARE row locks around product reference checks (lock order: shared row first, then SYNTH rows).
final class SharedApiKeyStore
{
    /** The same advisory key as ChimServer's ApiKeyStore: key creation is serialized across every product. */
    private const CATALOG_LOCK_KEY = 4851020019;
    public const ACTIVE_MAX = 64;
    // Same rule as ChimServer's ApiKeyManager::SECRET_PATTERN and the table's status_shape check.
    private const SECRET_PATTERN = '/^[\x21-\x7E]{1,4096}$/D';
    private const LABEL_PATTERN = '/^[^\x00-\x1F\x7F]{1,64}$/Du';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /**
     * Provider => fixed origin, or the origin SYNTH records when the provider's endpoint varies (Azure's is regional) and
     * nothing else names one. SYNTH never sends a key by its stored origin; it only has to satisfy the table's checks.
     */
    public const ORIGINS = [
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
        'replicate' => 'https://api.replicate.com:443',
        'azure' => 'https://api.cognitive.microsoft.com:443',
        'deepl' => 'https://api.deepl.com:443',
    ];

    /** Lower-cased label => provider. Any other label is a custom key (provider custom_openai, origin entered by the operator). */
    private const PROVIDER_BY_LABEL = [
        'openrouter' => 'openrouter', 'openai' => 'openai', 'inworld' => 'inworld', 'deepgram' => 'deepgram',
        'google' => 'google', 'gemini' => 'google', 'elevenlabs' => 'elevenlabs', 'cartesia' => 'cartesia',
        'nano-gpt' => 'nano_gpt', 'nano_gpt' => 'nano_gpt', 'groq' => 'groq', 'atlascloud' => 'atlascloud',
        'replicate' => 'replicate', 'azure' => 'azure', 'deepl' => 'deepl',
    ];

    private const COLUMNS = 'k.key_id::text AS id, k.provider, k.label, k.origin, k.status, k.revision';

    private ?Connection $connection = null;
    private bool $checked = false;
    private ?string $reason = null;

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

    /** The provider a label names, or null for a custom label. */
    public static function providerForLabel(string $label): ?string
    {
        return self::PROVIDER_BY_LABEL[strtolower(trim($label))] ?? null;
    }

    public static function secretValid(mixed $secret): bool
    {
        return is_string($secret) && preg_match(self::SECRET_PATTERN, $secret) === 1;
    }

    public static function labelValid(mixed $label): bool
    {
        return is_string($label) && preg_match(self::LABEL_PATTERN, $label) === 1 && $label === trim($label);
    }

    public static function idValid(mixed $id): bool
    {
        return is_string($id) && preg_match(self::UUID_PATTERN, $id) === 1;
    }

    /** The canonical (lower-case) key id, or null when $value is not one. Used wherever an id is accepted from a request or a row. */
    public static function normalizeId(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtolower(trim($value));
        return self::idValid($value) ? $value : null;
    }

    /**
     * The canonical "scheme://host:port" of an origin, or null: https, or http to a loopback host; no user info, path, query or
     * fragment (ChimServer's ApiKeyManager::canonicalOrigin rules).
     */
    public static function canonicalOrigin(mixed $origin): ?string
    {
        if (!is_string($origin) || preg_match('/[\x00-\x20\x7F?#@]/', $origin) === 1) return null;
        $parts = parse_url($origin);
        if (!is_array($parts) || isset($parts['path']) || isset($parts['user']) || !isset($parts['scheme'], $parts['host'])) return null;
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) return null;
        if ($scheme !== 'https' && $scheme !== 'http') return null;
        if (preg_match('/^([a-z0-9-]+(\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])$/D', $host) !== 1 || $port < 1 || $port > 65535) return null;
        return $scheme.'://'.$host.':'.$port;
    }

    /** True when the shared database answers and holds the api_keys table with the columns SYNTH uses. Never throws. */
    public function ready(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Why the store cannot be used, as one of 'unreachable' or 'not_migrated', or null when it can. Fixed codes only: a
     * driver message can carry connection details.
     */
    public function unavailableReason(): ?string
    {
        if ($this->checked) return $this->reason;
        $this->checked = true;
        try {
            $found = $this->db()->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'public'
                AND table_name = 'api_keys' AND column_name IN ('key_id','provider','label','origin','status','secret','revision','created_at')");
        } catch (\Throwable) {
            return $this->reason = 'unreachable';
        }
        return $this->reason = (int) $found === 8 ? null : 'not_migrated';
    }

    /**
     * Active keys, oldest first (created_at, key_id): id, provider, label, origin, revision and, with $withSecrets, secret
     * ('' for a sealed legacy row, which SYNTH cannot open).
     *
     * @return list<array<string,string>>
     */
    public function listActive(bool $withSecrets = false): array
    {
        try {
            return array_map(self::texts(...), $this->db()->fetchAllAssociative('SELECT '.self::COLUMNS.($withSecrets ? ", coalesce(k.secret, '') AS secret" : '')
                ." FROM public.api_keys AS k WHERE k.status = 'active' ORDER BY k.created_at, k.key_id LIMIT ?", [self::ACTIVE_MAX]));
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lookup failed');
        }
    }

    /**
     * One key by id, active or deleted: id, provider, label, origin, status, revision, secret ('' when sealed or deleted); null
     * when the id was never stored.
     *
     * @return ?array<string,string>
     */
    public function find(string $id): ?array
    {
        if (!self::idValid($id)) return null;
        try {
            $row = $this->db()->fetchAssociative('SELECT '.self::COLUMNS.", coalesce(k.secret, '') AS secret FROM public.api_keys AS k WHERE k.key_id = ?::uuid", [$id]);
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lookup failed');
        }
        return $row === false ? null : self::texts($row);
    }

    /**
     * Creates an active key with revision 1 under the catalog lock, or reports 'limit' when 64 keys are active. Returns
     * ['outcome' => 'created'|'limit', 'id' => ?string]. The provider's origin must satisfy the table's checks.
     */
    public function create(string $provider, string $label, string $origin, #[\SensitiveParameter] string $secret): array
    {
        if (!self::labelValid($label) || !self::secretValid($secret) || self::canonicalOrigin($origin) !== $origin) {
            throw new SharedApiKeyException('shared key value refused');
        }
        try {
            return $this->db()->transactional(function (Connection $db) use ($provider, $label, $origin, $secret): array {
                $db->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::CATALOG_LOCK_KEY]);
                if ((int) $db->fetchOne("SELECT count(*) FROM public.api_keys WHERE status = 'active'") >= self::ACTIVE_MAX) {
                    return ['outcome' => 'limit', 'id' => null];
                }
                $id = self::newKeyId();
                $db->executeStatement('INSERT INTO public.api_keys (key_id, provider, label, origin, secret) VALUES (?::uuid, ?, ?, ?, ?)',
                    [$id, $provider, $label, $origin, $secret]);
                return ['outcome' => 'created', 'id' => $id];
            });
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key write failed');
        }
    }

    /**
     * Edits an active key's label and/or secret (a new secret also clears any legacy sealed value), revision + 1, under the row
     * lock. Provider and origin never change. Returns 'updated', 'missing' or 'revoked'.
     */
    public function update(string $id, ?string $label, #[\SensitiveParameter] ?string $secret): string
    {
        if (!self::idValid($id) || ($label !== null && !self::labelValid($label)) || ($secret !== null && !self::secretValid($secret))) {
            throw new SharedApiKeyException('shared key value refused');
        }
        try {
            return $this->db()->transactional(function (Connection $db) use ($id, $label, $secret): string {
                $status = $db->fetchOne('SELECT status FROM public.api_keys WHERE key_id = ?::uuid FOR UPDATE', [$id]);
                if ($status === false) return 'missing';
                if ($status !== 'active') return 'revoked';
                $db->executeStatement('UPDATE public.api_keys SET label = COALESCE(?, label), secret = COALESCE(?::text, secret),
                    nonce = CASE WHEN ?::text IS NULL THEN nonce END, ciphertext = CASE WHEN ?::text IS NULL THEN ciphertext END,
                    revision = revision + 1, updated_at = clock_timestamp() WHERE key_id = ?::uuid', [$label, $secret, $secret, $secret, $id]);
                return 'updated';
            });
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key write failed');
        }
    }

    /**
     * Deletes an active key by wiping its secret and keeping a tombstone (revision + 1), after $inUse() returns exactly false
     * while the row is locked FOR UPDATE. $inUse must only read SYNTH's own database and must not use this store. Returns
     * 'deleted', 'in_use', 'missing' or 'revoked'.
     */
    public function tombstone(string $id, callable $inUse): string
    {
        if (!self::idValid($id)) return 'missing';
        try {
            return $this->db()->transactional(function (Connection $db) use ($id, $inUse): string {
                $status = $db->fetchOne('SELECT status FROM public.api_keys WHERE key_id = ?::uuid FOR UPDATE', [$id]);
                if ($status === false) return 'missing';
                if ($status !== 'active') return 'revoked';
                $used = $inUse();
                if ($used !== false) return 'in_use';
                $db->executeStatement("UPDATE public.api_keys SET status = 'deleted', secret = NULL, nonce = NULL, ciphertext = NULL,
                    revision = revision + 1, updated_at = clock_timestamp(), deleted_at = clock_timestamp() WHERE key_id = ?::uuid", [$id]);
                return 'deleted';
            });
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key write failed');
        }
    }

    /**
     * ChimServer's bind protocol: holds FOR SHARE on an active key while $work commits a SYNTH reference to it, so a concurrent
     * delete either waits and then sees the reference or wins and the work never runs. $work must be database-only, must
     * not use this store and must commit before it returns. Returns 'locked' (with $work's result), 'missing' or 'revoked'.
     *
     * @return array{outcome:string, result:mixed}
     */
    public function withShareLock(string $id, callable $work): array
    {
        if (!self::idValid($id)) return ['outcome' => 'missing', 'result' => null];
        try {
            return $this->db()->transactional(function (Connection $db) use ($id, $work): array {
                $status = $db->fetchOne('SELECT status FROM public.api_keys WHERE key_id = ?::uuid FOR SHARE', [$id]);
                if ($status === false) return ['outcome' => 'missing', 'result' => null];
                if ($status !== 'active') return ['outcome' => 'revoked', 'result' => null];
                return ['outcome' => 'locked', 'result' => $work()];
            });
        } catch (\Throwable) {
            throw new SharedApiKeyException('shared key lock failed');
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

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    private function db(): Connection
    {
        if ($this->connection !== null) return $this->connection;
        // Production dependencies come only from the root composer.lock (as in EntityManagerFactory); a missing tree reads as unavailable.
        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        if (!is_file($autoload)) throw new SharedApiKeyException('shared database is unavailable');
        require_once $autoload;
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

    private static function texts(array $row): array
    {
        return array_map(static fn($value) => $value === null ? '' : strval($value), $row);
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

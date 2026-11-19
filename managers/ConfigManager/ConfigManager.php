<?php

declare(strict_types=1);

namespace Synth\Managers\ConfigManager;

use InvalidArgumentException;
use Synth\Core\DbManager\Dao\ConfigSettingDao;
use Synth\Core\Doctrine\EntityManagerFactory;

require_once dirname(__DIR__, 2) . '/core/dbmanager/dao/ConfigSettingDao.php';
require_once dirname(__DIR__, 2) . '/core/dbmanager/EntityManagerFactory.php';
require_once dirname(__DIR__, 2) . '/lib/settings.php';

/**
 * The one door to stored settings (public.core_config_setting), over ConfigSettingDao.
 *
 * Settings live in five storage scopes: general (the dashboard's server-wide settings), conf (runtime options the game
 * and services write), player, narrator and meta. Any caller may read or write a scope through get/set/delete. The edit
 * API and the settings pages instead go through setEditable(), which checks the key against the declared definitions
 * (conf/conf_schema.json) and normalises the value, so an unknown key, a read-only key, a secret or a bad value is refused.
 *
 * Overrides: a profile or an NPC may override a general setting. explain() resolves Global -> profile -> NPC by presence
 * of the key and says where the winning value came from; keys that are global-only are never overridden.
 * See docs/CONFIG-SCOPES.md.
 */
final class ConfigManager
{
    /** Where a value is stored. */
    public const SCOPES = ['general', 'conf', 'player', 'narrator', 'meta'];

    /** The only storage scope the edit API and the settings pages may change by definition. */
    public const EDITABLE_SCOPE = 'general';

    /** Schema types that are never exposed or written through the edit path (secrets and unmanaged lists). */
    private const NON_EDITABLE_TYPES = ['apikey', 'ormodellist'];

    private static ?self $shared = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    public function __construct(private readonly ConfigSettingDao $dao)
    {
    }

    /** A manager over one legacy session, for code that owns it (tests, setup tools). */
    public static function forSession(object $db): self
    {
        return new self(ConfigSettingDao::using(EntityManagerFactory::forSharedSession($db)));
    }

    /** The process-wide manager: the booted DAO when Database::boot() ran, else the legacy $GLOBALS['db'] session. */
    public static function shared(): self
    {
        if (self::$shared !== null) {
            return self::$shared;
        }
        try {
            return self::$shared = new self(ConfigSettingDao::shared());
        } catch (\LogicException $notBooted) {
            $db = $GLOBALS['db'] ?? null;
            if (!is_object($db)) {
                throw new \RuntimeException('No database session is available for settings.', 0, $notBooted);
            }

            return self::$shared = self::forSession($db);
        }
    }

    /** Forgets the process-wide manager. For tests that change sessions. */
    public static function reset(): void
    {
        self::$shared = null;
    }

    /** Raw stored text of a setting, or $default when it has no row. */
    public function get(string $scope, string $key, ?string $default = null): ?string
    {
        $this->requireScope($scope);

        return $this->dao->value($scope, $key) ?? $default;
    }

    /** Stored value read as a boolean ("1", "true", "yes", "on" are true), or $default when it has no row. */
    public function getBool(string $scope, string $key, bool $default = false): bool
    {
        $raw = $this->get($scope, $key);

        return $raw === null ? $default : in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Stored value read as an integer, or $default when it has no row. */
    public function getInt(string $scope, string $key, int $default = 0): int
    {
        $raw = $this->get($scope, $key);

        return $raw === null ? $default : intval($raw);
    }

    /** Stored value read as a float, or $default when it has no row. */
    public function getFloat(string $scope, string $key, float $default = 0.0): float
    {
        $raw = $this->get($scope, $key);

        return $raw === null ? $default : floatval($raw);
    }

    /**
     * Raw stored values of the named keys of one scope that have a row, in one read.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    public function getMany(string $scope, array $keys): array
    {
        $this->requireScope($scope);

        return $this->dao->values($scope, $keys);
    }

    /**
     * Every stored row of one scope, ordered by key.
     *
     * @return list<array{id: string, value: string, description: string, updated_at: ?string}>
     */
    public function all(string $scope): array
    {
        $this->requireScope($scope);

        return $this->dao->all($scope);
    }

    /** Stores a setting in any scope; arrays and booleans are stored as JSON text and true/false. False when the write fails. */
    public function set(string $scope, string $key, mixed $value, ?string $description = null): bool
    {
        $this->requireScope($scope);
        $key = trim($key);
        if ($key === '') {
            return false;
        }

        return $this->dao->upsert($scope, $key, synthSettingsStringifyValue($value), $description);
    }

    /** Removes a setting so it reads as its default again. */
    public function delete(string $scope, string $key): bool
    {
        $this->requireScope($scope);

        return $this->dao->delete($scope, trim($key));
    }

    /**
     * The settings the edit API and the pages may change, keyed by their general-scope id.
     *
     * Each entry: key, type, default, description, readonly, global_only, options (for selects).
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $globalOnly = array_flip(synthGetGlobalOnlySettingIds());
        $definitions = [];
        foreach (synthFlattenConfSchema() as $id => $schema) {
            $type = strtolower(strval($schema['type'] ?? 'string'));
            if (in_array($type, self::NON_EDITABLE_TYPES, true) || str_starts_with($id, 'SYNTH_DB_') || $id === 'DBDRIVER') {
                continue;
            }
            $definitions[$id] = [
                'key' => $id,
                'type' => $type,
                'default' => $schema['default'] ?? null,
                'description' => strval($schema['description'] ?? ''),
                'readonly' => !empty($schema['readonly']),
                'global_only' => isset($globalOnly[$id]) || strval($schema['scope'] ?? '') === 'global',
                'options' => is_array($schema['values'] ?? null) ? array_values($schema['values']) : null,
            ];
        }

        return $this->definitions = $definitions;
    }

    /** One definition, or null when the key is not a declared, editable general setting. */
    public function definition(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * Validates and stores a declared general setting. The key, its type and (for a select) its options come from the
     * definitions; the caller's scope and key are never trusted.
     *
     * @throws InvalidArgumentException With a message safe to show the client.
     * @return mixed The normalised value that was stored.
     */
    public function setEditable(string $key, mixed $value): mixed
    {
        $definition = $this->definition($key);
        if ($definition === null) {
            throw new InvalidArgumentException('Unknown setting.');
        }
        if ($definition['readonly']) {
            throw new InvalidArgumentException('This setting is read-only.');
        }
        $normalised = $this->normaliseValue($definition, $value);
        if (!$this->set(self::EDITABLE_SCOPE, $key, $normalised, $definition['description'] === '' ? null : $definition['description'])) {
            throw new \RuntimeException('The setting could not be stored.');
        }

        return $normalised;
    }

    /** The effective global value of a declared setting: the stored row, else the schema default. */
    public function effective(string $key): mixed
    {
        $definition = $this->definition($key);
        $raw = $this->dao->value(self::EDITABLE_SCOPE, $key);
        if ($raw === null) {
            return $definition['default'] ?? null;
        }

        return synthSettingsNormalizeScalar($raw, ['type' => $definition['type'] ?? 'string']);
    }

    /**
     * Where a setting's value comes from for a profile and an NPC, narrowest first: NPC, profile, global, default.
     * An override counts by presence, so false, 0 and '' are real values; a null is "inherit". Global-only and
     * unknown keys ignore overrides, and the ignored scopes are listed.
     *
     * @param array<string, mixed>|null $profileSettings
     * @param array<string, mixed>|null $npcSettings
     * @return array{key: string, value: mixed, source: string, ignored: list<string>}
     */
    public function explain(string $key, ?array $profileSettings = null, ?array $npcSettings = null): array
    {
        $definition = $this->definition($key);
        $ignored = [];
        foreach (['npc' => $npcSettings, 'profile' => $profileSettings] as $source => $settings) {
            if (!is_array($settings) || !array_key_exists($key, $settings) || $settings[$key] === null) {
                continue;
            }
            if ($definition === null || $definition['global_only']) {
                $ignored[] = $source;
                continue;
            }

            return ['key' => $key, 'value' => $settings[$key], 'source' => $source, 'ignored' => $ignored];
        }
        $stored = $this->dao->value(self::EDITABLE_SCOPE, $key) !== null;

        return ['key' => $key, 'value' => $this->effective($key), 'source' => $stored ? 'global' : 'default', 'ignored' => $ignored];
    }

    /**
     * Turns a client value into what the definition stores.
     *
     * @param array<string, mixed> $definition
     */
    private function normaliseValue(array $definition, mixed $value): mixed
    {
        $type = $definition['type'];
        if ($type === 'boolean') {
            if (is_bool($value)) {
                return $value;
            }
            if (in_array($value, ['true', 'false'], true)) {
                return $value === 'true';
            }
            throw new InvalidArgumentException('Expected true or false.');
        }
        if ($type === 'integer' || str_starts_with($type, 'foreign:')) {
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,15}$/', trim($value)) === 1)) {
                return intval($value);
            }
            throw new InvalidArgumentException('Expected a whole number.');
        }
        if ($type === 'number') {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
                return floatval($value);
            }
            throw new InvalidArgumentException('Expected a number.');
        }
        if ($type === 'selectmultiple') {
            if (!is_array($value) || count($value) > 500) {
                throw new InvalidArgumentException('Expected a list.');
            }
            $items = array_map(static fn($item): string => is_scalar($item) ? strval($item) : '', array_values($value));
            if (in_array('', $items, true) || (is_array($definition['options']) && array_diff($items, array_map('strval', $definition['options'])) !== [])) {
                throw new InvalidArgumentException('The list holds a value that is not allowed.');
            }

            return $items;
        }
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Expected text.');
        }
        $text = strval($value ?? '');
        if (strlen($text) > 65536 || str_contains($text, "\0")) {
            throw new InvalidArgumentException('The text is too long or holds control characters.');
        }
        if ($type === 'select' && is_array($definition['options']) && !in_array($text, array_map('strval', $definition['options']), true)) {
            throw new InvalidArgumentException('Not one of the allowed options.');
        }
        if ($type === 'url' && $text !== '' && preg_match('#^https?://#i', $text) !== 1) {
            throw new InvalidArgumentException('Expected an http or https URL.');
        }

        return $text;
    }

    /** @throws InvalidArgumentException When the scope is not one of SCOPES. */
    private function requireScope(string $scope): void
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown settings scope.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

// Validates a decoded extension.json against the small schema below and reports every problem at once.
// Unknown fields are refused so a typo never silently disables a rule.
final class ManifestValidator
{
    private const REQUIRED = ['id', 'name', 'version', 'api', 'entry', 'autoload'];
    private const OPTIONAL = ['description', 'authors', 'license', 'homepage', 'hooks', 'settings'];
    private const SETTING_TYPES = ['string', 'integer', 'boolean', 'enum'];
    private const SETTING_KEYS = ['type', 'default', 'label', 'description', 'options'];
    public const NAMESPACE_ROOT = 'Synth\\Extensions\\';

    /** @return list<string> problems; empty when the manifest is valid. $folder is the extension's directory name. */
    public static function validate(mixed $manifest, string $folder): array
    {
        if (!is_array($manifest) || ($manifest !== [] && array_is_list($manifest))) return ['manifest must be a JSON object'];
        $errors = [];
        foreach (array_diff(array_keys($manifest), self::REQUIRED, self::OPTIONAL) as $key) $errors[] = "unknown field: {$key}";
        foreach (self::REQUIRED as $key) if (!array_key_exists($key, $manifest)) $errors[] = "missing field: {$key}";

        $id = $manifest['id'] ?? null;
        if (array_key_exists('id', $manifest)) {
            if (!is_string($id) || strlen($id) > 64 || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D', $id) !== 1) {
                $errors[] = 'id must be lowercase words joined by hyphens, at most 64 characters';
            } elseif ($id !== $folder) $errors[] = "id must equal the folder name ({$folder})";
        }
        if (array_key_exists('name', $manifest) && (!is_string($manifest['name']) || trim($manifest['name']) === '' || strlen($manifest['name']) > 100)) {
            $errors[] = 'name must be 1-100 characters';
        }
        if (array_key_exists('version', $manifest) && (!is_string($manifest['version'])
            || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D', $manifest['version']) !== 1)) {
            $errors[] = 'version must be SemVer such as 1.2.0';
        }
        if (array_key_exists('api', $manifest) && !self::apiSupported($manifest['api'])) {
            $errors[] = 'api must be a caret range this server supports, such as ^'.Hooks::API_VERSION;
        }
        foreach (['description', 'license', 'homepage'] as $key) {
            if (array_key_exists($key, $manifest) && !is_string($manifest[$key])) $errors[] = "{$key} must be a string";
        }
        if (array_key_exists('authors', $manifest)) {
            $authors = $manifest['authors'];
            if (!is_array($authors) || !array_is_list($authors)) $errors[] = 'authors must be a list of {"name": ...}';
            else foreach ($authors as $author) {
                if (!is_array($author) || !is_string($author['name'] ?? null)) { $errors[] = 'authors must be a list of {"name": ...}'; break; }
            }
        }
        if (array_key_exists('entry', $manifest)) $errors = [...$errors, ...self::entryErrors($manifest)];
        if (array_key_exists('hooks', $manifest)) {
            $hooks = $manifest['hooks'];
            if (!is_array($hooks) || !array_is_list($hooks)) $errors[] = 'hooks must be a list of hook names';
            else foreach ($hooks as $hook) {
                if (!is_string($hook) || !in_array($hook, Hooks::all(), true)) {
                    $errors[] = 'unknown hook: '.(is_string($hook) ? $hook : gettype($hook));
                }
            }
        }
        if (array_key_exists('settings', $manifest)) $errors = [...$errors, ...self::settingsErrors($manifest['settings'])];
        return $errors;
    }

    // Does a stored value fit a declared setting?
    public static function settingValueValid(array $definition, mixed $value): bool
    {
        return match ($definition['type'] ?? null) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'enum' => is_string($value) && in_array($value, $definition['options'] ?? [], true),
            default => false,
        };
    }

    private static function apiSupported(mixed $range): bool
    {
        if (!is_string($range) || preg_match('/^\^(\d+)\.(\d+)$/D', $range, $match) !== 1) return false;
        [$major, $minor] = array_map('intval', explode('.', Hooks::API_VERSION));
        return intval($match[1]) === $major && intval($match[2]) <= $minor;
    }

    private static function entryErrors(array $manifest): array
    {
        $errors = [];
        $entry = $manifest['entry'];
        $autoload = $manifest['autoload'] ?? null;
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
        if (!is_string($entry) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $entry) !== 1) {
            $errors[] = 'entry must be a fully qualified class name';
        }
        if (array_key_exists('autoload', $manifest)) {
            if (!is_array($autoload) || array_diff(array_keys($autoload), ['psr-4']) !== [] || !is_array($psr4) || $psr4 === []) {
                $errors[] = 'autoload must be {"psr-4": {"<prefix>\\\\": "<folder>/"}}';
            } else foreach ($psr4 as $prefix => $folder) {
                if (!is_string($prefix) || !str_starts_with($prefix, self::NAMESPACE_ROOT) || !str_ends_with($prefix, '\\')) {
                    $errors[] = 'autoload prefix must be inside '.self::NAMESPACE_ROOT.' and end with a backslash: '.$prefix;
                }
                if (!is_string($folder) || $folder === '' || str_starts_with($folder, '/') || str_contains($folder, '..')
                    || str_contains($folder, '\\') || preg_match('/^[A-Za-z]:/', $folder) === 1) {
                    $errors[] = 'autoload folder must be a relative path inside the extension';
                }
            }
            if (is_string($entry) && is_array($psr4) && $errors === []
                && array_filter(array_keys($psr4), static fn($prefix) => str_starts_with($entry, strval($prefix))) === []) {
                $errors[] = 'entry must be inside one of the autoload namespaces';
            }
        }
        return $errors;
    }

    private static function settingsErrors(mixed $settings): array
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) return ['settings must be an object'];
        $errors = [];
        foreach ($settings as $name => $definition) {
            $name = strval($name);
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) { $errors[] = "setting name must be snake_case: {$name}"; continue; }
            if (!is_array($definition) || !in_array($definition['type'] ?? null, self::SETTING_TYPES, true)) {
                $errors[] = "setting {$name}: type must be one of ".implode(', ', self::SETTING_TYPES); continue;
            }
            foreach (array_diff(array_keys($definition), self::SETTING_KEYS) as $key) $errors[] = "setting {$name}: unknown field {$key}";
            if ($definition['type'] === 'enum' && (!is_array($definition['options'] ?? null) || $definition['options'] === []
                || array_filter($definition['options'], 'is_string') !== $definition['options'])) {
                $errors[] = "setting {$name}: enum needs a non-empty list of string options"; continue;
            }
            if (!array_key_exists('default', $definition) || !self::settingValueValid($definition, $definition['default'])) {
                $errors[] = "setting {$name}: default is missing or does not match its type";
            }
        }
        return $errors;
    }
}

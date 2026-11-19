<?php

declare(strict_types=1);

namespace Synth\Managers\ConfigManager;

/**
 * Reads config/config.inc.php, the small array that holds the database connection and the few values needed before the
 * database can be read (chevo's config/config.inc.tmpl.php shape), and names them as the legacy configuration does.
 *
 * Everything else is a stored setting (ConfigManager). An installation without config.inc.php keeps using
 * conf/conf.sample.php and conf/conf.php, which stay valid as the compatibility path: legacyVariables() only adds the
 * connection values, and conf.php wins over nothing here. The file is optional and never required.
 */
final class ConfigFile
{
    /** The file's keys and the legacy variable each one sets. */
    public const LEGACY_NAMES = [
        'host' => 'SYNTH_DB_HOST',
        'port' => 'SYNTH_DB_PORT',
        'database' => 'SYNTH_DB_NAME',
        'username' => 'SYNTH_DB_USER',
        'password' => 'SYNTH_DB_PASSWORD',
        'connect_timeout' => 'SYNTH_DB_CONNECT_TIMEOUT',
        'game_id' => 'SYNTH_GAME_ID',
        'allowed_hosts' => 'SYNTH_ALLOWED_HOSTS',
    ];

    /**
     * The array config.inc.php returns, limited to the known keys; an empty array when the file is absent or not an array.
     *
     * @return array<string, scalar>
     */
    public static function read(string $enginePath): array
    {
        $file = rtrim($enginePath, '/' . chr(92)) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.inc.php';
        if (!is_file($file)) {
            return [];
        }
        ob_start();
        try {
            $values = (static fn(string $path): mixed => require $path)($file);
        } finally {
            ob_end_clean();
        }
        if (!is_array($values)) {
            return [];
        }

        return array_filter(
            array_intersect_key($values, self::LEGACY_NAMES),
            static fn($value): bool => is_scalar($value)
        );
    }

    /**
     * The file's values under their legacy names (strings, as conf.php holds them), ready to merge over the legacy
     * configuration.
     *
     * @return array<string, string>
     */
    public static function legacyVariables(string $enginePath): array
    {
        $variables = [];
        foreach (self::read($enginePath) as $key => $value) {
            $variables[self::LEGACY_NAMES[$key]] = strval($value);
        }

        return $variables;
    }
}

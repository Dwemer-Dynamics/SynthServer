<?php

if (!function_exists('synthDbSetting')) {
    function synthDbSetting(string $key, string $default): string
    {
        $envValue = getenv($key);
        if ($envValue !== false && trim(strval($envValue)) !== '') {
            return trim(strval($envValue));
        }

        if (isset($GLOBALS[$key]) && trim(strval($GLOBALS[$key])) !== '') {
            return trim(strval($GLOBALS[$key]));
        }

        return $default;
    }
}

if (!function_exists('synthPgConnectionValue')) {
    function synthPgConnectionValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1) {
            return $value;
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}

if (!function_exists('synthDbConnectionSettings')) {
    function synthDbConnectionSettings(string $defaultDatabase = 'synth'): array
    {
        return [
            'host' => synthDbSetting('SYNTH_DB_HOST', '127.0.0.1'),
            'port' => synthDbSetting('SYNTH_DB_PORT', '5432'),
            'dbname' => synthDbSetting('SYNTH_DB_NAME', $defaultDatabase),
            // Management pages qualify tables explicitly; match the application
            // schema used by runtime connections and migrations.
            'schema' => 'synth',
            'username' => synthDbSetting('SYNTH_DB_USER', 'dwemer'),
            'password' => synthDbSetting('SYNTH_DB_PASSWORD', 'dwemer'),
        ];
    }
}

if (!function_exists('synthPgConnectionString')) {
    function synthPgConnectionString(array $settings): string
    {
        return implode(' ', [
            'host=' . synthPgConnectionValue(strval($settings['host'] ?? '127.0.0.1')),
            'port=' . synthPgConnectionValue(strval($settings['port'] ?? '5432')),
            'dbname=' . synthPgConnectionValue(strval($settings['dbname'] ?? 'synth')),
            'user=' . synthPgConnectionValue(strval($settings['username'] ?? 'dwemer')),
            'password=' . synthPgConnectionValue(strval($settings['password'] ?? 'dwemer')),
        ]);
    }
}

?>

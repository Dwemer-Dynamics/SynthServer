<?php

if (!function_exists('synthNormalizePluginName')) {
    function synthNormalizePluginName($value): string
    {
        return trim((string) $value);
    }
}

if (!function_exists('synthNormalizeHexIdentifier')) {
    function synthNormalizeHexIdentifier($value, int $padLength = 0): string
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return '';
        }

        if (strpos($value, '0X') === 0) {
            $value = substr($value, 2);
        }

        $value = preg_replace('/[^0-9A-F]/', '', $value) ?? '';
        if ($value === '') {
            return '';
        }

        if ($padLength > 0 && strlen($value) < $padLength) {
            $value = str_pad($value, $padLength, '0', STR_PAD_LEFT);
        }

        return $value;
    }
}

if (!function_exists('synthNormalizeRuntimeFormId')) {
    function synthNormalizeRuntimeFormId($value): string
    {
        return synthNormalizeHexIdentifier($value, 8);
    }
}

if (!function_exists('synthNormalizeLocalFormId')) {
    function synthNormalizeLocalFormId($value): string
    {
        return synthNormalizeHexIdentifier($value, 8);
    }
}

if (!function_exists('synthNormalizeFormIdPrefix')) {
    function synthNormalizeFormIdPrefix($value): string
    {
        $prefix = synthNormalizeHexIdentifier($value);
        if ($prefix === '') {
            return '';
        }

        if (strlen($prefix) <= 2) {
            return str_pad(substr($prefix, -2), 2, '0', STR_PAD_LEFT);
        }

        return str_pad(substr($prefix, -5), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('synthBuildStableFormReference')) {
    function synthBuildStableFormReference($pluginName, $localFormId): string
    {
        $pluginName = synthNormalizePluginName($pluginName);
        $localFormId = synthNormalizeLocalFormId($localFormId);
        if ($pluginName === '' || $localFormId === '') {
            return '';
        }

        return $pluginName . '|' . $localFormId;
    }
}

if (!function_exists('synthParseStableFormReference')) {
    function synthParseStableFormReference($value): ?array
    {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, '|') === false) {
            return null;
        }

        $parts = explode('|', $value, 2);
        $pluginName = synthNormalizePluginName($parts[0] ?? '');
        $localFormId = synthNormalizeLocalFormId($parts[1] ?? '');
        if ($pluginName === '' || $localFormId === '') {
            return null;
        }

        return [
            'plugin_name' => $pluginName,
            'local_formid' => $localFormId,
            'stable_key' => synthBuildStableFormReference($pluginName, $localFormId),
        ];
    }
}

if (!function_exists('synthStableFormReferenceEquals')) {
    function synthStableFormReferenceEquals($left, $right): bool
    {
        $leftParsed = synthParseStableFormReference($left);
        $rightParsed = synthParseStableFormReference($right);
        if (!$leftParsed || !$rightParsed) {
            return false;
        }

        return strcasecmp($leftParsed['plugin_name'], $rightParsed['plugin_name']) === 0
            && $leftParsed['local_formid'] === $rightParsed['local_formid'];
    }
}

if (!function_exists('synthFactionEntryMatchesStableFormReference')) {
    function synthFactionEntryMatchesStableFormReference(array $factionEntry, $stableReference): bool
    {
        $parsedReference = synthParseStableFormReference($stableReference);
        if (!$parsedReference) {
            return false;
        }

        if (!empty($factionEntry['stable_key']) && synthStableFormReferenceEquals($factionEntry['stable_key'], $parsedReference['stable_key'])) {
            return true;
        }

        $pluginName = synthNormalizePluginName($factionEntry['plugin'] ?? '');
        $localFormId = synthNormalizeLocalFormId($factionEntry['local_formid'] ?? '');
        if ($pluginName === '' || $localFormId === '') {
            return false;
        }

        return strcasecmp($pluginName, $parsedReference['plugin_name']) === 0
            && $localFormId === $parsedReference['local_formid'];
    }
}

if (!function_exists('synthComputeRuntimeFormIdFromPrefix')) {
    function synthComputeRuntimeFormIdFromPrefix($formIdPrefix, $localFormId): ?string
    {
        $formIdPrefix = synthNormalizeFormIdPrefix($formIdPrefix);
        $localFormId = synthNormalizeLocalFormId($localFormId);
        if ($formIdPrefix === '' || $localFormId === '') {
            return null;
        }

        $localValue = hexdec($localFormId);

        if (strlen($formIdPrefix) === 2) {
            $runtimeValue = ((hexdec($formIdPrefix) & 0xFF) << 24) | ($localValue & 0x00FFFFFF);
            return sprintf('%08X', $runtimeValue & 0xFFFFFFFF);
        }

        if (strlen($formIdPrefix) === 5 && strpos($formIdPrefix, 'FE') === 0) {
            $lightIndex = hexdec(substr($formIdPrefix, 2));
            $runtimeValue = 0xFE000000 | (($lightIndex & 0x0FFF) << 12) | ($localValue & 0x00000FFF);
            return sprintf('%08X', $runtimeValue & 0xFFFFFFFF);
        }

        return null;
    }
}

if (!function_exists('synthGetLoadedGamePluginByName')) {
    function synthGetLoadedGamePluginByName($pluginName): ?array
    {
        static $pluginCache = [];

        $pluginName = synthNormalizePluginName($pluginName);
        if ($pluginName === '') {
            return null;
        }

        $cacheKey = strtolower($pluginName);
        if (array_key_exists($cacheKey, $pluginCache)) {
            return $pluginCache[$cacheKey];
        }

        global $db;
        if (!isset($db)) {
            return null;
        }

        $escapedPluginName = $db->escape($pluginName);
        $pluginRow = $db->fetchOne("
            SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix, updated_at
            FROM synth.game_plugins
            WHERE LOWER(plugin_name) = LOWER('{$escapedPluginName}')
            LIMIT 1
        ");

        $pluginCache[$cacheKey] = is_array($pluginRow) ? $pluginRow : null;
        return $pluginCache[$cacheKey];
    }
}

if (!function_exists('synthGetLoadedGamePluginByFormIdPrefix')) {
    function synthGetLoadedGamePluginByFormIdPrefix($formIdPrefix): ?array
    {
        static $pluginCache = [];

        $formIdPrefix = synthNormalizeFormIdPrefix($formIdPrefix);
        if ($formIdPrefix === '') {
            return null;
        }

        $cacheKey = strtoupper($formIdPrefix);
        if (array_key_exists($cacheKey, $pluginCache)) {
            return $pluginCache[$cacheKey];
        }

        global $db;
        if (!isset($db)) {
            return null;
        }

        $escapedFormIdPrefix = $db->escape($formIdPrefix);
        $pluginRow = $db->fetchOne("
            SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix, updated_at
            FROM synth.game_plugins
            WHERE formid_prefix = '{$escapedFormIdPrefix}'
            LIMIT 1
        ");

        $pluginCache[$cacheKey] = is_array($pluginRow) ? $pluginRow : null;
        return $pluginCache[$cacheKey];
    }
}

if (!function_exists('synthGetLoadedGamePluginByRuntimeFormId')) {
    function synthGetLoadedGamePluginByRuntimeFormId($runtimeFormId): ?array
    {
        $runtimeFormId = synthNormalizeRuntimeFormId($runtimeFormId);
        if ($runtimeFormId === '') {
            return null;
        }

        $formIdPrefix = (strpos($runtimeFormId, 'FE') === 0)
            ? substr($runtimeFormId, 0, 5)
            : substr($runtimeFormId, 0, 2);

        return synthGetLoadedGamePluginByFormIdPrefix($formIdPrefix);
    }
}

if (!function_exists('synthExtractLocalFormIdFromRuntimeFormId')) {
    function synthExtractLocalFormIdFromRuntimeFormId($runtimeFormId): string
    {
        $runtimeFormId = synthNormalizeRuntimeFormId($runtimeFormId);
        if ($runtimeFormId === '') {
            return '';
        }

        $runtimeValue = hexdec($runtimeFormId);
        if (strpos($runtimeFormId, 'FE') === 0) {
            return sprintf('%08X', $runtimeValue & 0x00000FFF);
        }

        return sprintf('%08X', $runtimeValue & 0x00FFFFFF);
    }
}

if (!function_exists('synthBuildWildcardDescriptionBaseId')) {
    function synthBuildWildcardDescriptionBaseId($runtimeFormId, ?array $pluginRow = null): string
    {
        $runtimeFormId = synthNormalizeRuntimeFormId($runtimeFormId);
        if ($runtimeFormId === '') {
            return '';
        }

        if (strpos($runtimeFormId, 'FE') === 0) {
            return 'FEXXX' . substr($runtimeFormId, -3);
        }

        if ($pluginRow === null) {
            $pluginRow = synthGetLoadedGamePluginByRuntimeFormId($runtimeFormId);
        }

        if (is_array($pluginRow) && !empty($pluginRow['plugin_name'])) {
            if (strcasecmp((string) $pluginRow['plugin_name'], 'Fallout.esm') === 0) {
                return '';
            }
        } elseif (substr($runtimeFormId, 0, 2) === '00') {
            return '';
        }

        return 'XX' . substr($runtimeFormId, -6);
    }
}

if (!function_exists('synthBuildDescriptionBaseIdCandidates')) {
    function synthBuildDescriptionBaseIdCandidates($value): array
    {
        $candidates = [];
        $pushCandidate = function ($candidate) use (&$candidates): void {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                return;
            }

            if (strpos($candidate, '|') !== false) {
                $parsedStable = synthParseStableFormReference($candidate);
                if ($parsedStable) {
                    $candidate = $parsedStable['stable_key'];
                }
            } else {
                $candidate = strtoupper($candidate);
            }

            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        };

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $parsedStableReference = synthParseStableFormReference($value);
        if ($parsedStableReference) {
            $pushCandidate($parsedStableReference['stable_key']);

            $pluginRow = synthGetLoadedGamePluginByName($parsedStableReference['plugin_name']);
            $runtimeFormId = null;
            if ($pluginRow && !empty($pluginRow['formid_prefix'])) {
                $runtimeFormId = synthComputeRuntimeFormIdFromPrefix(
                    $pluginRow['formid_prefix'],
                    $parsedStableReference['local_formid']
                );
            }

            if ($runtimeFormId) {
                $pushCandidate($runtimeFormId);
                $pushCandidate(synthBuildWildcardDescriptionBaseId($runtimeFormId, $pluginRow));
            }

            return $candidates;
        }

        $upperValue = strtoupper($value);
        if (strpos($upperValue, 'XX') === 0 || strpos($upperValue, 'FEXXX') === 0) {
            $pushCandidate($upperValue);
            return $candidates;
        }

        $runtimeFormId = synthNormalizeRuntimeFormId($value);
        if ($runtimeFormId === '') {
            return [];
        }

        $pushCandidate($runtimeFormId);

        $pluginRow = synthGetLoadedGamePluginByRuntimeFormId($runtimeFormId);
        $localFormId = synthExtractLocalFormIdFromRuntimeFormId($runtimeFormId);
        if ($pluginRow && !empty($pluginRow['plugin_name']) && $localFormId !== '') {
            $pushCandidate(synthBuildStableFormReference($pluginRow['plugin_name'], $localFormId));
        }

        $pushCandidate(synthBuildWildcardDescriptionBaseId($runtimeFormId, $pluginRow));

        return $candidates;
    }
}

if (!function_exists('synthResolveStableFormReferenceToRuntimeFormId')) {
    function synthResolveStableFormReferenceToRuntimeFormId($stableReference): ?string
    {
        $parsedReference = synthParseStableFormReference($stableReference);
        if (!$parsedReference) {
            return null;
        }

        $pluginRow = synthGetLoadedGamePluginByName($parsedReference['plugin_name']);
        if (!$pluginRow || empty($pluginRow['formid_prefix'])) {
            return null;
        }

        return synthComputeRuntimeFormIdFromPrefix($pluginRow['formid_prefix'], $parsedReference['local_formid']);
    }
}

if (!function_exists('synthReplaceLoadedGamePlugins')) {
    function synthLoadedGamePluginsSignature(array $plugins): string
    {
        ksort($plugins, SORT_STRING);
        return hash('sha256', json_encode($plugins, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    function synthFetchCurrentLoadedGamePluginsSignature(): string
    {
        global $db;
        if (!isset($db)) {
            return '';
        }

        $rows = $db->fetchAll("
            SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix
            FROM synth.game_plugins
            ORDER BY LOWER(plugin_name)
        ");
        if (!is_array($rows) || empty($rows)) {
            return '';
        }

        $plugins = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pluginName = synthNormalizePluginName($row['plugin_name'] ?? '');
            $formIdPrefix = synthNormalizeFormIdPrefix($row['formid_prefix'] ?? '');
            if ($pluginName === '' || $formIdPrefix === '') {
                continue;
            }

            $plugins[strtolower($pluginName)] = [
                'plugin_name' => $pluginName,
                'is_light' => filter_var($row['is_light'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'compile_index' => isset($row['compile_index']) ? intval($row['compile_index']) : 0,
                'small_file_compile_index' => isset($row['small_file_compile_index']) ? intval($row['small_file_compile_index']) : 0,
                'partial_index' => isset($row['partial_index']) ? intval($row['partial_index']) : 0,
                'formid_prefix' => $formIdPrefix,
            ];
        }

        return empty($plugins) ? '' : synthLoadedGamePluginsSignature($plugins);
    }

    function synthReplaceLoadedGamePlugins(array $plugins): int
    {
        global $db;
        if (!isset($db)) {
            return 0;
        }

        $normalizedPlugins = [];
        foreach ($plugins as $pluginRow) {
            if (!is_array($pluginRow)) {
                continue;
            }

            $pluginName = synthNormalizePluginName($pluginRow['plugin_name'] ?? '');
            $formIdPrefix = synthNormalizeFormIdPrefix($pluginRow['formid_prefix'] ?? '');
            if ($pluginName === '' || $formIdPrefix === '') {
                continue;
            }

            $normalizedPlugins[strtolower($pluginName)] = [
                'plugin_name' => $pluginName,
                'is_light' => !empty($pluginRow['is_light']),
                'compile_index' => isset($pluginRow['compile_index']) ? intval($pluginRow['compile_index']) : 0,
                'small_file_compile_index' => isset($pluginRow['small_file_compile_index']) ? intval($pluginRow['small_file_compile_index']) : 0,
                'partial_index' => isset($pluginRow['partial_index']) ? intval($pluginRow['partial_index']) : 0,
                'formid_prefix' => $formIdPrefix,
            ];
        }

        if (count($normalizedPlugins) === 0) {
            return 0;
        }

        $incomingSignature = synthLoadedGamePluginsSignature($normalizedPlugins);
        $currentSignature = synthFetchCurrentLoadedGamePluginsSignature();
        if ($currentSignature !== '' && hash_equals($currentSignature, $incomingSignature)) {
            return count($normalizedPlugins);
        }

        $db->execQuery('DELETE FROM synth.game_plugins');

        foreach ($normalizedPlugins as $pluginRow) {
            $escapedPluginName = $db->escape($pluginRow['plugin_name']);
            $escapedFormIdPrefix = $db->escape($pluginRow['formid_prefix']);
            $isLight = $pluginRow['is_light'] ? 'TRUE' : 'FALSE';
            $compileIndex = intval($pluginRow['compile_index']);
            $smallFileCompileIndex = intval($pluginRow['small_file_compile_index']);
            $partialIndex = intval($pluginRow['partial_index']);

            $db->execQuery("
                INSERT INTO synth.game_plugins (
                    plugin_name,
                    is_light,
                    compile_index,
                    small_file_compile_index,
                    partial_index,
                    formid_prefix,
                    updated_at
                ) VALUES (
                    '{$escapedPluginName}',
                    {$isLight},
                    {$compileIndex},
                    {$smallFileCompileIndex},
                    {$partialIndex},
                    '{$escapedFormIdPrefix}',
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (plugin_name) DO UPDATE SET
                    is_light = EXCLUDED.is_light,
                    compile_index = EXCLUDED.compile_index,
                    small_file_compile_index = EXCLUDED.small_file_compile_index,
                    partial_index = EXCLUDED.partial_index,
                    formid_prefix = EXCLUDED.formid_prefix,
                    updated_at = CURRENT_TIMESTAMP
            ");
        }

        return count($normalizedPlugins);
    }
}

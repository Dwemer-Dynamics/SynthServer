<?php

// Moved from lib/data_functions.php: item, inventory and equipment descriptions for prompts
// The original file requires this one, so every caller is unchanged.

function getCapsFromMetadata($npcName = null) {
    if ($npcName === null) {
        $npcName = \Synth\Bootstrap::has('SYNTH_NAME') ? \Synth\Bootstrap::config("SYNTH_NAME") : "";
    }

    if (empty($npcName)) {
        return 0;
    }

    try {
        $npcMaster = new NpcMaster();
        $npcData = $npcMaster->getByName($npcName);

        if (!$npcData) {
            return 0;
        }

        $metaData = $npcMaster->getMetaData($npcData);

        if (!isset($metaData["inventory"]) || !is_array($metaData["inventory"])) {
            return 0;
        }

        foreach ($metaData["inventory"] as $item) {
            $itemName = isset($item["name"]) ? strtolower($item["name"]) : "";
            if (stripos($itemName, "cap") !== false || stripos($itemName, "caps") !== false || stripos($itemName, "coin") !== false) {
                return isset($item["count"]) ? intval($item["count"]) : 0;
            }
        }
    } catch (Exception $e) {
        // Silently fail and return 0
    }

    return 0;
}

function isItemBlacklisted($itemName) {
    if (!\Synth\Bootstrap::has('ITEM_BLACKLIST') || empty(\Synth\Bootstrap::config('ITEM_BLACKLIST'))) {
        return false;
    }

    $blacklistedItems = array_map('trim', explode(',', \Synth\Bootstrap::config("ITEM_BLACKLIST")));
    $itemNameLower = strtolower(trim($itemName));

    foreach ($blacklistedItems as $blacklistedItem) {
        if (strtolower($blacklistedItem) === $itemNameLower) {
            return true;
        }
    }

    return false;
}

function synthNormalizePromptFormId($value): ?string
{
    $formId = trim((string)$value);
    if ($formId === '') {
        return null;
    }

    if (stripos($formId, '0x') === 0) {
        $formId = substr($formId, 2);
    }

    if (!preg_match('/^[0-9a-f]{1,8}$/i', $formId)) {
        return null;
    }

    return '0x' . strtoupper(str_pad($formId, 8, '0', STR_PAD_LEFT));
}

function synthEscapePromptItemText($value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', (string)$value);
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return str_replace('`', '&#96;', $value);
}

function synthInventoryMetadataLabels(array $item): array
{
    $labels = [];
    if (!empty($item['equipped'])) {
        $labels[] = 'equipped';
    }

    if (isset($item['condition']) && is_numeric($item['condition']) && (float)$item['condition'] >= 0) {
        $condition = (float)$item['condition'];
        $labels[] = 'condition ' . ($condition <= 1.0
            ? (string)round($condition * 100) . '%'
            : rtrim(rtrim(number_format($condition, 2, '.', ''), '0'), '.'));
    }

    if (isset($item['value']) && is_numeric($item['value']) && (int)$item['value'] >= 0) {
        $labels[] = 'value ' . (int)$item['value'] . ' caps';
    }

    $ammo = trim((string)($item['ammo'] ?? ''));
    if ($ammo !== '') {
        $labels[] = 'ammo ' . synthEscapePromptItemText($ammo);
    }

    $mods = $item['mods'] ?? [];
    if (is_string($mods)) {
        $decodedMods = json_decode($mods, true);
        $mods = is_array($decodedMods) ? $decodedMods : preg_split('/\s*[,|]\s*/', $mods, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (is_array($mods)) {
        $safeMods = [];
        foreach ($mods as $mod) {
            $modName = is_array($mod) ? ($mod['name'] ?? $mod['id'] ?? '') : $mod;
            $modName = trim((string)$modName);
            if ($modName !== '') {
                $safeMods[] = synthEscapePromptItemText($modName);
            }
        }
        if ($safeMods) {
            $labels[] = 'mods ' . implode(', ', $safeMods);
        }
    }

    return $labels;
}

function synthFormatInventoryPromptLines(
    array $inventory,
    ?callable $getItemDescription = null,
    array &$describedBaseids = [],
    bool $descriptionsOnly = false
): array {
    $lines = [];
    $normalizedDescribedBaseids = [];
    foreach ($describedBaseids as $describedBaseid) {
        $normalized = synthNormalizePromptFormId($describedBaseid);
        if ($normalized !== null) {
            $normalizedDescribedBaseids[] = $normalized;
        }
    }

    foreach ($inventory as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemName = trim((string)($item['name'] ?? ''));
        if ($itemName === '' || $itemName === '<Missing Name>' || isItemBlacklisted($itemName)) {
            continue;
        }

        $count = max(0, (int)($item['count'] ?? 0));
        $rawBaseId = trim((string)($item['baseid'] ?? ''));
        $baseId = synthNormalizePromptFormId($rawBaseId);
        $description = null;

        if ($getItemDescription !== null && $count <= 5 &&
            ($baseId === null || !in_array($baseId, $normalizedDescribedBaseids, true))) {
            $description = $getItemDescription($itemName, $rawBaseId !== '' ? $rawBaseId : null);
            if ($description && $baseId !== null) {
                $describedBaseids[] = $baseId;
                $normalizedDescribedBaseids[] = $baseId;
            }
        }

        if ($descriptionsOnly && !$description) {
            continue;
        }

        $safeName = synthEscapePromptItemText($itemName);
        $identifier = $baseId !== null ? "`{$baseId}:{$safeName}`" : $safeName;
        $line = "- {$identifier} ({$count})";
        $metadata = synthInventoryMetadataLabels($item);
        if ($metadata) {
            $line .= ' [' . implode('; ', $metadata) . ']';
        }
        if ($description) {
            $line .= ' - ' . synthEscapePromptItemText($description);
        }
        $lines[] = $line;
    }

    return $lines;
}

function synthBuildInventoryPromptContext(
    array $inventory,
    ?callable $getItemDescription = null,
    array &$describedBaseids = [],
    bool $descriptionsOnly = false
): string {
    $lines = synthFormatInventoryPromptLines($inventory, $getItemDescription, $describedBaseids, $descriptionsOnly);
    if (!$lines) {
        return '';
    }

    return "<inventory>\n# INVENTORY\nFormat: BaseID:ItemName (quantity)\n\n"
        . implode("\n", $lines)
        . "\n</inventory>";
}

/**
 * Lookup a description by candidate base IDs while preserving override priority.
 * Custom rows must win across all wildcard/stable candidates before seeded defaults.
 */
function lookupDescriptionRecordByCandidates(array $candidateBaseIds, bool $requireDescription = false): ?array {
    $db = \Synth\Bootstrap::db();

    $candidateRows = [];
    $pushCandidateRow = function (string $plugin, string $baseid) use (&$candidateRows): void {
        $plugin = trim($plugin);
        $baseid = trim($baseid);
        if ($baseid === '') {
            return;
        }

        $key = $plugin . '|' . $baseid;
        if (!isset($candidateRows[$key])) {
            $candidateRows[$key] = ['plugin' => $plugin, 'baseid' => $baseid];
        }
    };

    foreach ($candidateBaseIds as $candidateBaseId) {
        $candidateBaseId = trim((string) $candidateBaseId);
        if ($candidateBaseId === '') {
            continue;
        }

        if (strpos($candidateBaseId, '|') !== false) {
            $parsedStable = synthParseStableFormReference($candidateBaseId);
            if (!$parsedStable) {
                continue;
            }
            $plugin = $parsedStable['plugin_name'];
            $baseid = $parsedStable['local_formid'];
            $pushCandidateRow($plugin, $baseid);

            $pluginRow = function_exists('synthGetLoadedGamePluginByName')
                ? synthGetLoadedGamePluginByName($plugin)
                : null;
            if ($pluginRow && !empty($pluginRow['formid_prefix']) && function_exists('synthComputeRuntimeFormIdFromPrefix')) {
                $runtimeBaseid = synthComputeRuntimeFormIdFromPrefix($pluginRow['formid_prefix'], $baseid);
                if ($runtimeBaseid !== null && $runtimeBaseid !== $baseid) {
                    $pushCandidateRow($plugin, $runtimeBaseid);
                }
            }
        } else {
            $pushCandidateRow('', strtoupper($candidateBaseId));
        }
    }

    foreach (['core_item_description_custom', 'core_item_description'] as $tableName) {
        foreach ($candidateRows as $candidateRow) {
            $escapedPlugin = $db->escape($candidateRow['plugin']);
            $escapedBaseId = $db->escape($candidateRow['baseid']);
            $record = $db->fetchOne(
                "SELECT plugin, baseid, name, description
                   FROM public.{$tableName}
                  WHERE plugin = '{$escapedPlugin}'
                    AND baseid = '{$escapedBaseId}'
                  LIMIT 1"
            );

            if (!$record) {
                continue;
            }

            if ($requireDescription && empty($record['description'])) {
                continue;
            }

            return $record;
        }
    }

    return null;
}

/**
 * Lookup description from the merged descriptions view using runtime, wildcard, or stable keys.
 * Supports:
 * - exact runtime FormIDs (e.g. 020098A0)
 * - wildcard keys (e.g. XX0098A0, FEXXX822)
 * - internal plugin-aware candidates generated from loaded plugin metadata
 *
 * @param string $formId The identifier to lookup
 * @return array|null Array with 'baseid', 'name', and 'description' keys, or null if not found
 */
function lookupDescriptionByFormID(string $formId): ?array {
    return lookupDescriptionRecordByCandidates(synthBuildDescriptionBaseIdCandidates($formId));
}

/**
 * Lookup description only by exact runtime FormID or internal plugin-aware candidate.
 * This deliberately skips wildcard keys and name fallback to avoid
 * cross-matching unrelated item descriptions.
 *
 * @param string $formId The runtime or stable identifier to lookup
 * @return array|null Array with 'baseid', 'name', and 'description' keys, or null if not found
 */
function lookupStrictDescriptionByFormID(string $formId): ?array {
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

    $formId = trim($formId);
    if ($formId === '') {
        return null;
    }

    $parsedStableReference = synthParseStableFormReference($formId);
    if ($parsedStableReference) {
        $pushCandidate($parsedStableReference['stable_key']);

        $pluginRow = synthGetLoadedGamePluginByName($parsedStableReference['plugin_name']);
        if ($pluginRow && !empty($pluginRow['formid_prefix'])) {
            $runtimeFormId = synthComputeRuntimeFormIdFromPrefix(
                $pluginRow['formid_prefix'],
                $parsedStableReference['local_formid']
            );
            if ($runtimeFormId) {
                $pushCandidate($runtimeFormId);
            }
        }
    } else {
        $runtimeFormId = synthNormalizeRuntimeFormId($formId);
        if ($runtimeFormId !== '') {
            $pushCandidate($runtimeFormId);

            $pluginRow = synthGetLoadedGamePluginByRuntimeFormId($runtimeFormId);
            $localFormId = synthExtractLocalFormIdFromRuntimeFormId($runtimeFormId);
            if ($pluginRow && !empty($pluginRow['plugin_name']) && $localFormId !== '') {
                $pushCandidate(synthBuildStableFormReference($pluginRow['plugin_name'], $localFormId));
            }
        }
    }

    return lookupDescriptionRecordByCandidates($candidates, true);
}

/** Catalog text for a captured item, scoped to its observed origin plugin. */
function synthLookupCapturedEquipmentDescription(string $formId, string $plugin): ?string {
    $db = \Synth\Bootstrap::db();
    if (!preg_match('/^0x[0-9a-f]{8}$/iD', $formId)
        || strcasecmp(substr($formId, 2, 2), 'FF') === 0
        || strcasecmp($formId, '0x00000000') === 0
        || $plugin === '' || trim($plugin) !== $plugin || strlen($plugin) > 255
        || !mb_check_encoding($plugin, 'UTF-8') || preg_match('/[\x00-\x1f\x7f|\\\\\/]/', $plugin)
        || !preg_match('/\.(esm|esp|esl)$/iD', $plugin)) {
        return null;
    }
    $localFormId = synthExtractLocalFormIdFromRuntimeFormId($formId);
    // Do not use wildcard, bare runtime-ID or name fallbacks across plugins.
    foreach (['core_item_description_custom', 'core_item_description'] as $table) {
        $result = $db->execQuery("SELECT description FROM public.{$table}
            WHERE plugin=$1 AND baseid=$2 AND description<>'' LIMIT 1", [$plugin, $localFormId]);
        if ($result === false) return null;
        $row = pg_fetch_assoc($result);
        if (is_string($row['description'] ?? null)) return $row['description'];
    }
    return null;
}

/**
 * Get height description based on scale value
 * Reads height descriptions from prompts table with hardcoded fallback
 *
 * @param float $scale The NPC scale value (typically 0.6 to 1.4)
 * @return string Height description or empty string if not found
 */
function getHeightDescription(float $scale): string {
    static $heightDescriptions = null;

    // Hardcoded fallback in case database fails
    $fallbackDescriptions = [
        ['name' => 'VerySmall', 'min_scale' => 0.0, 'max_scale' => 0.60, 'description' => 'Very small and tiny in stature'],
        ['name' => 'Small', 'min_scale' => 0.60, 'max_scale' => 0.80, 'description' => 'Smaller than most people'],
        ['name' => 'ModestStature', 'min_scale' => 0.80, 'max_scale' => 0.95, 'description' => 'Slightly below average height'],
        ['name' => 'Average', 'min_scale' => 0.95, 'max_scale' => 1.05, 'description' => 'Typical height'],
        ['name' => 'Tall', 'min_scale' => 1.05, 'max_scale' => 1.20, 'description' => 'Tall, standing a head above most people'],
        ['name' => 'VeryTall', 'min_scale' => 1.20, 'max_scale' => 1.40, 'description' => 'Very tall'],
        ['name' => 'Giantlike', 'min_scale' => 1.40, 'max_scale' => 99.0, 'description' => 'Giant in height and stature']
    ];

    // Load height descriptions from prompts table (cached)
    if ($heightDescriptions === null) {
        try {
            $heightPrompt = \Synth\Core\DbManager\Dao\PromptDao::current()->effective('height_descriptions');

            if (!empty($heightPrompt)) {
                $data = json_decode($heightPrompt, true);
                $heightDescriptions = $data['height_descriptions'] ?? $fallbackDescriptions;
            } else {
                // Database query succeeded but no data - use fallback
                $heightDescriptions = $fallbackDescriptions;
            }
        } catch (Exception $e) {
            // Database error - use fallback
            Logger::debug("Using fallback height descriptions due to database error: " . $e->getMessage());
            $heightDescriptions = $fallbackDescriptions;
        }
    }

    // Find matching height description
    foreach ($heightDescriptions as $desc) {
        if ($scale >= $desc['min_scale'] && $scale < $desc['max_scale']) {
            return $desc['description'];
        }
    }

    return ''; // No description if out of range
}

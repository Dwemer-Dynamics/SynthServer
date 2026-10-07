<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'relationship_manager.php';

if (!function_exists('synthRelationshipSettingEnabled')) {
    function synthRelationshipSettingEnabled($value = null): bool
    {
        if (func_num_args() === 0) {
            $value = $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] ?? true;
        }

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
if (!function_exists('synthRelationshipUsesDedicatedConnector')) {
    function synthRelationshipUsesDedicatedConnector($value = null): bool
    {
        if (func_num_args() === 0) {
            $value = $GLOBALS['RELLLM_CONNECTOR'] ?? 0;
        }

        return synthRelationshipSettingEnabled() && intval($value) > 0;
    }
}

if (!function_exists('synthRelationshipCompletedText')) {
    function synthRelationshipCompletedText(array $lines): string
    {
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return trim(implode(' ', $clean));
    }
}

if (!function_exists('synthRelationshipProcessInlineCompletedResponse')) {
    function synthRelationshipProcessInlineCompletedResponse(
        array $lines,
        string $npcName,
        ?callable $parser = null
    ): string {
        $fullResponse = synthRelationshipCompletedText($lines);
        if (!synthRelationshipSettingEnabled()
            || synthRelationshipUsesDedicatedConnector()
            || $fullResponse === ''
            || trim($npcName) === ''
            || strcasecmp(trim($npcName), 'The Narrator') === 0) {
            return $fullResponse;
        }

        $parser = $parser ?? [RelationshipManager::class, 'parseChanges'];
        return (string)$parser($fullResponse, trim($npcName));
    }
}

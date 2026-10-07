<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "playthrough_snapshot.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "logger.php");

if (!function_exists('synthRollbackNormalizeGamets')) {
    function synthRollbackNormalizeGamets($gamets): int
    {
        $value = intval($gamets);
        return $value > 0 ? $value : 0;
    }
}

if (!function_exists('synthRollbackTableExists')) {
    function synthRollbackTableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            $cache[$table] = false;
            return false;
        }

        try {
            $db = $GLOBALS["db"] ?? null;
            if (!$db) {
                $cache[$table] = false;
                return false;
            }

            $escaped = $db->escape($table);
            $rows = $db->fetchAll("SELECT 1 FROM information_schema.tables WHERE table_schema='synth' AND table_name='{$escaped}' LIMIT 1");
            $cache[$table] = is_array($rows) && count($rows) > 0;
            return $cache[$table];
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Table existence check failed for {$table}: " . $e->getMessage());
            $cache[$table] = false;
            return false;
        }
    }
}

if (!function_exists('synthRollbackDelete')) {
    function synthRollbackDelete(string $table, string $where, array &$stats): void
    {
        if (!synthRollbackTableExists($table)) {
            return;
        }

        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }

        $count = 0;
        try {
            $rows = $db->fetchAll("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}");
            if (is_array($rows) && isset($rows[0]["n"])) {
                $count = intval($rows[0]["n"]);
            }
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Count failed for {$table}: " . $e->getMessage());
        }

        if ($count <= 0) {
            $stats[$table] = ($stats[$table] ?? 0);
            return;
        }

        try {
            if ($db->delete($table, $where)) {
                $stats[$table] = ($stats[$table] ?? 0) + $count;
            }
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Delete failed for {$table}: " . $e->getMessage());
        }
    }
}

if (!function_exists('synthRollbackClearConfOpt')) {
    function synthRollbackClearConfOpt(string $id, array &$stats): void
    {
        if (!synthRollbackTableExists('conf_opts')) {
            return;
        }

        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }

        $escaped = $db->escape($id);
        synthRollbackDelete('conf_opts', "id='{$escaped}'", $stats);
    }
}

if (!function_exists('synthRollbackPruneFutureData')) {
    function synthRollbackPruneFutureData(int $targetGamets, string $source = 'unknown', bool $force = false): array
    {
        $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
        if (in_array($event['protocol_version'] ?? null, [1, 2], true)) {
            // The legacy tables have no complete timeline ownership. Even a valid
            // native request (or force=true) cannot safely delete their global rows.
            return ['rolled_back' => false, 'reason' => 'scoped_timeline_required',
                'target_gamets' => $targetGamets];
        }
        $targetGamets = synthRollbackNormalizeGamets($targetGamets);
        if ($targetGamets <= 0) {
            return [
                'rolled_back' => false,
                'reason' => 'missing_gamets',
                'target_gamets' => $targetGamets,
            ];
        }

        $previousMaxGamets = function_exists('DataLastKnownGameTS') ? intval(DataLastKnownGameTS()) : 0;
        if (!$force && ($previousMaxGamets <= 0 || $targetGamets >= $previousMaxGamets)) {
            return [
                'rolled_back' => false,
                'reason' => 'not_older',
                'previous_max_gamets' => $previousMaxGamets,
                'target_gamets' => $targetGamets,
            ];
        }

        $stats = [];
        $snapshotId = 0;
        try {
            $snapshotId = function_exists('timeline_break_snapshot_if_needed')
                ? intval(timeline_break_snapshot_if_needed($previousMaxGamets, $targetGamets))
                : 0;
        } catch (Throwable $e) {
            Logger::warn("[SAVE_ROLLBACK] Timeline Break snapshot failed: " . $e->getMessage());
        }

        foreach ([
            'eventlog',
            'speech',
            'diarylog',
            'actions_issued',
            'moods_issued',
            'quests',
        ] as $table) {
            synthRollbackDelete($table, "gamets>={$targetGamets}", $stats);
        }

        synthRollbackDelete('memory_summary', "gamets_truncated>{$targetGamets}", $stats);
        synthRollbackDelete('memory', "gamets>{$targetGamets}", $stats);

        synthRollbackDelete('responselog', "1=1", $stats);
        synthRollbackDelete('rolemaster', "1=1", $stats);
        synthRollbackDelete('relationship_eval_queue', "1=1", $stats);
        synthRollbackDelete('relationship_init_queue', "1=1", $stats);

        synthRollbackClearConfOpt('COMBAT_BARK_LAST_TIMESTAMP', $stats);
        synthRollbackClearConfOpt('last_narrator_welcome', $stats);

        Logger::info("[SAVE_ROLLBACK] Pruned future SYNTH data" . Logger::formatContext([
            'source' => $source,
            'previous_max_gamets' => $previousMaxGamets,
            'target_gamets' => $targetGamets,
            'snapshot_id' => $snapshotId,
            'deleted' => $stats,
        ]));

        return [
            'rolled_back' => true,
            'previous_max_gamets' => $previousMaxGamets,
            'target_gamets' => $targetGamets,
            'snapshot_id' => $snapshotId,
            'deleted' => $stats,
        ];
    }
}

if (!function_exists('synthMaybeHandleIncomingGametsRollback')) {
    function synthMaybeHandleIncomingGametsRollback($incomingGamets, string $source = 'unknown', bool $force = false): array
    {
        if (in_array($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? null, [1, 2], true)) {
            return synthRollbackPruneFutureData(intval($incomingGamets), $source, $force);
        }
        $targetGamets = synthRollbackNormalizeGamets($incomingGamets);
        if ($targetGamets <= 0) {
            return ['rolled_back' => false, 'reason' => 'missing_gamets'];
        }

        if ($force) {
            return synthRollbackPruneFutureData($targetGamets, $source, true);
        }

        $previousMaxGamets = function_exists('DataLastKnownGameTS') ? intval(DataLastKnownGameTS()) : 0;
        if ($previousMaxGamets <= 0) {
            return [
                'rolled_back' => false,
                'reason' => 'empty_history',
                'target_gamets' => $targetGamets,
            ];
        }

        // Passive guards should not treat small out-of-order telemetry as a loaded save.
        $rollbackThreshold = intval($GLOBALS["SYNTH_ROLLBACK_GAMETS_THRESHOLD"] ?? 1000000);
        if ($targetGamets >= ($previousMaxGamets - max(1, $rollbackThreshold))) {
            return [
                'rolled_back' => false,
                'reason' => 'within_threshold',
                'previous_max_gamets' => $previousMaxGamets,
                'target_gamets' => $targetGamets,
            ];
        }

        return synthRollbackPruneFutureData($targetGamets, $source, false);
    }
}

?>

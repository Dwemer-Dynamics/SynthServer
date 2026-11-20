<?php

/**
 * Legacy playthrough snapshot schemas: read-only readiness, listing and inspection helpers.
 */

require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'logger.php');

// Snapshot create, restore and delete are paused. Snapshots copy the configured application schema but
// not its migration version, and the old restore dropped `public` and cleared public.core_catalog_version.
// These helpers keep their return shapes but never change the database. Existing snapshot schemas and
// profile rows are left as they are. Installing the clone functions belongs to the schema owner.
const PTS_SNAPSHOTS_UNAVAILABLE = 'Playthrough snapshots are unavailable.';

function pts_clone_function_is_current($definition): bool {
    return is_string($definition)
        && stripos($definition, 'OVERRIDING SYSTEM VALUE') !== false
        && stripos($definition, 'sync_schema_sequences(dest_schema)') !== false;
}

/**
 * Report whether the installed clone function is current. Read-only: it never installs functions.
 */
function pts_ensure_functions($conn): bool {
    $checkResult = @pg_query($conn, "
        SELECT pg_get_functiondef(p.oid) AS function_definition
          FROM pg_proc p
          JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname = 'synth_meta'
           AND p.proname = 'clone_schema'
         LIMIT 1
    ");
    $checkRow = $checkResult ? pg_fetch_assoc($checkResult) : false;
    return $checkRow && pts_clone_function_is_current($checkRow['function_definition'] ?? null);
}

/**
 * Verify the versioned playthrough metadata migration is available. Read-only: no DDL.
 */
function pts_metadata_schema_ready($conn): bool {
    $tableResult = @pg_query($conn, "
        SELECT
            to_regclass('public.core_playthrough_profile') AS profiles_relation,
            to_regclass('public.core_config_setting') AS settings_relation
    ");
    $tableRow = $tableResult ? pg_fetch_assoc($tableResult) : false;
    if (!$tableRow || empty($tableRow['profiles_relation']) || empty($tableRow['settings_relation'])) {
        return false;
    }

    $functionResult = @pg_query($conn, "
        SELECT COUNT(DISTINCT p.proname) AS total
          FROM pg_proc p
          JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname = 'synth_meta'
           AND p.proname IN ('clone_schema', 'drop_schema_safe', 'get_schema_size')
    ");
    $functionRow = $functionResult ? pg_fetch_assoc($functionResult) : false;
    return $functionRow && intval($functionRow['total'] ?? 0) === 3;
}

/**
 * Sanitize a profile name to create a valid PostgreSQL schema name.
 * Rules: lowercase, alphanumeric + underscore only, max 63 chars
 */
function pts_sanitize_profile_name(string $name): string {
    // Convert to lowercase
    $name = strtolower($name);
    
    // Replace spaces and special chars with underscores
    $name = preg_replace('/[^a-z0-9_]/', '_', $name);
    
    // Remove consecutive underscores
    $name = preg_replace('/_+/', '_', $name);
    
    // Remove leading/trailing underscores
    $name = trim($name, '_');
    
    // Ensure it doesn't start with a number
    if (preg_match('/^[0-9]/', $name)) {
        $name = 'p_' . $name;
    }
    
    // Truncate to safe length (PostgreSQL allows 63, leave room for prefix)
    $name = substr($name, 0, 40);
    
    // Prefix with synth_profile_
    return 'synth_profile_' . $name;
}

/**
 * Check if a schema exists in the database.
 */
function pts_schema_exists($conn, string $schemaName): bool {
    $result = @pg_query_params(
        $conn,
        "SELECT 1 FROM information_schema.schemata WHERE schema_name = $1",
        [$schemaName]
    );
    
    if (!$result) {
        return false;
    }
    
    return pg_num_rows($result) > 0;
}

/**
 * Clone a schema. Paused: always refuses without touching the database.
 * Returns ['success' => bool, 'error' => string]
 */
function pts_clone_schema($conn, string $sourceSchema, string $destSchema): array {
    return ['success' => false, 'error' => PTS_SNAPSHOTS_UNAVAILABLE];
}

/**
 * Drop a profile schema. Paused: always refuses without touching the database.
 * Returns ['success' => bool, 'error' => string]
 */
function pts_drop_schema($conn, string $schemaName): array {
    return ['success' => false, 'error' => PTS_SNAPSHOTS_UNAVAILABLE];
}

/**
 * Get the total size of a schema in bytes.
 * Returns 0 if schema doesn't exist or on error.
 */
function pts_get_schema_size($conn, string $schemaName): int {
    if (!pts_schema_exists($conn, $schemaName)) {
        return 0;
    }
    
    $result = @pg_query(
        $conn,
        "SELECT synth_meta.get_schema_size('" . pg_escape_string($conn, $schemaName) . "'::text) as size"
    );
    
    if (!$result) {
        Logger::warn("Failed to get schema size: " . pg_last_error($conn));
        return 0;
    }
    
    $row = pg_fetch_assoc($result);
    if ($row && isset($row['size'])) {
        return (int)$row['size'];
    }
    
    return 0;
}

/**
 * Recreate the public schema. Paused: always refuses without touching the database.
 */
function pts_recreate_public_schema($conn): bool {
    return false;
}

/**
 * Get metadata about a profile schema (table counts, record counts, etc.)
 * Returns array with stats or empty array on error.
 */
function pts_get_schema_metadata($conn, string $schemaName): array {
    if (!pts_schema_exists($conn, $schemaName)) {
        return [];
    }
    
    $metadata = [
        'player_name' => 'Unknown',
        'game' => 'Fallout',
        'eventlog_count' => 0,
        'worldknowledge_count' => 0,
        'last_gamets' => 0
    ];
    
    // Get eventlog count
    $result = @pg_query_params(
        $conn,
        "SELECT COUNT(*) as c FROM {$schemaName}.eventlog",
        []
    );
    if ($result && ($row = pg_fetch_assoc($result))) {
        $metadata['eventlog_count'] = (int)$row['c'];
    }
    
    // Check if worldknowledge table exists
    $result = @pg_query_params(
        $conn,
        "SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = 'worldknowledge'",
        [$schemaName]
    );
    if ($result && pg_num_rows($result) > 0) {
        $result = @pg_query_params($conn, "SELECT COUNT(*) as c FROM {$schemaName}.worldknowledge", []);
        if ($result && ($row = pg_fetch_assoc($result))) {
            $metadata['worldknowledge_count'] = (int)$row['c'];
        }
    }
    
    // Get last gamets
    $result = @pg_query_params(
        $conn,
        "SELECT MAX(gamets) as mx FROM {$schemaName}.eventlog",
        []
    );
    if ($result && ($row = pg_fetch_assoc($result)) && !is_null($row['mx'])) {
        $metadata['last_gamets'] = (int)$row['mx'];
    }
    
    return $metadata;
}

?>


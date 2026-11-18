<?php

// First, so a refused cross-origin management request stops before configuration or database work.
require_once(__DIR__ . DIRECTORY_SEPARATOR . "management_request_guard.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "settings.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "synth_runtime.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "logger.php");

if (!function_exists('synthRuntimeDatabaseEncoding')) {
    function synthRuntimeDatabaseEncoding(): string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return '';
        }

        try {
            $row = $db->fetchOne('SHOW server_encoding');
            return strtoupper(trim(strval($row['server_encoding'] ?? '')));
        } catch (\Throwable $e) {
            Logger::warn('[DATABASE] Could not read SYNTH database encoding: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('synthRuntimeDatabaseEncodingIsSupported')) {
    // $encoding: a value already read with synthRuntimeDatabaseEncoding(), so a caller asks the database once.
    function synthRuntimeDatabaseEncodingIsSupported(?string $encoding = null): bool
    {
        return ($encoding ?? synthRuntimeDatabaseEncoding()) === 'UTF8';
    }
}

if (!function_exists('synthRuntimeDatabaseEncodingError')) {
    function synthRuntimeDatabaseEncodingError(?string $encoding = null): string
    {
        $encoding = $encoding ?? synthRuntimeDatabaseEncoding();
        $label = $encoding !== '' ? $encoding : 'unknown encoding';
        return "SYNTH database uses {$label}; UTF8 is required for NPC metadata. "
            . 'Run sudo bash /var/www/html/Synthserver/tools/migrate-synth-db-utf8-wsl.sh.';
    }
}

if (!function_exists('synthRuntimeNeedsDbUpdates')) {
    function synthRuntimeNeedsDbUpdates(): bool
    {
        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return false;
        }

        $requiredRelations = [
            'synth.actions_issued',
            'synth.audit_memory',
            'synth.audit_request',
            'synth.bio_templates',
            'synth.bio_templates_custom',
            'synth.conf_opts',
            'synth.core_action',
            'synth.core_action_custom',
            'public.core_api_badge',
            'public.core_llm_connector',
            'synth.core_narrator',
            'synth.core_npc_master',
            'synth.core_npc_master_history',
            'public.core_player',
            'public.core_profiles',
            'public.core_stt_connector',
            'public.core_itt_connector',
            'public.core_tts_connector',
            'public.core_tts_pronunciation',
            'public.core_catalog_version',
            'synth.descriptions',
            'synth.descriptions_custom',
            'synth.diarylog',
            'synth.eventlog',
            'public.core_faction',
            'public.core_plugins',
            'synth.general_settings',
            'public.core_config_preset',
            'public.core_npc_profile_rule',
            'public.core_location',
            'synth.log',
            'synth.memory',
            'synth.memory_summary',
            'synth.moods_issued',
            'public.core_prompt',
            'public.core_quest',
            'public.core_relationship_eval_queue',
            'public.core_relationship_init_queue',
            'synth.responselog',
            'public.core_director_note',
            'synth.speech',
            'synth.worldknowledge',
            'synth.worldknowledge_catalogs',
            'public.core_worldknowledge_audit',
            'synth.worldknowledge_effective',
            'public.core_visual_context',
            'synth.combined_bio_templates',
            'synth.combined_core_action',
            'synth.combined_descriptions',
            'synth.synthnpcs',
            'synth.memory_v',
            'synth.actor_identities',
            'synth.bio_template_actor_map',
            'synth_meta.playthrough_profiles',
            'synth_meta.settings',
            'public.core_runtime_session',
            'synth_meta.request_executions',
            'public.core_dialogue_route',
            'public.core_rechat_chain',
            'public.core_rechat_continuation',
            'public.core_rechat_selection',
            'public.core_dialogue_origin_policy',
            'public.core_protocol_event',
            'public.core_runtime_context',
            'public.core_actor_activation',
            'public.core_media_object',
            'public.core_action_intent',
            'public.core_action_result',
            'public.core_worker_job',
            'synth_meta.history_events',
            'public.core_memory_batch',
            'public.core_memory_batch_source',
            'public.core_memory_summary',
            'public.core_memory_batch_summary_source',
            'public.core_memory_batch_control',
            'public.core_memory_batch_control_event',
            'public.core_memory_summary_revision',
            'public.core_memory_batch_rebuild',
            'public.core_diary_control_revision',
            'public.core_middleterm_control_revision',
            'public.core_middleterm_regeneration_approval',
            'public.core_middleterm_replacement',
            'public.core_narrator_pending_output',
        ];

        try {
            $relationRows = $db->fetchAll("
                SELECT n.nspname || '.' || c.relname AS relation_name
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind IN ('r', 'p', 'v')
                   AND (n.nspname IN ('synth', 'synth_meta') OR (n.nspname = 'public' AND c.relname LIKE 'core\_%'))
            ");
        } catch (\Throwable $e) {
            return true;
        }

        $existingRelations = [];
        foreach ($relationRows as $row) {
            $relationName = strval($row['relation_name'] ?? '');
            if ($relationName !== '') {
                $existingRelations[$relationName] = true;
            }
        }

        foreach ($requiredRelations as $requiredRelation) {
            if (empty($existingRelations[$requiredRelation])) {
                return true;
            }
        }

        if (!empty($existingRelations['synth.currentmission'])) {
            return true;
        }

        try {
            $managedGeneralSettingIds = array_values(array_unique(synthGetManagedGeneralSettingIds()));
            $managedGeneralSettingLiterals = implode(',', array_map(
                static fn(string $settingId): string => $db->escapeLiteral($settingId),
                $managedGeneralSettingIds
            ));
            $runtimeSeedState = $db->fetchOne("
                SELECT
                    (SELECT COUNT(*)
                       FROM public.core_prompt
                      WHERE prompt_key IN (
                        'synth_system_prompt',
                        'synth_response_rules',
                        'synth_world_prompt',
                        'synth_scene_prompt',
                        'synth_memory_prompt'
                      )) AS prompt_defaults,
                    (SELECT COUNT(*) FROM public.core_profiles WHERE default_npc = '1') AS npc_defaults,
                    (SELECT COUNT(*) FROM public.core_profiles WHERE default_narrator = '1') AS narrator_defaults,
                    (SELECT COUNT(DISTINCT p.proname)
                       FROM pg_proc p
                       JOIN pg_namespace n ON n.oid = p.pronamespace
                      WHERE n.nspname = 'synth_meta'
                        AND p.proname IN ('clone_schema', 'drop_schema_safe', 'get_schema_size')) AS playthrough_functions,
                    (SELECT COUNT(DISTINCT id)
                       FROM synth.general_settings
                      WHERE id IN ({$managedGeneralSettingLiterals})) AS managed_general_settings
            ");
        } catch (\Throwable $e) {
            return true;
        }
        if (
            intval($runtimeSeedState['prompt_defaults'] ?? 0) !== 5 ||
            intval($runtimeSeedState['npc_defaults'] ?? 0) !== 1 ||
            intval($runtimeSeedState['narrator_defaults'] ?? 0) !== 1 ||
            intval($runtimeSeedState['playthrough_functions'] ?? 0) !== 3 ||
            intval($runtimeSeedState['managed_general_settings'] ?? 0) !== count($managedGeneralSettingIds)
        ) {
            return true;
        }

        $requiredVersions = [
            'fallout4_content_pack' => 20261008003,
            'conf_opts' => 20260626001,
            'core_action' => 20261106001,
            'core_player' => 20260707001,
            'general_settings' => 20260722001,
            'global_settings_presets' => 20260828001,
            'import_rules' => 20260730001,
            'core_stt_connector' => 20260502002,
            'core_itt_connector' => 20260731001,
            'visual_context' => 20260731001,
            'pipvision_general_settings' => 20260731001,
            'itt_connector_defaults' => 20260731002,
            'descriptions_defaults' => 20260811002,
            'prompts' => 20260627001,
            'core_profiles' => 20260717001,
            'moods_issued_sequence' => 20260626001,
            'core_tts_connector_metadata' => 20260626001,
            'core_tts_connector_omnivoice' => 20260708001,
            'core_tts_connector_removed_drivers' => 20260712001,
            'core_tts_pronunciation' => 20261008003,
            'tts_gender_fallback_defaults' => 20260715001,
            'legacy_translation_tables_cleanup' => 20260628001,
            'legacy_currentmission_cleanup' => 20260713003,
            'general_settings_seed_repair' => 20260713004,
            'legacy_quest_tables_cleanup' => 20260628001,
            'memory_v' => 20260713001,
            'prompt_manager_defaults' => 20260713002,
            'synthnpcs_view' => 20260713002,
            'profile_defaults' => 20260713002,
            'playthrough_metadata_schema' => 20260730001,
            'relationship_async_queues' => 20260713002,
            'fallout_bio_templates_seed' => 20260811002,
            'fallout4_catalogue_sql' => 20261009002,
            'fallout_worldknowledge_seed' => 20260811002,
            'worldknowledge_parity' => 20260813001,
            'worldknowledge_access' => 20260813001,
            'worldknowledge_canonical_tags' => 20260813003,
            'worldknowledge_oghma_parity' => 20260814001,
            'worldknowledge_herika_v1' => 20260814002,
            'worldknowledge_npc_common_cleanup' => 20260814004,
            'worldknowledge_npc_class_cleanup' => 20260814005,
            'worldknowledge_catalog_integrity' => 20260814006,
            'synth_protocol_schema' => 20261113002,
            'synth_relationship_prompt' => 20260811003,
            'synth_fo4_prompt_examples' => 20260811004,
        ];

        try {
            $versionNames = implode(',', array_map(
                static fn(string $name): string => "'" . str_replace("'", "''", $name) . "'",
                array_keys($requiredVersions)
            ));
            $versionRows = $db->fetchAll(
                "SELECT catalog_key, version
                 FROM public.core_catalog_version
                WHERE catalog_key IN ({$versionNames})"
            );
        } catch (\Throwable $e) {
            return true;
        }

        $versions = [];
        foreach ($versionRows as $row) {
            $tableName = strval($row['catalog_key'] ?? '');
            if ($tableName !== '') {
                $versions[$tableName] = intval($row['version'] ?? -1);
            }
        }

        foreach ($requiredVersions as $tableName => $requiredVersion) {
            if (intval($versions[$tableName] ?? -1) < $requiredVersion) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('synthRuntimeImportConfigVariables')) {
    function synthRuntimeImportConfigVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if (!is_string($name) || $name === '' || $name[0] === '_') {
                continue;
            }
            if (!preg_match('/^[A-Z0-9_]+$/', $name)) {
                continue;
            }
            $GLOBALS[$name] = $value;
        }
    }
}

if (!function_exists('synthRuntimeReadConfiguration')) {
    // conf.sample.php then conf.php in one isolated scope, without writing runtime globals. Only upper-case
    // configuration names are kept, and anything the files print is discarded so it cannot corrupt a JSON answer.
    function synthRuntimeReadConfiguration(string $enginePath): array
    {
        $confDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "conf" . DIRECTORY_SEPARATOR;
        $files = array_values(array_filter([$confDir . "conf.sample.php", $confDir . "conf.php"], 'is_file'));
        ob_start();
        try {
            $config = (static function (array $__files): array {
                foreach ($__files as $__file) {
                    require $__file;
                }
                unset($__files, $__file);
                return get_defined_vars();
            })($files);
        } finally {
            ob_end_clean();
        }
        return array_filter($config, static fn($name): bool => is_string($name) && preg_match('/^[A-Z0-9_]+$/', $name) === 1,
            ARRAY_FILTER_USE_KEY);
    }
}

if (!function_exists('synthRuntimeConnectDatabase')) {
    // The legacy runtime session for readiness and setup entries (health.php, Quickstart saves): configured from
    // conf.sample.php and conf.php with the environment still winning, and an exception instead of the legacy
    // plaintext exit when PostgreSQL cannot be reached. The SYNTH_DB_* values also become runtime globals, so the
    // migration owner (synthDbConnectionSettings) connects as the same user. No log rotation, no settings loads.
    // $connectTimeoutCap (seconds) lowers this one connection's configured SYNTH_DB_CONNECT_TIMEOUT (default 90);
    // the runtime global keeps the configured value and an environment SYNTH_DB_CONNECT_TIMEOUT still wins.
    function synthRuntimeConnectDatabase(string $enginePath, ?int $connectTimeoutCap = null): object
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $config = synthRuntimeReadConfiguration($enginePath);
        if (($config["DBDRIVER"] ?? '') !== 'postgresql') {
            throw new \RuntimeException("PostgreSQL is not configured.");
        }
        $settings = array_filter($config, static fn(string $name): bool => str_starts_with($name, 'SYNTH_DB_'),
            ARRAY_FILTER_USE_KEY);
        foreach ($settings as $name => $value) {
            $GLOBALS[$name] = $value;
        }
        if ($connectTimeoutCap !== null) {
            $configured = intval($settings['SYNTH_DB_CONNECT_TIMEOUT'] ?? 90);
            $settings['SYNTH_DB_CONNECT_TIMEOUT'] = strval($configured > 0 ? min($configured, $connectTimeoutCap) : $connectTimeoutCap);
        }
        $GLOBALS["DBDRIVER"] = 'postgresql';
        require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "postgresql.class.php");
        $GLOBALS["db"] = new sql($settings);
        return $GLOBALS["db"];
    }
}

if (!function_exists('synthRuntimeSchemaMigrationOwner')) {
    // The one migration owner, connected to the legacy runtime session's database.
    function synthRuntimeSchemaMigrationOwner(string $enginePath): \Synth\Core\Doctrine\SchemaMigrations
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $db = $GLOBALS["db"] ?? null;
        if (!is_object($db)) {
            throw new \RuntimeException("SYNTH schema migrations need the legacy runtime session.");
        }
        require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "db_connection_settings.php");
        require_once($enginePath . "core" . DIRECTORY_SEPARATOR . "dbmanager" . DIRECTORY_SEPARATOR . "SchemaMigrations.php");
        return \Synth\Core\Doctrine\SchemaMigrations::connect(
            \Synth\Core\Doctrine\SchemaMigrations::settingsForLegacySession($db, synthDbConnectionSettings('synth')));
    }
}

if (!function_exists('synthRuntimeApplySchemaMigrations')) {
    // Schema changes for the legacy runtime session's database, through the one migration owner and its lock. Used by
    // tools/bootstrap-database.php and by every caller of the debug/db_updates.php compatibility entry.
    function synthRuntimeApplySchemaMigrations(string $enginePath): void
    {
        $migrations = synthRuntimeSchemaMigrationOwner($enginePath);
        try {
            $migrations->migrate();
        } finally {
            $migrations->close();
        }
    }
}

if (!function_exists('synthRuntimeSchemaMigrationStatus')) {
    // Read-only ledger readiness for the legacy runtime session's database (SchemaMigrations::status()): no lock,
    // no version table creation and no migration.
    function synthRuntimeSchemaMigrationStatus(string $enginePath): array
    {
        $migrations = synthRuntimeSchemaMigrationOwner($enginePath);
        try {
            return $migrations->status();
        } finally {
            $migrations->close();
        }
    }
}

if (!function_exists('synthRuntimeEnsureDbUpdates')) {
    // Only tools/bootstrap-database.php asks for updates during bootstrap.
    function synthRuntimeEnsureDbUpdates(string $enginePath): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }

        synthRuntimeApplySchemaMigrations($enginePath);
        // The pinned baseline only seeds the managed settings that existed when it was cut. Insert any managed
        // setting added since (schema default, ON CONFLICT upsert, existing rows untouched) so a newer entry in
        // synthGetManagedGeneralSettingIds() cannot leave a fresh install with pending updates. A setting with
        // no default and no seed fallback stays missing and fails the check below.
        synthSeedMissingManagedGeneralSettings();
        if (synthRuntimeNeedsDbUpdates()) {
            throw new \RuntimeException("SYNTH database bootstrap completed with pending schema updates.");
        }
        $ran = true;
    }
}

if (!function_exists('synthRuntimeApplyBootstrapOptions')) {
    function synthRuntimeApplyBootstrapOptions(string $enginePath, array $options = []): void
    {
        $runDbUpdates = !empty($options['run_db_updates']);
        $loadGeneralSettings = !array_key_exists('load_general_settings', $options) || (bool)$options['load_general_settings'];
        $loadSttConnector = !array_key_exists('load_stt_connector', $options) || (bool)$options['load_stt_connector'];
        $loadIttConnector = !empty($options['load_itt_connector']);
        $loadTtsConnector = $options['load_tts_connector'] ?? false;
        $loadPlayerName = !empty($options['load_player_name']);
        $loadNarrator = !empty($options['load_narrator']);

        if ($runDbUpdates) {
            synthRuntimeEnsureDbUpdates($enginePath);
        }
        if ($loadGeneralSettings) {
            synthLoadGeneralSettingsIntoGlobals();
        }
        if ($loadSttConnector) {
            synthLoadActiveSttConnectorIntoGlobals();
        }
        if ($loadIttConnector) {
            synthLoadActiveIttConnectorIntoGlobals();
        }
        if (is_string($loadTtsConnector) && trim($loadTtsConnector) !== '') {
            synthLoadPreferredTtsConnectorIntoGlobals(trim($loadTtsConnector));
        } elseif ($loadTtsConnector) {
            synthLoadPreferredTtsConnectorIntoGlobals();
        }
        if ($loadPlayerName) {
            synthLoadPlayerNameIntoGlobals();
        }
        if ($loadNarrator) {
            // seed_narrator (default true, false for web GET/HEAD): false reads narrator settings without inserting missing optional rows.
            synthLoadNarratorSettingsIntoGlobals($options['seed_narrator'] ?? !synthRuntimeIsReadOnlyWebRequest());
        }
    }
}

if (!function_exists('synthRuntimeIsReadOnlyWebRequest')) {
    // True for a web GET/HEAD page load (never the CLI), which must not rotate logs or seed settings rows by default.
    function synthRuntimeIsReadOnlyWebRequest(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        return in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')), ['GET', 'HEAD'], true);
    }
}

if (!function_exists('synthRuntimeBootstrap')) {
    function synthRuntimeBootstrap(string $enginePath, array $options = []): void
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $GLOBALS["ENGINE_PATH"] = $enginePath;
        Logger::bootstrapRequestId("rt");
        // rotate_logs (default true, false for web GET/HEAD): viewing a page never renames logs; an explicit option wins.
        if ($options['rotate_logs'] ?? !synthRuntimeIsReadOnlyWebRequest()) {
            Logger::rotateKnownLogs($enginePath);
        }

        $confPath = $enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php";
        $confSamplePath = $enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.sample.php";

        if (file_exists($confSamplePath)) {
            require($confSamplePath);
        }
        if (file_exists($confPath)) {
            require($confPath);
        }

        synthRuntimeImportConfigVariables(get_defined_vars());

        if (empty($GLOBALS["DBDRIVER"])) {
            throw new \RuntimeException("DBDRIVER is not configured during runtime bootstrap.");
        }

        require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
        $needsDbConnection = !isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql);
        if (!$needsDbConnection) {
            try {
                $needsDbConnection = !$GLOBALS["db"]->query("SELECT 1");
            } catch (\Throwable $e) {
                $needsDbConnection = true;
            }
        }
        if ($needsDbConnection && !empty($options['throw_db_connection_errors'])) {
            // Opt-in for setup entries: conf.php was read once above; connect with only its SYNTH_DB_* values so an
            // unreachable PostgreSQL throws instead of the legacy constructor's plaintext exit.
            if ($GLOBALS["DBDRIVER"] !== 'postgresql') {
                throw new \RuntimeException("PostgreSQL is not configured.");
            }
            $settings = [];
            foreach ($GLOBALS as $name => $value) {
                if (is_string($name) && str_starts_with($name, 'SYNTH_DB_')) {
                    $settings[$name] = $value;
                }
            }
            $GLOBALS["db"] = new sql($settings);
        } elseif ($needsDbConnection) {
            $GLOBALS["db"] = new sql();
        }

        synthRuntimeApplyBootstrapOptions($enginePath, $options);
    }
}

if (!function_exists('synthRuntimeBootstrapIfNeeded')) {
    function synthRuntimeBootstrapIfNeeded(string $enginePath, array $options = []): void
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (empty($GLOBALS["DBDRIVER"]) || !isset($GLOBALS["db"]) || !is_object($GLOBALS["db"])) {
            synthRuntimeBootstrap($enginePath, $options);
            return;
        }

        $GLOBALS["ENGINE_PATH"] = $enginePath;
        Logger::bootstrapRequestId("rt");
        if ($options['rotate_logs'] ?? !synthRuntimeIsReadOnlyWebRequest()) {
            Logger::rotateKnownLogs($enginePath);
        }
        synthRuntimeApplyBootstrapOptions($enginePath, $options);
    }
}

if (!function_exists('synthRuntimeSetActiveProfile')) {
    function synthRuntimeSetActiveProfile($profile): ?string
    {
        $profile = trim(strval($profile ?? ''));
        if ($profile === '') {
            unset($GLOBALS["active_profile"]);
            return null;
        }

        $GLOBALS["active_profile"] = $profile;
        return $profile;
    }
}

if (!function_exists('synthRuntimeGetActiveProfile')) {
    function synthRuntimeGetActiveProfile(): ?string
    {
        $profile = trim(strval($GLOBALS["active_profile"] ?? ''));
        return $profile !== '' ? $profile : null;
    }
}

if (!function_exists('synthRuntimeBindActiveProfileFromRequest')) {
    function synthRuntimeBindActiveProfileFromRequest(): ?string
    {
        return synthRuntimeGetActiveProfile();
    }
}

?>

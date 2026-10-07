<?php

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
    function synthRuntimeDatabaseEncodingIsSupported(): bool
    {
        return synthRuntimeDatabaseEncoding() === 'UTF8';
    }
}

if (!function_exists('synthRuntimeDatabaseEncodingError')) {
    function synthRuntimeDatabaseEncodingError(): string
    {
        $encoding = synthRuntimeDatabaseEncoding();
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
            'synth.core_api_badge',
            'synth.core_llm_connector',
            'synth.core_narrator',
            'synth.core_npc_master',
            'synth.core_npc_master_history',
            'synth.core_player',
            'synth.core_profiles',
            'synth.core_stt_connector',
            'synth.core_itt_connector',
            'synth.core_tts_connector',
            'synth.core_tts_pronunciation',
            'synth.database_versioning',
            'synth.descriptions',
            'synth.descriptions_custom',
            'synth.diarylog',
            'synth.eventlog',
            'synth.factions',
            'synth.game_plugins',
            'synth.general_settings',
            'synth.global_settings_presets',
            'synth.import_rules',
            'synth.locations',
            'synth.log',
            'synth.memory',
            'synth.memory_summary',
            'synth.moods_issued',
            'synth.prompts',
            'synth.quests',
            'synth.relationship_eval_queue',
            'synth.relationship_init_queue',
            'synth.responselog',
            'synth.rolemaster',
            'synth.speech',
            'synth.worldknowledge',
            'synth.worldknowledge_catalogs',
            'synth.worldknowledge_audit',
            'synth.worldknowledge_effective',
            'synth.visual_context',
            'synth.combined_bio_templates',
            'synth.combined_core_action',
            'synth.combined_descriptions',
            'synth.synthnpcs',
            'synth.memory_v',
            'synth.actor_identities',
            'synth.bio_template_actor_map',
            'synth_meta.playthrough_profiles',
            'synth_meta.settings',
            'synth_meta.runtime_sessions',
            'synth_meta.request_executions',
            'synth_meta.response_routes',
            'synth_meta.rechat_chains',
            'synth_meta.rechat_continuations',
            'synth_meta.rechat_selections',
            'synth_meta.dialogue_origin_policies',
            'synth_meta.protocol_events',
            'synth_meta.context_snapshots',
            'synth_meta.active_agents',
            'synth_meta.media_objects',
            'synth_meta.action_intents',
            'synth_meta.action_terminal_results',
            'synth_meta.worker_jobs',
            'synth_meta.history_events',
            'synth_meta.memory_batches',
            'synth_meta.memory_batch_sources',
            'synth_meta.memory_summaries',
            'synth_meta.memory_batch_summary_sources',
            'synth_meta.memory_batch_controls',
            'synth_meta.memory_batch_control_events',
            'synth_meta.memory_summary_revisions',
            'synth_meta.memory_batch_rebuilds',
            'synth_meta.diary_control_revisions',
            'synth_meta.middleterm_control_revisions',
            'synth_meta.middleterm_regeneration_approvals',
            'synth_meta.middleterm_replacements',
        ];

        try {
            $relationRows = $db->fetchAll("
                SELECT n.nspname || '.' || c.relname AS relation_name
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relkind IN ('r', 'p', 'v')
                   AND n.nspname IN ('synth', 'synth_meta')
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
                       FROM synth.prompts
                      WHERE prompt_key IN (
                        'synth_system_prompt',
                        'synth_response_rules',
                        'synth_world_prompt',
                        'synth_scene_prompt',
                        'synth_memory_prompt'
                      )) AS prompt_defaults,
                    (SELECT COUNT(*) FROM synth.core_profiles WHERE default_npc = '1') AS npc_defaults,
                    (SELECT COUNT(*) FROM synth.core_profiles WHERE default_narrator = '1') AS narrator_defaults,
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
            'conf_opts' => 20260626001,
            'core_action' => 20260716002,
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
            'core_tts_pronunciation' => 20260829003,
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
            'fallout_worldknowledge_seed' => 20260811002,
            'worldknowledge_parity' => 20260813001,
            'worldknowledge_access' => 20260813001,
            'worldknowledge_canonical_tags' => 20260813003,
            'worldknowledge_oghma_parity' => 20260814001,
            'worldknowledge_herika_v1' => 20260814002,
            'worldknowledge_npc_common_cleanup' => 20260814004,
            'worldknowledge_npc_class_cleanup' => 20260814005,
            'worldknowledge_catalog_integrity' => 20260814006,
            'synth_protocol_schema' => 20260909001,
            'synth_relationship_prompt' => 20260811003,
            'synth_fo4_prompt_examples' => 20260811004,
        ];

        try {
            $versionNames = implode(',', array_map(
                static fn(string $name): string => "'" . str_replace("'", "''", $name) . "'",
                array_keys($requiredVersions)
            ));
            $versionRows = $db->fetchAll(
                "SELECT tablename, version
                 FROM synth.database_versioning
                WHERE tablename IN ({$versionNames})"
            );
        } catch (\Throwable $e) {
            return true;
        }

        $versions = [];
        foreach ($versionRows as $row) {
            $tableName = strval($row['tablename'] ?? '');
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

if (!function_exists('synthRuntimeEnsureDbUpdates')) {
    function synthRuntimeEnsureDbUpdates(string $enginePath): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        if (!synthRuntimeNeedsDbUpdates()) {
            $ran = true;
            return;
        }

        $updatesPath = $enginePath . "debug" . DIRECTORY_SEPARATOR . "db_updates.php";
        $db=$GLOBALS["db"] ?? null;
        if (!file_exists($updatesPath)) {
            throw new \RuntimeException("SYNTH database update file is missing: {$updatesPath}");
        }

        require($updatesPath);
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
            synthLoadNarratorSettingsIntoGlobals();
        }
    }
}

if (!function_exists('synthRuntimeBootstrap')) {
    function synthRuntimeBootstrap(string $enginePath, array $options = []): void
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $GLOBALS["ENGINE_PATH"] = $enginePath;
        Logger::bootstrapRequestId("rt");
        Logger::rotateKnownLogs($enginePath);

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
        if ($needsDbConnection) {
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
        Logger::rotateKnownLogs($enginePath);
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

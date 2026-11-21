<?php

// Functions that used to be defined inside core/dbmanager/Legacy/{llm_connector,narrator,npc_master}.class.php.
// Callers use them without touching those classes, so they are loaded with Composer's `files` autoload (see
// composer.json) and always exist. Declarations only: every function is guarded and nothing runs at load time.
// Functions that need the protocol or identity modules load them lazily on first call.

if (!function_exists('synthConnectorExtraParametersEnabled')) {
    function synthConnectorExtraParametersEnabled(array $connector): bool
    {
        if (!array_key_exists('extra_parameters_enabled', $connector)) {
            return true;
        }

        $value = $connector['extra_parameters_enabled'];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int)$value) !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'off' || $normalized === 'no') {
                return false;
            }

            return true;
        }

        return (bool)$value;
    }
}

if (!function_exists('synthGetEnabledConnectorExtraParameters')) {
    function synthGetEnabledConnectorExtraParameters(array $connector): array
    {
        if (!synthConnectorExtraParametersEnabled($connector)) {
            return [];
        }

        if (!isset($connector['extra_parameters']) || !is_array($connector['extra_parameters'])) {
            return [];
        }

        return $connector['extra_parameters'];
    }
}

if (!function_exists('synthGetNarratorRoleplayName')) {
    function synthGetNarratorRoleplayName(): string
    {
        try {
            return Narrator::normalizeRoleplayName(\Synth\Bootstrap::config('NARRATOR_ROLEPLAY_NAME') ?? Narrator::DEFAULT_ROLEPLAY_NAME);
        } catch (\InvalidArgumentException $e) {
            return Narrator::DEFAULT_ROLEPLAY_NAME;
        }
    }
}

if (!function_exists('synthGetNarratorDisplayNameHeaderValue')) {
    function synthGetNarratorDisplayNameHeaderValue(): string
    {
        return base64_encode(synthGetNarratorRoleplayName());
    }
}

if (!function_exists('synthBuildNarratorContextLine')) {
    function synthBuildNarratorContextLine($text): string
    {
        return synthGetNarratorRoleplayName() . ': ' . ltrim((string)$text);
    }
}

if (!function_exists('synthGetPromptCharacterName')) {
    function synthGetPromptCharacterName(): string
    {
        require_once __DIR__.'/synth_protocol_state.php';
        $canonicalName = trim((string)(\Synth\Bootstrap::config('SYNTH_NAME') ?? ''));
        $role=synth_protocol_owned_response_role();
        if ($role==='narrator') return synthGetNarratorRoleplayName();
        if ($role==='npc') {
            $event=\Synth\Bootstrap::requestEvent()['json'];
            $db=\Synth\Bootstrap::db();
            // Ordinary dialogue needs its exact snapshot; only prepared rechat resolves without one here.
            $context=synth_protocol_owns_rechat($event) ? [] : (synth_protocol_current_context($db,$event) ?? []);
            $actor=synth_protocol_response_actor_identity($db,$event,$context);
            $name=trim(strval($actor['display_name'] ?? ''));
            if ($name==='') throw new LogicException('prompt_response_owner_unavailable');
            return $name;
        }
        if ($canonicalName !== '' && strcasecmp($canonicalName, Narrator::CANONICAL_NAME) !== 0) {
            return $canonicalName;
        }

        return synthGetNarratorRoleplayName();
    }
}

if (!function_exists('synthRenderNarratorRoleplayText')) {
    function synthRenderNarratorRoleplayText($text): string
    {
        require_once __DIR__.'/synth_protocol_state.php';
        $text = (string)$text;
        // Untyped quoted text can mention a real NPC with this name; never rewrite owned conversation evidence.
        if (synth_protocol_owns_rechat(\Synth\Bootstrap::requestEvent()['json'] ?? [])) return $text;
        $roleplayName = synthGetNarratorRoleplayName();
        if (strcasecmp($roleplayName, Narrator::CANONICAL_NAME) === 0) {
            return $text;
        }

        return str_ireplace(Narrator::CANONICAL_NAME, $roleplayName, $text);
    }
}

if (!function_exists('synthRenderNarratorContextText')) {
    function synthRenderNarratorContextText($text): string
    {
        return synthRenderNarratorRoleplayText($text);
    }
}

if (!function_exists('synthApplyNarratorRoleplayNameToContext')) {
    function synthApplyNarratorRoleplayNameToContext(array $messages): array
    {
        foreach ($messages as &$message) {
            if (is_array($message) && array_key_exists('content', $message) && is_string($message['content'])) {
                $message['content'] = synthRenderNarratorContextText($message['content']);
            }
        }
        unset($message);

        return $messages;
    }
}

if (!function_exists('synthNormalizeNarratorRoleplayActorName')) {
    function synthNormalizeNarratorRoleplayActorName($name): string
    {
        require_once __DIR__.'/synth_protocol_state.php';
        $name = trim((string)$name);
        // Candidate normalization runs before selection: labels cannot decide the future responder's role.
        if (synth_protocol_owns_rechat(\Synth\Bootstrap::requestEvent()['json'] ?? [])) return $name;
        $roleplayName = synthGetNarratorRoleplayName();
        if ($name !== '' && strcasecmp($name, $roleplayName) === 0) {
            return Narrator::CANONICAL_NAME;
        }

        return $name;
    }
}

if (!function_exists('synthRolemasterStateToBool')) {
    function synthRolemasterStateToBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        $text = strtolower(trim(strval($value)));
        if ($text === '' || $text === '0' || $text === 'false' || $text === 'f' || $text === 'no' || $text === 'off' || $text === 'null') {
            return false;
        }

        return in_array($text, ['1', 'true', 't', 'yes', 'on'], true);
    }
}

if (!function_exists('synthRolemasterStateResetCache')) {
    function synthRolemasterStateResetCache()
    {
        \Synth\Bootstrap::forget('SYNTH_ROLEMASTER_LOOKUP_CACHE');
    }
}

if (!function_exists('synthResolveNpcRolemasterState')) {
    function synthResolveNpcRolemasterState($npcName = '', array $options = [])
    {
        $npcName = trim(strval($npcName));
        if ($npcName === '') {
            $npcName = trim(strval(\Synth\Bootstrap::config('SYNTH_NAME') ?? ''));
        }

        $useGlobal = !array_key_exists('use_global', $options) || !empty($options['use_global']);
        if ($useGlobal && synthRolemasterStateToBool(\Synth\Bootstrap::config('is_rolemastered') ?? false)) {
            return true;
        }

        $npcData = is_array($options['npc_data'] ?? null) ? $options['npc_data'] : null;
        if (is_array($npcData) && synthRolemasterStateToBool($npcData['is_rolemastered'] ?? false)) {
            return true;
        }

        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : null;
        $extended = is_array($options['extended'] ?? null) ? $options['extended'] : null;
        $loadLookup = !array_key_exists('load_lookup', $options) || !empty($options['load_lookup']);

        if (($metadata === null || $extended === null) && $loadLookup && $npcName !== '' && class_exists('NpcMaster')) {
            if (!\Synth\Bootstrap::has('SYNTH_ROLEMASTER_LOOKUP_CACHE') || !is_array(\Synth\Bootstrap::config('SYNTH_ROLEMASTER_LOOKUP_CACHE'))) {
                \Synth\Bootstrap::set('SYNTH_ROLEMASTER_LOOKUP_CACHE', []);
            }

            if (!array_key_exists($npcName, \Synth\Bootstrap::config('SYNTH_ROLEMASTER_LOOKUP_CACHE'))) {
                $lookup = [
                    'npc_data' => [],
                    'metadata' => [],
                    'extended' => [],
                ];

                $npcMaster = new NpcMaster();
                $lookupNpcData = $npcMaster->getByName($npcName);
                if (is_array($lookupNpcData) && count($lookupNpcData) > 0) {
                    $lookup['npc_data'] = $lookupNpcData;
                    $lookup['metadata'] = $npcMaster->getMetadata($lookupNpcData);
                    $lookup['extended'] = $npcMaster->getExtendedData($lookupNpcData);
                }

                \Synth\Bootstrap::setAt('SYNTH_ROLEMASTER_LOOKUP_CACHE', $npcName, $lookup);
            }

            $lookup = \Synth\Bootstrap::config('SYNTH_ROLEMASTER_LOOKUP_CACHE')[$npcName];
            if ($npcData === null && is_array($lookup['npc_data'])) {
                $npcData = $lookup['npc_data'];
            }
            if ($metadata === null && is_array($lookup['metadata'])) {
                $metadata = $lookup['metadata'];
            }
            if ($extended === null && is_array($lookup['extended'])) {
                $extended = $lookup['extended'];
            }
        }

        if (is_array($npcData) && synthRolemasterStateToBool($npcData['is_rolemastered'] ?? false)) {
            return true;
        }
        if (is_array($metadata) && synthRolemasterStateToBool($metadata['is_rolemastered'] ?? false)) {
            return true;
        }
        if (is_array($extended) && synthRolemasterStateToBool($extended['is_rolemastered'] ?? false)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('synthRelationshipTimelineState')) {
    function synthRelationshipTimelineState($extendedData)
    {
        if (is_string($extendedData) && trim($extendedData) !== '') {
            $extendedData = json_decode($extendedData, true);
        }
        if (!is_array($extendedData)) {
            return null;
        }

        return ['relationships' => $extendedData['relationships'] ?? []];
    }
}

if (!function_exists('synthRelationshipRestoreQuery')) {
    function synthRelationshipRestoreQuery($timestamp, $schema = 'synth')
    {
        if (!is_numeric($timestamp) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$schema)) {
            throw new InvalidArgumentException('Invalid relationship restore query input.');
        }
        $timestamp = (float)$timestamp;

        return "WITH restore AS (
            SELECT c.id AS npc_id, h.extended_data
            FROM public.core_npc c
            JOIN LATERAL (
                SELECT h.extended_data
                FROM public.core_npc_history h
                WHERE h.npc_id = c.id
                  AND (h.gamets_last_updated <= {$timestamp} OR h.gamets_last_updated IS NULL)
                ORDER BY
                    h.gamets_last_updated DESC NULLS LAST,
                    CASE
                        WHEN h.extended_data ->> '_synth_history_source' LIKE 'relationship%' THEN 2
                        WHEN h.extended_data ->> '_synth_history_source' = 'infosave' THEN 1
                        ELSE 0
                    END DESC,
                    h.created DESC,
                    h.history_id DESC
                LIMIT 1
            ) h ON true
            WHERE c.npc_name <> 'The Narrator' AND c.actor_identity_id IS NULL
              AND NOT COALESCE((c.extended_data->>'relationships_locked')::boolean, false)
        ),
        updated AS (
            UPDATE public.core_npc c
            SET extended_data = (
                COALESCE(c.extended_data, '{}'::jsonb)
                - 'relationships' - 'relationships_analyzed' - 'relationships_inferred'
                - 'relationships_last_eval' - 'relationships_model' - 'relationships_updated'
                - '_synth_history_source'
            ) || jsonb_strip_nulls(jsonb_build_object(
                'relationships', restore.extended_data -> 'relationships',
                'relationships_analyzed', restore.extended_data -> 'relationships_analyzed',
                'relationships_inferred', restore.extended_data -> 'relationships_inferred',
                'relationships_last_eval', restore.extended_data -> 'relationships_last_eval',
                'relationships_model', restore.extended_data -> 'relationships_model',
                'relationships_updated', restore.extended_data -> 'relationships_updated'
            ))
            FROM restore
            WHERE c.id = restore.npc_id
            RETURNING c.id
        )
        SELECT COUNT(*)::int AS affected FROM updated";
    }
}

if (!function_exists('synthRelationshipFutureClearQuery')) {
    function synthRelationshipFutureClearQuery($timestamp, $schema = 'synth')
    {
        if (!is_numeric($timestamp) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$schema)) {
            throw new InvalidArgumentException('Invalid relationship clear query input.');
        }
        $timestamp = (float)$timestamp;

        return "WITH cleared AS (
            UPDATE public.core_npc AS c
            SET extended_data = c.extended_data
                - 'relationships' - 'relationships_analyzed' - 'relationships_inferred'
                - 'relationships_last_eval' - 'relationships_model' - 'relationships_updated'
                - '_synth_history_source'
            WHERE c.npc_name <> 'The Narrator' AND c.actor_identity_id IS NULL
              AND (c.gamets_last_updated > {$timestamp} OR c.gamets_last_updated IS NULL)
              AND c.extended_data IS NOT NULL
              AND c.extended_data ? 'relationships'
              AND NOT COALESCE((c.extended_data->>'relationships_locked')::boolean, false)
              AND NOT EXISTS (
                  SELECT 1 FROM public.core_npc_history h
                  WHERE h.npc_id = c.id
                    AND (h.gamets_last_updated <= {$timestamp} OR h.gamets_last_updated IS NULL)
                    AND h.extended_data IS NOT NULL
                    AND h.extended_data ? 'relationships'
              )
            RETURNING c.npc_name
        ), sample AS (
            SELECT npc_name FROM cleared ORDER BY npc_name LIMIT 10
        )
        SELECT (SELECT COUNT(*)::int FROM cleared) AS affected,
               COALESCE((SELECT string_agg(npc_name, ', ') FROM sample), '') AS sample_names";
    }
}

if (!function_exists('synthRelationshipTimelineStamp')) {
    // Snapshot each changed relationship state; identical state is deduplicated without a wall-clock throttle.
    function synthRelationshipTimelineStamp($npcId, $source = 'relationship')
    {
        require_once __DIR__.'/synth_npc_identity.php';
        require_once __DIR__.'/utils_game_timestamp.php';
        try {
            $npcId = (int)$npcId;
            if ($npcId <= 0 || !\Synth\Bootstrap::has('db')) {
                return false;
            }
            // Native relationship/profile revisions use their owned history writers, not the legacy timestamp lane.
            if (synth_npc_native_request()) return false;
            if (isset(\Synth\Bootstrap::requestEvent()['json']['protocol_version'])) {
                $profile=\Synth\Bootstrap::db()->fetchOne('SELECT actor_identity_id FROM core_npc WHERE id='.$npcId);
                if (!empty($profile['actor_identity_id'])) return false;
            }

            $gamets = 0;
            if (isset(\Synth\Bootstrap::gameRequest()[2]) && is_numeric(\Synth\Bootstrap::gameRequest()[2])) {
                $gamets = (float)\Synth\Bootstrap::gameRequest()[2];
            } elseif (function_exists('DataLastKnownGameTS')) {
                $gamets = (float)DataLastKnownGameTS();
            } else {
                $row = \Synth\Bootstrap::db()->fetchOne("SELECT MAX(gamets) AS gamets FROM core_context_event WHERE type = 'infosave'");
                $gamets = (float)($row['gamets'] ?? 0);
            }

            if ($gamets > 0 && \Synth\Bootstrap::db()->execQuery(
                "UPDATE core_npc SET gamets_last_updated = {$gamets} WHERE id = {$npcId}"
            ) === false) {
                throw new RuntimeException('failed to stamp game timestamp');
            }

            $current = \Synth\Bootstrap::db()->fetchOne(
                "SELECT extended_data FROM core_npc WHERE id = {$npcId} LIMIT 1"
            );
            if (!$current) {
                throw new RuntimeException('NPC row not found after relationship write');
            }
            $currentState = synthRelationshipTimelineState($current['extended_data'] ?? null);
            if ($currentState === null) {
                throw new RuntimeException('live extended_data is not valid relationship JSON');
            }

            $eligibleClause = $gamets > 0
                ? "AND (gamets_last_updated <= {$gamets} OR gamets_last_updated IS NULL)"
                : '';
            $historyQuery = "SELECT extended_data
                FROM core_npc_history
                WHERE npc_id = {$npcId} {$eligibleClause}
                ORDER BY gamets_last_updated DESC NULLS LAST,
                    CASE
                        WHEN extended_data ->> '_synth_history_source' LIKE 'relationship%' THEN 2
                        WHEN extended_data ->> '_synth_history_source' = 'infosave' THEN 1
                        ELSE 0
                    END DESC,
                    created DESC, history_id DESC
                LIMIT 1";
            $history = \Synth\Bootstrap::db()->fetchOne($historyQuery);
            $historyState = $history ? synthRelationshipTimelineState($history['extended_data'] ?? null) : null;
            if ($history && $historyState !== null && $currentState == $historyState) {
                \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'REL', "Timeline snapshot skipped for npc_id {$npcId} (relationship state unchanged)");
                return true;
            }

            $normalizedSource = strtolower(trim((string)$source));
            $normalizedSource = preg_replace('/[^a-z0-9_-]+/', '_', $normalizedSource);
            if ($normalizedSource === '' || strpos($normalizedSource, 'relationship') !== 0) {
                $normalizedSource = 'relationship';
            }

            $npcMaster = new NpcMaster();
            if (!$npcMaster->backupNpcById($npcId, $normalizedSource)) {
                throw new RuntimeException('relationship history insert failed');
            }
            $verified = \Synth\Bootstrap::db()->fetchOne($historyQuery);
            $verifiedState = $verified ? synthRelationshipTimelineState($verified['extended_data'] ?? null) : null;
            if ($verifiedState === null || $currentState != $verifiedState) {
                throw new RuntimeException('relationship history snapshot verification failed');
            }

            \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'REL', "Timeline snapshot for npc_id {$npcId} ({$normalizedSource})");
            return true;
        } catch (Throwable $e) {
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'REL', "Timeline stamp failed for npc_id " . (int)$npcId . ": " . $e->getMessage());
            return false;
        }
    }
}

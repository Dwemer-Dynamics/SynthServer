<?php

require_once(__DIR__."/utils.php");
// used for openai_token_count table

require_once(__DIR__."/utils_game_timestamp.php");
require_once(__DIR__."/lazy_xml.php");
require_once(__DIR__."/model_dynmodel.php");
require_once(__DIR__."/emote_moods.php");
require_once(__DIR__."/core/activity_status.php");
require_once(__DIR__."/core/game_plugins.php");
require_once(__DIR__."/core/npc_master.class.php");
require_once(__DIR__."/core/core_profiles.class.php");
require_once(__DIR__."/prompt_injections.php");
require_once(__DIR__."/memory_ranking.php");
require_once(__DIR__."/synth_protocol_state.php");
require_once(__DIR__."/synth_memory_recall.php");

// Function groups moved out of this file.
require_once dirname(__DIR__).'/managers/CharacterManager/Native/data_identity.php';
require_once dirname(__DIR__).'/managers/PromptManager/Native/data_item_prompts.php';
require_once dirname(__DIR__).'/managers/ContextManager/Native/data_world_state.php';
require_once dirname(__DIR__).'/managers/ContextManager/Native/data_history.php';
require_once dirname(__DIR__).'/managers/ContextManager/Native/data_location.php';
require_once dirname(__DIR__).'/managers/ContextManager/Native/data_nearby.php';
require_once dirname(__DIR__).'/managers/MemoryManager/Native/data_memory.php';
require_once dirname(__DIR__).'/drivers/llm/Native/data_llm_calls.php';
require_once dirname(__DIR__).'/managers/MemoryManager/Native/data_first_met.php';
require_once dirname(__DIR__).'/managers/CharacterManager/Native/data_profile_helpers.php';


if (!function_exists('synthAppendDiaryConnectorCandidate')) {
    function synthAppendDiaryConnectorCandidate(array &$candidates, string $candidate): void
    {
        if (str_contains($candidate, "\0")) return;
        $normalized = strtolower(trim($candidate));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $normalized) || $normalized === 'array') {
            return;
        }

        switch ($normalized) {
            case 'openrouter':
                $normalized = 'openrouterjson';
                break;
            case 'openai':
                $normalized = 'openaijson';
                break;
            case 'koboldcpp':
                $normalized = 'koboldcppjson';
                break;
        }

        if (!in_array($normalized, $candidates, true)) {
            $candidates[] = $normalized;
        }
    }
}

if (!function_exists('synthExtractDiaryConnectorCandidates')) {
    function synthExtractDiaryConnectorCandidates($value): array
    {
        $candidates = [];

        $pushValue = function ($candidate) use (&$candidates, &$pushValue): void {
            if (is_array($candidate)) {
                foreach ($candidate as $nestedCandidate) {
                    $pushValue($nestedCandidate);
                }
                return;
            }

            if (!is_scalar($candidate)) {
                return;
            }

            if (str_contains((string)$candidate, "\0")) return;
            $stringValue = trim((string)$candidate);
            if ($stringValue === '') {
                return;
            }

            if (($stringValue[0] === '[' || $stringValue[0] === '{')) {
                $decoded = json_decode($stringValue, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $decodedCandidate) {
                        $pushValue($decodedCandidate);
                    }
                    return;
                }
            }

            if (strpos($stringValue, ',') !== false) {
                foreach (explode(',', $stringValue) as $splitCandidate) {
                    $pushValue($splitCandidate);
                }
                return;
            }

            synthAppendDiaryConnectorCandidate($candidates, $stringValue);
        };

        $pushValue($value);

        return $candidates;
    }
}

if (!function_exists('synthResolveDiaryConnectorName')) {
    function synthResolveDiaryConnectorName($rawValue = null, bool $persistGlobal = true): ?string
    {
        $connectorDir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "connector" . DIRECTORY_SEPARATOR;
        $sourceValue = func_num_args() > 0 ? $rawValue : (\Synth\Bootstrap::config("CONNECTORS_DIARY") ?? null);

        $candidates = synthExtractDiaryConnectorCandidates($sourceValue);
        foreach (synthExtractDiaryConnectorCandidates(\Synth\Bootstrap::config("CONNECTORS") ?? null) as $candidate) {
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        foreach (['openrouterjson', 'openaijson', 'google_openaijson', 'koboldcppjson'] as $fallbackCandidate) {
            if (!in_array($fallbackCandidate, $candidates, true)) {
                $candidates[] = $fallbackCandidate;
            }
        }

        foreach ($candidates as $candidate) {
            // Resolve supported providers, never connector-directory helpers or paths supplied as configuration.
            if (in_array($candidate, ['openrouterjson','openaijson','google_openaijson','koboldcppjson','groqjson','player2json'], true)
                && is_file($connectorDir . $candidate . ".php")) {
                if ($persistGlobal) {
                    $originalValue = is_scalar($sourceValue) ? trim((string)$sourceValue) : json_encode($sourceValue);
                    if ($originalValue !== $candidate) {
                        Logger::warn("DIARY: Resolved configured diary provider to '{$candidate}'");
                    }
                    \Synth\Bootstrap::set('CONNECTORS_DIARY', $candidate);
                }
                return $candidate;
            }
        }

        return null;
    }
}

if (function_exists('synthRegisterActorProfileEnricher')) {
    synthRegisterActorProfileEnricher(
        'synth.player_survival',
        'synthPlayerSurvivalProfileEnricher',
        55
    );
}

if (!function_exists('synthFilterRechatHistorySinceLatestInput')) {
    function synthFilterRechatHistorySinceLatestInput(array $historyRows)
    {
        $chainRows = [];

        foreach ($historyRows as $row) {
            $eventType = strtolower(trim((string)($row['type'] ?? '')));
            if ($eventType === '') {
                continue;
            }

            if (in_array($eventType, ['inputtext', 'inputtext_s', 'narrator_inputtext'], true)) {
                // A fresh player turn must reset the rechat chain. The first rechat after player input
                // should therefore start at round 0 instead of inheriting an older chain budget.
                $chainRows = [];
                continue;
            }

            if (in_array($eventType, ['rechat', 'narration'], true)) {
                $chainRows[] = $row;
            }
        }

        return $chainRows;
    }
}


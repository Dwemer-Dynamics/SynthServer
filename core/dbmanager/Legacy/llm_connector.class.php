<?php

require_once dirname(__DIR__, 3).'/lib/autoload.php';

class LLMConnector
{

    private $table = "core_llm_connector";
    private static $quickstartTransportDefaults = null;

    private function normalizeConnectorUrlValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        return $trimmed === "" ? null : $trimmed;
    }

    private function normalizeConnectorRecord(array $data, bool $forWrite = false): array
    {
        if (array_key_exists("url", $data)) {
            $normalizedUrl = $this->normalizeConnectorUrlValue($data["url"]);
            if (!$forWrite && $normalizedUrl === null) {
                $normalizedUrl = "";
            }
            $data["url"] = $normalizedUrl;
        }

        // Rows read from the database show the key as one reference; data headed for it becomes the two typed columns.
        return $forWrite ? \Synth\Core\Contracts\ApiKeyRef::columnsFromData($data) : \Synth\Core\Contracts\ApiKeyRef::decorateRow($data);
    }

    private function normalizeConnectorRecords(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = $this->normalizeConnectorRecord($row);
            }
        }

        return $rows;
    }

    // The key a connector uses: the key it names (a missing or deleted one is no key, never another), else the global key of its
    // service (openrouter, openai, google, groq, nanogpt). A SYNTH-only key is used only when the connector names it.
    private function getApiKeyForBadge($apiBadgeId, ?array $connector = null): string
    {
        $row = is_array($connector) ? $connector : [];
        if ($apiBadgeId !== null) {
            $row['api_key_id'] = $apiBadgeId;
        }
        $service = strtolower(trim(strval($row['service'] ?? '')));
        $label = ['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'google' => 'Google', 'groq' => 'Groq', 'nanogpt' => 'Nano-GPT'][$service] ?? '';
        return ApiBadge::keyForConnector($row, $label, strval($row['url'] ?? ''));
    }
    public function create($data)
    {
        $fields = [
            "label",
            "metadata",
            "url",
            "model",
            "provider",
            "driver",
            "service",
            "reasoning_model",
            "max_tokens",
            "enforce_json",
            "prefill_json",
            "api_key_id",
            "local_api_key_id",
            "json_schema",
            "temperature",
            "presence_penalty",
            "frequency_penalty",
            "repetition_penalty",
            "top_p",
            "top_k",
            "min_p",
            "top_a"
        ];

        $data = $this->normalizeConnectorRecord($data, true);

        foreach ($data as $k => $v) {
            if (($v === "" || $v === null) && $v !== "0" && $v !== false && $v !== 0) {
                $data[$k] = null;
            }
        }

        $filtered = array_intersect_key($data, array_flip($fields));
        return ApiBadge::lockedWrite($filtered['api_key_id'] ?? null, fn() => \Synth\Bootstrap::db()->insertReturningId($this->table, $filtered), 0);
    }

    public function readAll()
    {
        $query = "SELECT * FROM {$this->table} ORDER BY LOWER(COALESCE(NULLIF(label,''), model)) ASC";
        return $this->normalizeConnectorRecords(\Synth\Bootstrap::db()->fetchAll($query));
    }

    // Url and model only (never a key or metadata) of every connector that names both: one query for the health check.
    public function endpointRows()
    {
        return \Synth\Bootstrap::db()->fetchAll("SELECT url, model FROM {$this->table} WHERE btrim(COALESCE(url, '')) <> '' AND btrim(COALESCE(model, '')) <> ''");
    }

    public function readOne($id)
    {
        $id = intval($id);
        $query = "SELECT * FROM {$this->table} WHERE id = {$id} LIMIT 1";
        $data = \Synth\Bootstrap::db()->fetchOne($query);

        if (!is_array($data)) {
            return $data;
        }

        return $this->normalizeConnectorRecord($data);
    }

    public function getById($id)
    {
        return $this->readOne($id);
    }

    public function update($id, $data)
    {
        $fields = [
            "label",
            "metadata",
            "url",
            "model",
            "provider",
            "driver",
            "service",
            "reasoning_model",
            "max_tokens",
            "enforce_json",
            "prefill_json",
            "api_key_id",
            "local_api_key_id",
            "json_schema",
            "temperature",
            "presence_penalty",
            "frequency_penalty",
            "repetition_penalty",
            "top_p",
            "top_k",
            "min_p",
            "top_a"
        ];

        $data = $this->normalizeConnectorRecord($data, true);

        foreach ($data as $k => $v) {
            if (($v === "" || $v === null) && $v !== "0" && $v !== false && $v !== 0) {
                $data[$k] = null;
            }
        }

        $id = intval($id);
        $where = "id = {$id}";
        $filtered = array_intersect_key($data, array_flip($fields));
        return ApiBadge::lockedWrite($filtered['api_key_id'] ?? null, fn() => \Synth\Bootstrap::db()->updateRow($this->table, $filtered, $where), false);
    }

    public function delete($id)
    {
        $id = intval($id);
        return \Synth\Bootstrap::db()->delete($this->table, "id = {$id}");
    }

    public function truncate($restart = false, $cascade = false)
    {
        return \Synth\Bootstrap::db()->truncate($this->table, $restart, $cascade);
    }

    public function getLastError()
    {
        return \Synth\Bootstrap::db()->GetLastError();
    }

    public function getDriver($id)
    {
        $driver = $this->readOne($id)["driver"];
        return new $driver();
    }

    public function getAllFk($fieldName)
    {
        // The API key list is the only supported foreign key.
        if ($fieldName !== "api_key_id") {
            return []; // Unknown FK
        }

        return (new \Synth\Core\ApiBadgeRepository(\Synth\Bootstrap::db()))->labels('lower_label');
    }

    public function clone($id)
    {
        $id = intval($id);
        $original = $this->readOne($id);

        if (!$original) {
            return false; // Original record not found
        }

        unset($original['id']); // Remove the ID to create a new record
        $original['label'] = $original['label'] . ' (Copy)'; // Modify label to indicate it's a clone

        return $this->create($original);
    }

    // Explicit OpenAI-compatible config for the native typed turn; never touches \Synth\Bootstrap::config("CONNECTOR").
    public function dialogueProviderConfig(array $row): array
    {
        $row = $this->normalizeConnectorRecord($row);
        $url = trim(strval($row['url'] ?? ''));
        $model = trim(strval($row['model'] ?? ''));
        if ($url === '' || $model === '') throw new DomainException('provider_unsupported');
        return [
            'url' => $url,
            'model' => $model,
            'api_key' => $this->getApiKeyForBadge($row['api_key_id'] ?? null, $row),
            'max_tokens' => intval($row['max_tokens'] ?? 1024),
            'temperature' => floatval($row['temperature'] ?? 1.0),
            'top_p' => floatval($row['top_p'] ?? 1.0),
            'presence_penalty' => floatval($row['presence_penalty'] ?? 0.0),
            'frequency_penalty' => floatval($row['frequency_penalty'] ?? 0.0),
        ];
    }

    public function setOldGlobals($currentConnectorData)
    {
        if (is_array($currentConnectorData)) {
            $currentConnectorData = $this->normalizeConnectorRecord($currentConnectorData);
        }

        if ($currentConnectorData["driver"] == "openaijson") {

            $metadata = json_decode($currentConnectorData['metadata'] ?? '{}', true);
            $metadata = is_array($metadata) ? $metadata : [];
            $quickstartManaged = filter_var($metadata['quickstart_managed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // Restore the prior request defaults after any sequence of managed connectors.
            // The next connector's explicit metadata is applied below and takes precedence.
            if ($quickstartManaged && self::$quickstartTransportDefaults === null) {
                self::$quickstartTransportDefaults = array_intersect_key(
                    \Synth\Bootstrap::config("CONNECTOR")["openaijson"] ?? [],
                    array_flip(['disable_streaming', 'lmstudio_compat'])
                );
            } elseif (!$quickstartManaged && self::$quickstartTransportDefaults !== null) {
                foreach (['disable_streaming', 'lmstudio_compat'] as $key) {
                    \Synth\Bootstrap::unsetPath('CONNECTOR', ["openaijson", $key]);
                    if (array_key_exists($key, self::$quickstartTransportDefaults)) {
                        \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", $key], self::$quickstartTransportDefaults[$key]);
                    }
                }
                self::$quickstartTransportDefaults = null;
            }
            \Synth\Bootstrap::unsetPath('CONNECTOR', ["openaijson", "quickstart_managed"]); \Synth\Bootstrap::unsetPath('CONNECTOR', ["openaijson", "quickstart_timeout"]);

            $apiKey = $this->getApiKeyForBadge($currentConnectorData["api_key_id"] ?? null, $currentConnectorData);
            // error_log("[CORE SYSTEM] Using new profile system CONNECTOR openaijson {$currentConnectorData["id"]} {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}");
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "url"], $currentConnectorData["url"] ?? 'https://openrouter.ai/api/v1/chat/completions');
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "model"], $currentConnectorData["model"] ?? 'google/gemini-2.5-flash');

            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "reasoning_model"], $currentConnectorData["reasoning_model"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "max_tokens"], $currentConnectorData["max_tokens"] ?? '1024');
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "temperature"], $currentConnectorData["temperature"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "presence_penalty"], $currentConnectorData["presence_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "frequency_penalty"], $currentConnectorData["frequency_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "repetition_penalty"], $currentConnectorData["repetition_penalty"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "top_p"], $currentConnectorData["top_p"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "top_k"], $currentConnectorData["top_k"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "min_p"], $currentConnectorData["min_p"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "top_a"], $currentConnectorData["top_a"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "ENFORCE_JSON"], $currentConnectorData["enforce_json"] ?? true);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "PREFILL_JSON"], $currentConnectorData["prefill_json"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "API_KEY"], $apiKey);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", "json_schema"], $currentConnectorData["json_schema"] ?? false);

            // Apply this connector's metadata after restoring transport defaults.
            foreach ($metadata as $key => $value) {
                \Synth\Bootstrap::setPath('CONNECTOR', ["openaijson", $key], $value);
            }

        } else if ($currentConnectorData["driver"] == "koboldcppjson") {
            // Keep the selected core record authoritative; otherwise workers silently use the global fallback endpoint.
            $settings = \Synth\Bootstrap::config('CONNECTOR')['koboldcppjson'] ?? [];
            $settings['url'] = strval($currentConnectorData['url'] ?? '');
            foreach (['max_tokens','temperature','top_p','top_k','min_p'] as $key) {
                if (isset($currentConnectorData[$key])) $settings[$key] = $currentConnectorData[$key];
            }
            if (isset($currentConnectorData['repetition_penalty'])) $settings['rep_pen'] = $currentConnectorData['repetition_penalty'];
            $settings['API_KEY'] = $this->getApiKeyForBadge($currentConnectorData['api_key_id'] ?? null, $currentConnectorData);
            $metadata = json_decode($currentConnectorData['metadata'] ?? '{}', true);
            \Synth\Bootstrap::setAt('CONNECTOR', 'koboldcppjson', array_replace($settings, is_array($metadata) ? $metadata : []));

        } else if ($currentConnectorData["driver"] == "openrouterjson") {

            $apiKey = $this->getApiKeyForBadge($currentConnectorData["api_key_id"] ?? null, $currentConnectorData);
            // error_log("[CORE SYSTEM] Using new profile system CONNECTOR openaijson {$currentConnectorData["id"]} {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}");
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "url"], $currentConnectorData["url"] ?? 'https://openrouter.ai/api/v1/chat/completions');
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "model"], $currentConnectorData["model"] ?? 'z-ai/glm-4.7');
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "PROVIDER"], $currentConnectorData["provider"] ?? '');
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "reasoning_model"], $currentConnectorData["reasoning_model"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "max_tokens"], $currentConnectorData["max_tokens"] ?? '1024');
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "temperature"], $currentConnectorData["temperature"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "presence_penalty"], $currentConnectorData["presence_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "frequency_penalty"], $currentConnectorData["frequency_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "repetition_penalty"], $currentConnectorData["repetition_penalty"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "top_p"], $currentConnectorData["top_p"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "top_k"], $currentConnectorData["top_k"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "min_p"], $currentConnectorData["min_p"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "top_a"], $currentConnectorData["top_a"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "ENFORCE_JSON"], $currentConnectorData["enforce_json"] ?? true);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "PREFILL_JSON"], $currentConnectorData["prefill_json"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "API_KEY"], $apiKey);
            \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", "json_schema"], $currentConnectorData["json_schema"] ?? false);

            // Decode metadata and extended_data if available
            $metadata = json_decode($currentConnectorData['metadata'] ?? '{}', true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    \Synth\Bootstrap::setPath('CONNECTOR', ["openrouterjson", $key], $value);
                }
            }

        } else if ($currentConnectorData["driver"] == "google_openaijson") {

            $apiKey = $this->getApiKeyForBadge($currentConnectorData["api_key_id"] ?? null, $currentConnectorData);
            // error_log("[CORE SYSTEM] Using new profile system CONNECTOR openaijson {$currentConnectorData["id"]} {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}");
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "url"], $currentConnectorData["url"] ?? 'https://openrouter.ai/api/v1/chat/completions');
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "model"], $currentConnectorData["model"] ?? 'google/gemini-2.5-flash');
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "PROVIDER"], $currentConnectorData["provider"] ?? '');
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "reasoning_model"], $currentConnectorData["reasoning_model"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "max_tokens"], $currentConnectorData["max_tokens"] ?? '1024');
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "temperature"], $currentConnectorData["temperature"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "presence_penalty"], $currentConnectorData["presence_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "frequency_penalty"], $currentConnectorData["frequency_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "repetition_penalty"], $currentConnectorData["repetition_penalty"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "top_p"], $currentConnectorData["top_p"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "top_k"], $currentConnectorData["top_k"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "min_p"], $currentConnectorData["min_p"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "top_a"], $currentConnectorData["top_a"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "ENFORCE_JSON"], $currentConnectorData["enforce_json"] ?? true);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "PREFILL_JSON"], $currentConnectorData["prefill_json"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "API_KEY"], $apiKey);
            \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", "json_schema"], $currentConnectorData["json_schema"] ?? false);

            // Decode metadata and extended_data if available
            $metadata = json_decode($currentConnectorData['metadata'] ?? '{}', true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    \Synth\Bootstrap::setPath('CONNECTOR', ["google_openaijson", $key], $value);
                }
            }

        } else if ($currentConnectorData["driver"] == "groqjson") {

            $apiKey = $this->getApiKeyForBadge($currentConnectorData["api_key_id"] ?? null, $currentConnectorData);
            // error_log("[CORE SYSTEM] Using new profile system CONNECTOR groqjson {$currentConnectorData["id"]} {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}");
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "url"], $currentConnectorData["url"] ?? 'https://api.groq.com/openai/v1/chat/completions');
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "model"], $currentConnectorData["model"] ?? 'llama-3.3-70b-versatile');
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "reasoning_model"], $currentConnectorData["reasoning_model"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "max_tokens"], $currentConnectorData["max_tokens"] ?? '1024');
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "temperature"], $currentConnectorData["temperature"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "presence_penalty"], $currentConnectorData["presence_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "frequency_penalty"], $currentConnectorData["frequency_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "repetition_penalty"], $currentConnectorData["repetition_penalty"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "top_p"], $currentConnectorData["top_p"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "top_k"], $currentConnectorData["top_k"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "min_p"], $currentConnectorData["min_p"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "top_a"], $currentConnectorData["top_a"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "ENFORCE_JSON"], $currentConnectorData["enforce_json"] ?? true);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "PREFILL_JSON"], $currentConnectorData["prefill_json"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "API_KEY"], $apiKey);
            \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", "json_schema"], false); // Force disabled for Groq - not supported on most models

            // Decode metadata and extended_data if available
            $metadata = json_decode($currentConnectorData['metadata'] ?? '{}', true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    \Synth\Bootstrap::setPath('CONNECTOR', ["groqjson", $key], $value);
                }
            }

        } else if ($currentConnectorData["driver"] == "player2json") {

            $gameKey = "SYNTH";
            if (!empty($currentConnectorData["api_key_id"])) {
                $apiKey = $this->getApiKeyForBadge($currentConnectorData["api_key_id"] ?? null, $currentConnectorData);
                if (!empty($apiKey)) {
                    $gameKey = $apiKey;
                }
            }

            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "url"], $currentConnectorData["url"] ?? 'http://127.0.0.1:4315/v1/chat/completions');
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "model"], $currentConnectorData["model"] ?? '');
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "reasoning_model"], $currentConnectorData["reasoning_model"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "max_tokens"], $currentConnectorData["max_tokens"] ?? '1024');
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "temperature"], $currentConnectorData["temperature"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "presence_penalty"], $currentConnectorData["presence_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "frequency_penalty"], $currentConnectorData["frequency_penalty"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "repetition_penalty"], $currentConnectorData["repetition_penalty"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "top_p"], $currentConnectorData["top_p"] ?? 1.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "top_k"], $currentConnectorData["top_k"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "min_p"], $currentConnectorData["min_p"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "top_a"], $currentConnectorData["top_a"] ?? 0.0);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "ENFORCE_JSON"], $currentConnectorData["enforce_json"] ?? true);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "PREFILL_JSON"], $currentConnectorData["prefill_json"] ?? false);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "API_KEY"], $gameKey);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "player2_game_key"], $gameKey);
            \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", "json_schema"], $currentConnectorData["json_schema"] ?? false);

            $metadata = json_decode($currentConnectorData['metadata'] ?? '{}', true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    \Synth\Bootstrap::setPath('CONNECTOR', ["player2json", $key], $value);
                }
            }

        }

    }


    public function getConnector($currentConnectorData)
    {

        if (!isset($currentConnectorData["driver"]) || empty($currentConnectorData["driver"])) {
            throw new \Exception("Invalid connector data: missing or empty 'driver' key");
        }

        require_once(dirname(__DIR__, 3) . '/drivers/llm/LlmWrapper.php');
        $connector = \Synth\Drivers\Llm\LlmWrapper::legacyConnector(strval($currentConnectorData["driver"]), \Synth\Bootstrap::enginePath() ?? null, $currentConnectorData);

        \Synth\Bootstrap::set('PATCH_PROMPT_ENFORCE_ACTIONS', false);
        \Synth\Bootstrap::set('COMMAND_PROMPT_ENFORCE_ACTIONS', "");
        error_log("[CORE SYSTEM] Action enforcement prompt hard-disabled for connector ID {$currentConnectorData["id"]} ({$currentConnectorData["driver"]}/{$currentConnectorData["model"]})");
        return $connector;

    }

}

?>

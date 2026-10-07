<?php

function synth_reject_json_request(int $statusCode, string $error, array $extra = []): void
{
    if (PHP_SAPI !== "cli" && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    http_response_code($statusCode);

    $payload = array_merge([
        "schema" => "synth.error.response.v1",
        "ok" => false,
        "error" => $error,
    ], $extra);

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'synth_ingress.php');

// Retain the object/list distinction for empty event payloads (notably halt).
function synth_protocol_decode_json(string $raw)
{
    $decoded = json_decode($raw, true, SYNTH_PROTOCOL_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
    if (is_array($decoded) && ($decoded['payload'] ?? null) === []) {
        $shape = json_decode($raw, false, SYNTH_PROTOCOL_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        if ($shape instanceof stdClass && ($shape->payload ?? null) instanceof stdClass) {
            $decoded['payload'] = $shape->payload;
        }
    }
    return $decoded;
}

function synth_decode_json_body(): array
{
    if (PHP_SAPI === "cli" && getenv('PHPUNIT_TEST') && isset($GLOBALS["SYNTH_TEST_JSON_BODY"])) {
        $testBody = $GLOBALS["SYNTH_TEST_JSON_BODY"];
        if (is_array($testBody)) {
            return $testBody;
        }
        if (is_string($testBody) && trim($testBody) !== "") {
            try {
                $decoded = synth_protocol_decode_json($testBody);
                return synth_protocol_is_object($decoded) ? $decoded : [];
            } catch (JsonException $e) {
                return [];
            }
        }
    }

    if (PHP_SAPI === "cli") {
        return [];
    }

    $method = strtoupper(strval($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if ($method === "OPTIONS") {
        http_response_code(204);
        exit;
    }

    if ($method !== "POST") {
        synth_reject_json_request(405, "Method Not Allowed", [
            "method" => $method,
        ]);
    }

    $contentType = strtolower(strval($_SERVER["CONTENT_TYPE"] ?? $_SERVER["HTTP_CONTENT_TYPE"] ?? ""));
    $baseContentType = trim(explode(';', $contentType, 2)[0]);
    if ($baseContentType !== "application/json") {
        synth_reject_json_request(415, "Unsupported Media Type", [
            "expected" => "application/json",
            "content_type" => $contentType,
        ]);
    }

    $declaredLength = strval($_SERVER['CONTENT_LENGTH'] ?? '');
    if ($declaredLength !== '' && (!ctype_digit($declaredLength) || intval($declaredLength) > SYNTH_PROTOCOL_MAX_JSON_BYTES)) {
        synth_reject_json_request(413, "Request body exceeds the configured limit", ["code" => "payload_too_large"]);
    }

    $raw = file_get_contents("php://input", false, null, 0, SYNTH_PROTOCOL_MAX_JSON_BYTES + 1);
    if (!is_string($raw) || trim($raw) === "") {
        synth_reject_json_request(400, "Missing JSON request body");
    }
    if (strlen($raw) > SYNTH_PROTOCOL_MAX_JSON_BYTES) {
        synth_reject_json_request(413, "Request body exceeds the configured limit", ["code" => "payload_too_large"]);
    }
    if (preg_match('//u', $raw) !== 1) {
        synth_reject_json_request(400, "Request body must be valid UTF-8", ["code" => "invalid_envelope"]);
    }

    try {
        $decoded = synth_protocol_decode_json($raw);
    } catch (JsonException $e) {
        synth_reject_json_request(400, "Invalid JSON request body", ["code" => "invalid_envelope"]);
    }
    if (!synth_protocol_is_object($decoded)) {
        synth_reject_json_request(400, "JSON root must be an object", ["code" => "invalid_envelope"]);
    }

    return $decoded;
}

function synth_json_actor_name(array $actor): string
{
    foreach (["name", "actor_name", "display_name"] as $key) {
        $value = trim(strval($actor[$key] ?? ""));
        if ($value !== "") {
            return $value;
        }
    }
    return "";
}

function synth_normalize_json_event(array $data): array
{
    $now = time();
    $wireType = strtolower(trim(strval($data["type"] ?? "")));
    $type = function_exists('synth_protocol_internal_type')
        ? synth_protocol_internal_type($wireType)
        : $wireType;
    $ts = strval($data["ts"] ?? $now);
    $gamets = strval($data["gamets"] ?? $ts);
    $requestId = trim(strval($data["request_id"] ?? ""));

    $payloadData = $data["payload"] ?? "";
    $payload = $payloadData;
    if (is_array($payload) || $payload instanceof stdClass) {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payload = is_string($encodedPayload) ? $encodedPayload : "";
    } else {
        $payload = strval($payload);
    }

    $player = is_array($data["player"] ?? null) ? $data["player"] : [];
    $speaker = is_array($data["speaker"] ?? null) ? $data["speaker"] : [];
    $target = is_array($data["target"] ?? null) ? $data["target"] : [];
    $text = trim(strval($data["text"] ?? (is_array($payloadData) ? ($payloadData['text'] ?? '') : '')));
    $playerName = synth_json_actor_name($player);
    $speakerName = synth_json_actor_name($speaker);
    $targetName = synth_json_actor_name($target);

    if ($payload === "") {
        if (in_array($type, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
            $structuredPayload = [
                "schema" => "synth.input.v1",
                "npc" => $targetName !== "" ? $targetName : $speakerName,
                "npc_id" => strval($target["refid"] ?? ""),
                "player" => $playerName,
                "text" => $text,
                "game" => strval($data["game"] ?? "fo4"),
            ];
            $structuredPayload = array_filter($structuredPayload, static function ($value) {
                return trim(strval($value)) !== "";
            });
            $payload = json_encode($structuredPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $payload = is_string($payload) ? $payload : "";
        } elseif ($type === "_speech" || $type === "rechat") {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
            $payload = is_string($encoded) ? $encoded : "";
        } elseif ($text !== "") {
            $payload = $text;
        }
    }

    return [
        "type" => $type,
        "ts" => $ts,
        "gamets" => $gamets,
        "payload" => $payload,
        "json" => $data,
        "source" => "json",
        "request_id" => $requestId,
        "turn_id" => trim(strval($data["turn_id"] ?? "")),
        "generation" => intval($data["generation"] ?? 0),
        "protocol_version" => intval($data["protocol_version"] ?? 1),
        "context_sequence" => intval($data["context_sequence"] ?? 0),
        "runtime_variant" => trim(strval($data["runtime_variant"] ?? "")),
        "capabilities" => is_array($data["capabilities"] ?? null) ? $data["capabilities"] : [],
        "wire_type" => $wireType,
        "response_format" => strtolower(trim(strval($data["response_format"] ?? ""))),
    ];
}

function synth_should_stream_json_response(array $event, string $acceptHeader = "", string $streamHeader = ""): bool
{
    $json = is_array($event["json"] ?? null) ? $event["json"] : [];
    $schema = trim(strval($json["schema"] ?? ""));
    $type = trim(strval($event["type"] ?? ""));
    $streamOverrideValue = array_key_exists("response_streaming", $json)
        ? $json["response_streaming"]
        : ($json["streaming"] ?? "");
    if (is_bool($streamOverrideValue)) {
        $streamOverride = $streamOverrideValue ? "true" : "false";
    } else {
        $streamOverride = strtolower(trim(strval($streamOverrideValue)));
    }

    if (in_array($streamOverride, ["0", "false", "no", "off", "buffered"], true)) {
        return false;
    }

    if (strpos(strtolower($acceptHeader), "application/x-ndjson") !== false) {
        return true;
    }

    if (in_array(strtolower(trim($streamHeader)), ["1", "true", "yes"], true)) {
        return true;
    }

    return strpos($schema, "synth.event.") === 0 && $type !== "";
}

function synth_decode_event_from_request(): array
{
    if (PHP_SAPI === "cli" && getenv('PHPUNIT_TEST') && isset($GLOBALS['SYNTH_TEST_NORMALIZED_EVENT'])) {
        $testEvent = $GLOBALS['SYNTH_TEST_NORMALIZED_EVENT'];
        if (is_array($testEvent)) {
            return $testEvent;
        }
    }

    $json = synth_decode_json_body();
    if (!empty($json)) {
        $json = synth_protocol_validate_event($json);
        $GLOBALS['SYNTH_REQUEST_ID'] = $json['request_id'];
        $GLOBALS['SYNTH_TURN_ID'] = $json['turn_id'];
        $GLOBALS['SYNTH_GENERATION'] = $json['generation'];
        $GLOBALS['SYNTH_RUNTIME_VARIANT'] = $json['runtime_variant'];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = $json['capabilities'];
        return synth_normalize_json_event($json);
    }

    return [
        "type" => "",
        "ts" => "",
        "gamets" => "",
        "payload" => "",
        "json" => [],
        "source" => "json",
        "response_format" => "json",
    ];
}

function synth_event_to_received_data(array $event): string
{
    $encoded = json_encode($event["json"] ?? $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($encoded) ? $encoded : "";
}

function synth_event_to_game_request(array $event): array
{
    $request = [
        strval($event["type"] ?? ""),
        strval($event["ts"] ?? ""),
        strval($event["gamets"] ?? ""),
        strval($event["payload"] ?? ""),
    ];

    $json = is_array($event["json"] ?? null) ? $event["json"] : [];
    $payload = $json["payload"] ?? null;
    if (!empty($json["audience_snapshot"])) {
        $request[4] = json_encode($json["audience_snapshot"], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (is_array($payload) && !empty($payload["audience_snapshot"])) {
        $request[4] = json_encode($payload["audience_snapshot"], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (!empty($json["people"]) || !empty($json["companions"])) {
        $snapshot = [];
        if (!empty($json["people"])) {
            $snapshot["people"] = $json["people"];
        }
        if (!empty($json["companions"])) {
            $snapshot["companions"] = $json["companions"];
        }
        $request[4] = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $request;
}

// Renders the native combat snapshot supplied with a combat-bark event.
function synth_build_combat_prompt_from_event(array $event, array $turnActorStates = []): string
{
    if (strtolower(trim(strval($event["type"] ?? ""))) !== "combatbark") {
        return "";
    }

    $json = is_array($event["json"] ?? null) ? $event["json"] : [];
    $payload = $json["payload"] ?? ($event["payload"] ?? []);
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($payload)) {
        return "";
    }

    $combat = $payload["combat"] ?? [];
    if (!is_array($combat)) {
        return "";
    }

    $actorStates = is_array($payload["actor_states"] ?? null)
        ? $payload["actor_states"]
        : $turnActorStates;
    if ($combat === [] && $actorStates !== []) {
        $combat = [
            "allies_currently_fighting" => [],
            "hostile_combatants" => [],
        ];
        foreach (array_slice($actorStates, 0, 16) as $state) {
            if (!is_array($state) || empty($state["alive"]) || !empty($state["disabled"]) ||
                empty($state["in_combat"])) {
                continue;
            }
            $actor = is_array($state["actor"] ?? null) ? $state["actor"] : [];
            $name = trim(strval($actor["display_name"] ?? ""));
            if ($name === "") {
                continue;
            }
            $bucket = !empty($state["hostile_to_player"])
                ? "hostile_combatants"
                : "allies_currently_fighting";
            $combat[$bucket][] = $name;
        }
    }

    $normalizeNames = static function ($values): array {
        if (!is_array($values)) {
            return [];
        }

        $names = [];
        $seen = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $name = preg_replace('/\s+/u', ' ', trim(strval($value)));
            if (!is_string($name) || $name === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = htmlspecialchars($name, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $names;
    };

    $allies = $normalizeNames($combat["allies_currently_fighting"] ?? []);
    $hostiles = $normalizeNames($combat["hostile_combatants"] ?? []);
    if (empty($allies) && empty($hostiles)) {
        return "";
    }

    $allyLines = implode("\n", array_map(static fn(string $name): string => "- {$name}", $allies));
    $hostileLines = implode("\n", array_map(static fn(string $name): string => "- {$name}", $hostiles));

    return "<combat>\n# Allies Currently Fighting\n{$allyLines}\n\n"
        . "# Hostile Combatants\n{$hostileLines}\n</combat>";
}

function synth_extract_conversation_target(string $payload): string
{
    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        $targetValue = $decoded["target"] ?? "";
        $speakerValue = $decoded["speaker"] ?? "";
        $targetName = is_array($targetValue) ? ($targetValue["name"] ?? "") : $targetValue;
        $speakerName = is_array($speakerValue) ? ($speakerValue["name"] ?? "") : $speakerValue;
        foreach ([
            $targetName,
            $speakerName,
            $decoded["npc"] ?? "",
            $decoded["actor_name"] ?? "",
        ] as $candidate) {
            $candidate = trim(strval($candidate));
            if ($candidate !== "") {
                return $candidate;
            }
        }
    }

    return "The Narrator";
}

function synth_payload_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim(strval($value)));
    return in_array($normalized, ["1", "true", "yes", "on"], true);
}

function synth_sanitize_pipeline_input_fragment(string $value): string
{
    $value = str_replace(["\r", "\n", "|"], " ", $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function synth_adapt_json_input_payload_for_pipeline(array &$gameRequest): array
{
    $result = [
        "changed" => false,
        "raw" => "",
        "fields" => [],
        "text" => "",
        "player" => "",
        "target" => "",
        "display" => "",
        "skip_player_tts" => false,
    ];

    $eventType = strtolower(trim(strval($gameRequest[0] ?? "")));
    if (!in_array($eventType, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
        return $result;
    }

    $rawPayload = strval($gameRequest[3] ?? "");
    $result["raw"] = $rawPayload;
    if (trim($rawPayload) === "") {
        return $result;
    }

    $fields = function_exists("synth_parse_payload_fields")
        ? synth_parse_payload_fields($rawPayload)
        : [];

    if (empty($fields)) {
        return $result;
    }

    $result["fields"] = $fields;
    $result["skip_player_tts"] = synth_payload_bool_value($fields["skip_player_tts"] ?? false);

    $text = "";
    foreach (["text", "message", "input", "utterance", "player_text", "speech", "line"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $text = trim(strval($fields[$key]));
            break;
        }
    }

    if ($text === "") {
        return $result;
    }

    $player = "";
    foreach (["player", "player_name", "player_actor"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $player = trim(strval($fields[$key]));
            break;
        }
    }
    if ($player === "") {
        $player = trim(strval($GLOBALS["PLAYER_NAME"] ?? "Player"));
    }

    $target = "";
    foreach (["npc", "target", "listener", "actor_name", "speaker"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $target = trim(strval($fields[$key]));
            break;
        }
    }
    if ($target === "" && function_exists("synth_extract_conversation_target")) {
        $target = synth_extract_conversation_target($rawPayload);
        if (strcasecmp($target, "The Narrator") === 0) {
            $target = "";
        }
    }

    $player = synth_sanitize_pipeline_input_fragment($player);
    $text = synth_sanitize_pipeline_input_fragment($text);
    $target = synth_sanitize_pipeline_input_fragment(str_replace(["(", ")"], " ", $target));

    $display = $player !== "" ? "{$player}: {$text}" : $text;
    if ($target !== "" && strcasecmp($target, "The Narrator") !== 0) {
        $display .= " (Talking to {$target})";
    }

    if (trim($display) === "") {
        return $result;
    }

    $gameRequest[3] = $display;
    if (!isset($gameRequest[4]) && isset($fields["audience_snapshot"]) && trim(strval($fields["audience_snapshot"])) !== "") {
        $gameRequest[4] = strval($fields["audience_snapshot"]);
    }

    $GLOBALS["SYNTH_STRUCTURED_INPUT_FIELDS"] = $fields;
    $GLOBALS["SYNTH_PLAYER_INPUT_TEXT"] = $text;
    $GLOBALS["SYNTH_INPUT_TARGET"] = $target;
    $GLOBALS["SYNTH_SKIP_PLAYER_TTS"] = $result["skip_player_tts"];

    $result["changed"] = true;
    $result["text"] = $text;
    $result["player"] = $player;
    $result["target"] = $target;
    $result["display"] = $display;
    return $result;
}

function synth_adapt_json_vision_payload_for_pipeline(array &$gameRequest): array
{
    $result = [
        "changed" => false,
        "target" => "",
        "description" => "",
    ];
    if (strtolower(trim(strval($gameRequest[0] ?? ""))) !== "vision") {
        return $result;
    }

    $rawPayload = strval($gameRequest[3] ?? "");
    $fields = json_decode($rawPayload, true);
    if (!is_array($fields)) {
        return $result;
    }

    $description = "";
    foreach (["text", "description", "visual_context", "scene"] as $key) {
        if (isset($fields[$key]) && is_scalar($fields[$key]) && trim(strval($fields[$key])) !== "") {
            $description = synth_sanitize_pipeline_input_fragment(trim(strval($fields[$key])));
            break;
        }
    }
    if ($description === "") {
        return $result;
    }

    $target = synth_extract_conversation_target($rawPayload);
    if (strcasecmp($target, "The Narrator") === 0) {
        $target = "";
    }
    $target = synth_sanitize_pipeline_input_fragment($target);
    if ($target !== "") {
        $GLOBALS["SYNTH_INPUT_TARGET"] = $target;
    }

    $gameRequest[3] = "PipVision visual observation: " . $description;
    $result["changed"] = true;
    $result["target"] = $target;
    $result["description"] = $description;
    return $result;
}

function synth_extract_funcret_actor(string $payload): string
{
    $decoded = json_decode($payload, true);
    if (is_array($decoded)) {
        foreach ([
            // Native action receipts identify the performer separately from the inspected/action target.
            $decoded["actor"] ?? "",
            $decoded["speaker"] ?? "",
            $decoded["npc"] ?? "",
            $decoded["actor_name"] ?? "",
            $decoded["character"] ?? "",
            $decoded["target"] ?? "",
        ] as $candidate) {
            $candidate = is_array($candidate) ? ($candidate["name"] ?? "") : $candidate;
            $candidate = trim(strval($candidate));
            if ($candidate !== "") {
                return $candidate;
            }
        }
    }

    return "";
}

?>

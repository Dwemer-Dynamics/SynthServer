<?php

// Moved from lib/data_functions.php: fast and main LLM calls with fallback
// The original file requires this one, so every caller is unchanged.

function FastCallOAI($question) {

    $call["messages"]=[
        [
            "role"=>"user",
            "content"=>"$question"
        ]
    ];


    $call["stream"]=false;
    $call["stop"]=["\n"];

    $headers = ['Content-Type: application/json'];

    $options = array(
        'http' => array(
            'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($call),
            )
    );

    $netContext = stream_context_create($options);
    $response=file_get_contents('http://localhost:5001/v1/chat/completions', false,$netContext);
    $rawResponse=json_decode($response,true);

    if (isset($rawResponse["choices"][0]["message"]["content"]))
        return $rawResponse["choices"][0]["message"]["content"];
    else
        return null;

}

function snapshot_response_prompt_debug_data($connectorData = null) {
    if (!isset($GLOBALS["DEBUG_DATA"]) || !is_array($GLOBALS["DEBUG_DATA"])) {
        $GLOBALS["DEBUG_DATA"] = [];
    }

    if (isset($GLOBALS["DEBUG_DATA"]["full"]) && is_array($GLOBALS["DEBUG_DATA"]["full"])) {
        $GLOBALS["DEBUG_DATA"]["response_full"] = $GLOBALS["DEBUG_DATA"]["full"];
    } else {
        unset($GLOBALS["DEBUG_DATA"]["response_full"]);
    }

    if ($connectorData === null
        && isset($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"])
        && is_array($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"])) {
        $connectorData = $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"];
    }

    if (!is_array($connectorData)) {
        unset($GLOBALS["DEBUG_DATA"]["response_connector"]);
        return;
    }

    $responseConnector = array_filter([
        'id' => $connectorData['id'] ?? null,
        'label' => $connectorData['label'] ?? null,
        'driver' => $connectorData['driver'] ?? null,
        'model' => $connectorData['model'] ?? null,
    ], function ($value) {
        return $value !== null && $value !== '';
    });

    if (!empty($responseConnector)) {
        $GLOBALS["DEBUG_DATA"]["response_connector"] = $responseConnector;
    } else {
        unset($GLOBALS["DEBUG_DATA"]["response_connector"]);
    }
}

function synth_try_llm_fallback($reason = "unknown") {
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    if (empty($GLOBALS["SYNTH_CORE_CURRENT_PROFILE_DATA"]) || !is_array($GLOBALS["SYNTH_CORE_CURRENT_PROFILE_DATA"])) {
        Logger::info("[FALLBACK] No current profile data available; cannot retry. reason={$reason}");
        return null;
    }

    $profileData = $GLOBALS["SYNTH_CORE_CURRENT_PROFILE_DATA"];
    $legacyFallbackConnectorId = class_exists('LLMRandomizer')
        ? LLMRandomizer::getConnectorIdForField($profileData, "llm_fallback_id")
        : ($profileData["llm_fallback_id"] ?? null);

    $metadata = [];
    if (!empty($profileData["metadata"])) {
        $metadata = is_string($profileData["metadata"])
            ? json_decode($profileData["metadata"], true)
            : $profileData["metadata"];
        if (!is_array($metadata)) {
            $metadata = [];
        }
    }

    $fallbackEnabled = array_key_exists("LLM_FALLBACK_ENABLED", $metadata)
        ? filter_var($metadata["LLM_FALLBACK_ENABLED"], FILTER_VALIDATE_BOOLEAN)
        : true;
    if (!$fallbackEnabled) {
        Logger::info("[FALLBACK] Fallback not available" . Logger::formatContext([
            "reason" => $reason,
            "enabled" => "no",
        ]));
        return null;
    }

    $fallbackConnectorIds = [];
    foreach ([$legacyFallbackConnectorId, $metadata["LLM_FALLBACK_2_ID"] ?? null, $metadata["LLM_FALLBACK_3_ID"] ?? null] as $connectorId) {
        $connectorId = filter_var($connectorId, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if ($connectorId && !in_array($connectorId, $fallbackConnectorIds, true)) {
            $fallbackConnectorIds[] = $connectorId;
        }
    }

    $attempted = $GLOBALS["SYNTH_LLM_ATTEMPTED_CONNECTOR_IDS"] ?? [];
    if (!is_array($attempted)) {
        $attempted = [];
    }
    $currentConnectorId = intval($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["id"] ?? 0);
    if ($currentConnectorId > 0 && !in_array($currentConnectorId, $attempted, true)) {
        $attempted[] = $currentConnectorId;
    }

    $connector = new LLMConnector();
    $fallbackConnectorData = null;
    $fallbackConnectorId = null;
    foreach ($fallbackConnectorIds as $candidateId) {
        if (in_array($candidateId, $attempted, true)) {
            continue;
        }
        $attempted[] = $candidateId;
        $candidate = $connector->getById($candidateId);
        if ($candidate) {
            $fallbackConnectorId = $candidateId;
            $fallbackConnectorData = $candidate;
            break;
        }
        Logger::warn("[FALLBACK] Connector ID {$candidateId} not found; advancing chain. reason={$reason}");
    }
    $GLOBALS["SYNTH_LLM_ATTEMPTED_CONNECTOR_IDS"] = $attempted;

    if (!$fallbackConnectorData) {
        Logger::info("[FALLBACK] Connector chain exhausted" . Logger::formatContext([
            "reason" => $reason,
            "attempted" => implode(",", $attempted),
        ]));
        return null;
    }

    $chainPosition = array_search($fallbackConnectorId, $fallbackConnectorIds, true);
    Logger::warn("[FALLBACK] Retrying LLM request with connector chain" . Logger::formatContext([
        "reason" => $reason,
        "fallback_id" => $fallbackConnectorId,
        "chain_position" => $chainPosition === false ? "" : $chainPosition + 1,
        "chain_length" => count($fallbackConnectorIds),
        "driver" => $fallbackConnectorData["driver"] ?? "",
        "model" => $fallbackConnectorData["model"] ?? "",
    ]));

    $fallbackDepth = intval($GLOBALS["SYNTH_LLM_FALLBACK_DEPTH"] ?? 0) + 1;
    $GLOBALS["SYNTH_LLM_FALLBACK_DEPTH"] = $fallbackDepth;
    $GLOBALS["IN_FALLBACK_MODE"] = true;
    $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"] = $fallbackConnectorData;
    $connector->setOldGlobals($fallbackConnectorData);

    $result = call_llm_internal();
    $fallbackDepth = intval($GLOBALS["SYNTH_LLM_FALLBACK_DEPTH"] ?? 1) - 1;
    if ($fallbackDepth <= 0) {
        unset($GLOBALS["SYNTH_LLM_FALLBACK_DEPTH"], $GLOBALS["IN_FALLBACK_MODE"]);
    } else {
        $GLOBALS["SYNTH_LLM_FALLBACK_DEPTH"] = $fallbackDepth;
    }
    return $result;
}

function call_llm() {
    require_once dirname(__DIR__, 3).'/lib'.'/relationship_response.php';
    global $contextData, $gameRequest, $receivedData, $startTime, $db;
    global $ERROR_TRIGGERED, $talkedSoFar, $alreadysent, $FUNCTIONS_ARE_ENABLED;
    global $overrideParameters, $request;

    unset($GLOBALS["SYNTH_LLM_ATTEMPTED_CONNECTOR_IDS"], $GLOBALS["SYNTH_LLM_FALLBACK_DEPTH"], $GLOBALS["IN_FALLBACK_MODE"]);
    // Call the internal function (which now handles the full fallback chain itself)
    try {
        $accepted=call_llm_internal();
        synth_relationship_finish_response((bool)$accepted && empty($GLOBALS['ERROR_TRIGGERED']) && empty($GLOBALS['FORCED_STOP']));
        return $accepted;
    } catch (Throwable $error) {
        synth_relationship_finish_response(false);
        throw $error;
    }
}

function synthRecoverPlainLlmSpeech(): bool
{
    global $gameRequest, $talkedSoFar, $alreadysent;

    if (($gameRequest[0] ?? '') === 'diary' || count($talkedSoFar ?? []) > 0 || count($alreadysent ?? []) > 0) {
        return false;
    }

    $raw = trim(strval($GLOBALS['SYNTH_LLM_RAW_TEXT'] ?? ''));
    if ($raw === '' || strlen($raw) < 3 || preg_match('/[[:alpha:]]/u', $raw) !== 1) {
        return false;
    }
    if (preg_match('/^(?:\{|\[|data\s*:|<!doctype|<html|error\b)/i', $raw) === 1) {
        return false;
    }

    $raw = preg_replace('/^```(?:json|text|markdown)?\s*|\s*```$/i', '', $raw);
    $raw = preg_replace('/<(think|thinking|reasoning)>.*?<\/\1>/is', '', $raw);
    $clean = trim(cleanResponse($raw));
    if ($clean === '' || preg_match('/[[:alpha:]]/u', $clean) !== 1) {
        return false;
    }

    $sentences = array_values(array_filter(split_sentences_stream($clean), static function ($line) {
        return trim(strval($line)) !== '';
    }));
    if (empty($sentences)) {
        return false;
    }

    Logger::warn('[LLM] Recovered valid plain-text model output as speech' . Logger::formatContext([
        'speaker' => $GLOBALS['SYNTH_NAME'] ?? '',
        'chars' => strlen($clean),
        'preview' => Logger::summarizePayload($clean, 160),
    ]));
    returnLines($sentences, modelResponse: true);
    return true;
}

function call_llm_internal() {
    if (function_exists('synth_protocol_assert_request_active')) synth_protocol_assert_request_active();
    require_once dirname(__DIR__, 3).'/lib'.'/relationship_response.php';
    synth_relationship_begin_response();
    global $contextData, $gameRequest, $receivedData, $startTime, $db;
    global $ERROR_TRIGGERED, $talkedSoFar, $alreadysent, $FUNCTIONS_ARE_ENABLED;
    global $overrideParameters, $request;

    $outputWasValid = true;
    $firstLlmChunkLogged = false;
    unset($GLOBALS['SYNTH_LLM_RAW_TEXT']);


    if (isset($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"])) {
        $connector=new LLMConnector();
        $connectionHandler = $connector->getConnector($GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]);
        error_log("[CORE SYSTEM] Using new profile system {$GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["driver"]}/{$GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["model"]}");
    } else {
        error_log("No connector defined");
        Logger::error("No connector defined");
        terminate();
    }

    if (isset($GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) && $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) {

         /* *****
        Player TTS

        Player TTS. We overwrite some confs an then restore them.
        */
        // Only process player TTS on the first attempt, not during fallback retry
        if (!isset($GLOBALS["IN_FALLBACK_MODE"])
            && in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"])
            && (!function_exists('synthShouldSkipPlayerTtsForRequest') || !synthShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? ""))) {
            require(dirname(__DIR__, 3).'/lib'."/../processor/player_tts.php");
        } elseif (function_exists('synthShouldSkipPlayerTtsForRequest') && synthShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? "")) {
            Logger::info("[Player TTS] Skipping LLM-path player TTS for input request; sidecar playback expected.");
        }
        $currentConnectorData=$GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"];
        error_log("[CLEAN_CONTEXT_FOCUS_CHAT] Using 2-step schema, model: {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}");


        if ($gameRequest[0] === "narrator_inputtext") {
            require_once($GLOBALS["ENGINE_PATH"]."/functions".DIRECTORY_SEPARATOR."json_response.php");
            if (function_exists('synthEnsureNarratorJsonResponseState')) {
                synthEnsureNarratorJsonResponseState('DATA_FUNCTIONS_FAST_STANDARD');
            }
            if (
                !empty($GLOBALS["PROMPT_ACTIONS_LIST"])
                && isset($contextData[0]["content"])
                && strpos($contextData[0]["content"], '<available_actions_list>') === false
            ) {
                $contextData[0]["content"] .= "\n" . $GLOBALS["PROMPT_ACTIONS_LIST"];
            }
        }

        $buffer=$connectionHandler->fast_request($contextData,$overrideParameters,'standard');
        snapshot_response_prompt_debug_data($currentConnectorData);
        $preserveAsterisksInContext = isset($GLOBALS["PRESERVE_ASTERISKS_IN_CONTEXT"]) ? (bool)$GLOBALS["PRESERVE_ASTERISKS_IN_CONTEXT"] : false;
        $inlineNarrationMode = strtolower(trim((string)($GLOBALS["INLINE_NARRATION_MODE"] ?? '')));
        if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
            $inlineNarrationMode = 'disabled';
        }
        if ($inlineNarrationMode === 'disabled' && !$preserveAsterisksInContext) {
            $buffer = preg_replace('/\*([^*]*\s+[^*]*)\*/', '', $buffer);
        }

        error_log("[STEP 1] Elapsed time: " . (microtime(true) - $startTime) . " seconds");

        if ($GLOBALS["FUNCTIONS_ARE_ENABLED"]) {
            require_once($GLOBALS["ENGINE_PATH"]."/functions".DIRECTORY_SEPARATOR."json_response.php");
            $GLOBALS["COMMAND_PROMPT"]="";

            setActions();
        } else {
            $GLOBALS["FUNC_LIST"][]="Talk";
            $GLOBALS["COMMAND_PROMPT"]="";

        }

        $jsonformat= json_encode(["character"=>$GLOBALS["SYNTH_NAME"],
        "listener"=>"specify who {$GLOBALS["SYNTH_NAME"]} is talking to, comma separated, max two listeners, in addressing order",
        "message"=>"lines of dialogue",
        "mood"=>"One of :".implode("|",normalizeEmoteMoods($GLOBALS["EMOTEMOODS"] ?? "")),
        "action"=>"One of :".implode("|",$GLOBALS["FUNC_LIST"]),
        "target"=>"action target actor or action destination location name",
        "item"=>"item identifier and name. For GiveItemTo, Consume, EquipItem, or UnequipItem, use exact BaseID:ItemName from inventory. For PickupItem, use exact RefID:ItemName from nearby_items",
        "lang"=>"language used, (es|en|fr|...)"]);


        $minimalContextData = array_slice($contextData, -5);
        $minimalContext=[];
        foreach ($minimalContextData as $ele) {
            if (strpos($ele["content"],"#MEMORY")===false) {
                $minimalContext[]="{$ele["content"]}";
            }
        }
        array_pop($minimalContext);

        $minimalContext[]="$buffer";// Add whole generated content.

        $buffer=preg_replace('/\([^)]*\)/', '', $buffer);//Remove text between space.
        $contextData2=[
            array('role' => 'system', 'content' => "Create a JSON object with this format: $jsonformat , using a 'Generated dialogue line' as source. "),
            array('role' => 'user', 'content' => "* Available actions:\n".$GLOBALS["COMMAND_PROMPT"]),
            array('role' => 'user', 'content' => "* Historic context information:\n".implode("\n",$minimalContext)),
            array('role' => 'user', 'content' => "* Generated dialogue line: <$buffer>"),
            array('role' => 'user', 'content' => "Convert the '* Generated dialogue line' , and ONLY the  '* Generated dialogue line' section, to a JSON object with this format: $jsonformat\n.You must infer some properties like action (check Available actions list ) and mood from context"),
        ];

        $connector=new LLMConnector();
        $formatterConnectorId = class_exists('LLMRandomizer')
            ? LLMRandomizer::getConnectorIdForField($GLOBALS["SYNTH_CORE_CURRENT_PROFILE_DATA"], "llm_formatter_id")
            : ($GLOBALS["SYNTH_CORE_CURRENT_PROFILE_DATA"]["llm_formatter_id"] ?? null);
        $currentConnectorData=$connector->getById($formatterConnectorId);



        $connector->setOldGlobals($currentConnectorData);

        $connectionHandler = $connector->getConnector($currentConnectorData);

        $buffer2=$connectionHandler->fast_request($contextData2,[],'formatter');
        unset($GLOBALS["_JSON_BUFFER"]);
        $finalRes=__jpd_decode_lazy($buffer2);
        file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/output_from_llm_fast_step_2.log", $buffer2, FILE_APPEND);
        file_put_contents(dirname(__DIR__, 3).'/lib'."/../log/output_from_llm_fast_step_2.log", print_r($finalRes,true), FILE_APPEND);
        unset($GLOBALS["_JSON_BUFFER"]);
        $fakeObject["choices"][0]=[
            "index"=>0,
            "delta"=>["role"=>"assistant","content"=>json_encode($finalRes)]
        ];

        $connectionHandler->primary_handler=fopen("php://memory", "r+");// Total hack, we're emulating streaming mode.
        $fakedStream='data: '.json_encode($fakeObject);
        fwrite($connectionHandler->primary_handler,$fakedStream);
        rewind($connectionHandler->primary_handler);

        error_log("[CLEAN_CONTEXT_FOCUS_CHAT] Using 2-step schema, model: {$currentConnectorData["driver"]}/{$currentConnectorData["model"]}" );

    } else {


            /* *****
        Player TTS

        Player TTS. We overwrite some confs an then restore them.
        */
        // Only process player TTS on the first attempt, not during fallback retry
        if (!isset($GLOBALS["IN_FALLBACK_MODE"])
            && in_array($gameRequest[0],["inputtext","inputtext_s","narrator_inputtext"])
            && (!function_exists('synthShouldSkipPlayerTtsForRequest') || !synthShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? ""))) {
            require(dirname(__DIR__, 3).'/lib'."/../processor/player_tts.php");
        } elseif (function_exists('synthShouldSkipPlayerTtsForRequest') && synthShouldSkipPlayerTtsForRequest($gameRequest[0], $gameRequest[3] ?? "")) {
            Logger::info("[Player TTS] Skipping LLM-path player TTS for input request; sidecar playback expected.");
        }

        Logger::phaseStart("llm_provider_open", [
            "connector" => $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["driver"] ?? "",
            "model" => $GLOBALS["SYNTH_CORE_CURRENT_CONNECTOR_DATA"]["model"] ?? "",
        ]);
        $connectionHandler->open($contextData,$overrideParameters);
        Logger::phaseEnd("llm_provider_open", [
            "status" => $connectionHandler->primary_handler === false ? "failed" : "ok",
        ], $connectionHandler->primary_handler === false ? "warn" : "debug");
        snapshot_response_prompt_debug_data();
    }
    error_log("[FALLBACK DEBUG] Checking primary_handler status: " . ($connectionHandler->primary_handler === false ? "FALSE" : "OK"));

    if ($connectionHandler->primary_handler === false) {
        error_log("[FALLBACK DEBUG] primary_handler is false, checking fallback conditions");

        $fallbackResult = synth_try_llm_fallback("connection_error");
        if ($fallbackResult !== null) {
            return $fallbackResult;
        }

        // No fallback or fallback also failed - send error message
        error_log("[FALLBACK DEBUG] Sending ERROR_OPENAI message to user");
        $db->insert(
            'core_log',
            array(
                'localts' => time(),
                'prompt' => nl2br((json_encode($GLOBALS["DEBUG_DATA"], JSON_PRETTY_PRINT))),
                'response' => ((print_r(error_get_last(), true))),
                'url' => nl2br(("$receivedData in " . (microtime(true) - $startTime) . " secs "))
            )
        );
        returnLines([$GLOBALS["ERROR_OPENAI"]]);

        $ERROR_TRIGGERED=true;
        @ob_end_flush();

        Logger::error(print_r(error_get_last(), true));
        return false;
    }

    // Check for error response code
    $statusCode = method_exists($connectionHandler, 'getHttpStatusCode') ? $connectionHandler->getHttpStatusCode() : 200;
    if ($statusCode >= 300) {
        $fallbackResult = synth_try_llm_fallback("http_{$statusCode}");
        if ($fallbackResult !== null) {
            return $fallbackResult;
        }

        Logger::error("LLM provider error response code: $statusCode");
        return false;
    }

    // Read and process the response line by line
    $buffer="";
    $totalBuffer="";
    $breakFlag=false;
    $lineCounter=0;
    $fullContent="";
    $totalProcessedData="";
    $numOutputTokens = 0;
    $INCREMENTAL_SENTENCESIZE=20;
    $actionsAllowedForTurn = (
        !empty($GLOBALS["FUNCTIONS_ARE_ENABLED"])
        || (
            isset($GLOBALS["PROMPT_ACTIONS_LIST"])
            && is_string($GLOBALS["PROMPT_ACTIONS_LIST"])
            && trim($GLOBALS["PROMPT_ACTIONS_LIST"]) !== ""
        )
    );

    while (true) {
        if ($breakFlag) {
            break;
        }

        $tmpData=$connectionHandler->process();
        if (!$firstLlmChunkLogged && is_string($tmpData) && $tmpData !== "") {
            $firstLlmChunkLogged = true;
            Logger::info("[LLM] First stream chunk received" . Logger::formatContext([
                "elapsed_ms" => round((microtime(true) - $startTime) * 1000, 2),
                "bytes" => strlen($tmpData),
                "preview" => Logger::summarizePayload($tmpData, 120),
            ]));
        }
        if ($tmpData==-1 || (isset($GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]) && !$GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]($tmpData))) {
            if ($tmpData == -1 && count($talkedSoFar ?? []) == 0 && count($alreadysent ?? []) == 0) {
                if (synthRecoverPlainLlmSpeech()) {
                    if (method_exists($connectionHandler, "close")) {
                        $connectionHandler->close("plain-text-recovery");
                    }
                    $buffer = "";
                    $breakFlag = true;
                    continue;
                }
                $streamErrorReason = "stream_error";
                if (method_exists($connectionHandler, "getLastStreamErrorCode")) {
                    $streamErrorReason .= " code=" . strval($connectionHandler->getLastStreamErrorCode() ?? "");
                }
                if (method_exists($connectionHandler, "getLastStreamErrorType")) {
                    $streamErrorType = trim((string)$connectionHandler->getLastStreamErrorType());
                    if ($streamErrorType !== "") {
                        $streamErrorReason .= " type=" . $streamErrorType;
                    }
                }
                if (method_exists($connectionHandler, "getLastStreamErrorMessage")) {
                    $streamErrorMessage = trim((string)$connectionHandler->getLastStreamErrorMessage());
                    if ($streamErrorMessage !== "") {
                        $streamErrorReason .= " message=" . $streamErrorMessage;
                    }
                }

                if (method_exists($connectionHandler, "close")) {
                    $connectionHandler->close("failed-stream-before-fallback");
                }

                $fallbackResult = synth_try_llm_fallback($streamErrorReason);
                if ($fallbackResult !== null) {
                    return $fallbackResult;
                }
            }

            Logger::warn("Invalid JSON Output.");
            $outputWasValid=false;
            $buffer="";
            $breakFlag=true;
        }
        else {
            $buffer.= $tmpData;
            $totalBuffer.=$buffer;
        }

        if ($connectionHandler->isDone()) {
            $breakFlag=true;
        }

        $buffer=strtr($buffer, array("\""=>"",".)"=>")."));

        // For narration events, allow immediate streaming without minimum buffer size
        if ($gameRequest[0] !== "narration" && strlen($buffer)<$INCREMENTAL_SENTENCESIZE) {	// Avoid too short buffers
            continue;
        }

        $position = findFastSentencePosition($buffer,$INCREMENTAL_SENTENCESIZE);

        //echo "<$buffer>".PHP_EOL;
        if (($position !== false) && ($gameRequest[0] === "narration" || $position>$INCREMENTAL_SENTENCESIZE)) {
            $extractedData = substr($buffer, 0, $position + 1);
            $remainingData = substr($buffer, $position + 1);
            $sentences=split_sentences_stream(cleanResponse($extractedData));
            $GLOBALS["DEBUG_DATA"]["response"][]=["raw"=>$buffer,"processed"=>implode("|", $sentences)];
            $GLOBALS["DEBUG_DATA"]["perf"][]=(microtime(true) - $startTime)." secs in openai stream";

            if ($gameRequest[0] != "diary") {
                returnLines($sentences, modelResponse: true);
                $INCREMENTAL_SENTENCESIZE=MINIMUM_SENTENCE_SIZE;
            } else { //why is the diary talking? is this correct?
                $talkedSoFar[md5(implode(" ", $sentences))]=implode(" ", $sentences);
            }

            //echo "$extractedData  # ".(microtime(true)-$startTime)."\t".strlen($finalData)."\t".PHP_EOL;  // Output
            $totalProcessedData.=$extractedData;
            $extractedData="";
            $buffer=$remainingData;
            //$user_input_after=$GLOBALS["db"]->fetchAll("select count(*) as N from core_context_event where type='user_input' and ts>$gameRequest[1]"); //9.0ms


        }
        // This is intended to stop the generation as soon as user input is detected, so we will attend new request instead of keeping generating this
        $user_input_after=$GLOBALS["db"]->fetchAll("select rowid as N from core_context_event where type='user_input' and ts>$gameRequest[1] LIMIT 1"); // 2.1ms, faster than count(*)
        if (isset($user_input_after[0]))
            if (isset($user_input_after[0]["N"]))
                if ($user_input_after[0]["N"]>0) {
                    Logger::info("Generation stopped because user_input. ".__FILE__." ".__LINE__." ".__FUNCTION__);
                    error_log("Generation stopped because user_input. ".__FILE__." ".__LINE__." ".__FUNCTION__);
                    $connectionHandler->close();
                    synth_abort_json_response();
                    // Abort , user input detected
                }

    } // --- end while

    if ($outputWasValid && trim($buffer) === '' && count($talkedSoFar ?? []) == 0 && count($alreadysent ?? []) == 0) {
        if (!synthRecoverPlainLlmSpeech()) {
            if (method_exists($connectionHandler, "close")) {
                $connectionHandler->close("empty-response-before-fallback");
            }
            $fallbackResult = synth_try_llm_fallback("empty_response");
            if ($fallbackResult !== null) {
                return $fallbackResult;
            }
        }
    }


    if ($outputWasValid && trim($buffer)) {
        Logger::info("REMAINING DATA <$buffer>");
        $sentences=split_sentences_stream(cleanResponse(trim($buffer)));

        $GLOBALS["DEBUG_DATA"]["response"][]=["raw"=>$buffer,"processed"=>implode("|", $sentences)];
        $GLOBALS["DEBUG_DATA"]["perf"][]=(microtime(true) - $startTime)." secs in openai stream";
        if ($gameRequest[0] != "diary") {
            returnLines($sentences, modelResponse: true);
        } else {
            $talkedSoFar[md5(implode(" ", $sentences))]=implode(" ", $sentences);
        }
        $totalBuffer.=trim($buffer);
        $totalProcessedData.=trim($buffer);
    }

    if ($actionsAllowedForTurn && $outputWasValid)  {
        $functionsEnabledBeforeActionProcessing = !empty($GLOBALS["FUNCTIONS_ARE_ENABLED"]);
        $GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
        $requestTypeForActions = (string)($gameRequest[0] ?? "");
        $isRechatActionContext = in_array($requestTypeForActions, ["rechat", "narration"], true);
        $rechatActionsAllowed = filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isRechatActionContext && !$rechatActionsAllowed) {
            $actions = [];
            $rawActionsForLog = [];
            Logger::info("[actions] Dropped LLM actions because rechat actions are disabled" . Logger::formatContext([
                "speaker" => $GLOBALS["SYNTH_NAME"] ?? "",
                "request_type" => $requestTypeForActions,
            ]));
        } else {
            $actions=$connectionHandler->processActions();
            $rawActionsForLog = is_array($actions) ? $actions : [];
            if (isset($GLOBALS["action_post_process_fnct"])) {
                $actions=$GLOBALS["action_post_process_fnct"]($actions);
            }

            // Extnded version which is an array, so we can hook more than one function
            if (isset($GLOBALS["action_post_process_fnct_ex"]) && is_array($GLOBALS["action_post_process_fnct_ex"])) {
                foreach ($GLOBALS["action_post_process_fnct_ex"] as $postFilterFunc)
                    $actions=$postFilterFunc($actions);
            }
            // Optional (off by default): a second bounded call may pick one negotiated action for an action-free reply.
            if (class_exists('Synth\Managers\ActionsManager\TwoStepActions', false)) $actions=Synth\Managers\ActionsManager\TwoStepActions::filterActions($actions,$totalProcessedData);
        }

        if (is_array($actions)) {
            require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'synth_command_payload.php');
            $actionNamesForLog = [];
            foreach ($actions as $actionForLog) {
                $decodedForLog = synthDecodeActionLine((string)$actionForLog);
                $nameForLog = $decodedForLog['action'] ?? '';
                if ($nameForLog !== "") {
                    $actionNamesForLog[] = $nameForLog;
                }
            }
            Logger::info("[actions] Processed LLM actions" . Logger::formatContext([
                "raw_count" => count($rawActionsForLog),
                "postfilter_count" => count($actions),
                "names" => $actionNamesForLog,
                "speaker" => $GLOBALS["SYNTH_NAME"] ?? "",
                "request_type" => $gameRequest[0] ?? "",
            ]));
            if (($gameRequest[0] ?? "") === "rechat") {
                Logger::info("[rechat] Rechat action pass" . Logger::formatContext([
                    "speaker" => $GLOBALS["SYNTH_NAME"] ?? "",
                    "actions" => $actionNamesForLog,
                    "action_count" => count($actions),
                ]));
            }
        }


        if (is_array($actions) && (sizeof($actions)>0)) {

            // ACTION POST-FILTER

            if ($GLOBALS["FUNCTIONS_ARE_ENABLED"]) {
                $isRolemasteredNpc = (
                    (!empty($GLOBALS["NPC_ROLEMASTERED"])) ||
                    synthResolveNpcRolemasterState($GLOBALS["SYNTH_NAME"] ?? '', ['load_lookup' => true])
                );
                $copyActions=[];
                $replaceAction = static function ($sourceAction, string $newAction, $newParameter = '') {
                    $decodedSource = synthDecodeActionLine((string)$sourceAction);
                    return synthEncodeActionLine(
                        (string)($decodedSource['actor'] ?? ''),
                        $newAction,
                        $newParameter
                    );
                };
                foreach ($actions as $n=>$action) {
                    $copyActions[$n]=$actions[$n];
                    $decodedAction = synthDecodeActionLine((string)$action);
                    $actionName = (string)($decodedAction['action'] ?? '');
                    $actionParts2 = array_merge([$actionName], array_values($decodedAction['parameter_args'] ?? []));

                    if (isset($actionParts2[1])) {
                        // Parameter part
                        if ($actionParts2[0]=="Attack") {
                            // Lets polish the parameters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $mang4=FindClosestNPCName($mang3[0]);

                            if ($mang4)
                                $actions[$n]=$replaceAction($action, "Attack", $mang4);
                            else
                                $actions[$n]=$replaceAction($action, "Attack", $mang3[0]);

                            error_log("[ACTION POSTFILTER Attack] $localtarget => {$mang3[0]} => $mang4");
                        } else if ($actionParts2[0]=="GiveItemTo") {
                            // Native ownership resolves the exact captured recipient later; never guess a nearby name here.
                            if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1) === 2) continue;
                            // Check if parameter is JSON (multi-param) - skip post-filtering for JSON
                            if (isset($actionParts2[1]) && substr(trim($actionParts2[1]), 0, 1) === '{') {
                                error_log("[ACTION POSTFILTER GiveItemTo] JSON parameter detected, skipping post-filter");
                                // Keep the action as-is for JSON parameters
                            } else {
                                $localtarget=$actionParts2[1] ?? "";
                                $itemArg=trim(strval($actionParts2[2] ?? ""));
                                $amountArg=trim(strval($actionParts2[3] ?? ""));
                                $mang1=explode(",",$localtarget);
                                $mang2=explode(" and ",$mang1[0]);
                                $mang3=explode("(",$mang2[0]);
                                $mang4=FindClosestActorName($mang3[0]);
                                error_log("[ACTION POSTFILTER GiveItemTo] $localtarget => {$mang3[0]} => $mang4");

                                $resolvedTarget = $mang4 ? $mang4 : trim($mang3[0]);
                                $rebuiltParameter = ['target' => $resolvedTarget];
                                if ($itemArg !== "") {
                                    $rebuiltParameter['item'] = $itemArg;
                                }
                                if ($amountArg !== "") {
                                    $rebuiltParameter['amount'] = $amountArg;
                                }
                                $actions[$n]=$replaceAction($action, "GiveItemTo", $rebuiltParameter);
                            }


                        }  else if ($actionParts2[0]=="TradeItems") {
                            // Lets polish the parammeters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);

                            $mang4=FindClosestActorName($mang3[0]);

                            error_log("[ACTION POSTFILTER TradeItems] $localtarget => {$mang3[0]} => $mang4");

                            if ($mang4)
                                $destination=$mang4;
                            else
                                $destination=$mang3[0];

                            error_log("[ACTION POSTFILTER TradeItems] $localtarget => {$mang3[0]} => $destination");

                            if ($destination!=$GLOBALS["PLAYER_NAME"])
                                $actions[$n]=$replaceAction($action, "TradeItems", $destination);

                        }  else if ($actionParts2[0]=="Follow") {
                            // Lets polish the parammeters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $requestedFollowTarget=trim($mang3[0]);
                            $playerNameForFollow=trim(strval($GLOBALS["PLAYER_NAME"] ?? ""));
                            $isPlayerFollowTarget=(
                                $requestedFollowTarget !== "" &&
                                (
                                    ($playerNameForFollow !== "" && strcasecmp($requestedFollowTarget, $playerNameForFollow) === 0) ||
                                    in_array(strtolower($requestedFollowTarget), ["player", "me", "the player"], true)
                                )
                            );

                            if ($isPlayerFollowTarget) {
                                error_log("[ACTION POSTFILTER Follow] $localtarget => {$requestedFollowTarget} => FollowPlayer");
                                $actions[$n]=$replaceAction($action, "FollowPlayer", "");
                                continue;
                            }

                            $mang4=FindClosestActorName($requestedFollowTarget);

                            error_log("[ACTION POSTFILTER Follow] $localtarget =>  {$mang3[0]} => $mang4");

                            if ($mang4)
                                $destination=$mang4;
                            else
                                $destination=$requestedFollowTarget;
                            $actions[$n]=$replaceAction($action, "Follow", $destination);


                            error_log("[ACTION POSTFILTER Follow] $localtarget => {$mang3[0]} => $destination");

                        } else if ($actionParts2[0]=="TravelTo") {
                            // Lets polish the parammeters
                            $localtarget=$actionParts2[1];
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $mang4=explode("--",$mang3[0]);

                            $destination=$mang4[0];

                            error_log("[ACTION POSTFILTER TravelTo]  $localtarget => {$mang4[0]} => $destination");

                            $destinationName=$GLOBALS["db"]->escape(trim($destination));
                            $dbDestination=$GLOBALS["db"]->fetchOne("SELECT name, similarity(name, '$destinationName') AS sim,formid FROM core_location_legacy ORDER BY sim DESC LIMIT 1");
                            $dbDestinationRegion=$GLOBALS["db"]->fetchOne("SELECT name, similarity(worldspace, '$destinationName') AS sim,formid FROM core_location_legacy ORDER BY sim DESC LIMIT 1");

                            $contextDestinations=DataPosibleLocationsToGo();

                            if (in_array(trim($localtarget),$contextDestinations)) {
                                // Perfect match
                                error_log("[ACTION POSTFILTER TravelTo] Seems valid as-is (context destination): <$localtarget> => $localtarget");
                                $travelPayload = function_exists('synthBuildTravelToActionPayload') ? synthBuildTravelToActionPayload($localtarget) : $localtarget;
                                $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);

                            } else if (in_array($destination,$contextDestinations)) {
                                error_log("[ACTION POSTFILTER TravelTo] Seemd valid (context destination): $localtarget => $destination");
                                $travelPayload = function_exists('synthBuildTravelToActionPayload') ? synthBuildTravelToActionPayload($destination) : $destination;
                                $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);

                            } else {
                                if ($isRolemasteredNpc) {
                                    if (stripos($destination,"home")===0) {
                                        // Rolemastered NPC wants to return back home
                                        $actions[$n]=$replaceAction($action, "ReturnBackHome", "");
                                        continue;

                                    }

                                }
                                if (is_array($dbDestination) && isset($dbDestination["formid"])) {
                                    $destination=trim(strval($dbDestination["name"] ?? $destination));
                                    error_log("[ACTION POSTFILTER TravelTo] found database entry for $localtarget => {$dbDestination["formid"]} => {$dbDestination["name"]}, similarity ({$dbDestination["sim"]})");
                                    $travelPayload = function_exists('synthBuildTravelToActionPayload') ? synthBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);

                                } else if (is_array($dbDestinationRegion) && isset($dbDestinationRegion["formid"])) {

                                    $destination=trim(strval($dbDestinationRegion["name"] ?? $destination));
                                    error_log("[ACTION POSTFILTER TravelTo] found database (searching by region) entry for $localtarget => {$dbDestinationRegion["formid"]} => {$dbDestinationRegion["name"]}, similarity ({$dbDestinationRegion["sim"]})");
                                    $travelPayload = function_exists('synthBuildTravelToActionPayload') ? synthBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                } else if (stripos($destination,"outside")!==false) {
                                    $destination=DataLastKnownLocationBaseHuman(false);
                                    error_log("[ACTION POSTFILTER TravelTo] reference to outside detected , $localtarget => $destination");
                                    $travelPayload = function_exists('synthBuildTravelToActionPayload') ? synthBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                } else {
                                    $travelPayload = function_exists('synthBuildTravelToActionPayload') ? synthBuildTravelToActionPayload($destination) : $destination;
                                    $actions[$n]=$replaceAction($action, "TravelTo", $travelPayload);
                                }
                            }

                        } else if ($actionParts2[0]=="MoveTo") {
                            // MoveTo is actor-only. Locations must remain TravelTo.
                            $localtarget=$actionParts2[1] ?? "";
                            $mang1=explode(",",$localtarget);
                            $mang2=explode(" and ",$mang1[0]);
                            $mang3=explode("(",$mang2[0]);
                            $mang4=explode("--",$mang3[0]);

                            $target=trim($mang4[0]);
                            $resolvedTarget="";

                            if ($target !== "" && isset($GLOBALS["PLAYER_NAME"]) && strcasecmp($target, $GLOBALS["PLAYER_NAME"]) === 0) {
                                $resolvedTarget=$GLOBALS["PLAYER_NAME"];
                            }

                            if ($resolvedTarget === "") {
                                foreach (DataPosibleMoveToTargets() as $candidateTarget) {
                                    if (strcasecmp($target, $candidateTarget) === 0) {
                                        $resolvedTarget=$candidateTarget;
                                        break;
                                    }
                                }
                            }

                            if ($resolvedTarget === "") {
                                $closestTarget=FindClosestActorName($target);
                                if ($closestTarget !== "" && levenshtein(strtolower($target), strtolower($closestTarget)) <= 3) {
                                    $resolvedTarget=$closestTarget;
                                }
                            }

                            if ($resolvedTarget === "") {
                                $resolvedTarget=$target;
                            }

                            error_log("[ACTION POSTFILTER MoveTo] $localtarget => $target => $resolvedTarget");
                            $actions[$n]=$replaceAction($action, "MoveTo", $resolvedTarget);

                        }  else if ($actionParts2[0]=="FollowPlayer") {

                            error_log("[ACTION POSTFILTER FollowPlayer] Just Cleaning here");
                            $actions[$n]=$replaceAction($action, "FollowPlayer", "");

                        }  else if ($actionParts2[0]=="ReturnBackHome") {

                            error_log("[ACTION POSTFILTER ReturnBackHome] Just Cleaning here");
                            $actions[$n]=$replaceAction($action, "ReturnBackHome", "");

                        } else if ($actionParts2[0]=="PickupItem") {
                            // Parse item parameter - can be JSON or plain string
                            $itemParam = trim($actionParts2[1]);

                            Logger::info("[PickupItem PostFilter] Raw LLM item parameter: '{$itemParam}'");

                            // Check if parameter is JSON (multi-param format)
                            if (substr($itemParam, 0, 1) === '{') {
                                // JSON format: {"target":"","item":"0xFF00550D:Diamond"}
                                $params = json_decode($itemParam, true);
                                $itemParam = isset($params['item']) ? trim($params['item']) : '';
                                Logger::info("[PickupItem PostFilter] Extracted item from JSON: '{$itemParam}'");
                            }
                            $itemParam = trim($itemParam, " \t\n\r\0\x0B`\"'");

                            // If still empty, can't proceed
                            if (empty($itemParam)) {
                                Logger::warn("[PickupItem PostFilter] No item parameter provided, skipping");
                                continue;
                            }

                            $itemsStr = DataItemsInCloseRange();
                            if ($itemsStr !== "") {
                                $itemsList = explode(',', $itemsStr);

                                    Logger::info("[PickupItem PostFilter] Found " . count($itemsList) . " items in database");
                                    Logger::info("[PickupItem PostFilter] First 3 items: " . implode(' | ', array_slice($itemsList, 0, 3)));

                                    $foundItem = false;

                                    // Check if LLM provided the RefID:ItemName format
                                    if (preg_match('/^0x[0-9A-Fa-f]+:/', $itemParam)) {
                                        // LLM provided "0xRefID:ItemName", extract the RefID
                                        $paramParts = explode(':', $itemParam, 2);
                                        $paramRefID = $paramParts[0];

                                        Logger::info("[PickupItem PostFilter] LLM provided RefID: {$paramRefID}, searching for exact match...");

                                        // Search for exact RefID match
                                        foreach ($itemsList as $itemEntry) {
                                            // Parse "RefID:BaseID:ItemName" from database
                                            $entryParts = explode(':', trim($itemEntry), 3);
                                            if (count($entryParts) >= 3) {
                                                $refID = $entryParts[0];
                                                $itemName = $entryParts[2];

                                                // Exact RefID match (case-insensitive)
                                                if (strcasecmp($refID, $paramRefID) === 0) {
                                                    // Send RefID:ItemName without (STEALING) tag to game
                                                    $cleanItemName = str_replace(' (STEALING)', '', $itemName);
                                                    $cleanFormat = "{$refID}:{$cleanItemName}";
                                                    Logger::info("[PickupItem PostFilter] EXACT MATCH FOUND! Sending: {$cleanFormat}");
                                                    $actions[$n]=$replaceAction($action, "PickupItem", $cleanFormat);
                                                    $foundItem = true;
                                                    break;
                                                }
                                            }
                                        }

                                        if (!$foundItem) {
                                            Logger::warn("[PickupItem PostFilter] No exact match found for RefID: {$paramRefID}");
                                            Logger::warn("[PickupItem PostFilter] Item may have despawned or moved. Available RefIDs: " .
                                                implode(', ', array_map(function($item) {
                                                    $parts = explode(':', trim($item), 3);
                                                    return $parts[0] ?? 'invalid';
                                                }, array_slice($itemsList, 0, 10))));
                                        }
                                    } else {
                                        // LLM provided just the item name, search by name
                                        foreach ($itemsList as $itemEntry) {
                                            $entryParts = explode(':', trim($itemEntry), 3);
                                            if (count($entryParts) >= 3) {
                                                $refID = $entryParts[0];
                                                $itemName = $entryParts[2];

                                                // Strip (STEALING) tag for comparison
                                                $cleanItemName = str_replace(' (STEALING)', '', $itemName);

                                                if (stripos($cleanItemName, $itemParam) !== false) {
                                                    // Send RefID:ItemName without (STEALING) tag to game
                                                    $displayFormat = "{$refID}:{$cleanItemName}";
                                                    $actions[$n]=$replaceAction($action, "PickupItem", $displayFormat);
                                                    $foundItem = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                            }

                        }
                    }

                }
            }

            require_once(dirname(__DIR__, 3).'/lib' . DIRECTORY_SEPARATOR . 'synth_command_payload.php');
            if (is_array($actions)) {
                $actions = array_map(static function ($action) {
                    $decodedAction = synthDecodeActionLine((string)$action);
                    $actor = trim((string)($decodedAction['actor'] ?? ''));
                    $actionName = trim((string)($decodedAction['action'] ?? ''));
                    if ($actionName === '') {
                        return (string)$action;
                    }
                    return synthEncodeActionLine(
                        $actor,
                        $actionName,
                        '',
                        (string)($decodedAction['parameter_string'] ?? '')
                    );
                }, $actions);
            }

            // Log actions
            foreach ($actions as $n=>$singleaction) {
                $decodedAction = synthDecodeActionLine((string)$singleaction);
                $actionName = trim((string)($decodedAction['action'] ?? ''));
                $actorName = trim((string)($decodedAction['actor'] ?? ''));
                if ($actionName === '') {
                    continue;
                }

                $GLOBALS["db"]->insert(
                    'core_action_issued',
                    array(
                        'action' => $actionName,
                        'fullcall' =>$singleaction,
                        'actorname'=> isset($GLOBALS["PATCH_ACTION_ALL_ACTORS"])?$GLOBALS["PATCH_ACTION_ALL_ACTORS"]:$actorName,
                        'ts' => $gameRequest[1],
                        'gamets' => $gameRequest[2],
                        'localts'=>time(),
                        'original'=>function_exists('synthActionCatalogApplyFollowupChainToActionsIssuedOriginal')
                            ? synthActionCatalogApplyFollowupChainToActionsIssuedOriginal($copyActions[$n])
                            : $copyActions[$n]
                    )
                );


            }
            $GLOBALS["DEBUG_DATA"]["response"][]=$actions;

            $requestTypeForActionEmit = (string)($gameRequest[0] ?? "");
            $isRechatActionEmitContext = in_array($requestTypeForActionEmit, ["rechat", "narration"], true);
            $rechatActionEmitAllowed = filter_var($GLOBALS["RECHAT_ALLOW_ACTIONS"] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isRechatActionEmitContext && !$rechatActionEmitAllowed) {
                Logger::info("[actions] Suppressed plugin action emission because rechat actions are disabled" . Logger::formatContext([
                    "speaker" => $GLOBALS["SYNTH_NAME"] ?? "",
                    "request_type" => $requestTypeForActionEmit,
                    "action_count" => count($actions),
                ]));
                $actions = [];
            }

            $pluginOutputLogLines = [];
            // Queue actions for the JSON response and mirror the structured records to output_to_plugin.log.
            foreach ($actions as $action) {
                Logger::info("Echoing action to plugin: {$action}");
                if (function_exists('synth_buffer_command_response_line')) {
                    $decodedActionLine = synthDecodeActionLine((string)$action);
                    $speaker = trim((string)($decodedActionLine['actor'] ?? ""));
                    if ($speaker === "") {
                        $speaker = trim((string)($GLOBALS["SYNTH_NAME"] ?? "rolemaster"));
                    }

                    $commandName = trim((string)($decodedActionLine['action'] ?? ""));
                    if ($commandName === "") {
                        continue;
                    }
                    $commandArgsForResponse = array_values($decodedActionLine['parameter_args'] ?? []);
                    $payload = synthEncodeCommandAction($commandName, $commandArgsForResponse);
                    $responseMetadata = [
                        "request_type" => $requestTypeForActionEmit,
                    ];
                    $structuredParameter = $decodedActionLine['parameter'] ?? null;
                    if (!is_array($structuredParameter)) {
                        $parameterString = trim(strval($decodedActionLine['parameter_string'] ?? ''));
                        if ($parameterString !== '' && $parameterString[0] === '{') {
                            $decodedParameter = json_decode($parameterString, true);
                            if (is_array($decodedParameter)) {
                                $structuredParameter = $decodedParameter;
                            }
                        }
                    }
                    if (is_array($structuredParameter)) {
                        foreach ($structuredParameter as $metadataKey => $metadataValue) {
                            if (is_string($metadataKey) && is_scalar($metadataValue)) {
                                $responseMetadata[$metadataKey] = $metadataValue;
                            }
                        }
                    }
                    synth_buffer_command_response_line($speaker, $payload, $responseMetadata);
                    $decodedCommand = synthDecodeCommandAction($payload);
                    $commandPayload = $decodedCommand["command_payload"];
                    $pluginOutputLogLines[] = json_encode([
                        "schema" => "synth.response.line.v1",
                        "speaker" => $speaker,
                        "action" => "rolecommand",
                        "request_type" => $requestTypeForActionEmit,
                        "text" => $decodedCommand["command_name"],
                        "command" => $commandPayload,
                        "command_name" => $decodedCommand["command_name"],
                        "command_args" => $decodedCommand["command_args"],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    Logger::error("[actions] JSON response buffer is unavailable; action was not queued");
                }
            }

            $actionOutput = implode(PHP_EOL, $pluginOutputLogLines);
            if ($actionOutput !== "") {
                $actionOutput .= PHP_EOL;
            }
            $outputToPluginLog = dirname(__DIR__, 3).'/lib'."/../log/output_to_plugin.log";
            Logger::rotateLogIfTooLarge($outputToPluginLog);
            Logger::info("[plugin-output] queued action batch" . Logger::formatContext([
                "count" => count($actions),
                "request_type" => $gameRequest[0] ?? "",
                "speaker" => $GLOBALS["SYNTH_NAME"] ?? "",
            ]));
            if ($actionOutput !== "") {
                file_put_contents($outputToPluginLog,$actionOutput, FILE_APPEND | LOCK_EX);
            }

        }
        $GLOBALS["FUNCTIONS_ARE_ENABLED"] = $functionsEnabledBeforeActionProcessing;
    }

    if (isset($GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) && $GLOBALS["CLEAN_CONTEXT_FOCUS_CHAT"]) {
        ;//Was a faked stream
    } else
        $connectionHandler->close('standard');
    //fwrite($fileLog, $totalBuffer . PHP_EOL); // Write the line to the file with a line break // DEBUG CODE


    return $outputWasValid;
}

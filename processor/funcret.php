<?php
if (!function_exists('synthActionShouldEmitDebugNotification')) {
	function synthActionShouldEmitDebugNotification($functionCodeName)
	{
		if (!function_exists('synthGetActionCatalogRow')) {
			return false;
		}

		$row = synthGetActionCatalogRow($functionCodeName);
		if (!is_array($row)) {
			return false;
		}

		$metadata = $row['metadata'] ?? [];
		if (!is_array($metadata)) {
			return false;
		}

		return !empty($metadata['debug_notification']) || !empty($metadata['top_left_notification']);
	}
}

if (!function_exists('synthSanitizeDebugNotificationText')) {
	function synthSanitizeDebugNotificationText($text)
	{
		$text = str_replace(["\r", "\n"], ' ', strval($text));
		$text = str_replace('@', ' at ', $text);
		$text = str_replace('|', '/', $text);
		$text = preg_replace('/\s+/', ' ', $text);
		return trim(strval($text));
	}
}

if (!function_exists('synthBuildMetadataFollowupRequest')) {
	function synthBuildMetadataFollowupRequest($requestText, $promptText)
	{
		$requestText = trim(strval($requestText));
		$promptText = trim(strval($promptText));
		if ($promptText === '') {
			return $requestText;
		}
		if ($requestText === '') {
			return "({$promptText})";
		}

		return "({$promptText}) {$requestText}";
	}
}

if (!function_exists('synthShouldSkipFuncretInfoActionLog')) {
	function synthShouldSkipFuncretInfoActionLog($functionCodeName)
	{
		$functionCodeName = trim(strval($functionCodeName));
		if ($functionCodeName === '') {
			return true;
		}

		if (function_exists('isNarratorPrivateActionName') && isNarratorPrivateActionName($functionCodeName)) {
			return true;
		}

		return function_exists('synthActionCatalogMetadataFlagEnabled')
			&& synthActionCatalogMetadataFlagEnabled($functionCodeName, 'suppress_placeholder_infoaction');
	}
}

if (!function_exists('synthLogFuncretResultInfoAction')) {
	function synthLogFuncretResultInfoAction($functionCodeName, $argName, $argValue, $resultText)
	{
		if (!function_exists('logEvent') || !function_exists('synthBuildFuncretResultInfoActionMessage')) {
			return false;
		}

		if (synthShouldSkipFuncretInfoActionLog($functionCodeName)) {
			return false;
		}

		$message = synthBuildFuncretResultInfoActionMessage($functionCodeName, $argName, $argValue, $resultText);
		if ($message === '') {
			return false;
		}

		$gameRequestCopy = $GLOBALS["gameRequest"] ?? [];
		if (!is_array($gameRequestCopy)) {
			return false;
		}

		$gameRequestCopy[0] = "infoaction";
		$gameRequestCopy[3] = $message;
		logEvent($gameRequestCopy);
		return true;
	}
}

$funcretPayload = $gameRequest[3] ?? '';
$decodedFuncretPayload = is_string($funcretPayload) ? json_decode($funcretPayload, true) : null;
if (is_array($decodedFuncretPayload)) {
	$returnFunction = [
		'command',
		trim(strval($decodedFuncretPayload['action'] ?? $decodedFuncretPayload['command'] ?? '')),
		trim(strval($decodedFuncretPayload['target'] ?? $decodedFuncretPayload['arg'] ?? '')),
		trim(strval($decodedFuncretPayload['result'] ?? $decodedFuncretPayload['message'] ?? '')),
	];
} else {
	Logger::warn("Ignoring non-JSON funcret payload");
	return;
}

$nativeActionEvent = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
if (($nativeActionEvent['protocol_version'] ?? 1) === 2) {
	$lastCallId = synth_protocol_action_tool_call_id($nativeActionEvent, $decodedFuncretPayload);
} elseif (file_exists(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . ".last_tool_call_openai.id.txt")) {
	$lastCallId = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . ".last_tool_call_openai.id.txt");
} else {
	$lastCallId = "";
}

$functionDisplayName = getFunctionDisplayName($returnFunction[1]);

$functionCodeName = $functionDisplayName;

$functionCodeName = $returnFunction[1];

$useFunctionsAgain = false;
$argName = "target";
$currentFollowupChainDepth = 0;
$followupConfig = function_exists('synthActionCatalogGetResolvedFollowupConfig')
	? synthActionCatalogGetResolvedFollowupConfig($functionCodeName)
	: [];
$argName = function_exists('synthResolveFuncretArgumentName')
	? synthResolveFuncretArgumentName($functionCodeName, $followupConfig)
	: trim(strval($followupConfig['arg_name'] ?? 'target'));
if ($argName === '') {
	$argName = 'target';
}

$toolArguments = [$argName => $returnFunction[2] ?? ''];
$nativeTransfer = ($nativeActionEvent['protocol_version'] ?? 1) === 2 && in_array($functionCodeName,['GiveItemTo','GiveCapsTo','TakeCapsFromPlayer'],true);
if ($nativeTransfer) {
	// These arguments were restored from the exact owned intent, not supplied by the receipt or follow-up model.
	$toolArguments = $decodedFuncretPayload['arguments'] ?? null;
	if (!synthNativeTransferParametersValid($toolArguments,$functionCodeName!=='GiveItemTo')) throw new RuntimeException('action_transfer_arguments_unavailable');
}
synthLogFuncretResultInfoAction($functionCodeName, $argName,
	$nativeTransfer ? $toolArguments : ($returnFunction[2] ?? ''),
	$returnFunction[3] ?? '');

if (isset($returnFunction[2])) {
	// Patch. 
	$returnFunction[2] = trim($returnFunction[2]);

	error_log("[SYNTH] Checking <$functionCodeName> <{$returnFunction[1]}>");

	$followupEnabled = !empty($followupConfig['enabled']);
	$followupPrompt = trim(strval($followupConfig['prompt'] ?? ''));

	if (!$followupEnabled) {
		terminate();

	}

	$useFunctionsAgain = !empty($followupConfig['use_functions_again']);
	$currentFollowupChainDepth = function_exists('synthActionCatalogGetLastIssuedActionFollowupChainDepth')
		? intval(synthActionCatalogGetLastIssuedActionFollowupChainDepth($functionCodeName))
		: 0;
	$followupChainLimit = function_exists('synthActionCatalogGetFollowupChainLimit')
		? intval(synthActionCatalogGetFollowupChainLimit())
		: 1;

	if ($useFunctionsAgain && $followupChainLimit > 0 && $currentFollowupChainDepth >= $followupChainLimit) {
		error_log("[SYNTH] Follow-up action chaining disabled for {$functionCodeName}: depth {$currentFollowupChainDepth} reached limit {$followupChainLimit}");
		$useFunctionsAgain = false;
	}

	if ($followupPrompt !== '') {
		$request = synthBuildMetadataFollowupRequest($request, $followupPrompt);

	} else {
		terminate();
	}
	$functionCalled[] = array(
		'role' => 'assistant',
		'content' => null,
		'tool_calls' => [
			array(
				"id" => $lastCallId,
				"type" => "function",
				"function" => ["name" => $functionDisplayName, "arguments" => json_encode(
					$toolArguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
			)
		]
	);

} else {
	$functionCalled[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
		'id' => $lastCallId, 'type' => 'function', 'function' => ['name' => $functionDisplayName,
			'arguments' => json_encode([$argName => ''], JSON_THROW_ON_ERROR)],
	]]];
}

$debugNotificationText = synthSanitizeDebugNotificationText($returnFunction[3] ?? '');
if ($debugNotificationText !== '' && synthActionShouldEmitDebugNotification($functionCodeName)) {
	$notificationSpeaker = trim(strval($GLOBALS["SYNTH_NAME"] ?? ''));
	if ($notificationSpeaker === '') {
		$notificationSpeaker = 'The Narrator';
	}

	synth_buffer_command_response_line($notificationSpeaker, "DebugNotification", ["message" => $debugNotificationText]);
}

$actionToolResult = ['role' => 'tool', 'content' => "{$returnFunction[3]}", 'tool_call_id' => "$lastCallId"];
if (($nativeActionEvent['protocol_version'] ?? 1) === 2) {
	// Keep the bound effective outcome (including unavailable inventory) with its exact tool pair.
	$actionToolResult['_synth_action_status'] = $decodedFuncretPayload['status'];
}
$returnFunctionArray[] = $actionToolResult;

$returnFunctionArray[] = array('role' => $LAST_ROLE, 'content' => $request);


$contextData = array_merge($head, ($contextDataFull), $functionCalled, $returnFunctionArray);

file_put_contents(__DIR__ . "/../log/context_for_{$GLOBALS["SYNTH_NAME"]}_after_func.txt", print_r($contextData, true));

if ($useFunctionsAgain) {
	$GLOBALS["FUNCTIONS_ARE_ENABLED"] = true;
	$GLOBALS["FUNCTIONS"];
	$GLOBALS["FUNCTIONS_FORCE_CALL"] = "auto";
	$GLOBALS["FOLLOWUP_CHAIN_NEXT_DEPTH"] = $currentFollowupChainDepth + 1;
} else {
	$GLOBALS["FUNCTIONS_ARE_ENABLED"] = false;
	unset($GLOBALS["FOLLOWUP_CHAIN_NEXT_DEPTH"]);
}

?>

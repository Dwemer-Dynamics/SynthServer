<?php

require_once(__DIR__.DIRECTORY_SEPARATOR.'worldknowledge_topic.php');





function storeMemory($embeddings, $text, $id, $category='past dialogues' )
{
    // Numeric native IDs are a separate namespace, not permission to update a legacy embedding.
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2) return false;

	$url = $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["TXTAI_URL"].'/embed';
	$data = [
		'text' => $text
	];

	// Convert to JSON
	$options = [
		'http' => [
			'method'  => 'POST',
			'header'  => "Content-Type: application/json\r\n" .
						"Accept: application/json\r\n",
			'content' => json_encode($data),
			'ignore_errors' => true // to capture error messages if any
		]
	];

	// Create context and send the request
	$context  = stream_context_create($options);
	$response = file_get_contents($url, false, $context);

	// Output the response
	if ($response === false) {
		Logger::error("Request failed.\n");
		return false;
	} else {
		Logger::info("Request done:\n");
	}

	$vector = json_decode($response, true);
	
	// Check if JSON decode was successful and embedding exists
	if ($vector === null || !isset($vector["embedding"])) {
		Logger::error("Invalid response format or missing embedding data: " . $response);
		return false;
	}

	// Handle both array and string formats for embedding
	$embedding_data = $vector["embedding"];
	if (is_string($embedding_data)) {
		// If it's already a string, use it directly (might be JSON string)
		$embedding_str = $embedding_data;
	} else if (is_array($embedding_data)) {
		// If it's an array, convert to comma-separated string
		$embedding_str = implode(",", $embedding_data);
	} else {
		Logger::error("Embedding data is neither string nor array: " . gettype($embedding_data));
		return false;
	}

	$GLOBALS["db"]->execQuery("update memory_summary set embedding='[" . $embedding_str . "]' where rowid=$id");
	return true;
}

function storeMemoryWorldKnowledge($embeddings, $text, $id)
{

	$url = $GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["TXTAI_URL"].'/embed';
	$data = [
		'text' => $text
	];

	// Convert to JSON
	$options = [
		'http' => [
			'method'  => 'POST',
			'header'  => "Content-Type: application/json\r\n" .
						"Accept: application/json\r\n",
			'content' => json_encode($data),
			'ignore_errors' => true // to capture error messages if any
		]
	];

	// Create context and send the request
	$context  = stream_context_create($options);
	$response = file_get_contents($url, false, $context);

	// Output the response
	if ($response === false) {
		Logger::error("Request failed.\n");
		return false;
	} else {
		Logger::info("Request done:\n");
	}

	$vector = json_decode($response, true);
	
	// Check if JSON decode was successful and embedding exists
	if ($vector === null || !isset($vector["embedding"])) {
		Logger::error("Invalid response format or missing embedding data: " . $response);
		return false;
	}

	// Handle both array and string formats for embedding
	$embedding_data = $vector["embedding"];
	if (is_string($embedding_data)) {
		// If it's already a string, use it directly (might be JSON string)
		$embedding_str = $embedding_data;
	} else if (is_array($embedding_data)) {
		// If it's an array, convert to comma-separated string
		$embedding_str = implode(",", $embedding_data);
	} else {
		Logger::error("Embedding data is neither string nor array: " . gettype($embedding_data));
		return false;
	}

	$canonicalTopic = synthWorldKnowledgeCanonicalTopic($id);
	$cleanedid = $GLOBALS["db"]->escape($canonicalTopic);
	$GLOBALS["db"]->execQuery("update worldknowledge set vector384='[" . $embedding_str . "]' where lower(split_part(topic, ',', 1))=lower('$cleanedid')");
	return true;
}

function countMemories()
{
	if (!isset($GLOBALS["db"]) || !is_object($GLOBALS["db"])) {
		return 0;
	}

    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2) {
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return 0;
        require_once __DIR__.'/synth_memory.php';
        $db=$GLOBALS['db'];
        return $db->withNativeRequestLock(static function($connection,array $event) use ($db) {
            $result=@pg_query($connection,synth_memory_visible_batch_cte($db,$event)
                .'SELECT count(*) AS n FROM synth_meta.memory_summaries s JOIN native_memory_batches b ON b.id=s.batch_id');
            if (!$result) throw new RuntimeException('Unable to count native memories');
            return intval(pg_fetch_assoc($result)['n']);
        },2000);
    }
	$row = $GLOBALS["db"]->fetchOne("SELECT count(*) AS n FROM synth.memory_summary");
	return intval($row["n"] ?? 0);
}

function deleteElement($id, $onlyEmbedding = false)
{
    if (($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? 1)===2) return false;
	if (!isset($GLOBALS["db"]) || !is_object($GLOBALS["db"])) {
		return false;
	}

	$cleanId = intval($id);
	if ($cleanId <= 0) {
		return false;
	}

	if ($onlyEmbedding) {
		$GLOBALS["db"]->execQuery("UPDATE synth.memory_summary SET embedding = NULL WHERE rowid = {$cleanId}");
	} else {
		$GLOBALS["db"]->execQuery("DELETE FROM synth.memory_summary WHERE rowid = {$cleanId}");
	}

	return true;
}



function queryMemory($embeddings, $category='past dialogues', $limitNpc="")
{
	$rawText = "";
	if (is_array($embeddings)) {
		$rawText = implode(" ", array_map('strval', $embeddings));
	} else {
		$rawText = (string)$embeddings;
	}

	$rawText = trim($rawText);
	if ($rawText === "") {
		return ["content" => []];
	}

	$npcFilter = trim((string)$limitNpc);
	if ($npcFilter === "") {
		$npcFilter = trim((string)($GLOBALS["SYNTH_NAME"] ?? ""));
	}

	if (!isset($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]) || !$GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]) {
		return ["content" => []];
	}

	if (!function_exists('DataSearchMemory') || !function_exists('DataSearchMemoryByVector')) {
		if (class_exists('Logger')) {
			Logger::warn("queryMemory called before SYNTH memory search helpers were loaded.");
		}
		return ["content" => []];
	}

	$results = null;
	if (!empty($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["USE_TEXT2VEC"])) {
		$results = DataSearchMemoryByVector($rawText, $npcFilter, false, 0);
	}

	if (!is_array($results) || empty($results)) {
		$results = DataSearchMemory($rawText, $npcFilter);
	}

	if (!is_array($results)) {
		return ["content" => []];
	}

	return synthMemoryQueryContent($results,$category);
}

// Preserve recall identity and evidence provenance at the legacy-shaped request boundary.
function synthMemoryQueryContent(array $results, $category='past dialogues'): array
{
	$content = [];
	foreach ($results as $row) {
		if (!is_array($row)) {
			continue;
		}

		$summary = trim((string)($row["summary"] ?? ""));
		if ($summary === "") {
			continue;
		}

		$source = strval($row['memory_source'] ?? 'legacy');
		$native = in_array($source,['native','native_diary'],true);
		$distance = isset($row["distance"]) ? floatval($row["distance"]) : null;
		if ($distance === null && !$native) {
			$rankAny = isset($row["rank_any"]) ? floatval($row["rank_any"]) : 0.0;
			$rankAll = isset($row["rank_all"]) ? floatval($row["rank_all"]) : 0.0;
			$distance = max(0.0, 1.0 - (($rankAny + $rankAll) / 2.0));
		}

		$content[] = [
			"briefing" => $summary,
			"content" => $summary,
			"timestamp" => intval($row["gamets_truncated"] ?? 0),
			// Legacy CRUD IDs must not address an unrelated row when a native result has the same number.
			"memory_id" => $native ? 0 : intval($row["rowid"] ?? $row["uid"] ?? 0),
			"memory_source" => $source,
			"native_memory_id" => $source === 'native' ? intval($row['rowid']) : null,
			"native_diary_id" => $source === 'native_diary' ? intval($row['rowid']) : null,
			"summary_revision" => $native ? ($row['summary_revision'] ?? null) : null,
			"classifier" => (string)($row["classifier"] ?? $category),
			"distance" => $distance,
			"rank_any" => $row["rank_any"] ?? null,
			"rank_all" => $row["rank_all"] ?? null,
		];
	}

	return ["content" => $content];
}



?>

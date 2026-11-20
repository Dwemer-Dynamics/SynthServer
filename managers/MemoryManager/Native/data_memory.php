<?php

// Moved from lib/data_functions.php: closest-actor lookups, short-term memory and memory/world knowledge search
// The original file requires this one, so every caller is unchanged.

// Find actor name with closest name, useful to sanitize actions parameters
function FindClosestActorName($actorName)
{
    $beingsArrayCleaned = synthNearbyActorNamesFromPayload(true, false);

    if (empty($beingsArrayCleaned)) {
        return "";
    }

    // Find the closest match using Levenshtein distance
    $closest = null;
    $shortest = -1;

    foreach ($beingsArrayCleaned as $name) {
        $lev = levenshtein($actorName, $name);

        if ($lev == 0) {
            return $name; // Exact match
        }

        if ($lev < $shortest || $shortest < 0) {
            $closest = $name;
            $shortest = $lev;
        }
    }

    return $closest;
}

function FindClosestNPCName($actorName)
{
    $beingsArrayCleaned = synthNearbyActorNamesFromPayload(true, false);

    if (empty($beingsArrayCleaned)) {
        return $actorName;
    }

    // Find the closest match using Levenshtein distance
    $closest = null;
    $shortest = -1;

    foreach ($beingsArrayCleaned as $name) {
        $lev = levenshtein($actorName, $name);
        error_log("Comparing: $actorName, $name");

        if ($lev == 0) {
            return $name; // Exact match
        }

        if ($lev < $shortest || $shortest < 0) {
            $closest = $name;
            $shortest = $lev;
        }
    }

    return (!empty(trim($closest)))?$closest:$actorName;
}

function DirectConversationsWith($actor, $speaker="")
{

    $db = \Synth\Bootstrap::db();
    $i_res = 0;

    if ($speaker=="")
        $speakerprmt=$db->escape(GetOriginalSYNTHName());
    else
        $speakerprmt=$db->escape($speaker);

    $listenerprmt=$db->escape($actor);
    $gametsLimit=round((\Synth\Bootstrap::gameRequest()[2]??0)-(getGametsLimitFor($actor)/0.0000024),0);
    $lastLoc=$db->fetchAll("SELECT count(*) as N FROM core_speech WHERE (speaker='$speakerprmt' and listener='$listenerprmt') OR (listener='$speakerprmt' and speaker='$listenerprmt') and gamets<$gametsLimit");

    if (!is_array($lastLoc) || sizeof($lastLoc)==0) {
        Logger::warn("DirectConversationsWith: zero interactions {$speakerprmt} - {$listenerprmt} ");
    } else {
        $i_res = intval($lastLoc[0]["n"]);
    }
    //error_log(" --- dbg DirectConversationsWith: |{$i_res}| {$speakerprmt} - {$listenerprmt} ");
    return $i_res;

}

function isIndividualMemoryEnabledForNpc($npcName)
{
    static $cache = [];

    $npcName = trim((string) $npcName);
    if ($npcName === '' || $npcName === '%' || strpos($npcName, '%') !== false || strpos($npcName, '_') !== false) {
        return false;
    }

    if (isset($cache[$npcName])) {
        return $cache[$npcName];
    }

    $enabled = false;
    try {
        $escaped = \Synth\Bootstrap::db()->escape($npcName);
        $row = \Synth\Bootstrap::db()->fetchOne("SELECT extended_data FROM core_npc WHERE npc_name='$escaped' LIMIT 1");
        if (is_array($row) && !empty($row["extended_data"])) {
            $extendedData = json_decode($row["extended_data"], true);
            if (
                is_array($extendedData)
                && array_key_exists('individual_memory_enabled', $extendedData)
                && $extendedData['individual_memory_enabled'] !== null
                && $extendedData['individual_memory_enabled'] !== ''
            ) {
                $enabled = !empty($extendedData['individual_memory_enabled']);
            }
        }
    } catch (Throwable $e) {
        Logger::warn("isIndividualMemoryEnabledForNpc failed for {$npcName}: " . $e->getMessage());
    }

    $cache[$npcName] = $enabled;
    return $enabled;
}

/** Return the newest completed dialogue summary older than the live context window. */
function DataShortTermMemoryFor($npcName, int $contextWindowFloor): array
{
    $npcName = trim((string)$npcName);
    if ($npcName === '' || $npcName === 'The Narrator' || $contextWindowFloor <= 0) {
        return [];
    }
    if (!filter_var(
        \Synth\Bootstrap::config('FEATURES')['MEMORY_EMBEDDING']['ENABLED'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    )) {
        return [];
    }
    if (!isset($GLOBALS['db']) || !is_object(\Synth\Bootstrap::db()) || !method_exists(\Synth\Bootstrap::db(), 'fetchOne')) {
        return [];
    }

    try {
        if ((\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1) === 2) {
            $rows = synth_memory_recall(\Synth\Bootstrap::db(),$npcName,[],$contextWindowFloor,null,true);
            $row = $rows[0] ?? [];
        } else {
            $scopeConditionSql = dataGetMemoryScopeConditionSql($npcName);
            $companionConditionSql = dataGetMemoryCompanionConditionSql(
                $npcName,
                'companions',
                'classifier'
            );
            $row = \Synth\Bootstrap::db()->fetchOne(
                "SELECT summary, gamets_truncated FROM public.core_memory"
                . " WHERE source_type='summary' AND classifier='dialogue'"
                . " AND NULLIF(BTRIM(COALESCE(summary, '')), '') IS NOT NULL"
                . " AND gamets_truncated < " . intval($contextWindowFloor)
                . " AND {$scopeConditionSql} AND {$companionConditionSql}"
                . " ORDER BY gamets_truncated DESC, rowid DESC LIMIT 1"
            );
        }
    } catch (SYNTHProtocolCancelled $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        Logger::warn("DataShortTermMemoryFor failed for {$npcName}: " . $exception->getMessage());
        return [];
    }

    $summary = trim(strval($row['summary'] ?? ''));
    if ($summary === '') {
        return [];
    }
    return [[
        'role' => 'user',
        'content' => "#MEMORY: {$npcName} remembers this recent summary: [{$summary}]",
    ]];
}

function dataGetMemoryScopeConditionSql($npcName)
{
    if (isIndividualMemoryEnabledForNpc($npcName)) {
        $npcEsc = \Synth\Bootstrap::db()->escape($npcName);
        try {
            $scopedMemory = \Synth\Bootstrap::db()->fetchOne(
                "SELECT 1 FROM core_memory WHERE source_type='summary' AND scope='$npcEsc' AND NULLIF(BTRIM(COALESCE(summary, '')), '') IS NOT NULL LIMIT 1"
            );
            if (is_array($scopedMemory) && count($scopedMemory) > 0) {
                return "scope='$npcEsc'";
            }
        } catch (Throwable $e) {
            Logger::warn("dataGetMemoryScopeConditionSql failed for {$npcName}: " . $e->getMessage());
        }
    }

    return "(scope IS NULL OR scope='global')";
}

function dataGetMemoryCompanionConditionSql(
    $npcName,
    string $column = 'companions',
    string $classifierColumn = 'classifier'
): string
{
    $npcName = trim((string)$npcName);
    if ($npcName === '') {
        $narratorOnlyDiaryAccess = filter_var(
            \Synth\Bootstrap::config('NARRATOR_ONLY_DIARY_ACCESS') ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$narratorOnlyDiaryAccess) {
            // By default, the narrator searches every NPC diary in the global memory bank.
            return 'TRUE';
        }

        $narratorName = \Synth\Bootstrap::db()->escape('The Narrator');
        return "(COALESCE($classifierColumn, '') NOT IN ('diary','auto_diary','backgroundlife_diary')"
            . " OR $column LIKE '%|$narratorName|%' OR $column='$narratorName')";
    }

    $npcEsc = \Synth\Bootstrap::db()->escape($npcName);
    return "($column LIKE '%|$npcEsc|%' OR $column='$npcEsc')";
}

// Removes request-routing labels before text and vector memory matching.
function synthNormalizeMemorySearchInput($rawstring): string
{
    $text = (string)$rawstring;
    $playerName = trim((string)(\Synth\Bootstrap::config('PLAYER_NAME') ?? ''));
    if ($playerName !== '') {
        $text = str_replace("{$playerName}:", '', $text);
    }

    $text = strtr($text, [
        'Talking to The Narrator' => '',
        'Whispering to The Narrator' => '',
        'Speaking privately to The Narrator' => '',
    ]);
    $text = preg_replace('/\(Context location:[^)]+?\)/i', '', $text);
    $text = preg_replace('/\((?:(?:talking|whispering|shouting)|speaking privately)\s+to\s+[^()]+\)/i', '', $text);

    return trim((string)$text);
}

function DataSearchMemory($rawstring,$npcfilter,$timeThreshold=0) {

    $nativeMemory = (\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1) === 2;
    if ($nativeMemory) {
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return [];
        synth_protocol_assert_request_active();
    }

    //$kw=explode(" ",($rawstring));
    if (is_array($rawstring)) {
        $kwStringAny=implode(" | ",$rawstring);
        $kwStringAll=implode(" & ",$rawstring);

    } else if (isMinimeT5Enabled()) {
        // MiniMe keyword extraction
        Logger::info("Using minime-t5 context");
        $TEST_TEXT = synthNormalizeMemorySearchInput($rawstring);

        $keywords=minimeExtract($TEST_TEXT);
        $reponse=json_decode($keywords,true);

        //print_r($reponse);

        if (isset($reponse["is_memory_recall"]) && $reponse["is_memory_recall"]=="No") {
            return "";
        } else  if (isset($reponse["is_memory_recall"])) {

            if (isset($reponse["version"]) && $reponse["version"]==2) {
                $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                $altKeywords=[];
                $keywords=explode(" ",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                $kwStringAny=implode(" | ",$keywords);
                $kwStringAll=implode(" & ",$keywords);
                $result = array_unique($keywords);
            } else {
                $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                $altKeywords=[];
                $keywords=explode("|",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                array_merge($keywords,$altKeywords);
                $kw=[];

                foreach ($keywords as $tag) {
                    if (strlen($tag)<4)
                        continue;


                    $lkwPre="";
                    foreach (explode(" ",$tag) as $stag) {
                        $lkwPre.=ucfirst($stag);
                    }

                    //$lkw=hashtagify($tag);
                    $lkw="#$lkwPre";

                    if ($lkw) {
                        $kw=array_merge($kw,explode(" ",$lkw));
                    }
                }
                $result = array_unique($kw);

                $kwStringAny=implode(" | ",$result);
                $kwStringAll=implode(" & ",$result);
            }
            Logger::debug("CONTEXT SEARCH KEYWORDS FROM MINIME: ".print_r($result,true));
        }

    }

    if (empty($kwStringAll)) {
        Logger::info("Using dumb context");
        $TEST_TEXT = synthNormalizeMemorySearchInput($rawstring);

        $keywords=hashtagifySentences($TEST_TEXT);
        $kw=[];

        //print_r($keywords);

        foreach (explode(" ",$keywords) as $tag) {
            if (strlen($tag)<4)
                continue;
            $lkw=hashtagify(strtr($tag,["remember"=>"","Remember"=>""]));
            if ($lkw) {
                $kw=array_merge($kw,explode(" ",$lkw));
            }
        }
        $result = array_unique($kw);

        $kwStringAny=implode(" | ",$result);
        $kwStringAll=implode(" & ",$result);
        Logger::debug("CONTEXT SEARCH KEYWORDS FROM DUMB: ".print_r($result,true));
    }




    if ($nativeMemory) {
        return synth_memory_recall(\Synth\Bootstrap::db(),strval($npcfilter),[$kwStringAny ?? ''],intval($timeThreshold));
    }
    $scopeConditionSql = dataGetMemoryScopeConditionSql($npcfilter);
    $companionConditionSql = dataGetMemoryCompanionConditionSql($npcfilter, 'A.companions', 'A.classifier');

    $memory=\Synth\Bootstrap::db()->fetchAll("
        SELECT summary,gamets_truncated,
        ts_rank(native_vec, to_tsquery('$kwStringAny')) AS rank_any,
        ts_rank(native_vec, to_tsquery('$kwStringAll')) AS rank_all
        FROM core_memory A
        where source_type='summary' AND native_vec @@to_tsquery('$kwStringAny')
        and not (native_vec @@to_tsquery('#Reminiscence'))
        and $scopeConditionSql
        and $companionConditionSql

        ORDER BY rank_all DESC, rank_any DESC;
        ",true);

        if (!isset($memory[0]))
            $memory[0]=["rank_any"=>null,"rank_all"=>null,"summary"=>null];



    return $memory;

}

function synthNormalizeTsQueryTerms(string $text): array
{
    if (!preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches)) {
        return [];
    }

    $terms = [];
    foreach ($matches[0] as $term) {
        if (mb_strlen($term, 'UTF-8') < 3) {
            continue;
        }
        $terms[] = $term;
    }

    return array_values(array_unique($terms));
}

function DataSearchMemoryByVector($rawstring,$npcfilter,$useContextKw=false,$timeThreshold=0) {

        $nativeMemory = (\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1) === 2;
        if ($nativeMemory) {
            if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return null;
            synth_protocol_assert_request_active();
        }

        $localStartTime=microtime(true);
        Logger::info("Using DataSearchMemoryByVector $rawstring,$npcfilter,$useContextKw=false,$timeThreshold=0");

        if (!$timeThreshold)
            $timeThreshold=0;

        $result=[];
        if (is_array($rawstring)) {
            $kwStringAny=implode(" ",$rawstring);
            $kwStringAll=implode(" ",$rawstring);

        } else if (isMinimeT5Enabled()) {
            // MiniMe keyword extraction
            Logger::info("Using minime-t5 context");
            error_log("[DataSearchMemoryByVector] Using minime-t5 context");
            $TEST_TEXT = synthNormalizeMemorySearchInput($rawstring);

            error_log("[DataSearchMemoryByVector start] minimeExtract : " . (microtime(true) - $localStartTime) . " seconds");
            $TEST_TEXT = preg_replace('/[(),;:!?."\'-]/', ' ', $TEST_TEXT);
            $TEST_TEXT = preg_replace('/\s+/', ' ', trim($TEST_TEXT));

            if (isset($GLOBALS["PATCH_BYPASS_MINIME_EXTRACT"]) && \Synth\Bootstrap::config("PATCH_BYPASS_MINIME_EXTRACT")) {
                error_log("[DataSearchMemoryByVector ] PATCH_BYPASS_MINIME_EXTRACT");
                $keywords=json_encode(["is_memory_recall"=>"Yes"]);
            } else {
                $keywords=minimeExtract($TEST_TEXT,true);// Only to check if memory is needed
            }

            error_log("[DataSearchMemoryByVector end] minimeExtract : " . (microtime(true) - $localStartTime) . " seconds");
            $reponse=json_decode($keywords,true);

            error_log("[DataSearchMemoryByVector end] minimeExtract : " .print_r($reponse,true));


            if (isset($reponse["is_memory_recall"]) && $reponse["is_memory_recall"]=="No") {
                return null;

            }/* else  if (isset($reponse["is_memory_recall"])) {

                if (isset($reponse["version"]) && $reponse["version"]==2) {
                    $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                    $altKeywords=[];
                    $keywords=explode(" ",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                    $result = array_unique($keywords);

                } else {
                    $altKeywords=explode(" ",lastNames(15,["inputtext"]));
                    $altKeywords=[];
                    $keywords=explode("|",strtr($reponse["generated_tags"],["remember"=>"","Remember"=>""]));
                    array_merge($keywords,$altKeywords);
                    $kw=[];

                    foreach ($keywords as $tag) {
                        if (strlen($tag)<4)
                            continue;


                        $lkwPre="";
                        foreach (explode(" ",$tag) as $stag) {
                            $lkwPre.=ucfirst($stag);
                        }

                        //$lkw=hashtagify($tag);
                        $lkw="$lkwPre";

                        if ($lkw) {
                            $kw=array_merge($kw,explode(" ",$lkw));
                        }
                    }
                    $result = array_unique($kw);


                }
                Logger::debug("CONTEXT SEARCH KEYWORDS FROM MINIME: ".print_r($result,true));
            }*/

        } else {
            error_log("[DataSearchMemoryByVector] Minime disabled; using dumb context fallback.");
            $TEST_TEXT = synthNormalizeMemorySearchInput($rawstring);
        }

        if (sizeof($result)<1) {
            Logger::info("Using dumb context");
            $TEST_TEXT = synthNormalizeMemorySearchInput($rawstring);

            $keywords=strtr($TEST_TEXT,["."=>" ",","=>" ","'"=>" "]);
            $kw=[];

            //print_r($keywords);

            foreach (explode(" ",$keywords) as $tag) {
                if (strlen($tag)<4)
                    continue;
                $lkw=$tag;
                if ($lkw) {
                    $kw=array_merge($kw,explode(" ",$lkw));
                }
            }
            $result = array_unique($kw);

            $resultEn=[];
            foreach ($result as $r) {
                $resultEn[]=$r;
            }

            if (sizeof($resultEn)<1) {
                $resultEn=$result;
            }

            $kwStringAny=implode(" | ",$resultEn);
            $kwStringAll=implode(" & ",$resultEn);

            Logger::debug("CONTEXT SEARCH KEYWORDS FROM DUMB: ".print_r($resultEn,true));
            error_log("CONTEXT SEARCH KEYWORDS FROM DUMB: <".implode("><",$resultEn).">");
        }


        if (!empty($npcfilter) && $useContextKw) {
            $result=array_merge($result,lastKeyWordsContext(5,$npcfilter));
        }

        $contextKeywords  = implode(" ", $result);
        $contextKeywords=strtr($contextKeywords,["remember"=>"","Remember"=>"","do you remember"=>""]);

        if ($nativeMemory) {
            $embedding = synth_memory_fetch_embedding($contextKeywords);
            if ($embedding === null) return null;
            $candidates = synth_memory_recall(\Synth\Bootstrap::db(),strval($npcfilter),[$contextKeywords],intval($timeThreshold),$embedding);
            $candidate = synthSelectBestNativeMemoryCandidate($candidates);
            return $candidate === null ? [] : [$candidate];
        }
        $scopeConditionSql = dataGetMemoryScopeConditionSql($npcfilter);
        $companionConditionSql = dataGetMemoryCompanionConditionSql($npcfilter);

        $url = \Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["TXTAI_URL"].'/embed';

        $data = [

            'text' => $contextKeywords
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

        error_log("[DataSearchMemoryByVector Embedding start] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");
        $response = file_get_contents($url, false, $context);
        error_log("[DataSearchMemoryByVector Embedding end] Elapsed time: " . (microtime(true) - $localStartTime) . " seconds");

        // Output the response
        if ($response === false) {
            Logger::error("Request failed.\n");
        } else {
            Logger::info("Request done:\n");

        }

        $resultNormalized = synthNormalizeTsQueryTerms($contextKeywords);
        $kwStringAny=implode(" | ",$resultNormalized);
        $kwStringAll=implode(" & ",$resultNormalized);
        error_log("[DataSearchMemoryByVector] Generated Tags: $kwStringAny" );
        $vector=json_decode($response,true);

        if (is_array($vector) && isset($vector["embedding"])) {
            $vectorString="'[".implode(",",$vector["embedding"])."]'";
            $rankAnySql = $kwStringAny !== ''
                ? "ts_rank(native_vec, to_tsquery('" . \Synth\Bootstrap::db()->escape($kwStringAny) . "'))"
                : "0::real";
            $rankAllSql = $kwStringAll !== ''
                ? "ts_rank(native_vec, to_tsquery('" . \Synth\Bootstrap::db()->escape($kwStringAll) . "'))"
                : "0::real";
            $rankCombinedSql = "($rankAnySql + $rankAllSql)";

            $finalQuery="
                SELECT rowid,gamets_truncated,
                        embedding <-> $vectorString as distance,
                         $rankAnySql AS rank_any_fts_raw,
                         $rankAllSql AS rank_all_fts_raw,
                         $rankCombinedSql AS rank_fts,
                         (embedding <-> $vectorString) - $rankCombinedSql AS mixed_distance,
                         summary
                    FROM public.core_memory
                    WHERE source_type='summary' AND embedding IS NOT NULL
                    and $scopeConditionSql
                    and $companionConditionSql
                    and (gamets_truncated<$timeThreshold or $timeThreshold=0)

                    ORDER BY
                        mixed_distance ASC,
                        distance ASC,
                        gamets_truncated DESC,
                        rowid DESC
                    LIMIT 50 OFFSET 0
                ";
            $memory=\Synth\Bootstrap::db()->fetchAll($finalQuery);
            //error_log($finalQuery);
            $singleMemory = synthSelectBestHybridMemoryCandidate($memory);

            if (!isset($singleMemory)) {
                $singleMemory = [
                    "rank_any" => null,
                    "rank_all" => null,
                    "summary" => null,
                    "distance" => 1.4,
                    "mixed_distance" => 1.4,
                ];
            }

            /*error_log("
                 SELECT summary, gamets_truncated,
                        embedding <-> $vectorString as distance,
                         ts_rank(native_vec, to_tsquery('$kwStringAny')) AS rank_any_fts,
                         ts_rank(native_vec, to_tsquery('$kwStringAll')) AS rank_all_fts
                    FROM public.core_memory
                    WHERE source_type='summary' AND embedding IS NOT NULL
                    and companions like '%{$GLOBALS["db"]->escape($npcfilter)}%'
                    ORDER BY (embedding <-> $vectorString)-ts_rank(native_vec, to_tsquery('$kwStringAny'))
                    LIMIT 5 OFFSET 0
                ");*/


        } else {
            return null;
        }


    return [$singleMemory];

}

function DataSearchWorldKnowledgeByVector($rawstring,$currentWorldKnowledgeTopic,$locationCtx,$contextKeywords) {
//function DataSearchWorldKnowledgeByVector($rawstring) {


    Logger::info("Using DataSearchWorldKnowledgeByVector");
    $rawstring=strtr($rawstring,["{$GLOBALS["PLAYER_NAME"]}:"=>""]);
    $rawstring=strtr($rawstring,["Talking to The Narrator"=>""]);

    $pattern = "/\(Context location:[^)]+?\)/"; // Remove only the exact context location pattern
    $replacement = "";
    $TEST_TEXT = preg_replace($pattern, $replacement, $rawstring);

    $pattern = '/\(talking to [^()]+\)/i';
    $TEST_TEXT = preg_replace($pattern, '', $TEST_TEXT);


    Logger::info("DataSearchWorldKnowledgeByVector <$TEST_TEXT> Expanded keywords: <$currentWorldKnowledgeTopic> <$locationCtx> <$contextKeywords>");
    /***/

    $embeddingFunction=function($text) {
        if (empty($text))
            return ["embedding"=>array_fill(0, 384, 0)];

        $url = \Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["TXTAI_URL"].'/embed';
        $data = [
            'text' => $text   // We add previous keywords
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
        } else {
            Logger::info("Request done: Searched: {$data["text"]}\n");

        }

        $vector=json_decode($response,true);
        return sizeof($vector)>0?$vector:["embedding"=>array_fill(0, 384, 0)];

    };

    $vector1=$embeddingFunction($TEST_TEXT);
    $vector2=$embeddingFunction($locationCtx);
    $vector3=$embeddingFunction($contextKeywords);
    $vector4=$embeddingFunction($currentWorldKnowledgeTopic);



    if (is_array($vector1) && isset($vector1["embedding"])) {
        $vectorString1="'[".implode(",",$vector1["embedding"])."]'";
        $vectorString2="'[".implode(",",$vector2["embedding"])."]'";
        $vectorString3="'[".implode(",",$vector3["embedding"])."]'";
        $vectorString4="'[".implode(",",$vector4["embedding"])."]'";

        $memory=\Synth\Bootstrap::db()->fetchAll("
            SELECT  topic_desc,
                                topic,
                                knowledge_class,
                                knowledge_class_basic,
                                topic_desc_basic,
                    vector384 <-> $vectorString1 as distance1,
                    vector384 <-> $vectorString2 as distance2,
                    vector384 <-> $vectorString3 as distance3,
                    vector384 <-> $vectorString4 as distance4,
                    ((vector384 <-> $vectorString1) + (vector384 <-> $vectorString2)/4 + (vector384 <-> $vectorString3)/2 + (vector384 <-> $vectorString4)/2 )/2 as distance
                FROM public.core_worldknowledge_article
                WHERE vector384 IS NOT NULL
                ORDER BY ((vector384 <-> $vectorString1) + (vector384 <-> $vectorString2)/4 + (vector384 <-> $vectorString3)/2 + (vector384 <-> $vectorString4)/2 )/4 ASC
                LIMIT 5 OFFSET 0
            ");



        if (!isset($memory[0]))
            $memory[0]=["combined_rank"=>null];
        else {
             $memory[0]['combined_rank']=(7.95-$memory[0]["distance"]);
             $memory[0]['combined_rank']=(7.95-$memory[0]["distance"]);
        }


    } else {
        return null;
    }


    return $memory;

}

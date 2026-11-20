<?php

// Moved from lib/chat_helper_functions.php: memory logging, keyword extraction and memory offers
// The original file requires this one, so every caller is unchanged.

function returnLines($lines,$writeOutput=true,$modelResponse=false)
{
    global $db, $startTime, $forceMood, $staticMood, $talkedSoFar, $FORCED_STOP, $TRANSFORMER_FUNCTION,$receivedData;

    $canonSpeechPolicy=synth_character_current_speech_policy();
    $canonSpeechEmitted=(bool)(\Synth\Bootstrap::config('SYNTH_CANONICAL_SPEECH_EMITTED') ?? false);
    $lines=synth_character_constrain_lines($lines,$canonSpeechPolicy,$canonSpeechEmitted);
    $GLOBALS['SYNTH_CANONICAL_SPEECH_EMITTED']=$canonSpeechEmitted;

    $inlineNarrationMode = getInlineNarrationMode();
    $inlineNarrationEnabled = $inlineNarrationMode !== 'disabled';
    $preserveAsterisksInContext = isset($GLOBALS["PRESERVE_ASTERISKS_IN_CONTEXT"]) ? (bool)\Synth\Bootstrap::config("PRESERVE_ASTERISKS_IN_CONTEXT") : false;

    // If inline narration is enabled, recombine split narration sentences
    if ($inlineNarrationEnabled) {
        $recombinedLines = [];
        $i = 0;
        while ($i < count($lines)) {
            $currentLine = trim($lines[$i]);

            // Check if this line looks like narration: starts with * and ends with period/no dialogue
            if (preg_match('/^\*[^*]+\.?\s*$/', $currentLine)) {
                // This is a narration-only sentence, check if next line is dialogue
                if ($i + 1 < count($lines) && !preg_match('/^\*/', trim($lines[$i + 1]))) {
                    // Next line doesn't start with *, combine them
                    $recombinedLines[] = $currentLine . ' ' . trim($lines[$i + 1]);
                    \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'returnLines', "Recombined narration + dialogue: " . substr($currentLine . ' ' . trim($lines[$i + 1]), 0, 100));
                    $i += 2; // Skip next line since we combined it
                    continue;
                }
            }

            $recombinedLines[] = $currentLine;
            $i++;
        }
        $lines = $recombinedLines;
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'returnLines', "After recombination: " . count($lines) . " lines");
    }

    foreach ($lines as $n => $sentence) {

        if ($FORCED_STOP) {
            return;
        }

        if (is_array($sentence))
            continue;

        // Remove actions
        if (isset($GLOBALS["startTimeAfterPlayerTTTS"]))
            $elapsedTimeAI= microtime(true) - \Synth\Bootstrap::config("startTimeAfterPlayerTTTS");
        else
            $elapsedTimeAI= microtime(true) - $startTime;
        //error_log("PRE LLM STATUS DONE 2: ". (microtime(true) - $startTime));

        $pattern = '/<[^>]+>/';
        $output = str_replace("#CHAT#", "", preg_replace($pattern, '', $sentence));

        // This should be reworked
        //$sentence = preg_replace('/[[:^print:]]/', '', $output); // Remove non ASCII chracters

        $sentence=$output;

        // Only model speech call sites opt in; player playback and canned errors are not model evidence.
        $relationshipLineToken=($writeOutput && $modelResponse)
            ? synth_relationship_capture_line(strval(\Synth\Bootstrap::config('SYNTH_NAME') ?? ''),$sentence) : null;
        $sentence=synth_relationship_strip_commands($sentence);

        // Preserve the original sentence for subtitles BEFORE any processing
        $inlineNarrationMode = getInlineNarrationMode();
        $inlineNarrationEnabled = $inlineNarrationMode !== 'disabled';
        $sentenceForSubtitles = $sentence; // Keep the original with narration

        // Strip trailing directed-dialogue tags from player speech for cleaner subtitles,
        // regardless of inline narration mode.
        $isPlayerSpeech = synth_protocol_current_speech_role()==='player';
        if ($inlineNarrationEnabled || $isPlayerSpeech) {
            $sentence = preg_replace('/\s*\((?:Talking|Whispering|Shouting|Speaking privately)\s+to\s+[^)]+\)\s*$/i', '', $sentence);
            $sentenceForSubtitles = preg_replace('/\s*\((?:Talking|Whispering|Shouting|Speaking privately)\s+to\s+[^)]+\)\s*$/i', '', $sentenceForSubtitles);
        }

        // Check if we should split narration to The Narrator BEFORE unmoodSentence strips asterisks
        $splitNarration = false;
        $textOnlyNarration = false;
        $narrationParts = null;
        if ($inlineNarrationEnabled && !$isPlayerSpeech) {
            $narrationParts = extractNarrationAndDialogue($sentenceForSubtitles);
            $splitNarration = shouldSplitInlineNarration() && $narrationParts['has_narration'];
            $textOnlyNarration = $inlineNarrationMode === 'text_only' && $narrationParts['has_narration'];

            // Debug logging
            Logger::info("[INLINE_NARRATION] Mode: " . $inlineNarrationMode);
            Logger::info("[INLINE_NARRATION] Original sentence: " . $sentenceForSubtitles);
            Logger::info("[INLINE_NARRATION] Has narration: " . ($splitNarration ? 'yes' : 'no'));
            if ($splitNarration) {
                Logger::info("[INLINE_NARRATION] Narrations: " . json_encode($narrationParts['narrations']));
                Logger::info("[INLINE_NARRATION] Dialogue: " . $narrationParts['dialogue']);
            }
        } else {
            Logger::info("[INLINE_NARRATION] Disabled, not set, or skipped for player speech");
        }

        $responseTextUnmooded=unmoodSentence($sentence);

        $scoring = checkOAIComplains($responseTextUnmooded);

        if ($scoring >= 3) { // Catch OpenAI brekaing policies stuff
            $responseTextUnmooded = \Synth\Bootstrap::config("ERROR_OPENAI_POLICY"); // Key phrase to indicate OpenAI triggered warning
            $ERROR_TRIGGERED=true;
            $FORCED_STOP = true;
        } else {
            if (isset($TRANSFORMER_FUNCTION)) {
                $responseTextUnmooded = $TRANSFORMER_FUNCTION($responseTextUnmooded);
            }
        }

        if (isset($forceMood)) {
            $mood = $forceMood;
        } elseif (isset($GLOBALS["LAST_LLM_RESPONSE"]["mood"]) && !empty($GLOBALS["LAST_LLM_RESPONSE"]["mood"])) {
            // Use mood from JSON response (set by connector)
            $mood = trim(\Synth\Bootstrap::config("LAST_LLM_RESPONSE")["mood"]);
        } elseif (!empty($matches) && !empty($matches[1]) && isset($matches[1][0])) {
            // Fallback to SSML-style mood extraction for backward compatibility
            $mood = $matches[1][0];
        } else {
            $mood = "default";
        }

        if (isset($staticMood)) {
            $mood = $staticMood;
        } else {
            $staticMood = $mood;
        }

        if (isset($GLOBALS["FORCE_MOOD"])) {
            $mood = \Synth\Bootstrap::config("FORCE_MOOD");
        }
        $mood = extractFirstEmoteMood($mood, "default");


        if (strlen($responseTextUnmooded) < 2 && !($splitNarration && $narrationParts && !empty($narrationParts['narrations']))) { // Avoid too short responses
            continue;
        }


        if (strpos($responseTextUnmooded, "The Narrator:") !== false) { // Force not impersonating the narrator.
            continue;
        }

        $responseTextUnmooded = stripOutputSpeakerPrefix($responseTextUnmooded);	// Should not happen

        // Transformers and narration formatters cannot expand the canonical phrase.
        if ($canonSpeechPolicy==='takahashi') {
            $responseTextUnmooded='Nan-ni shimasho-ka?';
            $sentenceForSubtitles=$responseTextUnmooded;
        }
        $responseText = $responseTextUnmooded;
        $responseForTTS = $responseTextUnmooded; // TTS gets the "unmooded" version (narration stripped)

        // Set up subtitles based on whether inline narration is enabled
        if ($isPlayerSpeech) {
            $responseForSubtitles = formatPlayerSubtitleText($sentenceForSubtitles);
        } elseif ($textOnlyNarration) {
            $responseForSubtitles = formatTextOnlyInlineNarrationSubtitleText($sentenceForSubtitles);
            if (strlen($responseForSubtitles) > _MAX_SUBTITLE_LENGTH) {
                $responseForSubtitles = substr($responseForSubtitles, 0, _MAX_SUBTITLE_LENGTH);
            }
        } elseif (!$splitNarration) {
            $responseForSubtitles = formatNpcSubtitleText($sentenceForSubtitles);
            if (strlen($responseForSubtitles) > _MAX_SUBTITLE_LENGTH) {
                $responseForSubtitles = substr($responseForSubtitles, 0, _MAX_SUBTITLE_LENGTH);
            }
        } else {
            // If narration is disabled or will be split, use the same text as TTS (narration stripped)
            $responseForSubtitles = strlen($responseTextUnmooded) > _MAX_SUBTITLE_LENGTH ?
            substr($responseTextUnmooded, 0, _MAX_SUBTITLE_LENGTH) :
            $responseTextUnmooded;
        }

        $responseForContext = $responseTextUnmooded;
        if ($preserveAsterisksInContext) {
            $responseForContext = cleanupDisplayText($sentenceForSubtitles, \Synth\Bootstrap::config("SYNTH_NAME") ?? null);
        }

        $ttsOutput = null;
        $pendingNpcTtsText = "";
        $pendingNpcTtsMood = "";
        $pendingNpcTtsCacheKey = "";
        $pendingNpcTtsPlan = null;
        $npcTtsGeneratedBeforeEmit = false;

        if (isset($GLOBALS["FEATURES"]["MISC"]["TTS_RANDOM_PITCH"])&&(\Synth\Bootstrap::config("FEATURES")["MISC"]["TTS_RANDOM_PITCH"])) {
            $random_per_character=sprintf('%u', crc32(\Synth\Bootstrap::config("SYNTH_NAME"))); // Unsigned integer
            $pitch=$random_per_character%5;

            if ($pitch==0)
                $GLOBALS["TTS_FFMPEG_FILTERS"]["rubberband"]="rubberband=pitch=1.02";
            if ($pitch==1)
                $GLOBALS["TTS_FFMPEG_FILTERS"]["rubberband"]="rubberband=pitch=0.98";
            if ($pitch==2)
                $GLOBALS["TTS_FFMPEG_FILTERS"]["rubberband"]="rubberband=pitch=1.01";
            if ($pitch==3)
                $GLOBALS["TTS_FFMPEG_FILTERS"]["rubberband"]="rubberband=pitch=0.99";
            if ($pitch==4)
                ;
        }

        $hasNarrationBlocks = $splitNarration && $narrationParts && !empty($narrationParts['narrations']);
        $hasTextOnlyNarration = $textOnlyNarration && $narrationParts && !empty($narrationParts['narrations']);
        $shouldEmitNpcLine = false;
        $currentUtteranceId = null;

        if ($responseTextUnmooded || $hasNarrationBlocks || $hasTextOnlyNarration) {
            $shouldEmitNpcLine = true;

            if ($hasTextOnlyNarration) {
                $responseForSubtitles = formatTextOnlyInlineNarrationSubtitleText($sentenceForSubtitles);
                if (strlen($responseForSubtitles) > _MAX_SUBTITLE_LENGTH) {
                    $responseForSubtitles = substr($responseForSubtitles, 0, _MAX_SUBTITLE_LENGTH);
                }

                if (!empty($narrationParts['dialogue'])) {
                    $responseForTTS = formatTextOnlyInlineNarrationSpeechText($sentenceForSubtitles, $narrationParts);
                    $responseText = $responseForTTS;
                    $responseTextUnmooded = $responseForTTS;
                    if (!$preserveAsterisksInContext) {
                        $responseForContext = $responseForTTS;
                    }
                } else {
                    // Do not queue a speech line when there is no audio file to download.
                    $shouldEmitNpcLine = false;
                    $responseForTTS = "";
                    $responseText = "";
                    $responseTextUnmooded = "";
                }
            // Check if we need to split narration to The Narrator
            } elseif ($hasNarrationBlocks) {
                Logger::info("[INLINE_NARRATION] Splitting narration - processing " . count($narrationParts['narrations']) . " blocks");

                // Save the current NPC voice settings
                $savedVoiceSettings = saveCurrentVoiceSettings();
                $savedSYNTHName = \Synth\Bootstrap::config("SYNTH_NAME");
                $hadSpeechRole = array_key_exists('SYNTH_SPEECH_ROLE',$GLOBALS);
                $savedSpeechRole = \Synth\Bootstrap::config('SYNTH_SPEECH_ROLE') ?? null;

                Logger::info("[INLINE_NARRATION] Saved NPC name: " . $savedSYNTHName);

                try {
                    // Process each narration block with The Narrator's voice
                    foreach ($narrationParts['narrations'] as $narrationText) {
                        if (empty(trim($narrationText))) {
                            continue; // Skip empty narrations
                        }

                        Logger::info("[INLINE_NARRATION] Processing narration: " . $narrationText);

                        try {
                            // Switch to Narrator voice
                            loadNarratorVoiceSettings();
                            $GLOBALS["SYNTH_NAME"] = "The Narrator";
                            $GLOBALS['SYNTH_SPEECH_ROLE'] = 'narrator';

                            Logger::info("[INLINE_NARRATION] Switched to Narrator, voice settings loaded");

                            // Prepare narration for TTS (with asterisks for subtitle display)
                            $narrationForTTS = synthApplyTtsPronunciationDictionary($narrationText, null, []);
                            $narrationForSubtitles = formatNarrationSubtitleText($narrationText);
                            $narrationCacheText = $narrationForTTS !== $narrationText
                                ? $narrationForTTS
                                : $narrationForSubtitles;

                            Logger::info("[INLINE_NARRATION] Generating TTS with function: " . \Synth\Bootstrap::config("TTSFUNCTION"));

                            // Generate TTS for narration using the configured TTS function
                            $narratorTtsOutput = callConfiguredTts(
                                $narrationForTTS,
                                "default",
                                synthTtsCacheKeyForLine("The Narrator", $narrationCacheText)
                            );

                            // Track narrator TTS output
                            if ($narratorTtsOutput) {
                                $GLOBALS["TRACK"]["FILES_GENERATED"][] = $narratorTtsOutput;
                                Logger::info("[INLINE_NARRATION] Narrator TTS generated: " . $narratorTtsOutput);

                                // Output narrator speech to game immediately
                                if ($writeOutput) {
                                    // Use the same format as the main output at line 1093
                                    $narratorListener = isset($GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]) ? \Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC") : "";
                                    $narratorExpression = ""; // No expression for narrator
                                    $narratorAnimation = ""; // No animation for narrator

                                    synth_buffer_speech_response_line(
                                        "The Narrator",
                                        $narrationForSubtitles,
                                        $narratorExpression,
                                        $narratorListener,
                                        $narratorAnimation,
                                        $narrationForTTS
                                    );
                                    Logger::info("[INLINE_NARRATION] Narrator speech sent to game: " . $narrationForSubtitles);
                                }
                            } else {
                                Logger::warn("[INLINE_NARRATION] WARNING: Narrator TTS returned null/empty");
                            }
                        } catch (Throwable $e) {
                            if ($e instanceof SYNTHProtocolCancelled) throw $e;
                            Logger::error("[INLINE_NARRATION] Narrator routing failed; continuing with NPC dialogue: " . $e->getMessage());
                        }
                    }
                } finally {
                    // Always restore the NPC voice, including after narrator connector failures.
                    restoreVoiceSettings($savedVoiceSettings);
                    $GLOBALS["SYNTH_NAME"] = $savedSYNTHName;
                    if ($hadSpeechRole) $GLOBALS['SYNTH_SPEECH_ROLE']=$savedSpeechRole;
                    else unset($GLOBALS['SYNTH_SPEECH_ROLE']);
                }

                // Now generate TTS for the NPC's dialogue (if any)
                if (!empty($narrationParts['dialogue'])) {
                    $responseForTTS = unmoodSentence($narrationParts['dialogue']);
                    $responseForSubtitles = formatInlineNarrationDialogueSubtitleText($narrationParts['dialogue']);
                    // IMPORTANT: Also update the main response variables so the output buffer uses dialogue only
                    $responseText = $responseForTTS;
                    $responseTextUnmooded = $responseForTTS;
                    if (!$preserveAsterisksInContext) {
                        $responseForContext = $responseForTTS;
                    }
                } else {
                    // Narration-only line: narrator speech already emitted above, skip NPC speech output.
                    $shouldEmitNpcLine = false;
                    $responseForTTS = "";
                    $responseForSubtitles = "";
                    $responseText = "";
                    $responseTextUnmooded = "";
                }
            }

            if ($shouldEmitNpcLine && trim((string)$responseForTTS) !== "") {
                $pronunciationScope = synthTtsPronunciationCurrentSpeakerScope();
                $pendingNpcTtsText = synthApplyTtsPronunciationDictionary(
                    (string)$responseForTTS,
                    null,
                    $pronunciationScope['knowledge_tags'],
                    $pronunciationScope['npc_name'],
                    $pronunciationScope['race']
                );
                $pendingNpcTtsMood = $mood;
                $ttsCacheText = $pendingNpcTtsText !== $responseForTTS ? $pendingNpcTtsText : $responseForSubtitles;
                ensureNpcTtsVoiceResolvedForCurrentSpeaker();
                require_once dirname(__DIR__, 3).'/lib' . '/synth_tts_plan.php';
                $pendingNpcTtsPlan = synth_capture_tts_plan(dirname(__DIR__, 3), (string)\Synth\Bootstrap::config('SYNTH_NAME'), $ttsCacheText, (string)$pendingNpcTtsMood, $pendingNpcTtsText);
                $pendingNpcTtsCacheKey = $pendingNpcTtsPlan['cache_seed'];

                if (trim($responseText)) {
                    $talkedSoFar[] = $responseText;
                }
            }
        }

        if ($shouldEmitNpcLine) {
            Logger::info("Speech sent for {$GLOBALS["SYNTH_NAME"]}, generator {$GLOBALS["TTSFUNCTION"]}, size: ".strlen($responseText). "  '".substr($responseText,0,10)."'");
        } else {
            Logger::info("[INLINE_NARRATION] No NPC dialogue line queued.");
        }
        $outBuffer = array(
            'localts' => time(),
            'sent' => 1,
            'text' => trim(preg_replace('/\s\s+/', ' ', $responseText)),
            'actor' => \Synth\Bootstrap::config("SYNTH_NAME"),
            'action' => "synth_dialogue_response",
            'tag' => (isset($tag) ? $tag : "")
        );


        if ($writeOutput && $shouldEmitNpcLine) {

            if (true) {
                 if (isset($GLOBALS["SCRIPTLINE_ANIMATION_SENT"]) && \Synth\Bootstrap::config("SCRIPTLINE_ANIMATION_SENT"))
                     $GLOBALS["SCRIPTLINE_ANIMATION"]="";
                else {
                    if ((rand(0,5)==0)){ // Will disable animations, 20% chance to trigger
                        $GLOBALS["SCRIPTLINE_ANIMATION"]="IdleDialogueExpressiveStart";
                    }
                    $GLOBALS["SCRIPTLINE_ANIMATION_SENT"]=true;
                }

                // SYNTH_ANIMATIONS is now always enabled (controlled via MCM in-game)
                // Removed profile configuration option as it's redundant with MCM control

                if (is_array(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER")) && sizeof(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER")) > 0 && is_string(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER")[0])) {
                    $GLOBALS["SCRIPTLINE_LISTENER"]=\Synth\Bootstrap::config("SCRIPTLINE_LISTENER")[0];
                    Logger::info("GLOBALS['SCRIPTLINE_LISTENER'] seems to be an array!");

                }

                $strictRechatListener = synthGetStrictRechatListenerName();
                if ($strictRechatListener !== "") {
                    $GLOBALS["SCRIPTLINE_LISTENER"] = $strictRechatListener;
                }

                $strictDirectedPlayerListener = synthGetStrictDirectedPlayerListenerName();
                if ($strictDirectedPlayerListener !== "") {
                    $GLOBALS["SCRIPTLINE_LISTENER"] = $strictDirectedPlayerListener;
                }


                $listenerFix=explode(" and ",\Synth\Bootstrap::config("SCRIPTLINE_LISTENER"));
                // Don't touch original one
                $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]=\Synth\Bootstrap::config("SCRIPTLINE_LISTENER");
                $GLOBALS["SCRIPTLINE_RECHAT_TARGET"]=\Synth\Bootstrap::config("SCRIPTLINE_LISTENER");

                if (is_array($listenerFix) && (sizeof($listenerFix)>1)) {
                    $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]=trim($listenerFix[0]);
                }

                $listenerFix2=parseDialogueListenerNames(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER"));
                if (!is_array($listenerFix2)) {
                    $listenerFix2 = [];
                }
                $listenerFix2 = array_values(array_unique(array_filter(array_map('normalizeDialogueListenerName', $listenerFix2))));

                if (is_array($listenerFix2) && (sizeof($listenerFix2)>1)) {
                    if (!isset($GLOBALS["SCRIPTLINE_LISTENER_CYCLE"])) {
                        $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]=0;
                    } else
                        $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]++;

                    if (\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_CYCLE")>(sizeof($listenerFix2)-1))
                        $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]=sizeof($listenerFix2)-1;

                    // Code to fix multiple listener issues
                    // Arrays to store positions of found names
                    $positions = [];           // For determining the first mentioned name
                    $positionsWithIndex = [];  // For determining the last mentioned name and its index

                    // Search for each name in the subtitle sentence
                    //$listenerFix2[]="Sole Survivor";

                    foreach ($listenerFix2 as $index => $name) {
                        $pos = stripos($responseForSubtitles, trim($name)); // Case-insensitive search
                        if ($pos !== false) {
                            $positions[$name] = $pos;           // Save position for first-mention check
                            $positionsWithIndex[$index] = $pos; // Save index and position for last-mention check
                        }
                    }

                    if (!empty($positions)) {
                        // Sort positions to find the first mentioned name
                        asort($positions); // Ascending order by position
                        $listener = array_key_first($positions); // Get the name of the first mentioned
                        $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]=trim($listener);
                        // Sort positions to find the last mentioned index
                        arsort($positionsWithIndex); // Descending order by position
                        $nextListener = array_key_first($positionsWithIndex); // Get the index of the last mentioned name
                        if ($nextListener>0)
                            $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]=$nextListener-1;  // Next round will use this speaker if no refernce found.
                        else
                            $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]=$nextListener;
                        // Test
                        $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]=$nextListener;
                        // Output results
                        Logger::info("Applying smarter listenerFix2: $listener {$listenerFix2["$nextListener"]} {$GLOBALS["SCRIPTLINE_LISTENER"]} {$GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]} {$GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]}");

                    } else {
                        $listener=$listenerFix2[\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_CYCLE")];
                        $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]=trim($listener);
                    }

                    $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]=normalizeDialogueListenerName(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"));
                    //$GLOBALS["SCRIPTLINE_LISTENER"]=trim($listenerFix2[ $GLOBALS["SCRIPTLINE_LISTENER_CYCLE"]]);
                    // $GLOBALS["SCRIPTLINE_LISTENER"] = trim($listenerFix2[array_rand($listenerFix2)]); // Random


                }
                else {
                    $GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"] = normalizeDialogueListenerName(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"));
                }

                $speakerName = normalizeDialogueListenerName($outBuffer["actor"] ?? "");
                $normalizedNearby = getNormalizedNearbyDialogueListenerNames(true);
                $rechatCandidates = $listenerFix2;
                if (empty($rechatCandidates) && \Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC") !== "") {
                    $rechatCandidates[] = \Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC");
                }
                if (isOpenRechatEnabled()) {
                    $rechatCandidates = buildOpenRechatListenerCandidates($rechatCandidates, $speakerName);
                }

                $rechatTarget = "";
                foreach ($rechatCandidates as $candidateName) {
                    $candidateName = normalizeDialogueListenerName($candidateName);
                    if ($candidateName === "" || isPlayerDialogueListenerName($candidateName)) {
                        continue;
                    }
                    if ($speakerName !== "" && strcasecmp($candidateName, $speakerName) === 0) {
                        continue;
                    }
                    if (strcasecmp($candidateName, "The Narrator") === 0) {
                        continue;
                    }
                    if (in_array($candidateName, $normalizedNearby, true)) {
                        $rechatTarget = $candidateName;
                        break;
                    }
                }

                if ($rechatTarget === "" &&
                    !isPlayerDialogueListenerName(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC")) &&
                    ($speakerName === "" || strcasecmp(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"), $speakerName) !== 0)) {
                    $rechatTarget = \Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC");
                }

                if (!in_array(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"), $normalizedNearby, true) &&
                    !isPlayerDialogueListenerName(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"))) {
                    Logger::info("Atomic listener {$GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]} not nearby; preserving playback target. Raw listener={$GLOBALS["SCRIPTLINE_LISTENER"]}");
                }

                if ($rechatTarget !== "") {
                    $GLOBALS["SCRIPTLINE_RECHAT_TARGET"] = $rechatTarget;
                } else {
                    $GLOBALS["SCRIPTLINE_RECHAT_TARGET"] = \Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC");
                }

                $currentUtteranceId = synthGenerateUtteranceId();
                $GLOBALS["SCRIPTLINE_UTTERANCE_ID"] = $currentUtteranceId;

                $responseTextPhonetic = "";

                $volumeBoost = 1.0;

                $responseStreamingRequested = function_exists('synth_response_stream_requested')
                    ? synth_response_stream_requested()
                    : !empty($GLOBALS["SYNTH_RESPONSE_STREAMING"]);
                $jsonStreamingEnabled = function_exists('synth_json_streaming_enabled') && synth_json_streaming_enabled();
                $generateNpcTtsBeforeEmit = function_exists('synth_should_generate_npc_tts_before_emit')
                    ? synth_should_generate_npc_tts_before_emit()
                    : !$responseStreamingRequested;

                if ($generateNpcTtsBeforeEmit && $pendingNpcTtsText !== "" && $pendingNpcTtsCacheKey !== "") {
                    $phaseName = "npc_tts_before_emit:" . substr(md5((\Synth\Bootstrap::config("SYNTH_NAME") ?? "") . "|" . $pendingNpcTtsCacheKey), 0, 8);
                    Logger::phaseStart($phaseName, [
                        "speaker" => \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                        "connector" => \Synth\Bootstrap::config("TTSFUNCTION") ?? "",
                        "cache_key" => $pendingNpcTtsCacheKey,
                        "chars" => strlen($pendingNpcTtsText),
                    ]);
                    $ttsOutput = synth_call_planned_npc_tts($pendingNpcTtsPlan, $pendingNpcTtsText, $pendingNpcTtsMood, $pendingNpcTtsCacheKey);
                    $ttsReady=synth_finish_immediate_npc_tts($pendingNpcTtsCacheKey,$ttsOutput,(string)\Synth\Bootstrap::config('SYNTH_NAME'),$currentUtteranceId);

                    if ($ttsReady) {
                        $GLOBALS["TRACK"]["FILES_GENERATED"][] = $ttsOutput;
                        $npcTtsGeneratedBeforeEmit = true;
                        Logger::info("[TTS] Generated before JSON emit for {$GLOBALS["SYNTH_NAME"]}: {$ttsOutput}");
                        Logger::phaseEnd($phaseName, [
                            "status" => "ok",
                            "speaker" => \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                            "output" => $ttsOutput,
                        ], "info");
                    } else {
                        Logger::warn("[TTS] No audio generated before JSON emit for {$GLOBALS["SYNTH_NAME"]}");
                        Logger::phaseEnd($phaseName, [
                            "status" => "failed",
                            "speaker" => \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                            "cache_key" => $pendingNpcTtsCacheKey,
                        ], "warn");
                    }
                } elseif (!$generateNpcTtsBeforeEmit && $pendingNpcTtsText !== "" && $pendingNpcTtsCacheKey !== "") {
                    // A new synthesis attempt must not inherit an earlier attempt's terminal failure on the wire.
                    if (!in_array(strtolower(trim(strval(\Synth\Bootstrap::config('TTSFUNCTION') ?? ''))), ['', 'none'], true)) {
                        synth_protocol_assert_request_active();
                        synth_write_npc_tts_status(md5($pendingNpcTtsCacheKey), 'pending', [
                            'utterance_id' => $currentUtteranceId,
                            'speaker' => strval($outBuffer['actor']),
                        ], dirname(__DIR__, 3));
                    }
                    Logger::info("[TTS] Streaming response: emitting line before NPC TTS generation for {$GLOBALS["SYNTH_NAME"]}: {$pendingNpcTtsCacheKey}" . Logger::formatContext([
                        "stream_requested" => $responseStreamingRequested ? 1 : 0,
                        "json_streaming" => $jsonStreamingEnabled ? 1 : 0,
                        "response_format" => \Synth\Bootstrap::config("SYNTH_RESPONSE_FORMAT") ?? "",
                    ]));
                }

                synth_buffer_speech_response_line(
                    (string)$outBuffer["actor"],
                    $responseForSubtitles,
                    (string)\Synth\Bootstrap::config("SCRIPTLINE_EXPRESSION"),
                    (string)\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"),
                    (string)\Synth\Bootstrap::config("SCRIPTLINE_ANIMATION"),
                    $responseTextPhonetic,
                    $volumeBoost,
                    (string)\Synth\Bootstrap::config("SCRIPTLINE_RECHAT_TARGET"),
                    $currentUtteranceId,
                    $pendingNpcTtsCacheKey !== "" ? md5($pendingNpcTtsCacheKey) : ""
                );
                synth_relationship_capture_route($relationshipLineToken,(string)\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"),$currentUtteranceId);

                $GLOBALS["DEBUG_DATA"]["OUTPUT_LOG"] = json_encode([
                    "speaker" => (string)$outBuffer["actor"],
                    "action" => "say",
                    "text" => $responseForSubtitles,
                    "metadata" => [
                        "expression" => (string)\Synth\Bootstrap::config("SCRIPTLINE_EXPRESSION"),
                        "listener" => (string)\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"),
                        "animation" => (string)\Synth\Bootstrap::config("SCRIPTLINE_ANIMATION"),
                        "phonetic" => $responseTextPhonetic,
                        "volume" => $volumeBoost,
                        "rechat_target" => (string)\Synth\Bootstrap::config("SCRIPTLINE_RECHAT_TARGET"),
                        "utterance_id" => $currentUtteranceId,
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

                $outputToPluginLog = dirname(__DIR__, 3).'/lib'."/../log/output_to_plugin.log";
                if (class_exists('Logger')) {
                    Logger::rotateLogIfTooLarge($outputToPluginLog);
                    Logger::debug("[plugin-output] queued speech line" . Logger::formatContext([
                        "speaker" => (string)$outBuffer["actor"],
                        "listener" => (string)\Synth\Bootstrap::config("SCRIPTLINE_LISTENER_ATOMIC"),
                        "utterance_id" => $currentUtteranceId,
                        "chars" => strlen($responseForSubtitles),
                        "rechat_target" => (string)\Synth\Bootstrap::config("SCRIPTLINE_RECHAT_TARGET"),
                    ]));
                }
                file_put_contents($outputToPluginLog,\Synth\Bootstrap::config("DEBUG_DATA")["OUTPUT_LOG"], FILE_APPEND | LOCK_EX);

            }
            else
                synth_buffer_response_line((string)$outBuffer["actor"], (string)$outBuffer["action"], $responseForSubtitles);

            if (ob_get_level()) @ob_flush();
            @flush();
        }

        $deferNpcTts = synth_should_defer_speech_tts($pendingNpcTtsPlan);

        if ($npcTtsGeneratedBeforeEmit) {
            Logger::info("[TTS] Skipping deferred/immediate post-emit TTS because audio was prepared before JSON emit for {$GLOBALS["SYNTH_NAME"]}: {$pendingNpcTtsCacheKey}");
        } elseif ($pendingNpcTtsText !== "" && $pendingNpcTtsCacheKey !== "" && $deferNpcTts) {
            $streamingMode = function_exists('synth_response_stream_requested')
                ? synth_response_stream_requested()
                : (function_exists('synth_json_streaming_enabled') && synth_json_streaming_enabled());
            $responseContext = function_exists('synth_protocol_response_context')
                ? synth_protocol_response_context()
                : [];
            $speakerIdentity = function_exists('synth_protocol_response_speaker')
                ? synth_protocol_response_speaker((string)\Synth\Bootstrap::config("SYNTH_NAME"))
                : null;
            $speakerIdentityId = is_array($speakerIdentity) && !isset($speakerIdentity['kind'])
                && function_exists('synth_protocol_actor_identity_id') && isset($GLOBALS['db'])
                    ? synth_protocol_actor_identity_id(\Synth\Bootstrap::db(), $speakerIdentity)
                    : null;
            $deferredEntry = [
                "speaker" => (string)\Synth\Bootstrap::config("SYNTH_NAME"),
                "speaker_identity" => $speakerIdentity,
                "speaker_identity_id" => $speakerIdentityId,
                "text" => $pendingNpcTtsText,
                "mood" => $pendingNpcTtsMood,
                "cache_seed" => $pendingNpcTtsCacheKey,
                "tts_plan" => $pendingNpcTtsPlan,
                "cache_key" => md5($pendingNpcTtsCacheKey),
                "tts_function" => (string)(\Synth\Bootstrap::config("TTSFUNCTION") ?? ""),
                "request_id" => strval($responseContext['request_id'] ?? ''),
                "turn_id" => strval($responseContext['turn_id'] ?? ''),
                "utterance_id" => strval(\Synth\Bootstrap::config('SCRIPTLINE_UTTERANCE_ID') ?? ''),
                "generation" => intval($responseContext['generation'] ?? 0),
            ];
            $spawnedWorker = $streamingMode && synth_spawn_deferred_npc_tts_worker($deferredEntry);
            if (!$spawnedWorker) {
                if (!isset($GLOBALS["SYNTH_DEFERRED_TTS"]) || !is_array(\Synth\Bootstrap::config("SYNTH_DEFERRED_TTS"))) {
                    $GLOBALS["SYNTH_DEFERRED_TTS"] = [];
                }
                $GLOBALS["SYNTH_DEFERRED_TTS"][] = $deferredEntry;
            }
            Logger::info("[TTS] Deferred JSON-mode TTS for {$GLOBALS["SYNTH_NAME"]}: {$pendingNpcTtsCacheKey} mode=" . ($streamingMode ? "streaming" : "buffered"));
        } elseif ($pendingNpcTtsText !== "" && $pendingNpcTtsCacheKey !== "") {
            $phaseName = "npc_tts_immediate:" . substr(md5((\Synth\Bootstrap::config("SYNTH_NAME") ?? "") . "|" . $pendingNpcTtsCacheKey), 0, 8);
            Logger::phaseStart($phaseName, [
                "speaker" => \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                "connector" => \Synth\Bootstrap::config("TTSFUNCTION") ?? "",
                "cache_key" => $pendingNpcTtsCacheKey,
                "chars" => strlen($pendingNpcTtsText),
            ]);
            $ttsOutput = synth_call_planned_npc_tts($pendingNpcTtsPlan, $pendingNpcTtsText, $pendingNpcTtsMood, $pendingNpcTtsCacheKey);
            $ttsReady=synth_finish_immediate_npc_tts($pendingNpcTtsCacheKey,$ttsOutput,(string)\Synth\Bootstrap::config('SYNTH_NAME'),strval(\Synth\Bootstrap::config('SCRIPTLINE_UTTERANCE_ID') ?? ''));

            if ($ttsReady) {
                $GLOBALS["TRACK"]["FILES_GENERATED"][] = $ttsOutput;
                Logger::info("[TTS] Generated after response emit for {$GLOBALS["SYNTH_NAME"]}: {$ttsOutput}");
                Logger::phaseEnd($phaseName, [
                    "status" => "ok",
                    "speaker" => \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                    "output" => $ttsOutput,
                ], "info");
            } else {
                Logger::warn("[TTS] No audio generated after response emit for {$GLOBALS["SYNTH_NAME"]}");
                Logger::phaseEnd($phaseName, [
                    "status" => "failed",
                    "speaker" => \Synth\Bootstrap::config("SYNTH_NAME") ?? "",
                    "cache_key" => $pendingNpcTtsCacheKey,
                ], "warn");
            }
        }

        $elapsedTimeTTS=microtime(true) - $startTime;


        if (!isset($GLOBALS["PATCH_DONT_STORE_SPEECH_ON_DB"])) {
            $escapeLogValue = static function ($value) use ($db) {
                $value = (string)$value;
                if (method_exists($db, 'escape')) {
                    return $db->escape($value);
                }
                return str_replace("'", "''", $value);
            };

            $db->insert(
                'core_log',
                array(
                    'localts' => time(),
                    'prompt' => nl2br($escapeLogValue(json_encode(\Synth\Bootstrap::config("DEBUG_DATA"), JSON_PRETTY_PRINT))),
                    'response' => $escapeLogValue($responseForContext),
                    'url' => nl2br($escapeLogValue("$receivedData [AI secs] $elapsedTimeAI  [TTS secs] $elapsedTimeTTS"))


                )
            );

            // RECHAT
            $originalRequest=\Synth\Bootstrap::gameRequest();
            $originalRequest[0]="prechat";
            $originalRequest[1]++;
            $originalRequest[2]++;
            if (\Synth\Bootstrap::config("SCRIPTLINE_LISTENER")) {
                // Check if speaking from distance (shouting)
                if (!defined('SHOUTING_DISTANCE_THRESHOLD')) {
                    define('SHOUTING_DISTANCE_THRESHOLD', 800);
                }
                $distance = isset($GLOBALS["LAST_SPEECH_DISTANCE"]) ? \Synth\Bootstrap::config("LAST_SPEECH_DISTANCE") : 0.0;
                $incomingSpatialVolume = isset($GLOBALS["LAST_SPEECH_VOLUME"]) ? floatval(\Synth\Bootstrap::config("LAST_SPEECH_VOLUME")) : null;
                if (($incomingSpatialVolume !== null && $incomingSpatialVolume < 0.35) || $distance > SHOUTING_DISTANCE_THRESHOLD) {
                    $addonlistener = buildDialogueTargetSuffix(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER"), true);
                } else {
                    $addonlistener = buildDialogueTargetSuffix(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER"), false);
                }
            } else {
                $addonlistener="";
            }
            $originalRequest[3]="{$outBuffer["actor"]}: $responseForContext $addonlistener";
            $prechatRequest=$originalRequest;

            // Log chat here, because  function return comes back out of sync.
            $originalRequest[0]="chat";
            $originalRequest[1]++;
            $originalRequest[2]++;
            if (\Synth\Bootstrap::config("SCRIPTLINE_LISTENER")) {
                // Check if speaking from distance (shouting)
                if (!defined('SHOUTING_DISTANCE_THRESHOLD')) {
                    define('SHOUTING_DISTANCE_THRESHOLD', 800);
                }
                $distance = isset($GLOBALS["LAST_SPEECH_DISTANCE"]) ? \Synth\Bootstrap::config("LAST_SPEECH_DISTANCE") : 0.0;
                if ($distance > SHOUTING_DISTANCE_THRESHOLD) {
                    $addonlistener = buildDialogueTargetSuffix(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER"), true);
                } else {
                    $addonlistener = buildDialogueTargetSuffix(\Synth\Bootstrap::config("SCRIPTLINE_LISTENER"), false);
                }
            } else {
                $addonlistener="";
            }
            $originalRequest[3]="{$outBuffer["actor"]}: $responseForContext $addonlistener";
            $originalRequest[5] = [
                'utterance_id' => $currentUtteranceId ?? synthGenerateUtteranceId(),
                'delivery_state' => 'emitted'
            ];
            // An enrolled delivery-receipt line gets heard history only from its accepted receipt.
            if ($currentUtteranceId === null || !synth_receipt_hold_history($currentUtteranceId, [$prechatRequest, $originalRequest])) {
                logEvent($prechatRequest);
                logEvent($originalRequest);
            }
        }

    }

}

function logMemory($speaker, $listener, $message, $momentum, $gamets,$event,$ts)
{
    $db = \Synth\Bootstrap::db();

    $db->insert(
        'core_memory_log',
        array(
                'localts' => time(),
                'speaker' => $speaker,
                'listener' => $listener,
                'message' => $message,
                'gamets' => $gamets,
                'session' => $momentum,
                'momentum'=>$momentum,
                'event'=>$event,
                'ts'=>$ts
        )
    );
}

function lastNames($n, $eventypes)
{

    $db = \Synth\Bootstrap::db();

    $m=$n+1;

    $nativeEvent = \Synth\Bootstrap::requestEvent()['json'] ?? [];
    if (($nativeEvent['protocol_version'] ?? 1) === 2) {
        $m = max(1, min(150, intval($m)));
        $types = implode(',', array_map(static fn($type) => $db->escapeLiteral(strval($type)), $eventypes));
        $visibleChat = synthBuildChatDeliveryStateSql('delivery_state');
        $lastRecords = $types === '' ? [] : $db->fetchAll(synth_protocol_history_cte($db, $nativeEvent)
            . "SELECT data FROM native_history WHERE type IN ({$types})"
            . " AND (type<>'chat' OR {$visibleChat})"
            . " ORDER BY gamets DESC,ts DESC,rowid DESC LIMIT {$m}");
    } else {
        $lastRecords = $db->fetchAll("SELECT data from core_context_event where type in ('".implode("','",$eventypes)."') order by gamets desc limit $m offset 0");
    }

    $uppercaseWords=[];

    foreach ($lastRecords as $record) {
        $pattern = '/\([^)]+\)/';
        $string = preg_replace($pattern, '', $record["data"]);

        $pattern = '/ ([A-Z][a-z\-]{4,}){1,}/';
        preg_match_all($pattern, $string, $matches);

        $uppercaseWords = array_merge($uppercaseWords, $matches[0]);
    }


    $repeatedWords = array();
    $wordCount = array_count_values($uppercaseWords);

    foreach ($wordCount as $word => $count) {
        if ($count > 1) {
            $repeatedWords[] = $word;
        }
    }


    //die(print_r($uppercaseWords,true));
    if (sizeof($repeatedWords)>0) {
        return " ".implode(" ", $repeatedWords);
    } else {
        return "";
    }
}

function lastSpeech($npcname)
{

    $db = \Synth\Bootstrap::db();


    $speaker=$db->escape($npcname);
    $pj=$db->escape(\Synth\Bootstrap::config("PLAYER_NAME"));
    $lastRecords = $db->fetchAll("SELECT * from core_speech where (speaker ilike '$speaker' or speaker ilike '%$pj%' ) order by rowid desc LIMIT 5 OFFSET 0");
    $buffer="";
    foreach (array_reverse($lastRecords) as $record) {
        $buffer.="{$record["speaker"]}:{$record["speech"]}\n";

    }

    return $buffer;


}

function lastKeyWordsContext($n, $npcname='')
{

    global $db,$gameRequest;

    $m=$n+1;
    $speaker=$db->escape($npcname);
    $pj=$db->escape(\Synth\Bootstrap::config("PLAYER_NAME"));

    if (isset($gameRequest[2]))
        $whileago=round($gameRequest[2] - (2/ 0.0000024));
    else
        $whileago=0;

    $nativeEvent = \Synth\Bootstrap::requestEvent()['json'] ?? [];
    if (($nativeEvent['protocol_version'] ?? 1) === 2) {
        $m = max(1, min(150, intval($m)));
        $visibleChat = synthBuildChatDeliveryStateSql('delivery_state');
        // Recall hints use saved dialogue evidence, not the unrelated global speech/playback log.
        $lastRecords = $db->fetchAll(synth_protocol_history_cte($db, $nativeEvent, $npcname)
            . "SELECT substring(data FROM position(':' IN data)+1) AS speech FROM native_history"
            . " WHERE type IN ('chat','inputtext','inputtext_s') AND gamets>" . intval($whileago)
            . " AND speaker_identity_id IN (reader_identity_id,reader_player_id)"
            . " AND (type<>'chat' OR {$visibleChat})"
            . " ORDER BY gamets DESC,ts DESC,rowid DESC LIMIT {$m}");
    } else {
        $lastRecords = $db->fetchAll("SELECT speaker, location, companions, speech, gamets
     from (select * from core_speech where  gamets>{$whileago}) AS sp
     where ((speaker ilike '{$speaker}') or (speaker ilike '%{$pj}%' ))
        order by gamets desc limit {$m} offset 0");
    }

    $words=[];
    $uniqueArray=[];
    $uppercaseWords = [];
    foreach ($lastRecords as $record) {
        $pattern = '/[A-Za-z\-]{4,}/';
        $matches=[];
        preg_match_all($pattern,  $record["speech"],$matches);
        $uppercaseWords1 = array_merge($uppercaseWords, $matches[0]);

        // Get words>4 chars starting with upercase, not in the beginning of string and not after .?
        $pattern = '/(?<!^|[.?]\s)(\b[A-Z][a-zA-Z\-]{4,}\b)/';
        $matches=[];
        preg_match_all($pattern,  $record["speech"],$matches);
        $uppercaseWords = array_merge($uppercaseWords1, $matches[0]);

    }
    foreach ($uppercaseWords as $n=>$e) {
        if (stripos($e, \Synth\Bootstrap::config("PLAYER_NAME"))!==false) {

        } else if (stripos($e, \Synth\Bootstrap::config("SYNTH_NAME"))!==false) {

        } else {
            if (!isset($words[$e]))
                $words[$e]=0;
            $words[$e]++;
            if ( preg_match('~^\p{Lu}~u', $e) ) {
                $words[$e]++;

            }


        }

    }

    unset($words["Yeah"]);
    unset($words["Wouldn"]);
    unset($words["What"]);
    unset($words["Well"]);
    unset($words["Those"]);
    unset($words["This"]);
    unset($words["These"]);
    unset($words["There"]);
    unset($words["That"]);
    unset($words["Seems"]);
    unset($words["Shall"]);
    unset($words["Maybe"]);
    unset($words["Looks"]);
    unset($words["Just"]);
    unset($words["Narrator"]);


    foreach ($words as $n=>$e) {
        if ($e>1)
           if (startsWithUppercase($n))
                $uniqueArray[]=$n;
    }
    $GLOBALS["DEBUG_DATA"]["textToEmbedFinalKwywords"]=implode(" ",$uniqueArray);

    rsort($uniqueArray);
    return $uniqueArray;

}

function lastKeyWordsNew($n, $eventypes='')
{

    $db = \Synth\Bootstrap::db();

    $m=$n+1;

    $lastRecords = $db->fetchAll("SELECT speaker,location,companions,speech from core_speech order by gamets desc limit $m offset 0");
    $words=[];
    $uniqueArray=[];
    $uppercaseWords = [];
    foreach ($lastRecords as $record) {
        $pattern = '/[A-Za-z\-]{4,}/';
        $matches=[];
        preg_match_all($pattern,  $record["speaker"]." ".$record["location"]." ".$record["companions"],$matches);
        $uppercaseWords1 = array_merge($uppercaseWords, $matches[0]);

        // Get words>4 chars starting with upercase, not in the beginning of string and not after .?
        $pattern = '/(?<!^|[.?]\s)(\b[A-Z][a-zA-Z\-]{4,}\b)/';
        $matches=[];
        preg_match_all($pattern,  $record["speech"],$matches);
        $uppercaseWords = array_merge($uppercaseWords1, $matches[0]);

    }
    foreach ($uppercaseWords as $n=>$e) {
        if (stripos($e, \Synth\Bootstrap::config("PLAYER_NAME"))!==false) {

        } else if (stripos($e, \Synth\Bootstrap::config("SYNTH_NAME"))!==false) {

        } else {
            if (!isset($words[$e]))
                $words[$e]=0;
            $words[$e]++;
            if ( preg_match('~^\p{Lu}~u', $e) ) {
                $words[$e]++;

            }


        }

    }

    unset($words["Yeah"]);
    unset($words["Wouldn"]);
    unset($words["What"]);
    unset($words["Well"]);
    unset($words["Those"]);
    unset($words["This"]);
    unset($words["These"]);
    unset($words["There"]);
    unset($words["That"]);
    unset($words["Seems"]);
    unset($words["Shall"]);
    unset($words["Maybe"]);
    unset($words["Looks"]);
    unset($words["Just"]);


    foreach ($words as $n=>$e) {
        if ($e>1)
           if (startsWithUppercase($n))
                $uniqueArray[]=$n;
    }
    $GLOBALS["DEBUG_DATA"]["textToEmbedFinalKwywords"]=implode(" ",$uniqueArray);

    rsort($uniqueArray);
    return $uniqueArray;

}

function lastKeyWords($n, $eventypes='')
{

    $db = \Synth\Bootstrap::db();

    $m=$n+1;

    $lastRecords = $db->fetchAll("SELECT message from core_memory_log order by gamets desc limit $m offset 0");
    $words=[];
    $uniqueArray=[];
    $uppercaseWords = [];
    foreach ($lastRecords as $record) {
        $pattern = '/\([^)]+\)/';
        $string = preg_replace($pattern, '', $record["message"]);

        $pattern = '/[A-Za-z\-]{4,}/';
        preg_match_all($pattern, $string, $matches);

        $uppercaseWords = array_merge($uppercaseWords, $matches[0]);

    }
    foreach ($uppercaseWords as $n=>$e) {
        if (stripos($e, \Synth\Bootstrap::config("PLAYER_NAME"))!==false) {

        } else if (stripos($e, \Synth\Bootstrap::config("SYNTH_NAME"))!==false) {

        } else {
            if (!isset($words[$e]))
                $words[$e]=0;
            $words[$e]++;
            if ( preg_match('~^\p{Lu}~u', $e) ) {
                $words[$e]++;

            }


        }

    }


    foreach ($words as $n=>$e) {
        if ($e>1)
            $uniqueArray[]=$n;
    }
    $GLOBALS["DEBUG_DATA"]["textToEmbedFinalKwywords"]=implode(" ",$uniqueArray);

    //$uniqueArray = array_unique($uppercaseWords);

    //die(print_r($uppercaseWords,true));
    if (sizeof($uniqueArray)>0) {
        return " ".implode(" ", $uniqueArray);
    } else {
        return "";
    }
}

function hashtagify($input) {
    // Remove all punctuation
    $input = preg_replace('/[^\w\s]/u', ' ', $input);

    // Split the string into words
    $words = explode(' ', $input);

    // Filter out words shorter than 2 characters
    $words = array_filter($words, function($word) {
        return mb_strlen(trim($word)) >= 2;
    });

    // Join adjacent words that both start with an uppercase letter
    $result = [];
    $buffer = '';

    foreach ($words as $word) {
        if (ctype_upper(mb_substr($word, 0, 1))) {
            if ($buffer !== '') {
                $buffer .= $word;
            } else {
                $buffer = $word;
            }
        } else {
            if ($buffer !== '') {
                $result[] = "#".ucfirst($buffer);
                $buffer = '';
            }
            $result[] =  "#".ucfirst($word);
        }
    }

    if ($buffer !== '') {
        $result[] = "#$buffer";
    }

    // Convert words to camel case
    /*$result = array_map(function($word, $index) {
        return $index === 0 ? strtolower($word) : ucfirst(strtolower($word));
    }, $result, array_keys($result));*/

    $hashtag = implode(' ', $result);

    return $hashtag;
}

function hashtagifySentences($input) {
    // Remove all punctuation
    $input = preg_replace('/[^\w\s]/u', ' ', $input);

    // Split the string into words
    $words = explode(' ', $input);

    // Filter out words shorter than 2 characters
    $words = array_filter($words, function($word) {
        return mb_strlen(trim($word)) >= 2;
    });

    // Join adjacent words that both start with an uppercase letter
    $result = [];
    $buffer = '';

    foreach ($words as $word) {
        if (ctype_upper(mb_substr($word, 0, 1))) {
            if ($buffer !== '') {
                $buffer .= $word;
            } else {
                $buffer = $word;
            }
        } else {
            if ($buffer !== '') {
                $result[] = ucfirst($buffer);
                $buffer = '';
            }
            $result[] = ucfirst($word);
        }
    }

    if ($buffer !== '') {
        $result[] = "#$buffer";
    }

    // Convert words to camel case
    $result = array_map(function($word, $index) {
        return $index === 0 ? strtolower($word) : ucfirst(strtolower($word));
    }, $result, array_keys($result));

    $hashtag = implode(' ', $result);

    return $hashtag;
}

function ExtractKeywords($sourceText) {

    $uppercaseWords=[];

    $pattern = '/[A-Za-z\-]{4,}/';
    $matches=[];
    preg_match_all($pattern,  $sourceText,$matches);
    $uppercaseWords1 = array_merge($uppercaseWords, $matches[0]);

    $pattern = '/(?<!^|[.?]\s)(\b[A-Z][a-zA-Z\-]{4,}\b)/';
    $matches=[];
    preg_match_all($pattern,  $sourceText,$matches);
    $uppercaseWords = array_merge($uppercaseWords1, $matches[0]);
    $words=[];
    foreach ($uppercaseWords as $n=>$e) {
        if (stripos($e, \Synth\Bootstrap::config("PLAYER_NAME"))!==false) {

        } else if (stripos($e, \Synth\Bootstrap::config("SYNTH_NAME"))!==false) {

        } else {
            if (!isset($words[$e]))
                $words[$e]=0;
            $words[$e]++;
            if ( preg_match('~^\p{Lu}~u', $e) ) {
                $words[$e]++;

            }


        }

    }

    unset($words["Yeah"]);
    unset($words["Wouldn"]);
    unset($words["What"]);
    unset($words["Well"]);
    unset($words["Those"]);
    unset($words["This"]);
    unset($words["These"]);
    unset($words["There"]);
    unset($words["That"]);
    unset($words["Seems"]);
    unset($words["Shall"]);
    unset($words["Maybe"]);
    unset($words["Looks"]);
    unset($words["Just"]);

    $uniqueArray=[];

    foreach ($words as $n=>$e) {
        if ($e>1)
           if (startsWithUppercase($n))
                $uniqueArray[]=$n;
    }
    if (is_array($uniqueArray)) {
        rsort($uniqueArray);
    } else
        return [];

    return $uniqueArray;
}

function getGametsLimitFor($actor) {
    $nativeEvent = \Synth\Bootstrap::requestEvent()['json'] ?? [];
    if (($nativeEvent['protocol_version'] ?? 1) === 2) {
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return 72;
        synth_protocol_assert_request_active();
        $cacheKey = hash('sha256',json_encode([$nativeEvent['session_id'],$nativeEvent['request_id'],
            $nativeEvent['generation'],$nativeEvent['context_sequence'],strval($actor)]));
        if (isset($GLOBALS['SYNTH_NATIVE_MEMORY_HOURS'][$cacheKey])) return \Synth\Bootstrap::config('SYNTH_NATIVE_MEMORY_HOURS')[$cacheKey];
        $limit = max(1,min(150,intval(\Synth\Bootstrap::config('CONTEXT_HISTORY') ?? 50)));
        $db = \Synth\Bootstrap::db();
        $hours = $db->withNativeRequestLock(static function($connection,array $event) use ($db,$actor,$limit) {
            $query = synth_protocol_history_cte($db,$event,strval($actor))."SELECT (MAX(gamets)-MIN(gamets))*0.0000024 AS hours
                FROM (SELECT gamets FROM native_history WHERE type='chat'
                    AND COALESCE(delivery_state,'spoken') IN ('emitted','spoken','presented')
                    ORDER BY gamets DESC,ts DESC,history_event_id DESC LIMIT {$limit}) recent_chat";
            $result = @pg_query($connection,$query);
            if (!$result) throw new RuntimeException('Unable to read native memory window');
            return floatval(pg_fetch_assoc($result)['hours'] ?? 0);
        },2000);
        return $GLOBALS['SYNTH_NATIVE_MEMORY_HOURS'][$cacheKey] = $hours>0 ? $hours : 72;
    }
    if (isset($GLOBALS["GAMETS_LIMIT_FOR_ACTOR"][$actor])) {
        return \Synth\Bootstrap::config("GAMETS_LIMIT_FOR_ACTOR")[$actor];
    }
    $db = \Synth\Bootstrap::db();

    $actorEscaped = $db->escape($actor);
    $limit = (int) \Synth\Bootstrap::config("CONTEXT_HISTORY");

    $visibleChatStateSql = synthBuildChatDeliveryStateSql('delivery_state');
    $query = "
        SELECT
            (MAX(gamets) - MIN(gamets)) * 0.0000024 AS hour_threshold
        FROM (
            SELECT gamets
            FROM core_context_event
            WHERE type='chat'
            AND {$visibleChatStateSql}
            and people LIKE '%$actorEscaped%'
            ORDER BY gamets DESC
            LIMIT $limit
        ) AS recent_events
    ";

    $limitRow = $db->fetchOne($query);

    Logger::trace("MEMORY_EMBEDDING getGametsLimitFor($actor),CONTEXT_HISTORY: {$GLOBALS["CONTEXT_HISTORY"]} => {$limitRow["hour_threshold"]}");

    // If no data or result is too small, fall back to a sensible default (e.g. 72 in-game hours)
    $res = (isset($limitRow["hour_threshold"]) && $limitRow["hour_threshold"] > 0)
        ? $limitRow["hour_threshold"]
        : 72;

    $GLOBALS["GAMETS_LIMIT_FOR_ACTOR"][$actor] = $res;
    return $res;
}

function synthMemorySearchInputFromRequest(array $gameRequest): string
{
    $rawInput = trim((string)($gameRequest[3] ?? ''));
    if (($gameRequest[0] ?? '') !== 'rechat') {
        return $rawInput;
    }

    $payload = synthParseServerSideRechatPayload($rawInput);
    return trim((string)($payload['origin_line'] ?? ''));
}

function offerMemory($gameRequest)
{
    $db = \Synth\Bootstrap::db();

    $startTime=microtime(true);

    if (isset($GLOBALS["FEATURES"]["MEMORY_EMBEDDING"]["ENABLED"]) && !\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["ENABLED"] ) {
        Logger::debug("MEMORY_EMBEDDING disabled");
        return "";
    }

    // PostgreSQL full text Searching



    $npc=\Synth\Bootstrap::config("SYNTH_NAME");
    if ($npc=="The Narrator") { // Narrator knows all
       $npc="";
    }

    $timeThreshold=round($gameRequest[2]-(getGametsLimitFor($npc)/0.0000024),0)-1;
    $memorySearchInput = synthMemorySearchInputFromRequest($gameRequest);

    \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'DataSearchMemoryByVector', "Using timeThreshold $timeThreshold");
    $contextKeywords  = implode(" ", lastKeyWordsContext(5,$npc));

    if (\Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["USE_TEXT2VEC"]) {
        $localStartTime = microtime(true);
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'DataSearchMemoryByVector calling', " : " . (microtime(true) - $localStartTime) . " seconds");
        $res = DataSearchMemoryByVector($memorySearchInput, $npc, true,$timeThreshold);
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'DataSearchMemoryByVector called 1', " : " . (microtime(true) - $localStartTime) . " seconds");
        $res2 = DataSearchMemoryByVector($memorySearchInput, $npc,false,$timeThreshold);
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'DataSearchMemoryByVector called 2', " : " . (microtime(true) - $localStartTime) . " seconds");

        if (isset($res[0]) && isset($res2[0])) {
            $resFinal = ($res[0]['rank_any'] >= $res2[0]['rank_any']) ? $res : $res2;
        } else {
            $resFinal = isset($res[0]['rank_any']) ? $res : (isset($res2[0]['rank_any']) ? $res2 : []);
        }

        // Recover only strong semantic matches when MiniMe suppresses the primary recall.
        $strictSemanticFallback = false;
        $hasPrimaryCandidate = isset($resFinal[0])
            && is_array($resFinal[0])
            && trim((string)($resFinal[0]['summary'] ?? '')) !== '';
        if (!$hasPrimaryCandidate && isMinimeT5Enabled()) {
            $GLOBALS['MEMORY_STRICT_FALLBACK_ATTEMPTED'] = true;
            $hadBypassSetting = array_key_exists('PATCH_BYPASS_MINIME_EXTRACT', $GLOBALS);
            $previousBypassSetting = \Synth\Bootstrap::config('PATCH_BYPASS_MINIME_EXTRACT') ?? null;
            try {
                $GLOBALS['PATCH_BYPASS_MINIME_EXTRACT'] = true;
                $fallbackResult = DataSearchMemoryByVector($memorySearchInput, $npc, false, $timeThreshold);
            } finally {
                if ($hadBypassSetting) {
                    $GLOBALS['PATCH_BYPASS_MINIME_EXTRACT'] = $previousBypassSetting;
                } else {
                    unset($GLOBALS['PATCH_BYPASS_MINIME_EXTRACT']);
                }
            }

            if (isset($fallbackResult[0])
                && is_array($fallbackResult[0])
                && trim((string)($fallbackResult[0]['summary'] ?? '')) !== '') {
                $resFinal = $fallbackResult;
                $strictSemanticFallback = true;
                Logger::info('[MEMORY] Primary recall produced no candidate; evaluating strict semantic fallback');
            } else {
                Logger::info('[MEMORY] Primary recall produced no candidate and strict semantic fallback found no candidate');
            }
        }
        // Native summaries remain usable through scoped text recall when vector generation is unavailable.
        if ((\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? 1) === 2
            && empty($resFinal[0]['summary'])) {
            $resFinal = DataSearchMemory($memorySearchInput,$npc,$timeThreshold);
            $strictSemanticFallback = false;
        }
        $memories = $resFinal;

    } else {
        $memories=DataSearchMemory($memorySearchInput,$npc,$timeThreshold);
    }


    $thresholdModifier = floatval(\Synth\Bootstrap::config("MEMORY_THRESHOLD_MODIFIER") ?? 0);
    if (!empty($strictSemanticFallback)) {
        $thresholdModifier = max($thresholdModifier, 0.5);
    }

    if (isset($memories[0])) {
        Logger::trace(print_r($memories[0],true));

        if (($memories[0]["rank_any"]==$memories[0]["rank_all"])&&($memories[0]["rank_any"]> (0.25+$thresholdModifier) )) {

            $memory=(isset($memories[0]["summary"])?$memories[0]["summary"]:"");

        } else if ((($memories[0]["rank_all"]+$memories[0]["rank_any"])/2)> (0.25+ $thresholdModifier)) {

            $memory=(isset($memories[0]["summary"])?$memories[0]["summary"]:"");

        } else if ((($memories[0]["rank_all"]+$memories[0]["rank_any"])/2)>0.05 && false) {//This is too low

            $memory=(isset($memories[0]["summary"])?$memories[0]["summary"]:"");

        } else if (($memories[0]["rank_any"]> (0.50 + $thresholdModifier)) && isset($memories[0]["mixed_distance"])) {// Search by mixed vector/fts .

            $memory=(isset($memories[0]["summary"])?$memories[0]["summary"]:"");

        } else {
           $decisionContext = Logger::formatContext([
               'rank_any' => $memories[0]['rank_any'] ?? null,
               'rank_all' => $memories[0]['rank_all'] ?? null,
               'threshold_modifier' => $thresholdModifier,
               'strict_fallback' => !empty($strictSemanticFallback) ? 1 : 0,
           ]);
           Logger::trace("[MEMORY] Memory discarded by scoring" . $decisionContext);
           \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'MEMORY', "Memory discarded by scoring" . $decisionContext);
           return "";
        }
    } else {
        Logger::trace("[MEMORY] Memory not found");
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'MEMORY', "Memory not found");
        return "";
    }

    if (!empty($memory)) {
        Logger::trace("adding date to memory <".substr($memory,0,25)."...>");
        $hoursAgo=round(($gameRequest[2]-$memories[0]["gamets_truncated"]) * 0.0000024, 0);
        if($hoursAgo > getGametsLimitFor(\Synth\Bootstrap::config("SYNTH_NAME"))) {
            $daysAgo = floor(($gameRequest[2]-$memories[0]["gamets_truncated"]) * 0.0000001);
            $fallout_date = gamets2str_format_date($memories[0]["gamets_truncated"], 'Y-m-d');
            $s_prefix = "{$daysAgo} days ago, on {$fallout_date} ... ";
        } else {
            $s_prefix = "{$hoursAgo} hours ago ... ";
            Logger::trace("Discarding memory because recent ($hoursAgo} hours ago ... )"); ////DataSearchMemoryByVector filter  by gamets, this should happend if using it
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'MEMORY', "Discarding memory because recent ($hoursAgo} hours ago");
            return "";// Do not offer memory if its recent
        }
        $pattern = '/#Tags:.*/';
        $replacement = '';
        $output = preg_replace($pattern, $replacement, $memory);
        $memory = $s_prefix . $output;
        Logger::trace("Final memory <".substr($memory,0,25)."...>");
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'chat_helper_functions', "Final memory <".substr($memory,0,25)."...>");

    }
    \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'MEMORY', "Returning memory");
    return ($memory);
}

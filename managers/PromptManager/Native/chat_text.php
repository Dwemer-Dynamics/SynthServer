<?php

// Moved from lib/chat_helper_functions.php: response cleaning, sentence splitting, asterisk/narration handling and speech/subtitle formatting
// The original file requires this one, so every caller is unchanged.

function randomReplaceShortWordsWithPoints($inputString, $distance)
{
    // Split the input string into words
    $words = explode(' ', str_replace("Dear Diary", "", $inputString));

    $limit=round(30-($distance*30), 0);

    // Iterate through each word and replace short words with points
    foreach ($words as &$word) {

        if (preg_match('/^[A-Z]/', trim($word))) { // Skip names
            continue;
        }

        if ((rand(0, round($limit/2, 0))==0) && true) {
            $word = "[gap]";
        }
    }

    // Join the words back into a string
    $outputString = implode(' ', $words);

    return $outputString;
}

function cleanResponse($rawResponse)
{
    // Never speak unresolved NVSE string-variable names from contaminated context.
    $rawResponse = preg_replace('/\bs(?:Subtitle|SpeakerName|TargetName)\b/i', '', strval($rawResponse));
    // Remove Context Location between parenthesys
    $pattern = '/\(C[^)]*\)/';
    $replacement = '';
    $rawResponse = preg_replace($pattern, $replacement, $rawResponse);

    // Remove {*}
    $pattern = '/\{.*?\}/';
    $replacement = '';
    $rawResponse = preg_replace($pattern, $replacement, $rawResponse);

    $rawResponse = strtr($rawResponse,["The Narrator: background dialogue:"=>""]);

    // Conditionally preserve paralinguistic tags based on provider settings
    // This feature works for any TTS provider that defines PARALINGUISTIC_TAGS_ENABLED
    $shouldPreserveTags = false;
    $eventTags = [];

    if (\Synth\Bootstrap::has('TTSFUNCTION') && !empty(\Synth\Bootstrap::config('TTSFUNCTION'))) {
        // Map TTSFUNCTION to TTS array key
        $ttsMap = [
            'xtts-fastapi' => 'XTTSFASTAPI',
            'chatterbox' => 'CHATTERBOX',
            'pockettts' => 'POCKETTTS',
            'omnivoice' => 'OMNIVOICE',
            '11labs' => 'ELEVEN_LABS',
            'kokoro' => 'KOKORO',
            'piper-tts' => 'PIPERTTS',
            'cartesia' => 'CARTESIA',
            'inworld' => 'INWORLD'
        ];

        $ttsKey = $ttsMap[\Synth\Bootstrap::config("TTSFUNCTION")] ?? strtoupper(\Synth\Bootstrap::config("TTSFUNCTION"));

        if (isset(\Synth\Bootstrap::config('TTS')[$ttsKey]["PARALINGUISTIC_TAGS_ENABLED"]) &&
            (bool)\Synth\Bootstrap::config("TTS")[$ttsKey]["PARALINGUISTIC_TAGS_ENABLED"]) {
            $shouldPreserveTags = true;

            // Parse the configurable tag list
            if (isset(\Synth\Bootstrap::config('TTS')[$ttsKey]["PARALINGUISTIC_TAGS_LIST"]) &&
                !empty(\Synth\Bootstrap::config('TTS')[$ttsKey]["PARALINGUISTIC_TAGS_LIST"])) {
                $tagsList = \Synth\Bootstrap::config("TTS")[$ttsKey]["PARALINGUISTIC_TAGS_LIST"];
                $eventTags = array_map('trim', explode(',', $tagsList));
            }
        }
    }

    if ($shouldPreserveTags && !empty($eventTags)) {
        $rawResponse = preg_replace_callback('/\[.*?\]/', function($matches) use ($eventTags) {
            // Convert to lowercase to ensure case-insensitive matching
            foreach ($eventTags as $tag) {
                if (strtolower($matches[0]) === strtolower($tag)) {
                    return $matches[0]; // Return the tag as-is
                }
            }
            return ''; // Delete the tag
        }, $rawResponse);
    } else {
        // Remove all square brackets content
        $rawResponse = preg_replace('/\[.*?\]/', '', $rawResponse);
    }

    // Any bracket { or }]
    //$rawResponse = strtr($rawResponse, array("{" => "", "}" => ""));

    // clean { , fix ellipsis and unicode punctuation etc
    $rawResponse = str_replace(
        ["\0", "\u{2010}", "\u{2011}", " \u{2014} ",  "\u{2014}",  "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{3002}\u{3002}\u{3002}", "\u{2026}",   "{", "}" ],  //, " U.S.A. ", " U.S. " ],
        [''  , "-", "-", " - ", " - ", "'", "'", "'", "'", "...", "...", "",  ""  ], //,  " USA ",    " US "    ],
        $rawResponse);

    if (strpos($rawResponse, "(Context location") !== false || strpos($rawResponse, "(Context new location") !== false) {
        $rawResponseSplited = explode(":", $rawResponse);
        if (!isset($rawResponseSplited[2])) {
            Logger::warn("Could not extract speech from raw response: $rawResponse");
        }
        $toSplit = $rawResponseSplited[2];
    } else {
        $toSplit = $rawResponse;
    }

    $synthNameShort = \Synth\Bootstrap::config("SYNTH_NAME");
    $matches = [];
        // Some LLMs will omit part of the name in brackets (e.g., Ranger [NCR Patrol] becomes Ranger in the response).
    // This avoids reading off the abbreviated name in the TTS.
    if (preg_match('/^(.+?) \[(.+)\]$/', \Synth\Bootstrap::ref('SYNTH_NAME'), $matches)) {
        $synthNameShort = $matches[1];
    }

    if (stripos($toSplit, (\Synth\Bootstrap::config('SYNTH_NAME') . ":")) !== false || preg_match("/{$synthNameShort}\s*:/", $toSplit, $matches)) {
        $rawResponseSplited = explode(":", $toSplit);
        array_shift($rawResponseSplited);
        $toSplit = implode(":", $rawResponseSplited);
    }

    //$toSplit = preg_replace("/{\Synth\Bootstrap::config("SYNTH_NAME")}\s*:\s*/", '', $toSplit);

    $sentences = split_sentences($toSplit);

    $sentence = trim((implode(" ", $sentences)));

    $sentenceX = strtr(
        $sentence,
        array(
            ",." => ","
        )
    );


    // convert to half-width numbers (to avoid display issues with Japanese font)
    $sentenceXX = str_replace(
        array("\u{FF11}", "\u{FF12}", "\u{FF13}", "\u{FF14}", "\u{FF15}", "\u{FF16}", "\u{FF17}", "\u{FF18}", "\u{FF19}", "\u{FF10}"),
        array('1', '2', '3', '4', '5', '6', '7', '8', '9', '0'),
        $sentenceX
    );

    return $sentenceXX;
}

function findFastSentencePosition($s_string,$min_sentence_size=0) {
    // Find the position of the first sentence-ending punctuation followed by a space
    // This preserves ellipsis (...) because we require a space after the punctuation
    $eosPunc = preg_quote(getEndOfSentencePunctuation(), '/'); // .?! plus Japanese sentence punctuation

    // Match the EOS punctuation character followed by spaces
    // Negative lookbehind ensures we don't match after ellipsis (..)
    // "Don't split after ellipses either... Thanks :-)" -for example
    $splitSentenceRegex = "/([" . $eosPunc . "])(?<!\.\.)(?<!\.\.\.)\s+/u";

    // Find the first safe match and return the position of the EOS punctuation.
    // Do not split while a single-asterisk narration span is still open.
    if (preg_match_all($splitSentenceRegex, $s_string, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $match) {
            $position = $match[1];
            // Use the end of the matched punctuation so that multi-byte characters
            // (e.g. Japanese sentence punctuation, which is multi-byte in UTF-8) are not split mid-character.
            $endPosition = $position + strlen($match[0]) - 1;
            if ($min_sentence_size > 0 && $endPosition <= $min_sentence_size) {
                continue;
            }

            $candidate = substr($s_string, 0, $endPosition + 1);
            if (hasUnclosedSingleAsteriskBlock($candidate) || synth_relationship_has_open_command($candidate)) {
                continue;
            }

            return $endPosition;
        }
    }

    return false;
}

function findDotPosition($s_string) {

    $lastChar = substr($s_string, -1);

    // Only skip if it ends with ellipsis (...), not regular sentence endings
    // This allows streaming to work properly for complete sentences
    if ($lastChar === ".") {
        // Check if it's an ellipsis (...)
        $last3Chars = substr($s_string, -3);
        if ($last3Chars === "...") {
            return false; // Don't process ellipsis yet
        }
        // Otherwise, allow processing of regular sentence endings
    }

    $dotPosition = strrpos($s_string, "."); // last dot in string

    /* // old version
    if (($dotPosition !== false) && (strpos($s_string, ".", $dotPosition + 1) === false) && (substr($s_string, $dotPosition - 3, 3) !== "...")) {
        return $dotPosition;
    } */

    if ($dotPosition !== false) {// found last dot
        // check for ...
        if (substr($s_string, ($dotPosition - 1), 1) !== ".") {
            return $dotPosition;
        }
    }

    return false;
}

function br2nl($string)
{
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[\r\n]+/', ' ', preg_replace('/\<br(\s*)?\/?\>/i', " ", $string))));
}

function split_at_end_of_sentence($paragraph) {
    // Split only at sentence-ending punctuation followed by a space
    // This preserves ellipsis (...) because we require a space after the punctuation
    $eosPunc = preg_quote(getEndOfSentencePunctuation(), '/'); // .?! plus Japanese sentence punctuation

    // Split at any end-of-sentence punctuation followed by one or more spaces
    // Negative lookahead (?!\.) ensures we don't split after a dot if another dot follows (ellipsis)
    // "Don't split after ellipses either... Thanks :-)" -for example
    $splitSentenceRegex = "/(?<=[" . $eosPunc . "])(?<!\.\.)(?<!\.\.\.)\s+/u";

    if (!preg_match_all($splitSentenceRegex, $paragraph, $matches, PREG_OFFSET_CAPTURE)) {
        return [$paragraph];
    }

    $sentences = [];
    $chunkStart = 0;

    foreach ($matches[0] as $match) {
        $splitOffset = $match[1];
        $candidate = substr($paragraph, 0, $splitOffset);
        if (hasUnclosedSingleAsteriskBlock($candidate) || synth_relationship_has_open_command($candidate)) {
            continue;
        }

        $sentence = trim(substr($paragraph, $chunkStart, $splitOffset - $chunkStart));
        if ($sentence !== '') {
            $sentences[] = $sentence;
        }

        $chunkStart = $splitOffset + strlen($match[0]);
    }

    $tail = trim(substr($paragraph, $chunkStart));
    if ($tail !== '') {
        $sentences[] = $tail;
    }

    return $sentences ?: [$paragraph];
}

function split_sentences($paragraph)
{
    // Normalize newlines to periods -dont know what this is fixing, but i can see what it is breaking (.\n becomes .. is that useful?)- matt
//    $paragraph = strtr($paragraph, array(" \n\n" => ".", " \n" => ".", "\n\n" => ".", '\n' => ".", "\n" => "."));

    if (strlen($paragraph) <= MAXIMUM_SENTENCE_SIZE) {
        return [$paragraph];
    }

    $paragraphNcr = br2nl($paragraph); // Remove any BR tags
    $paragraphNcr = preg_replace('/([\x{3002}\x{FF01}\x{FF1F}])(?=\S)/u', '$1 ', $paragraphNcr);

    $sentences = split_at_end_of_sentence($paragraphNcr);

    return $sentences;
}

function split_sentences_stream($paragraph)
{
    if (strlen($paragraph) <= MAXIMUM_SENTENCE_SIZE) {
        return [$paragraph];
    }

    $paragraph = preg_replace('/([\x{3002}\x{FF01}\x{FF1F}])(?=\S)/u', '$1 ', $paragraph);
    // Split at sentence boundaries
    $sentences = split_at_end_of_sentence($paragraph);

    // Combine sentences to fit within MINIMUM_SENTENCE_SIZE and MAXIMUM_SENTENCE_SIZE
    $splitSentences = [];
    $currentSentence = '';

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (empty($sentence)) {
            continue;
        }

        if (empty($currentSentence)) {
            // Start a new chunk
            $currentSentence = $sentence;
        } else {
            $combined = $currentSentence . ' ' . $sentence;
            $currentHasOpenAsteriskBlock = hasUnclosedSingleAsteriskBlock($currentSentence);

            // Keep multi-sentence *...* spans intact even if they temporarily exceed the normal max size.
            if (strlen($combined) > MAXIMUM_SENTENCE_SIZE && !$currentHasOpenAsteriskBlock) {
                $splitSentences[] = $currentSentence;
                $currentSentence = $sentence;
            } else {
                // Add to current sentence
                $currentSentence = $combined;

                // If we've reached minimum size and we're between min and max, we can flush
                //EXPERIMENT: talk more in one go (longer subtitle text, fewer TTS calls)
                $talkMore = true; //false to split as soon as we reach min len (old behavior), true to pack up to max
                if (!$talkMore && strlen($currentSentence) >= MINIMUM_SENTENCE_SIZE) {
                    $splitSentences[] = $currentSentence;
                    $currentSentence = '';
                }
            }
        }
    }

    // Flush the last accumulated chunk
    if (!empty($currentSentence)) {
        $splitSentences[] = $currentSentence;
    }

    return $splitSentences;
}

function getEndOfSentencePunctuation() {
    $en='.?!';
    $cjk="\u{3002}\u{FF1F}\u{FF01}";

    return $en.$cjk;
}

function remove_between($marker, $s_input) {
    $s_res = $s_input;
    $p_start=null;
    $i_mk_len = strlen($marker);
    if ($i_mk_len > 0) {
        $i_str_len = strlen($s_input);
        if ($i_str_len > (2 * $i_mk_len)) {
            $p_first = strpos($s_input, $marker);
            if (!($p_first === false)) {
                $p_last = strrpos($s_input, $marker);
                if ((!($p_first === false)) && ($p_last > $p_first)) {
                    $s1 = substr($s_input, 0, $p_start);
                    $s2 = substr($s_input, $p_last);
                    $s_res = $s1 . $s2;
                }
            }

        }
    }
    return $s_res;
}

function checkOAIComplains($responseTextUnmooded)
{


    if (\Synth\Bootstrap::has('OPENAI_FILTER_DISABLED'))
        return 0;

    $scoring = 0;

    if (stripos($responseTextUnmooded, "can't") !== false) {
        $scoring++;
    }
    if (stripos($responseTextUnmooded, "apologi") !== false) {
        $scoring++;
    }
    if (stripos($responseTextUnmooded, "sorry") !== false) {
        $scoring++;
    }
    if (stripos($responseTextUnmooded, "not able") !== false) {
        $scoring++;
    }
    if (stripos($responseTextUnmooded, "won't be able") !== false) {
        $scoring++;
    }
    if (stripos($responseTextUnmooded, "that direction") !== false) {
        $scoring += 2;
    }
    if (stripos($responseTextUnmooded, "AI language model") !== false) {
        $scoring += 4;
    }
    if (stripos($responseTextUnmooded, "openai") !== false) {
        $scoring += 3;
    }
    if (stripos($responseTextUnmooded, "generate") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "request") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "policy") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "to provide") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "context") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "unable") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "assist") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "inappropriate") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "explicit") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "roleplay") !== false) {
        $scoring += 1;
    }
    if (stripos($responseTextUnmooded, "please provide an alternative scenario") !== false) {
        $scoring += 3;
    }

    return $scoring;
}

/**
 * Count standalone single-asterisk markers while ignoring double-asterisk markup.
 */
function countSingleAsteriskMarkers($text) {
    if (!preg_match_all('/(?<!\*)\*(?!\*)/', (string)$text, $matches)) {
        return 0;
    }

    return count($matches[0]);
}

/**
 * Detect whether a single-asterisk span is still open.
 */
function hasUnclosedSingleAsteriskBlock($text) {
    return (countSingleAsteriskMarkers($text) % 2) !== 0;
}

/**
 * Normalize the leading text from a candidate dialogue segment salvaged out of
 * a malformed fully wrapped *...* reply.
 */
function normalizeWrappedDialogueLead($text) {
    $normalized = trim((string)$text);
    return preg_replace('/^[\s"\'\(\[\{]+/', '', $normalized);
}

/**
 * Detect whether the text after the first sentence in a malformed fully
 * wrapped *...* reply looks like spoken dialogue rather than more narration.
 */
function isLikelyWrappedDialogueLead($text) {
    $lead = normalizeWrappedDialogueLead($text);
    if ($lead === '') {
        return false;
    }

    if (preg_match('/\b(?:you|he|she|they)\s+(?:say|says|said|ask|asks|asked|reply|replies|replied|whisper|whispers|whispered|murmur|murmurs|murmured)\b/i', $lead)) {
        return false;
    }

    if (preg_match('/^(?:indeed|yes|no|of course|certainly|very well|as you wish|understood|ready|i am|i\'m|i will|i\'ll|i can|i cannot|i do|i did|i have|i\'ve|i understand|i obey|i serve|forgive me|thank you)\b/i', $lead)) {
        return true;
    }

    if (preg_match('/,\s*(?:my lord|milord|my lady|your majesty|your highness|my friend|sister|brother)\b/i', $lead)) {
        return true;
    }

    if (preg_match('/\b(?:if i do say so myself|i suppose|i think|i know|i dare say)\b/i', $lead)) {
        return true;
    }

    return false;
}

/**
 * Salvage a malformed reply where the model wrapped both narration and speech
 * inside a single *...* block. This only splits after the first sentence when
 * the remaining text strongly resembles spoken dialogue.
 */
function trySplitFullyWrappedNarrationReply($text) {
    $wrappedText = trim((string)$text);
    if ($wrappedText === '') {
        return null;
    }

    $sentences = split_at_end_of_sentence($wrappedText);
    if (count($sentences) < 2) {
        return null;
    }

    $narrationCandidate = trim($sentences[0]);
    $dialogueCandidate = trim(implode(' ', array_slice($sentences, 1)));
    if ($narrationCandidate === '' || $dialogueCandidate === '') {
        return null;
    }

    if (!isLikelyWrappedDialogueLead($dialogueCandidate)) {
        return null;
    }

    Logger::info("[extractNarrationAndDialogue] Salvaged malformed wrapped reply into narration + dialogue: " . substr($wrappedText, 0, 100));

    return [
        'narrations' => [$narrationCandidate],
        'dialogue' => $dialogueCandidate,
        'has_narration' => true
    ];
}

/**
 * Extract narration (text in asterisks) from dialogue
 * Handles multiple narration blocks throughout the text
 *
 * @param string $text The full text potentially containing narration
 * @return array ['narration' => array of narration texts, 'dialogue' => cleaned dialogue]
 */
function extractNarrationAndDialogue($text) {
    $narrations = [];
    $remainingText = $text;

    // IMPORTANT: Check for leftover "* " from sentence splitting FIRST, before checking paired asterisks
    // This prevents "* dialogue with *emphasis*" from being treated as narration
    if (preg_match('/^\*\s+(.+)$/s', $text, $matches)) {
        // This is likely dialogue that got a leftover "* " prefix from sentence splitting
        // Treat it as dialogue, not narration
        $remainingText = trim($matches[1]);
        Logger::info("[extractNarrationAndDialogue] Detected leftover asterisk from sentence split, treating as dialogue: " . substr($remainingText, 0, 50));
    }
    // Try to extract paired asterisks at the START of the sentence: *narration* dialogue
    else if (preg_match('/^\*([^*]+)\*(?:\s*[.!?,;:-])?\s*(.*)$/s', $text, $matches)) {
        // Only extract narration if it's at the beginning, followed by dialogue.
        // If the entire reply is wrapped in one *...* block, try to salvage the
        // first sentence as narration when the remainder clearly looks spoken.
        $wrappedText = trim($matches[1]);
        $remainingText = trim($matches[2]);

        if ($remainingText === '') {
            $wrappedSplit = trySplitFullyWrappedNarrationReply($wrappedText);
            if ($wrappedSplit !== null) {
                return $wrappedSplit;
            }
        }

        $narrations = [$wrappedText];

        Logger::info("[extractNarrationAndDialogue] Found narration at start (paired asterisks): " . substr($narrations[0], 0, 50));
    }
    // If starts with asterisk and ends with period/punctuation, it's pure narration
    else if (preg_match('/^\*([^*]+)[.!?]\s*$/s', $text, $matches)) {
        // Single asterisk at start with punctuation at end - pure narration, no dialogue
        $narrations = [trim($matches[1], '. !?')];
        $remainingText = ''; // All narration, no dialogue

        Logger::info("[extractNarrationAndDialogue] Found 1 narration block (single asterisk, complete sentence)");
    }
    else {
        Logger::info("[extractNarrationAndDialogue] No narration found in: " . substr($text, 0, 100));
    }

    return [
        'narrations' => $narrations,
        'dialogue' => $remainingText,
        'has_narration' => !empty($narrations)
    ];
}

function stripPlayerAsteriskActions($text) {
    $strippedText = preg_replace('/\*[^*]*\*/', ' ', $text);
    $strippedText = str_replace('*', '', $strippedText);
    return trim(preg_replace('/\s+/', ' ', $strippedText));
}

function getInlineNarrationMode() {
    $mode = strtolower(trim((string)(\Synth\Bootstrap::config("INLINE_NARRATION_MODE") ?? "")));
    if (in_array($mode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
        return $mode;
    }

    return 'disabled';
}

function isInlineNarrationEnabled() {
    return getInlineNarrationMode() !== 'disabled';
}

function shouldRemovePlayerAutochatAsterisks() {
    if (\Synth\Bootstrap::has('REMOVE_PLAYER_AUTOCHAT_ASTERISKS')) {
        return (bool)\Synth\Bootstrap::config('REMOVE_PLAYER_AUTOCHAT_ASTERISKS');
    }

    return true;
}

function shouldSplitInlineNarration() {
    return getInlineNarrationMode() === 'narrator';
}

function shouldStripPlayerInputAsterisks() {
    if (\Synth\Bootstrap::has('REMOVE_ASTERISKS_FROM_PLAYER_INPUT')) {
        return (bool)\Synth\Bootstrap::config('REMOVE_ASTERISKS_FROM_PLAYER_INPUT');
    }

    return true;
}

function shouldStripNpcOutputAsterisks() {
    if (array_key_exists('strip_emotes_from_output', $GLOBALS)) {
        return (bool)\Synth\Bootstrap::config('strip_emotes_from_output');
    }

    if (\Synth\Bootstrap::has('REMOVE_ASTERISKS_FROM_NPC_OUTPUT')) {
        return (bool)\Synth\Bootstrap::config('REMOVE_ASTERISKS_FROM_NPC_OUTPUT');
    }

    return true;
}

function normalizeAsteriskTextForSpeech($text) {
    $normalizedText = preg_replace('/\*([^*]+)\*/', '$1', (string)$text);
    return str_replace('*', '', $normalizedText);
}

function filterKnownNpcAsteriskTokens($text) {
    return strtr((string)$text, [
        "*Smirks*" => "", "*smirks*" => "",
        "*smiles*" => "", "*Smile*" => "", "*smile*" => "",
        "*winks*" => "", "*wink*" => "", "*smirk*" => "", "*gasps*" => "", "*chuckles*" => "", "*giggles*" => "", "*Giggles*" => "", "*laughs*" => "",
        "*gasp*" => "", "*moans*" => "", "*whispers*" => "", "*moan*" => "",
        "*pant*" => "", "*cough*" => "", "*hiccup*" => "", "*whimper*" => ""
    ]);
}

function formatPlayerSpeechText($text) {
    if (shouldStripPlayerInputAsterisks()) {
        return stripPlayerAsteriskActions($text);
    }

    return trim(preg_replace('/\s+/', ' ', normalizeAsteriskTextForSpeech($text)));
}

function formatNpcSpeechText($text) {
    $speechText = (string)$text;
    if (shouldStripNpcOutputAsterisks()) {
        $speechText = filterKnownNpcAsteriskTokens($speechText);
    }

    $speechText = normalizeAsteriskTextForSpeech($speechText);
    return trim(preg_replace('/\s+/', ' ', $speechText));
}

function stripOutputSpeakerPrefix($text, $speakerName = null) {
    $speakerName = $speakerName ?? (\Synth\Bootstrap::config("SYNTH_NAME") ?? "");
    if ($speakerName === '') {
        return (string)$text;
    }

    return preg_replace('/^' . preg_quote((string)$speakerName, '/') . '\s*:\s*/i', '', (string)$text);
}

function stripOutputSpeakerPrefixAfterInlineNarration($text, $speakerName = null) {
    $speakerName = $speakerName ?? (\Synth\Bootstrap::config("SYNTH_NAME") ?? "");
    if ($speakerName === '') {
        return (string)$text;
    }

    return preg_replace(
        '/^(\s*(?:\*[^*]+\*\s*)+)' . preg_quote((string)$speakerName, '/') . '\s*:\s*/i',
        '$1',
        (string)$text
    );
}

function stripLeadingParentheticalBlocks($text) {
    return preg_replace('/^\s*(?:\([^)]*\)\s*)+/', '', (string)$text);
}

function stripLeadingPlayerRespeechNarration($text) {
    $speechText = (string)$text;
    do {
        $previousText = $speechText;
        $speechText = preg_replace('/^\s*(?:(?:\([^)]*\))|(?:\*[^*]+\*))\s*/', '', $speechText);
    } while ($speechText !== $previousText);

    return $speechText;
}

function convertLeadingParentheticalBlocksToInlineNarration($text) {
    $speechText = (string)$text;
    if (!preg_match('/^\s*((?:\([^)]*\)\s*)+)/', $speechText, $matches)) {
        return $speechText;
    }

    preg_match_all('/\(([^)]*)\)/', $matches[1], $narrationMatches);
    $narrationBlocks = [];
    foreach ($narrationMatches[1] as $block) {
        $block = trim((string)$block);
        if ($block !== '') {
            $narrationBlocks[] = "*{$block}*";
        }
    }

    if (empty($narrationBlocks)) {
        return stripLeadingParentheticalBlocks($speechText);
    }

    $remainingText = preg_replace('/^\s*(?:\([^)]*\)\s*)+/', '', $speechText);
    $inlineNarration = implode(' ', $narrationBlocks);
    return trim($inlineNarration . ' ' . ltrim((string)$remainingText));
}

function sanitizePlayerRespeechText($text, $speakerName = null) {
    $removeNarration = shouldRemovePlayerAutochatAsterisks();
    $speechText = str_replace(["\r", "\n"], ' ', (string)$text);
    $speechText = $removeNarration
        ? stripLeadingPlayerRespeechNarration($speechText)
        : convertLeadingParentheticalBlocksToInlineNarration($speechText);
    if (!$removeNarration) {
        $speechText = stripOutputSpeakerPrefixAfterInlineNarration($speechText, $speakerName);
    }
    $speechText = stripOutputSpeakerPrefix($speechText, $speakerName);
    $speechText = $removeNarration
        ? stripLeadingPlayerRespeechNarration($speechText)
        : convertLeadingParentheticalBlocksToInlineNarration($speechText);
    return trim(preg_replace('/\s+/', ' ', $speechText));
}

function cleanupDisplayText($text, $speakerName = null) {
    $displayText = stripOutputSpeakerPrefix(synth_relationship_strip_commands((string)$text), $speakerName);
    $displayText = strtr($displayText, [
        "#SpeechStyle" => "",
        "#SpeechStyle:" => ""
    ]);
    $displayText = preg_replace('/^\*\*\([^)]*\)\*\*\s*/i', '', $displayText);
    $displayText = preg_replace('/"/', '', $displayText);
    $displayText = preg_replace('/\s*# ?ACTIONS.*/', '', $displayText);
    $displayText = preg_replace('/#[A-Za-z]+/', '', $displayText);
    return trim(preg_replace('/\s+/', ' ', $displayText));
}

function formatPlayerSubtitleText($text, $speakerName = null) {
    $speakerName = $speakerName ?? (\Synth\Bootstrap::config("PLAYER_NAME") ?? null);
    $subtitleText = preg_replace(
        '/\s*\((?:(?:Talking|Whispering|Shouting|Speaking privately) to [^)]+|speaking loudly to [^)]+ from far away)\)\s*$/i',
        '',
        $text
    );
    return cleanupDisplayText($subtitleText, $speakerName);
}

function formatNpcSubtitleText($text) {
    $subtitleText = shouldStripNpcOutputAsterisks() ? formatNpcSpeechText($text) : (string)$text;
    return cleanupDisplayText($subtitleText, \Synth\Bootstrap::config("SYNTH_NAME") ?? null);
}

function formatInlineNarrationDialogueSubtitleText($text) {
    $subtitleText = shouldStripNpcOutputAsterisks() ? formatNpcSpeechText($text) : (string)$text;
    $subtitleText = cleanupDisplayText($subtitleText, \Synth\Bootstrap::config("SYNTH_NAME") ?? null);
    $subtitleText = ltrim($subtitleText, ".!?,;:- \t\n\r\0\x0B");
    return trim($subtitleText);
}

function formatNarrationSubtitleText($text) {
    $narrationText = trim((string)$text, " \t\n\r\0\x0B*");
    return "*{$narrationText}*";
}

function formatTextOnlyInlineNarrationSubtitleText($text) {
    return cleanupDisplayText($text, \Synth\Bootstrap::config("SYNTH_NAME") ?? null);
}

function formatTextOnlyInlineNarrationSpeechText($text, $narrationParts = null) {
    $narrationParts = $narrationParts ?? extractNarrationAndDialogue($text);
    $speechText = $narrationParts['has_narration'] ? $narrationParts['dialogue'] : $text;
    return unmoodSentence($speechText);
}

function shouldStripAsterisksFromCleanContextBuffer() {
    $preserveAsterisksInContext = \Synth\Bootstrap::has('PRESERVE_ASTERISKS_IN_CONTEXT') ? (bool)\Synth\Bootstrap::config("PRESERVE_ASTERISKS_IN_CONTEXT") : false;
    return getInlineNarrationMode() === 'disabled' && !$preserveAsterisksInContext;
}

function unmoodSentence($sentence) {
    $forceMood = &\Synth\Bootstrap::ref('forceMood');

    $output = synth_relationship_strip_commands((string)$sentence);
    $isPlayerSpeech = synth_protocol_current_speech_role()==='player';

    if ($isPlayerSpeech) {
        $output = formatPlayerSpeechText($output);
    }

    // Determine whether to process asterisks:
    // This function prepares text for TTS/log text. Player speech uses its own toggle.
    $processAsterisks = true; // Default to stripping asterisks for TTS

    if (!$isPlayerSpeech) {
        $processAsterisks = shouldStripNpcOutputAsterisks();
        if (\Synth\Bootstrap::has('REMOVE_ASTERISKS_FROM_NPC_OUTPUT')) {
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'unmoodSentence', ("REMOVE_ASTERISKS_FROM_NPC_OUTPUT is setted to <" . \Synth\Bootstrap::config('REMOVE_ASTERISKS_FROM_NPC_OUTPUT') . ">") );
        }
    }


    if (!$isPlayerSpeech && $processAsterisks === true ) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'unmoodSentence', "NPC output asterisk filtering is active! $sentence <" . (\Synth\Bootstrap::config('strip_emotes_from_output') ?? 'N/A') . "> <" . (\Synth\Bootstrap::config('REMOVE_ASTERISKS_FROM_NPC_OUTPUT') ?? 'N/A') . ">" );

        $output = formatNpcSpeechText($output);
    }
    else if (!$isPlayerSpeech) {
        \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'unmoodSentence', "NPC output asterisk filtering is disabled; keeping asterisk content in speech");
        $output = formatNpcSpeechText($output);
    }

    // Non-asterisk-related cleanup always applies
    $output = strtr($output, [
        "#SpeechStyle" => "",
        "#SpeechStyle:" => ""
    ]);

    // Clean player name from output (for AUTOCHAT mode) - handles multiple repetitions
    if (\Synth\Bootstrap::has('PLAYER_NAME')) {
        $playerName = preg_quote(\Synth\Bootstrap::config("PLAYER_NAME"), '/');
        // Remove all leading occurrences of "PLAYERNAME:" or "PLAYERNAME: " (case-insensitive)
        $output = preg_replace('/^(?:' . $playerName . '\s*:\s*)+/i', '', $output);
    }

    // Remove AUTOCHAT mode wrapper pattern **(...)** (after player name is cleaned)
    $output = preg_replace('/^\*\*\([^)]*\)\*\*\s*/i', '', $output);

    $output = preg_replace('/\s*# ?ACTIONS.*/', '', $output);  // Remove "#ACTIONS ..."
    $output = preg_replace('/#[A-Za-z]+/', '', $output);       // Remove "#<text>"

    // Remove quotes
    $output = preg_replace('/"/', '', $output);

    // Remove parenthesized content and trim the result
    $output = preg_replace('/\((.*?)\)/i', '', $output);
    $responseTextUnmooded = trim(preg_replace('/\s+/', ' ', $output));

    // Handle whispering mood marker
    if (stripos($responseTextUnmooded, "whispering:") !== false) {
        $responseTextUnmooded = str_ireplace("whispering:", "", $responseTextUnmooded);
        $forceMood = "whispering";
    }

    return $responseTextUnmooded;
}

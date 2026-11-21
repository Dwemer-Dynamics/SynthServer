<?php


// New structure
// $PROMPTS["event"]["cue"] => array containing cues. This is the last text sent to LLM, should be an guided instruction
// $PROMPTS["event"]["player_request"] => array containing requirements. This is what is the player requesting for (a question, a comment...)
// $PROMPTS["event"]["extra"] =>  enable/disable, force mod, change token limit or define a transformer (non IA related) function.
// Full Prompt then is $PROMPT_HEAD + $SYNTH_PERS + $COMMAND_PROMPT + CONTEXT + requirement + cue

// Common patterns to use in most functions
require_once __DIR__ . '/../lib/classes/Logger.php';
$maxWordsLimit = intval(\Synth\Bootstrap::config("MAX_WORDS_LIMIT") ?? 0);
$MAXIMUM_WORDS=($maxWordsLimit>0)?"(Maximum {$maxWordsLimit} words)":"";
\Synth\Bootstrap::set('MAXIMUM_WORDS', $MAXIMUM_WORDS);
$promptCharacterName = function_exists('synthGetPromptCharacterName')
    ? synthGetPromptCharacterName()
    : (\Synth\Bootstrap::config("SYNTH_NAME") ?? 'The Narrator');
$narratorRoleplayName = function_exists('synthGetNarratorRoleplayName')
    ? synthGetNarratorRoleplayName()
    : 'The Narrator';

$directNarratorDialogue = false;
if (\Synth\Bootstrap::has('DIRECT_NARRATOR_DIALOGUE')) {
    $directNarratorDialogue = (bool)\Synth\Bootstrap::config("DIRECT_NARRATOR_DIALOGUE");
} elseif (isset(\Synth\Bootstrap::gameRequest()[0])) {
    $directNarratorDialogue = (\Synth\Bootstrap::gameRequest()[0] === 'narrator_inputtext');
} elseif (isset($gameRequest[0])) {
    $directNarratorDialogue = ($gameRequest[0] === 'narrator_inputtext');
}

if (!function_exists('synthLoadManagedPromptTemplate')) {
    function synthLoadManagedPromptTemplate($promptKey, $fallbackPrompt, array $replacements = [], $logContext = "PROMPT")
    {
        $db = \Synth\Bootstrap::db();

        $promptText = null;
        try {
            if (isset($db) && is_object($db)) {
                $promptData = \Synth\Core\DbManager\Dao\PromptDao::current($db)->texts((string)$promptKey);
                if ($promptData) {
                    $promptText = (!empty($promptData['custom_prompt'])) ? $promptData['custom_prompt'] : $promptData['default_prompt'];
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warn("[$logContext] Failed to load prompt from database, using hardcoded fallback: " . $e->getMessage());
            } else {
                \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'dialogue_prompt', "[$logContext] Failed to load prompt from database, using hardcoded fallback: " . $e->getMessage());
            }
        }

        if (!$promptText) {
            $promptText = $fallbackPrompt;
        }

        return strtr($promptText, $replacements);
    }
}

if (!function_exists('synthNormalizePromptActorName')) {
    function synthNormalizePromptActorName(string $name): string
    {
        return strtolower(trim($name));
    }
}

if (!function_exists('synthExtractDirectedListenerNamesFromText')) {
    function synthExtractDirectedListenerNamesFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (!preg_match('/\((talking|whispering|shouting|speaking privately)\s+to\s+([^)]+)\)/i', $text, $matches)) {
            return [];
        }

        $raw = trim((string)($matches[2] ?? ''));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,| and )\s*/i', $raw);
        $names = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $names[] = $part;
            }
        }

        return array_values(array_unique($names));
    }
}

if (!function_exists('synthIsStrictDirectedPlayerResponseContext')) {
    function synthIsStrictDirectedPlayerResponseContext(): bool
    {
        if (empty(\Synth\Bootstrap::config('ENFORCE_STRICT_RECHAT_RESPONSE'))) {
            return false;
        }

        if (!\Synth\Bootstrap::has('gameRequest') || !is_array(\Synth\Bootstrap::gameRequest())) {
            return false;
        }

        $eventType = strtolower(trim((string)(\Synth\Bootstrap::gameRequest()[0] ?? '')));
        if (!in_array($eventType, ['inputtext', 'inputtext_s'], true)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('synthGetRechatPreviousSpeakerName')) {
    function synthGetRechatPreviousSpeakerName(): string
    {
        if (synthIsStrictDirectedPlayerResponseContext()) {
            return trim((string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? "Player"));
        }

        $speaker = trim((string)(\Synth\Bootstrap::config("RECHAT_PREVIOUS_SPEAKER") ?? ""));
        if ($speaker === "") {
            try {
                $db = \Synth\Bootstrap::db();
                if (isset($db)) {
                    $speaker = trim((string)(\Synth\Core\DbManager\Dao\SpeechDao::current($db)->latestSpeaker() ?? ""));
                }
            } catch (\Throwable $e) {
                $speaker = "";
            }
        }
        if ($speaker === "") {
            $speaker = trim((string)(\Synth\Bootstrap::config("PLAYER_NAME") ?? ""));
        }

        return $speaker;
    }
}

if (!function_exists('synthIsStrictRechatResponseEnabled')) {
    function synthIsStrictRechatResponseEnabled(): bool
    {
        return !empty(\Synth\Bootstrap::config('ENFORCE_STRICT_RECHAT_RESPONSE'));
    }
}

if (!function_exists('synthIsStrictRechatPromptContext')) {
    function synthIsStrictRechatPromptContext(): bool
    {
        if (!synthIsStrictRechatResponseEnabled()) {
            return false;
        }

        if (!\Synth\Bootstrap::has('gameRequest') || !is_array(\Synth\Bootstrap::gameRequest())) {
            return false;
        }

        return in_array((\Synth\Bootstrap::gameRequest()[0] ?? ""), ["rechat", "continue"], true);
    }
}

if (!function_exists('synthIsStrictResponsePromptContext')) {
    function synthIsStrictResponsePromptContext(): bool
    {
        return synthIsStrictRechatPromptContext() || synthIsStrictDirectedPlayerResponseContext();
    }
}

if (!function_exists('synthLoadManagedRechatCuePrompts')) {
    function synthLoadManagedRechatCuePrompts(): array
    {
        $previousSpeaker = synthGetRechatPreviousSpeakerName();
        $replacements = [
            "{SYNTH_NAME}" => synthGetPromptCharacterName(),
            "{NARRATOR_NAME}" => synthGetNarratorRoleplayName(),
            "{TEMPLATE_DIALOG}" => \Synth\Bootstrap::config("TEMPLATE_DIALOG"),
            "{PREVIOUS_SPEAKER}" => $previousSpeaker,
        ];

        $strictFallback = "Dialogue turn for {SYNTH_NAME}. The previous speaker was {PREVIOUS_SPEAKER}. You must respond directly to {PREVIOUS_SPEAKER}.";
        $relaxedFallbacks = [
            "Dialogue turn for {SYNTH_NAME}. Respond naturally to whoever just spoke. Address the previous speaker directly. {TEMPLATE_DIALOG}",
            "Dialogue turn for {SYNTH_NAME}. Continue the conversation naturally. Address whoever you're actually responding to. {TEMPLATE_DIALOG}",
            "Dialogue turn for {SYNTH_NAME}. Focus on one actor - respond to whoever just spoke. {TEMPLATE_DIALOG}",
        ];

        if (synthIsStrictResponsePromptContext()) {
            $strictPrompts = [];
            for ($i = 1; $i <= 3; $i++) {
                $strictPrompts[] = synthLoadManagedPromptTemplate(
                    "rechat_response_prompt_strict_{$i}",
                    $strictFallback,
                    $replacements,
                    "RECHAT_RESPONSE_PROMPT_STRICT"
                );
            }
            return $strictPrompts;
        }

        $relaxedPrompts = [];
        foreach ($relaxedFallbacks as $index => $fallbackPrompt) {
            $relaxedPrompts[] = synthLoadManagedPromptTemplate(
                "rechat_response_prompt_relaxed_" . ($index + 1),
                $fallbackPrompt,
                $replacements,
                "RECHAT_RESPONSE_PROMPT_RELAXED"
            );
        }

        return $relaxedPrompts;
    }
}

if (!function_exists('synthLoadManagedRechatListenerPrompt')) {
    function synthLoadManagedRechatListenerPrompt(): string
    {
        $replacements = [
            "{SYNTH_NAME}" => synthGetPromptCharacterName(),
            "{NARRATOR_NAME}" => synthGetNarratorRoleplayName(),
            "{PREVIOUS_SPEAKER}" => synthGetRechatPreviousSpeakerName(),
        ];

        if (synthIsStrictResponsePromptContext()) {
            return synthLoadManagedPromptTemplate(
                'rechat_listener_prompt_strict',
                "specify who {SYNTH_NAME} is talking to. The listener must be exactly {PREVIOUS_SPEAKER}. Address the person who just spoke.",
                $replacements,
                "RECHAT_LISTENER_PROMPT_STRICT"
            );
        }

        return synthLoadManagedPromptTemplate(
            'rechat_listener_prompt_relaxed',
            "specify who {SYNTH_NAME} is talking to. Address whoever just spoke - can be any person in the conversation.",
            $replacements,
            "RECHAT_LISTENER_PROMPT_RELAXED"
        );
    }
}

if (!function_exists('synthLoadManagedContinueCuePrompts')) {
    function synthLoadManagedContinueCuePrompts(string $mode = 'continue'): array
    {
        if (synthIsStrictResponsePromptContext()) {
            return synthLoadManagedRechatCuePrompts();
        }

        $fallback = "Dialogue turn for {SYNTH_NAME}. Continue the ongoing discussion. Build on what was just said. {TEMPLATE_DIALOG}";

        return [
            strtr($fallback, [
                "{SYNTH_NAME}" => synthGetPromptCharacterName(),
                "{NARRATOR_NAME}" => synthGetNarratorRoleplayName(),
                "{TEMPLATE_DIALOG}" => \Synth\Bootstrap::config("TEMPLATE_DIALOG"),
            ]),
        ];
    }
}


// Add narration instruction when inline narration mode expects leading asterisk narration blocks.
$inlineNarrationMode = strtolower(trim((string)(\Synth\Bootstrap::config("INLINE_NARRATION_MODE") ?? '')));
if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
    $inlineNarrationMode = 'disabled';
}
$inlineNarrationMode = $directNarratorDialogue ? 'disabled' : $inlineNarrationMode;
$inlineNarrationEnabled = $inlineNarrationMode !== 'disabled';
if ($inlineNarrationEnabled) {
    if (in_array($inlineNarrationMode, ['npc', 'text_only'], true)) {
        $inlineDialoguePromptKey = 'dialogue_line_inline_response_npc';
        $inlineDialogueFallback = " Write {SYNTH_NAME}'s next dialogue line."
            . " If needed, you may include one brief third-person narration block in single asterisks before the dialogue."
            . " Keep any spoken dialogue outside the asterisks, and do not wrap the entire reply in asterisks."
            . " Be original, creative, knowledgeable, use your own thoughts."
            . " Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}";
        $inlineNarrationPromptKey = 'inline_narration_prompt_npc';
        $inlineNarrationFallback = "You may include one brief third-person narration block in single asterisks before the dialogue (e.g., *She smiles softly*). Keep any spoken dialogue outside the asterisks. Do not wrap the entire reply in asterisks.";
    } else {
        $inlineDialoguePromptKey = 'dialogue_line_inline_response_narrator';
        $inlineDialogueFallback = " Write {SYNTH_NAME}'s next prose/narration."
            . " Be original, creative, knowledgeable, use your own thoughts. "
            . " Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}";
        $inlineNarrationPromptKey = 'inline_narration_prompt_narrator';
        $inlineNarrationFallback = "You may include one brief third-person narration block in single asterisks before the dialogue (e.g., *She smiles*). Do not wrap the entire reply in asterisks; keep any spoken dialogue outside the asterisks.";
    }

    $TEMPLATE_DIALOG = synthLoadManagedPromptTemplate(
        $inlineDialoguePromptKey,
        $inlineDialogueFallback,
        [
            "{SYNTH_NAME}" => $promptCharacterName,
            "{NARRATOR_NAME}" => $narratorRoleplayName,
            "{MAXIMUM_WORDS}" => $MAXIMUM_WORDS,
        ],
        "DIALOGUE_LINE_INLINE_RESPONSE"
    );

    $inlineNarrationPrompt = synthLoadManagedPromptTemplate(
        $inlineNarrationPromptKey,
        $inlineNarrationFallback,
        [],
        "INLINE_NARRATION"
    );
    $TEMPLATE_DIALOG .= " " . $inlineNarrationPrompt;
} else {
    $TEMPLATE_DIALOG = synthLoadManagedPromptTemplate(
        'dialogue_line_response',
        " Write {SYNTH_NAME}'s next dialogue line." .
        " Be original, creative, knowledgeable, use your own thoughts. " .
        " Review context history to focus on conversation topic and to avoid repeating sentences and phraseology from previous lines.{MAXIMUM_WORDS}",
        [
            "{SYNTH_NAME}" => $promptCharacterName,
            "{NARRATOR_NAME}" => $narratorRoleplayName,
            "{MAXIMUM_WORDS}" => $MAXIMUM_WORDS,
        ],
        "DIALOGUE_LINE_RESPONSE"
    );
}

if ($directNarratorDialogue) {
    $TEMPLATE_DIALOG .= (" Reply directly to " . \Synth\Bootstrap::config('PLAYER_NAME') . " in spoken dialogue.") .
        " If an narrator action matches the request, use it and keep the spoken line consistent with that action." .
        " Do not include third-person narration, scene description, stage directions, or text in asterisks.";
}

//$TEMPLATE_DIALOG.=" \"";



if (isset(\Synth\Bootstrap::config('FEATURES')["MEMORY_EMBEDDING"]["ENABLED"]) && \Synth\Bootstrap::config("FEATURES")["MEMORY_EMBEDDING"]["ENABLED"]) {
    \Synth\Bootstrap::set('MEMORY_STATEMENT', ".USE #MEMORY.");
} else
    \Synth\Bootstrap::set('MEMORY_STATEMENT', "");


if (!empty(\Synth\Bootstrap::config('FUNCTIONS_ARE_ENABLED'))) {
    $TEMPLATE_ACTION="call a function to control {$promptCharacterName} or";
    $TEMPLATE_ACTION=("(Check #ACTIONS section. If " . \Synth\Bootstrap::config('PLAYER_NAME') . "'s input asks, orders, permits, or clearly implies a concrete in-game action, choose the matching action instead of Talk. Questions that current observations already answer use Talk for an in-character description; known visible gear does not require Inspect or CheckInventory. Otherwise use Talk when no listed action fits.)");
} else {
    $TEMPLATE_ACTION="";
}

// Database Prompt (Dialogue should all be one)
/* Model-specific overrides removed - prose/narration now handled uniformly */
?>

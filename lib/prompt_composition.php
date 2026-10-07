<?php

// Live input is newer than the immutable history frontier. Keep it outside history
// compaction and never deduplicate by text: consecutive identical utterances are valid.
function synthCurrentPlayerInputMessages(array $event, array $gameRequest): array
{
    if (($event['protocol_version'] ?? 1) !== 2
        || !in_array($event['type'] ?? '', ['input_text', 'input_audio'], true)
        || !in_array($gameRequest[0] ?? '', ['inputtext', 'inputtext_s', 'narrator_inputtext'], true)) {
        return [];
    }
    $text = $gameRequest[3] ?? '';
    return is_string($text) && trim($text) !== ''
        ? [['role' => 'user', 'content' => $text]] : [];
}

// Convert prompt section tags after XML-based context filtering has finished.
function synthFormatPromptHeadSection(string $systemPrompt, bool $markdownEnabled): string
{
    if (!$markdownEnabled) {
        return $systemPrompt;
    }

    $systemPrompt = str_replace(["\r\n", "\r"], "\n", $systemPrompt);
    $formatTagName = static function (string $tag): string {
        return $tag === 'available_actions_list'
            ? 'Available Actions'
            : ucwords(str_replace(['_', '-'], ' ', strtolower($tag)));
    };
    $systemPrompt = preg_replace_callback(
        '/^[ \t]*<([A-Za-z][A-Za-z0-9_-]*)((?:[ \t]+[A-Za-z][A-Za-z0-9_-]*="[^"]*")+)>[ \t]*$/m',
        static function (array $matches) use ($formatTagName): string {
            preg_match_all('/([A-Za-z][A-Za-z0-9_-]*)="([^"]*)"/', $matches[2], $attributes, PREG_SET_ORDER);
            $lines = ['<' . $matches[1] . '>'];
            foreach ($attributes as $attribute) {
                $lines[] = '- ' . $formatTagName($attribute[1]) . ': ' . $attribute[2];
            }
            return implode("\n", $lines);
        },
        $systemPrompt
    );
    $systemPrompt = preg_replace_callback(
        '/^[ \t]*<([A-Za-z][A-Za-z0-9_-]*)>([^<\n]*)<\/\1>[ \t]*$/mi',
        static function (array $matches) use ($formatTagName): string {
            $tag = strtolower($matches[1]);
            if (in_array($tag, ['location', 'worldspace', 'weather', 'date', 'time'], true)) {
                return '- ' . $formatTagName($tag) . ': ' . trim($matches[2]);
            }
            return '<' . $tag . ">\n" . $matches[2] . "\n</" . $tag . '>';
        },
        $systemPrompt
    );
    $parts = preg_split(
        '/(^[ \t]*<[A-Za-z][A-Za-z0-9_-]*>[ \t]*(?=\n|$)|<\/[A-Za-z][A-Za-z0-9_-]*>)/m',
        (string)$systemPrompt,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    $sections = [];
    $formattedPrompt = '';
    $legacyTitles = [
        'equipment' => 'Current Equipment',
        'spells' => 'Known Spells',
        'nearby_actors' => 'NEARBY ACTORS/NPC IN THE SCENE',
        'points_of_interest' => 'POIs - Points of Interest nearby',
    ];
    $listSections = ['people_present', 'nearby_actors', 'nearby_items', 'points_of_interest', 'scene_notes'];

    foreach ($parts as $part) {
        if (preg_match('/^\s*<([A-Za-z][A-Za-z0-9_-]*)>\s*$/', $part, $tag)) {
            $sections[] = strtolower($tag[1]);
            $formattedPrompt .= "\n\n" . str_repeat('#', min(6, count($sections)))
                . ' ' . $formatTagName(end($sections)) . "\n\n";
            continue;
        }
        if (preg_match('/^<\/([A-Za-z][A-Za-z0-9_-]*)>$/', $part, $tag)) {
            $index = array_search(strtolower($tag[1]), array_reverse($sections, true), true);
            if ($index !== false) {
                $sections = array_slice($sections, 0, $index);
            }
            $formattedPrompt .= "\n\n";
            continue;
        }

        $section = end($sections);
        $part = preg_replace_callback(
            '/^[ \t]*(#{1,6})[ \t]*([^\n]+)$/m',
            static function (array $matches) use ($sections, $section, $formatTagName, $legacyTitles, $listSections): string {
                $title = trim($matches[2]);
                if ($section !== false) {
                    if (strcasecmp($title, $formatTagName($section)) === 0
                        || strcasecmp($title, $legacyTitles[$section] ?? '') === 0) {
                        return '';
                    }
                    if (in_array($section, $listSections, true)) {
                        if (strlen($matches[1]) >= 2) {
                            return '- ' . $title;
                        }
                        if ($section === 'nearby_items' && strcasecmp($title, 'ITEM DESCRIPTIONS') === 0) {
                            return "\n\n" . str_repeat('#', min(6, count($sections) + 1)) . " Item Descriptions\n\n";
                        }
                        return preg_replace('/^NEARBY ITEMS[ \t]+(?=\()/i', '', $title);
                    }
                }
                $level = $section === false ? strlen($matches[1]) : min(6, count($sections) + 1);
                return "\n\n" . str_repeat('#', $level) . ' ' . $title . "\n\n";
            },
            $part
        );
        if ($section === 'available_actions_list') {
            $part = preg_replace('/^([ \t]*)(AVAILABLE ACTION:)/m', '$1- $2', $part);
        }
        $formattedPrompt .= preg_replace_callback(
            '/<([A-Za-z][A-Za-z0-9_-]*)>/',
            static fn(array $matches): string => '`' . $formatTagName(strtolower($matches[1])) . '`',
            $part
        );
    }
    $formattedPrompt = preg_replace('/^([ \t]*)(?:•|\*|\+)[ \t]+/m', '$1- ', (string)$formattedPrompt);
    $formattedPrompt = trim((string)preg_replace('/\n{3,}/', "\n\n", $formattedPrompt));

    return $formattedPrompt === '' ? '' : $formattedPrompt . "\n";
}

function synthPromptCompositionCharacterCount($value): int
{
    if (is_string($value) || is_numeric($value)) {
        $text = strval($value);
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    if (!is_array($value)) {
        return 0;
    }

    $characters = 0;
    foreach ($value as $item) {
        if (is_array($item) && array_key_exists('content', $item)) {
            $characters += synthPromptCompositionCharacterCount($item['content']);
            continue;
        }

        $characters += synthPromptCompositionCharacterCount($item);
    }

    return $characters;
}

function synthPromptCompositionMeasure($value): array
{
    $characters = synthPromptCompositionCharacterCount($value);

    return [
        'characters' => $characters,
        'estimated_tokens' => $characters > 0 ? intval(ceil($characters / 4)) : 0,
    ];
}

function synthBuildPromptCompositionReport(string $requestType, array $sections, array $messages): array
{
    $measuredSections = [];
    foreach ($sections as $name => $value) {
        $measuredSections[strval($name)] = synthPromptCompositionMeasure($value);
    }

    $messageMeasurement = synthPromptCompositionMeasure($messages);

    return [
        'request_type' => $requestType,
        'message_count' => count($messages),
        'total_characters' => $messageMeasurement['characters'],
        'estimated_total_tokens' => $messageMeasurement['estimated_tokens'],
        'sections' => $measuredSections,
    ];
}

// Emit a compact section-level report without logging prompt contents.
function synthLogPromptComposition(string $requestType, array $sections, array $messages): array
{
    $report = synthBuildPromptCompositionReport($requestType, $sections, $messages);
    $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) {
        Logger::debug('[PROMPT-COMPOSITION] ' . $json);
    }

    return $report;
}

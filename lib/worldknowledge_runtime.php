<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'worldknowledge_retrieval.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'worldknowledge_topic.php';

/** Report which already-applied configuration layer supplied a World Knowledge setting. */
function synthWorldKnowledgeSettingSource(string $name): string
{
    $npc = is_array($GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] ?? null)
        ? $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']
        : [];
    foreach (['extended_data', 'metadata'] as $field) {
        $values = $npc[$field] ?? [];
        if (is_string($values)) {
            $values = json_decode($values, true);
        }
        if (is_array($values) && array_key_exists($name, $values)) {
            return 'npc';
        }
    }
    $profile = is_array($GLOBALS['SYNTH_CORE_CURRENT_PROFILE_DATA'] ?? null)
        ? $GLOBALS['SYNTH_CORE_CURRENT_PROFILE_DATA']
        : [];
    $metadata = $profile['metadata'] ?? [];
    if (is_string($metadata)) {
        $metadata = json_decode($metadata, true);
    }
    return is_array($metadata) && array_key_exists($name, $metadata) ? 'core_profile' : 'global';
}

/** Resolve Global -> Core Profile -> NPC values into the Oghma parity settings contract. */
function synthWorldKnowledgeEffectiveSettings(): array
{
    $bool = static function (mixed $value, bool $default = false): bool {
        if ($value === null || $value === '') {
            return $default;
        }
        return !in_array(strtolower(trim(strval($value))), ['0', 'false', 'no', 'off'], true);
    };
    $topicCount = max(1, min(3, intval($GLOBALS['WORLDKNOWLEDGE_AMOUNT'] ?? 1)));
    $resultLimit = max(1, min(5, intval($GLOBALS['WORLDKNOWLEDGE_RESULT_LIMIT'] ?? 3)));
    $fallbackKey = array_key_exists('WORLDKNOWLEDGE_EXTRACTOR_FALLBACK', $GLOBALS)
        ? 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK'
        : 'WORLDKNOWLEDGE_CUSTOM';
    $values = [
        'enabled' => $bool($GLOBALS['WORLDKNOWLEDGE_INFINIUM'] ?? true, true),
        'extractor_fallback_enabled' => $bool($GLOBALS[$fallbackKey] ?? false),
        'topic_count' => $topicCount,
        'result_limit' => $resultLimit,
        'racial_context_enabled' => $bool($GLOBALS['RACE_WORLDKNOWLEDGE'] ?? true, true),
        'faction_context_enabled' => $bool($GLOBALS['FACTION_WORLDKNOWLEDGE'] ?? true, true),
        'location_context_enabled' => $bool($GLOBALS['LOCATION_WORLDKNOWLEDGE'] ?? true, true),
        'extractor_timeout_ms' => max(250, min(3000, intval($GLOBALS['WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS'] ?? 1500))),
        'connector_id' => trim(strval($GLOBALS['CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM'] ?? '')),
    ];
    $sourceKeys = [
        'enabled' => 'WORLDKNOWLEDGE_INFINIUM',
        'extractor_fallback_enabled' => $fallbackKey,
        'topic_count' => 'WORLDKNOWLEDGE_AMOUNT',
        'result_limit' => 'WORLDKNOWLEDGE_RESULT_LIMIT',
        'racial_context_enabled' => 'RACE_WORLDKNOWLEDGE',
        'faction_context_enabled' => 'FACTION_WORLDKNOWLEDGE',
        'location_context_enabled' => 'LOCATION_WORLDKNOWLEDGE',
        'extractor_timeout_ms' => 'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS',
        'connector_id' => 'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM',
    ];
    $sources = [];
    foreach ($sourceKeys as $field => $key) {
        $sources[$field] = synthWorldKnowledgeSettingSource($key);
    }
    $canonical = $values;
    ksort($canonical, SORT_STRING);
    return [
        'contract' => SynthWorldKnowledgeRetriever::VERSION,
        'values' => $values,
        'sources' => $sources,
        'sha256' => hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
}

function synthWorldKnowledgeFetchEffectiveCatalog(object $db): array
{
    if (!method_exists($db, 'fetchAll')) {
        return [];
    }
    try {
        $rows = $db->fetchAll(
            "SELECT entry_id, topic, aliases, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                    knowledge_class_basic, tags, category, source_kind, catalog_id, catalog_version, is_active
               FROM synth.worldknowledge_effective
              ORDER BY canonical_topic"
        );
    } catch (Throwable) {
        $rows = $db->fetchAll(
            "SELECT topic, ''::text AS aliases, lower(btrim(split_part(topic, ',', 1))) AS canonical_topic,
                    topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic,
                    tags, category, 'custom'::text AS source_kind, NULL::text AS catalog_id,
                    NULL::text AS catalog_version, TRUE AS is_active
               FROM synth.worldknowledge
              ORDER BY lower(btrim(split_part(topic, ',', 1)))"
        );
    }
    return is_array($rows) ? $rows : [];
}

function synthWorldKnowledgeKnowledgeTags(): array
{
    $tags = [];
    foreach (explode(',', strval($GLOBALS['WORLDKNOWLEDGE'] ?? '')) as $value) {
        $tags[] = $value;
    }

    $npcName = synthWorldKnowledgeNormalizeAccessTag(strval($GLOBALS['SYNTH_NAME'] ?? ''));
    if ($npcName !== '') {
        $tags[] = $npcName;
    }

    $db = $GLOBALS['db'] ?? null;
    $currentNpc = $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA'] ?? [];
    $npcCodename = is_array($currentNpc)
        ? trim(strval($currentNpc['npc_codename'] ?? ''))
        : '';
    // Read factory template tags into the request context so existing profiles
    // gain controlled defaults without changing their user-authored database row.
    if ($npcCodename !== '' && $db && method_exists($db, 'fetchOne') && method_exists($db, 'escape')) {
        try {
            $template = $db->fetchOne(
                "SELECT worldknowledge_tags FROM synth.combined_bio_templates"
                . " WHERE lower(npc_name)=lower('" . $db->escape($npcCodename) . "') LIMIT 1"
            );
            foreach (explode(',', strval($template['worldknowledge_tags'] ?? '')) as $value) {
                $tags[] = $value;
            }
        } catch (Throwable) {
            // Custom installs may not have loaded the optional factory templates yet.
        }
    }
    if (function_exists('synthWorldKnowledgeCurrentNpcSignals')) {
        $signals = synthWorldKnowledgeCurrentNpcSignals($db);
        foreach ((array)($signals['race'] ?? []) as $value) {
            $tag = synthWorldKnowledgeNormalizeAccessTag($value);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        foreach ((array)($signals['faction'] ?? []) as $value) {
            $tag = synthWorldKnowledgeNormalizeAccessTag($value);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
    }

    if (function_exists('synthWorldKnowledgeCollectLocationSignalGroups')) {
        $signals = synthWorldKnowledgeCollectLocationSignalGroups($db);
        foreach ((array)($signals['location'] ?? []) as $value) {
            $tag = synthWorldKnowledgeNormalizeAccessTag($value);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        foreach ((array)($signals['worldspace'] ?? []) as $value) {
            foreach (synthWorldKnowledgeNormalizeRegionTags($value) as $tag) {
                $tags[] = $tag;
            }
        }
    }

    $normalizedTags = array_values(array_unique(array_filter(array_map(
        'synthWorldKnowledgeNormalizeAccessTag',
        $tags
    ))));
    return array_values(array_filter(
        $normalizedTags,
        static fn(string $tag): bool => !in_array($tag, ['blocked', 'common'], true)
    ));
}

/** Map runtime worldspace variants into Fallout 4 knowledge regions. */
function synthWorldKnowledgeNormalizeRegionTag($value): string
{
    $tags = synthWorldKnowledgeNormalizeRegionTags($value);
    foreach (['commonwealth', 'far_harbor', 'nuka_world'] as $parent) {
        if (in_array($parent, $tags, true)) {
            return $parent;
        }
    }
    return '';
}

/** Map Fallout 4 DLC worldspaces to local scope plus the shared Fallout 4 setting. */
function synthWorldKnowledgeNormalizeRegionTags($value): array
{
    $value = synthWorldKnowledgeNormalizeAccessTag($value);
    if ($value === '') {
        return [];
    }
    $dlcRegions = [
        'far_harbor' => ['far_harbor', 'commonwealth'],
        'the_island' => ['far_harbor', 'commonwealth'],
        'mount_desert_island' => ['far_harbor', 'commonwealth'],
        'nuka_world' => ['nuka_world', 'commonwealth'],
    ];
    foreach ($dlcRegions as $needle => [$local, $parent]) {
        if (str_contains($value, $needle)) {
            return [$local, $parent];
        }
    }
    if (str_contains($value, 'commonwealth') || str_contains($value, 'boston')) {
        return ['commonwealth'];
    }
    return [];
}

/** Resolve one article to advanced, basic, or denied without exposing protected text. */
function synthWorldKnowledgeAccessDecision(array $row, array $knowledgeTags): array
{
    $normalizedTags = array_values(array_unique(array_filter(array_map(
        'synthWorldKnowledgeNormalizeAccessTag',
        $knowledgeTags
    ))));
    $topic = synthWorldKnowledgeCanonicalTopic(strval($row['topic'] ?? $row['canonical_topic'] ?? ''));
    if (in_array('knowall', $normalizedTags, true) && trim(strval($row['topic_desc'] ?? '')) !== '') {
        return [
            'topic' => $topic,
            'level' => 'advanced',
            'reason' => 'knowall',
            'matched' => ['knowall'],
            'description' => trim(strval($row['topic_desc'])),
        ];
    }
    $advanced = synthWorldKnowledgeClassDecision(strval($row['knowledge_class'] ?? ''), $normalizedTags);
    if ($advanced['allowed'] && trim(strval($row['topic_desc'] ?? '')) !== '') {
        return [
            'topic' => $topic,
            'level' => 'advanced',
            'reason' => $advanced['reason'],
            'matched' => $advanced['matched'],
            'description' => trim(strval($row['topic_desc'])),
        ];
    }

    $basic = synthWorldKnowledgeClassDecision(strval($row['knowledge_class_basic'] ?? ''), $normalizedTags, true);
    if ($basic['allowed'] && trim(strval($row['topic_desc_basic'] ?? '')) !== '') {
        return [
            'topic' => $topic,
            'level' => 'basic',
            'reason' => $basic['reason'],
            'matched' => $basic['matched'],
            'description' => trim(strval($row['topic_desc_basic'])),
        ];
    }

    return [
        'topic' => $topic,
        'level' => 'denied',
        'reason' => $advanced['reason'] === 'negative_class' || $basic['reason'] === 'negative_class'
            ? 'negative_class'
            : 'knowledge_classes_not_authorized',
        'matched' => array_values(array_unique(array_merge($advanced['matched'], $basic['matched']))),
        'description' => '',
    ];
}

function synthWorldKnowledgeClassDecision(string $classes, array $knowledgeTags, bool $allowCommon = false): array
{
    $rule = synthWorldKnowledgeParseAccessRule($classes);
    $knowledgeTags = array_values(array_unique(array_filter(array_map(
        'synthWorldKnowledgeNormalizeAccessTag',
        $knowledgeTags
    ))));
    if ($rule['unrestricted']) {
        return ['allowed' => true, 'reason' => 'unrestricted', 'matched' => []];
    }
    $negativeMatches = array_values(array_intersect($rule['denied'], $knowledgeTags));
    if ($negativeMatches !== []) {
        return ['allowed' => false, 'reason' => 'negative_class', 'matched' => $negativeMatches];
    }
    if ($allowCommon && in_array('common', $rule['allowed'], true)) {
        return ['allowed' => true, 'reason' => 'common', 'matched' => ['common']];
    }
    $positiveMatches = array_values(array_intersect($rule['allowed'], $knowledgeTags));
    return [
        'allowed' => $positiveMatches !== [],
        'reason' => $positiveMatches !== [] ? 'positive_class' : 'missing_class',
        'matched' => $positiveMatches,
    ];
}

function synthWorldKnowledgeRenderArticleXml(array $row, array $decision, string $source): string
{
    $topic = htmlspecialchars(strval($decision['topic'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $articleSource = htmlspecialchars($source, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $access = htmlspecialchars(strval($decision['level'] ?? 'denied'), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $lines = ['  <article topic="' . $topic . '" source="' . $articleSource . '" access="' . $access . '">'];
    if (($decision['level'] ?? 'denied') === 'denied') {
        $reason = htmlspecialchars(
            strval($decision['reason'] ?? 'knowledge_classes_not_authorized'),
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );
        $lines[] = '    <denial reason="' . $reason . '" />';
    } else {
        $content = htmlspecialchars(strval($decision['description'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $lines[] = '    <content>' . $content . '</content>';
    }
    $lines[] = '  </article>';
    return implode("\n", $lines);
}

function synthWorldKnowledgeRecordAudit(object $db, array $trace): void
{
    if (!method_exists($db, 'execQuery') || !method_exists($db, 'escapeLiteral')) {
        return;
    }
    try {
        $json = static fn(mixed $value): string => $db->escapeLiteral(json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )) . '::jsonb';
        $sql = 'INSERT INTO synth.worldknowledge_audit ('
            . 'algorithm_version,status,request_type,npc_name,input_text,normalized_input,catalog_id,catalog_version,'
            . 'grounded_matches,rejected_candidates,tag_decisions,context_tags,fallback,forced_signals,access_decisions,selected_articles,'
            . 'settings,catalog_checksum,prompt_hash,retrieval_elapsed_ms,elapsed_ms'
            . ') VALUES (' . implode(',', [
                $db->escapeLiteral(strval($trace['algorithm_version'] ?? SynthWorldKnowledgeRetriever::VERSION)),
                $db->escapeLiteral(strval($trace['status'] ?? 'no_match')),
                $db->escapeLiteral(strval($trace['request_type'] ?? '')),
                $db->escapeLiteral(strval($trace['npc_name'] ?? '')),
                $db->escapeLiteral(strval($trace['input_text'] ?? '')),
                $db->escapeLiteral(strval($trace['normalized_input'] ?? '')),
                ($trace['catalog_id'] ?? '') === '' ? 'NULL' : $db->escapeLiteral(strval($trace['catalog_id'])),
                ($trace['catalog_version'] ?? '') === '' ? 'NULL' : $db->escapeLiteral(strval($trace['catalog_version'])),
                $json($trace['grounded_matches'] ?? []),
                $json($trace['rejected_candidates'] ?? []),
                $json($trace['tag_decisions'] ?? []),
                $json($trace['context_tags'] ?? []),
                $json($trace['fallback'] ?? []),
                $json($trace['forced_signals'] ?? []),
                $json($trace['access_decisions'] ?? []),
                $json($trace['selected_articles'] ?? []),
                $json($trace['settings'] ?? []),
                ($trace['catalog_checksum'] ?? '') === '' ? 'NULL' : $db->escapeLiteral(strval($trace['catalog_checksum'])),
                ($trace['prompt_hash'] ?? '') === '' ? 'NULL' : $db->escapeLiteral(strval($trace['prompt_hash'])),
                strval(round(floatval($trace['retrieval_elapsed_ms'] ?? 0), 3)),
                strval(round(floatval($trace['elapsed_ms'] ?? 0), 3)),
            ]) . ')';
        $db->execQuery($sql);
    } catch (Throwable $exception) {
        if (class_exists('Logger')) {
            Logger::warn('[WORLDKNOWLEDGE] Unable to record structured audit: ' . $exception->getMessage());
        }
    }
}

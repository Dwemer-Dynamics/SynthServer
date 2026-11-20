<?php

if (!function_exists('synthWorldKnowledgeTopicParts')) {
    function synthWorldKnowledgeTopicParts($value): array
    {
        $parts = array_map('trim', explode(',', (string)$value));
        return array_values(array_filter($parts, static fn($part) => $part !== ''));
    }
}

if (!function_exists('synthWorldKnowledgeCanonicalTopic')) {
    function synthWorldKnowledgeCanonicalTopic($value): string
    {
        $parts = synthWorldKnowledgeTopicParts($value);
        return $parts[0] ?? '';
    }
}

if (!function_exists('synthWorldKnowledgeComparableTopic')) {
    function synthWorldKnowledgeComparableTopic($value): string
    {
        $value = strtolower(str_replace('_', ' ', trim((string)$value)));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}

if (!function_exists('synthWorldKnowledgeNormalizeTopicList')) {
    function synthWorldKnowledgeNormalizeTopicList($value): string
    {
        $parts = synthWorldKnowledgeTopicParts($value);
        if (empty($parts)) {
            return '';
        }

        $canonical = strtolower($parts[0]);
        $canonical = preg_replace('/[^a-z0-9]+/', '_', $canonical);
        $canonical = trim($canonical, '_');
        if ($canonical === '') {
            return '';
        }

        $result = [$canonical];
        $seen = [synthWorldKnowledgeComparableTopic($canonical) => true];
        foreach (array_slice($parts, 1) as $alias) {
            $alias = trim(preg_replace('/\s+/', ' ', $alias));
            $comparable = synthWorldKnowledgeComparableTopic($alias);
            if ($alias === '' || $comparable === '' || isset($seen[$comparable])) {
                continue;
            }
            $seen[$comparable] = true;
            $result[] = $alias;
        }

        return implode(',', $result);
    }
}

if (!function_exists('synthWorldKnowledgeNormalizeCanonicalTopic')) {
    /** Normalize the stable Herika-compatible topic identifier. */
    function synthWorldKnowledgeNormalizeCanonicalTopic($value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string)$value, '_');
    }
}

if (!function_exists('synthWorldKnowledgeSplitAliases')) {
    function synthWorldKnowledgeSplitAliases($value): array
    {
        $parts = preg_split('/\s*[,;|]\s*/u', (string)$value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
    }
}

if (!function_exists('synthWorldKnowledgeNormalizeAliases')) {
    /** Store aliases as one deduplicated comma-separated list, separate from topic. */
    function synthWorldKnowledgeNormalizeAliases($value, string $topic = ''): string
    {
        $topicKey = synthWorldKnowledgeComparableTopic($topic);
        $aliases = [];
        $seen = [];
        foreach (synthWorldKnowledgeSplitAliases($value) as $alias) {
            $alias = trim(preg_replace('/\s+/u', ' ', $alias) ?? $alias);
            $key = synthWorldKnowledgeComparableTopic($alias);
            if ($key === '' || $key === $topicKey || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $aliases[] = $alias;
        }
        return implode(',', $aliases);
    }
}

if (!function_exists('synthWorldKnowledgeFilterAliases')) {
    /** Enforce one unambiguous owner for every canonical topic and alias. */
    function synthWorldKnowledgeFilterAliases(string $topic, $aliases, array $rows): array
    {
        $topicKey = synthWorldKnowledgeComparableTopic($topic);
        $canonicalOwners = [];
        $aliasOwners = [];
        foreach ($rows as $row) {
            $owner = trim((string)($row['topic'] ?? ''));
            $ownerKey = synthWorldKnowledgeComparableTopic($owner);
            if ($ownerKey !== '') {
                $canonicalOwners[$ownerKey] = $owner;
            }
            foreach (synthWorldKnowledgeSplitAliases($row['aliases'] ?? '') as $existingAlias) {
                $key = synthWorldKnowledgeComparableTopic($existingAlias);
                if ($key !== '') {
                    $aliasOwners[$key][$owner] = true;
                }
            }
        }

        $accepted = [];
        $rejected = [];
        $seen = [];
        foreach (synthWorldKnowledgeSplitAliases($aliases) as $alias) {
            $key = synthWorldKnowledgeComparableTopic($alias);
            $reason = '';
            if ($key === '' || $key === $topicKey || isset($seen[$key])) {
                $reason = 'duplicate or canonical variant';
            } elseif (isset($canonicalOwners[$key])
                && synthWorldKnowledgeComparableTopic($canonicalOwners[$key]) !== $topicKey) {
                $reason = 'matches canonical topic ' . $canonicalOwners[$key];
            } else {
                $otherOwners = array_filter(
                    array_keys($aliasOwners[$key] ?? []),
                    static fn(string $owner): bool => synthWorldKnowledgeComparableTopic($owner) !== $topicKey
                );
                if ($otherOwners !== []) {
                    $reason = 'already used by ' . implode(', ', $otherOwners);
                }
            }
            if ($reason !== '') {
                $rejected[] = ['alias' => $alias, 'reason' => $reason];
                continue;
            }
            $seen[$key] = true;
            $accepted[] = trim(preg_replace('/\s+/u', ' ', $alias) ?? $alias);
        }
        return ['aliases' => implode(',', $accepted), 'rejected' => $rejected];
    }
}

if (!function_exists('synthWorldKnowledgeNormalizeAccessTag')) {
    /** Normalize namespaced and plain Fallout 4 tags to one canonical vocabulary. */
    function synthWorldKnowledgeNormalizeAccessTag($value): string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }
        $negative = str_starts_with($value, '!');
        if ($negative) {
            $value = substr($value, 1);
        }
        $normalize = static function ($part): string {
            $part = preg_replace('/[^a-z0-9]+/', '_', trim((string)$part));
            return trim((string)$part, '_');
        };
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            $namespace = $normalize($parts[0]);
            $label = $normalize($parts[1]);
            if ($namespace === '' || $label === '') {
                return '';
            }
            $namespacedAliases = [
                'person:sole_survivor' => 'sole_survivor',
                'person:nick_valentine' => 'nick_valentine',
                'role:caravaner' => 'traveler',
                'role:military' => 'soldier',
                'faction:military' => 'soldier',
                'faction:brotherhood' => 'brotherhood_of_steel',
                'faction:brotherhood_of_steelf' => 'brotherhood_of_steel',
                'faction:raiders' => 'raider',
                'race:supermutant' => 'super_mutant',
                'region:far_harbor' => 'far_harbor',
                'place:the_island' => 'far_harbor',
                'region:nuka_world' => 'nuka_world',
                'community:diamond_city' => 'diamond_city',
            ];
            $namespacedKey = $namespace . ':' . $label;
            $knownNamespaces = ['person', 'region', 'community', 'place', 'faction', 'role', 'domain', 'race'];
            $result = $namespacedAliases[$namespacedKey]
                ?? (in_array($namespace, $knownNamespaces, true) ? $label : $normalize($namespacedKey));
        } else {
            $result = $normalize($value);
            $plainAliases = [
                'solesurvivor' => 'sole_survivor',
                'caravaner' => 'traveler',
                'brotherhood' => 'brotherhood_of_steel',
                'brotherhood_of_steelf' => 'brotherhood_of_steel',
                'raiders' => 'raider',
                'supermutant' => 'super_mutant',
                'the_island' => 'far_harbor',
                'nukaworld' => 'nuka_world',
            ];
            $result = $plainAliases[$result] ?? $result;
        }
        if ($result === '' || !preg_match('/^[a-z0-9][a-z0-9_]{0,100}$/', $result)) {
            return '';
        }
        return $negative ? '!' . $result : $result;
    }
}

if (!function_exists('synthWorldKnowledgeParseAccessRule')) {
    /** Parse the flat Oghma any-of classes while collecting negatives separately. */
    function synthWorldKnowledgeParseAccessRule($value): array
    {
        $value = trim((string)$value);
        $allowed = [];
        $denied = [];
        foreach (preg_split('/\s*[,;|]\s*/u', $value) ?: [] as $rawClass) {
            $tag = synthWorldKnowledgeNormalizeAccessTag($rawClass);
            if ($tag === '') {
                continue;
            }
            if (str_starts_with($tag, '!')) {
                $denied[] = substr($tag, 1);
            } else {
                $allowed[] = $tag;
            }
        }
        return [
            'allowed' => array_values(array_unique($allowed)),
            'denied' => array_values(array_unique($denied)),
            'unrestricted' => $value === '',
        ];
    }
}

if (!function_exists('synthWorldKnowledgeNormalizeAccessRule')) {
    /** Canonicalize a stored Oghma class list to comma-separated plain IDs. */
    function synthWorldKnowledgeNormalizeAccessRule($value): string
    {
        $rule = synthWorldKnowledgeParseAccessRule($value);
        $classes = $rule['allowed'];
        foreach ($rule['denied'] as $denied) {
            $classes[] = '!' . $denied;
        }
        return implode(',', array_values(array_unique($classes)));
    }
}

if (!function_exists('synthWorldKnowledgeAccessTierConflicts')) {
    /** Report duplicate or contradictory classes shared by advanced and basic tiers. */
    function synthWorldKnowledgeAccessTierConflicts($advancedValue, $basicValue): array
    {
        $signedClasses = static function ($value): array {
            $rule = synthWorldKnowledgeParseAccessRule($value);
            return array_merge($rule['allowed'], array_map(
                static fn(string $denied): string => '!' . $denied,
                $rule['denied']
            ));
        };
        $advanced = $signedClasses($advancedValue);
        $basic = $signedClasses($basicValue);
        $duplicates = array_values(array_unique(array_intersect($advanced, $basic)));
        $advancedByBase = [];
        foreach ($advanced as $class) {
            $advancedByBase[ltrim($class, '!')] = $class;
        }
        $basicByBase = [];
        foreach ($basic as $class) {
            $basicByBase[ltrim($class, '!')] = $class;
        }
        $contradictions = [];
        foreach (array_intersect(array_keys($advancedByBase), array_keys($basicByBase)) as $base) {
            if ($advancedByBase[$base] !== $basicByBase[$base]) {
                $contradictions[] = $base;
            }
        }
        sort($duplicates, SORT_STRING);
        sort($contradictions, SORT_STRING);
        return ['duplicates' => $duplicates, 'contradictions' => $contradictions];
    }
}

?>

<?php

function synthMemoryCandidateNumber(array $candidate, string $field, float $default): float
{
    $value = $candidate[$field] ?? null;

    return is_numeric($value) ? (float)$value : $default;
}

function synthMemoryCandidateMixedDistance(array $candidate): float
{
    if (isset($candidate['mixed_distance']) && is_numeric($candidate['mixed_distance'])) {
        return (float)$candidate['mixed_distance'];
    }

    $distance = synthMemoryCandidateNumber($candidate, 'distance', INF);
    $keywordRank = synthMemoryCandidateNumber($candidate, 'rank_fts', 0.0);

    return $distance - $keywordRank;
}

// Native text-only corrections compete using real text ranks, never a made-up semantic distance.
function synthSelectBestNativeMemoryCandidate(array $candidates): ?array
{
    $semantic=synthSelectBestHybridMemoryCandidate($candidates);
    $text=array_values(array_filter($candidates,static fn($row)=>is_array($row)
        && in_array($row['memory_source'] ?? '',['native','native_diary'],true)
        && ($row['distance'] ?? null)===null && is_numeric($row['rank_any'] ?? null)
        && is_finite((float)$row['rank_any']) && (float)$row['rank_any']>0));
    usort($text,static function(array $a,array $b): int {
        foreach (['rank_all','rank_any','gamets_truncated','rowid'] as $key) {
            $order=($b[$key] ?? 0)<=>($a[$key] ?? 0);if ($order!==0) return $order;
        }
        return strcmp($a['memory_source'],$b['memory_source']);
    });
    $lexical=$text[0] ?? null;
    // The surrounding recall path already compares contextual and direct results by rank_any.
    if ($lexical!==null && ($semantic===null || (float)$lexical['rank_any']>(float)$semantic['rank_any'])) {
        $lexical['distance']=null;
        $lexical['mixed_distance']=null;
        return $lexical;
    }
    return $semantic;
}

// Selects the strongest semantic and keyword match with deterministic recency tie-breakers.
function synthSelectBestHybridMemoryCandidate(array $candidates): ?array
{
    $best = null;
    $bestMixedDistance = INF;
    $bestDistance = INF;
    $bestGamets = -INF;
    $bestRowId = -INF;

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        // Native lexical fallbacks have no measured distance; leave them to ordinary text recall.
        if (in_array($candidate['memory_source'] ?? '',['native','native_diary'],true)
            && (!is_numeric($candidate['distance'] ?? null) || !is_finite((float)$candidate['distance']))) continue;

        $mixedDistance = synthMemoryCandidateMixedDistance($candidate);
        $distance = synthMemoryCandidateNumber($candidate, 'distance', INF);
        $gamets = synthMemoryCandidateNumber($candidate, 'gamets_truncated', -INF);
        $rowId = synthMemoryCandidateNumber($candidate, 'rowid', -INF);

        $isBetter = $mixedDistance < $bestMixedDistance
            || ($mixedDistance === $bestMixedDistance && $distance < $bestDistance)
            || ($mixedDistance === $bestMixedDistance && $distance === $bestDistance && $gamets > $bestGamets)
            || (
                $mixedDistance === $bestMixedDistance
                && $distance === $bestDistance
                && $gamets === $bestGamets
                && $rowId > $bestRowId
            );

        if (!$isBetter) {
            continue;
        }

        $best = $candidate;
        $bestMixedDistance = $mixedDistance;
        $bestDistance = $distance;
        $bestGamets = $gamets;
        $bestRowId = $rowId;
    }

    if ($best === null) {
        return null;
    }

    $best['mixed_distance'] = $bestMixedDistance;
    $best['distance'] = $bestDistance;
    $best['rank_any'] = 1.4 - $bestMixedDistance;
    $best['rank_all'] = 1.4 - $bestDistance;

    return $best;
}

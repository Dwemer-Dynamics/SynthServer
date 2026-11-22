<?php

/**
 * Read-only display labels for save-owned actors. The digest is a display aid;
 * actor IDs and complete canonical identity remain authoritative everywhere else.
 */
function synthNpcActorSaveLabels($db, array $actorIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $actorIds),
        static fn(int $id): bool => $id > 0)));
    if (!$ids) return [];
    if (count($ids) > 1000) throw new InvalidArgumentException('Too many actor identity labels.');

    // Session state and actor last_seen also change without an accepted scene.
    // A timestamp labels the latest recorded save, not a promise it is still running.
    $latest = \Synth\Core\DbManager\Dao\RuntimeContextDao::current($db)->latestAcceptedForPlayer();
    $latestPlaythrough = (string)($latest['playthrough_id'] ?? '');
    $observedAt = (string)($latest['received_at'] ?? '');

    $labels = [];
    foreach (\Synth\Core\DbManager\Dao\ActorIdentityDao::current($db)->playthroughsByIds($ids) as $identity) {
        $playthrough = (string)$identity['playthrough_id'];
        $scope = $latestPlaythrough === '' ? 'unknown'
            : ($playthrough === $latestPlaythrough ? 'latest' : 'other');
        $prefix = ['latest'=>'Latest captured save', 'other'=>'Other save', 'unknown'=>'Save'][$scope];
        $detail = $scope === 'latest'
            ? 'Most recent accepted game context: ' . $observedAt
            : ($scope === 'other'
                ? 'A different saved-playthrough identity. Its character history remains separate.'
                : 'No accepted game context is available to identify the latest save.');
        $labels[intval($identity['id'])] = [
            'label'=>$prefix . ' · ' . substr(hash('sha256', $playthrough), 0, 10),
            'scope'=>$scope,
            'detail'=>$detail,
        ];
    }
    return $labels;
}

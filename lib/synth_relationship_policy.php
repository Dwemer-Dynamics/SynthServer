<?php

// Dialectic's evaluator infers wary although its legacy catalog omits it; native state preserves that meaning.
function synth_relationship_canonical_type($type, array $custom=[]): ?string
{
    return RelationshipManager::canonicalizeRelationshipType($type,array_merge(['wary'],$custom));
}

function synth_relationship_validate_details(array $details): void
{
    if (array_diff(array_keys($details),['note','best','worst','relation','best_delta','worst_delta'])) {
        throw new InvalidArgumentException('Invalid native relationship details');
    }
    foreach (['note','best','worst','relation'] as $key) if (array_key_exists($key,$details)
        && (!is_string($details[$key]) || strlen($details[$key])>($key==='relation' ? 100 : 256)
            || !mb_check_encoding($details[$key],'UTF-8'))) throw new InvalidArgumentException('Invalid native relationship details');
    foreach (['best_delta'=>'best','worst_delta'=>'worst'] as $key=>$text) if (array_key_exists($key,$details)
        && (!is_int($details[$key]) || empty($details[$text]) || ($key==='best_delta'
            ? ($details[$key]<10 || $details[$key]>200) : ($details[$key]>-10 || $details[$key]<-200)))) {
        throw new InvalidArgumentException('Invalid native relationship details');
    }
}

// Project one bounded dedicated proposal using the baseline's raw-delta notes and neutral-only type evolution.
function synth_relationship_project_evaluation(?array $state, int $delta, ?string $type, string $reason, string $relation): ?array
{
    if ($delta===0 && $type===null) return $state;
    // A reset prior carries a revision but no observed value; zero is only the arithmetic origin for a new change.
    $state=($state['aff'] ?? null)===null ? ['aff'=>0,'type'=>'neutral'] : $state;unset($state['revision']);
    $state['aff']=max(-100,min(100,$state['aff']+$delta));
    if ($type!==null) $state['type']=$type;
    elseif ($state['type']==='neutral') {
        $aff=$state['aff'];
        $state['type']=$aff>=6 ? 'platonic' : ($aff<=-55 ? 'enemy' : ($aff<=-30 ? 'rival' : ($aff<=-6 ? 'wary' : 'neutral')));
    }
    if (!empty($reason)) {
        if (abs($delta)>=3 || empty($state['note'])) $state['note']=$reason;
        if ($delta>=10 && $delta>=($state['best_delta'] ?? 0)) {$state['best']=$reason;$state['best_delta']=$delta;}
        if ($delta<=-10 && $delta<=($state['worst_delta'] ?? 0)) {$state['worst']=$reason;$state['worst_delta']=$delta;}
    }
    if (!empty($relation) && empty($state['relation'])) $state['relation']=strtolower($relation);
    return $state;
}

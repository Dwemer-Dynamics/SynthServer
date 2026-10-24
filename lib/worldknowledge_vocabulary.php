<?php
declare(strict_types=1);

require_once __DIR__.'/worldknowledge_runtime.php';

/** A small everyday vocabulary reference, separate from topic-specific expertise. */
function synthWorldKnowledgeVocabularyContext(array $catalog,array $knowledgeTags): ?array
{
    $tags=array_values(array_unique(array_filter(array_map('synthWorldKnowledgeNormalizeAccessTag',$knowledgeTags))));
    if (synthWorldKnowledgeCurrentNpcIsPrewar() || in_array('prewar',$tags,true)) return null;
    $matches=[];
    foreach ($catalog as $row) {
        if (!is_array($row)) continue;
        $canonical=synthWorldKnowledgeNormalizeCanonicalTopic(synthWorldKnowledgeCanonicalTopic(strval($row['canonical_topic'] ?? $row['topic'] ?? '')));
        if ($canonical!=='wasteland_vocabulary') continue;
        if (array_key_exists('is_active',$row)
            && in_array($row['is_active'],[false,0,'0','f','false','off'],true)) return null;
        $matches[]=$row;
    }
    // The effective catalogue normally has one row. Ambiguous input fails closed.
    if (count($matches)!==1) return null;
    $row=$matches[0];
    // Never proactively grant specialist/advanced facts, even to a knowall profile.
    $row['topic_desc']='';
    $row['knowledge_class']='';
    $decision=synthWorldKnowledgeAccessDecision($row,$tags);
    if ($decision['level']!=='basic' || trim(strval($decision['description']))==='') return null;
    $decision['description']=mb_strcut(strval($decision['description']),0,2000,'UTF-8');
    $decision['source']='vocabulary';
    return ['row'=>$row,'decision'=>$decision];
}

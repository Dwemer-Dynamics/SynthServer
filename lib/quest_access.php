<?php

require_once __DIR__.'/quest_knowledge.php';
require_once __DIR__.'/worldknowledge_topic.php';

function synthQuestAccessCanonicalTopic(string $value): string
{
    return synthWorldKnowledgeNormalizeCanonicalTopic(synthWorldKnowledgeCanonicalTopic($value));
}

function synthQuestAccessKey($value): ?string
{
    if (!is_string($value) || preg_match('/^([^|\/\\\\\x00-\x1f]+\.(?:esm|esp|esl))\|([0-9a-f]{8})$/Di',$value,$parts)!==1
        || in_array(strtoupper($parts[2]),['00000000','FFFFFFFF'],true)) return null;
    return mb_strtolower($parts[1],'UTF-8').'|'.strtoupper($parts[2]);
}

// Only reviewed identity metadata and bounded original paraphrases are admitted.
function synthQuestAccessValidateCatalogue(array $catalogue): array
{
    if (($catalogue['schema'] ?? '')!=='synth.quest.rules.v1'
        || !is_array($catalogue['quests'] ?? null) || !array_is_list($catalogue['quests'])
        || count($catalogue['quests'])<1 || count($catalogue['quests'])>64) {
        throw new UnexpectedValueException('quest_access_catalogue_invalid');
    }
    $keys=[];$names=[];
    foreach ($catalogue['quests'] as &$quest) {
        if (!is_array($quest) || !($key=synthQuestAccessKey($quest['quest_key'] ?? null))
            || isset($keys[$key]) || !synthQuestKnowledgeText($quest['quest_name'] ?? null,255)
            || !synthQuestKnowledgeText($quest['safe_basic'] ?? null,2048)
            || ($quest['verification']['quest_record_verified'] ?? null)!==true
            || ($quest['verification']['actor_records_verified'] ?? null)!==true
            || !is_array($quest['participants'] ?? null) || !array_is_list($quest['participants'])
            || count($quest['participants'])<1 || count($quest['participants'])>16
            || !is_array($quest['sources'] ?? null) || !array_is_list($quest['sources'])
            || count($quest['sources'])<1 || count($quest['sources'])>16) {
            throw new UnexpectedValueException('quest_access_quest_rule_invalid');
        }
        $topic=synthQuestAccessCanonicalTopic($quest['quest_name']);
        if ($topic==='' || isset($names[$topic])) throw new UnexpectedValueException('quest_access_quest_name_ambiguous');
        foreach ($quest['sources'] as $url) {
            if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL)
                || parse_url($url,PHP_URL_SCHEME)!=='https') throw new UnexpectedValueException('quest_access_source_invalid');
        }
        $quest['quest_key']=$key;$keys[$key]=true;$names[$topic]=true;$actors=[];
        foreach ($quest['participants'] as &$participant) {
            if (!is_array($participant) || !($actor=synthQuestAccessKey($participant['actor_key'] ?? null))
                || $actor==='fallout4.esm|00000014' || isset($actors[$actor])
                || !is_string($participant['role'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$participant['role'])!==1
                || !is_array($participant['facts'] ?? null) || !array_is_list($participant['facts'])
                || count($participant['facts'])<1 || count($participant['facts'])>4) {
                throw new UnexpectedValueException('quest_access_participant_invalid');
            }
            foreach ($participant['facts'] as $fact) {
                if (!synthQuestKnowledgeText($fact,1024)) throw new UnexpectedValueException('quest_access_fact_invalid');
            }
            $participant['actor_key']=$actor;$actors[$actor]=true;
        }
        unset($participant);
    }
    unset($quest);
    return $catalogue;
}

function synthQuestAccessCatalogue(): array
{
    static $catalogue=null;
    if ($catalogue!==null) return $catalogue;
    $path=dirname(__DIR__).'/data/fallout_quest_knowledge_rules.json';
    $size=@filesize($path);
    if (!$size || $size>131072) throw new UnexpectedValueException('quest_access_catalogue_unavailable');
    $raw=file_get_contents($path);
    $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new UnexpectedValueException('quest_access_catalogue_invalid');
    return $catalogue=synthQuestAccessValidateCatalogue($decoded);
}

function synthQuestAccessRolesForIdentity(array $identity,array $observedQuestKeys): array
{
    if (!synthQuestKnowledgeIdentity($identity) || str_starts_with(strtoupper($identity['form_id']),'0XFF')) return [];
    $key=mb_strtolower($identity['origin_plugin'],'UTF-8').'|'.synthExtractLocalFormIdFromRuntimeFormId($identity['form_id']);
    $roles=[];
    foreach (synthQuestAccessCatalogue()['quests'] as $quest) {
        if (!in_array($quest['quest_key'],$observedQuestKeys,true)) continue;
        foreach ($quest['participants'] as $participant) {
            if ($participant['actor_key']!==$key) continue;
            $roles[]=['quest_key'=>$quest['quest_key'],'quest_name'=>$quest['quest_name'],'actor_key'=>$key,
                'role'=>$participant['role'],'facts'=>$participant['facts']];
        }
    }
    return array_slice($roles,0,8);
}

function synthQuestAccessObservedQuestKeys(array $payload): array
{
    if (!in_array($payload['active_quests_observation'] ?? '',['complete','partial'],true)
        || !is_array($payload['active_quests'] ?? null) || !array_is_list($payload['active_quests'])
        || count($payload['active_quests'])>16) return [];
    $keys=[];
    foreach ($payload['active_quests'] as $quest) {
        if (!is_array($quest) || !synthQuestKnowledgeIdentity($quest+['playthrough_id'=>'quest'])
            || str_starts_with(strtoupper($quest['form_id']),'0XFF')) continue;
        $keys[]=mb_strtolower($quest['origin_plugin'],'UTF-8').'|'.synthExtractLocalFormIdFromRuntimeFormId($quest['form_id']);
    }
    return array_values(array_unique($keys));
}

// The locked quest reader supplies the identity proof; names, tags and caller views never do.
function synthQuestAccessReaderView($db,array $event): array
{
    $unavailable=['schema'=>'synth.quest.access.v1','perspective'=>'npc','observation'=>'unavailable','roles'=>[]];
    if (($event['protocol_version'] ?? null)!==2 || empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
        || !is_object($db) || !method_exists($db,'withNativeRequestLock')) return $unavailable;
    $context=synth_protocol_current_context($db,$event);
    $narrator=synth_protocol_quest_response_is_narrator($db,$event);
    $view=synthQuestKnowledgeView($db,$event,$context ?? [],null,$narrator);
    $roles=is_array($view['participant_background'] ?? null) ? $view['participant_background'] : [];
    return ['schema'=>'synth.quest.access.v1','perspective'=>$view['perspective'],
        'observation'=>$roles ? 'complete' : 'unavailable','roles'=>$roles];
}

// Canonical quest topics use reviewed replacements; unknown quest namespaces fail closed.
function synthQuestAccessTopicPolicy(string $topic): ?array
{
    $canonical=synthQuestAccessCanonicalTopic($topic);
    $denied=['advanced'=>'denied','basic'=>'denied','quest_keys'=>[],'safe_basic'=>''];
    foreach (synthQuestAccessCatalogue()['quests'] as $quest) {
        $name=synthQuestAccessCanonicalTopic($quest['quest_name']);
        if (in_array($canonical,[$name,'quest_'.$name,'fo4_quest_'.$name],true)) {
            return ['advanced'=>'participant','basic'=>'public','quest_keys'=>[$quest['quest_key']],
                'safe_basic'=>$quest['safe_basic']];
        }
    }
    return preg_match('/^(?:fo4_)?quest(?:_|$)/i',trim($topic))===1
        || preg_match('/^(?:fo4_)?quest(?:_|$)/D',$canonical)===1 ? $denied : null;
}

function synthQuestAccessDecision(string $topic,string $tier,array $view): array
{
    $denied=['allowed'=>false,'reason'=>'quest_rule_denied','matched'=>[],'description'=>''];
    $policy=synthQuestAccessTopicPolicy($topic);
    if ($policy===null) return ['allowed'=>true,'reason'=>'ordinary_topic','matched'=>[],'description'=>''];
    if (!in_array($tier,['advanced','basic'],true)) return $denied;
    if ($policy[$tier]==='public') {
        return ['allowed'=>true,'reason'=>'quest_public_premise','matched'=>[],'description'=>$policy['safe_basic']];
    }
    if ($policy[$tier]!=='participant') return $denied;
    if (($view['schema'] ?? '')!=='synth.quest.access.v1' || ($view['perspective'] ?? '')!=='npc'
        || ($view['observation'] ?? '')!=='complete' || !is_array($view['roles'] ?? null)
        || !array_is_list($view['roles']) || count($view['roles'])>8) {
        return $denied;
    }
    $facts=[];$matched=[];
    foreach (array_slice($view['roles'],0,8) as $role) {
        if (!is_array($role) || !in_array($role['quest_key'] ?? '',$policy['quest_keys'],true)) continue;
        foreach (synthQuestAccessCatalogue()['quests'] as $quest) {
            if ($quest['quest_key']!==$role['quest_key']) continue;
            foreach ($quest['participants'] as $participant) {
                if ($participant['actor_key']!==($role['actor_key'] ?? '') || $participant['role']!==($role['role'] ?? '')) continue;
                // Never take authored facts from a caller-provided view or profile.
                $facts=array_merge($facts,$participant['facts']);$matched[]=$quest['quest_key'];
            }
        }
    }
    $description=implode(' ',array_values(array_unique($facts)));
    if ($description==='' || strlen($description)>4096) return $denied;
    return ['allowed'=>true,'reason'=>'quest_authored_participant','matched'=>array_values(array_unique($matched)),
        'description'=>$description];
}

function synthQuestAccessCatalogRows(): array
{
    $rows=[];
    foreach (synthQuestAccessCatalogue()['quests'] as $quest) {
        $name=synthQuestAccessCanonicalTopic($quest['quest_name']);
        $plugin=strstr($quest['quest_key'],'|',true);
        $region=match($plugin) {'dlccoast.esm'=>'far_harbor','dlcnukaworld.esm'=>'nuka_world',default=>'commonwealth'};
        $rows[]=['topic'=>'quest_'.$name,'canonical_topic'=>'quest_'.$name,'aliases'=>$quest['quest_name'],
            'topic_desc'=>'Authored quest background.','knowledge_class'=>'',
            'topic_desc_basic'=>$quest['safe_basic'],'knowledge_class_basic'=>'region:'.$region,
            'tags'=>'quest,'.$region,'category'=>'quest','source_kind'=>'quest_rules',
            'catalog_id'=>'fallout-4-quest-knowledge','catalog_version'=>'content-v1','is_active'=>true];
    }
    return $rows;
}

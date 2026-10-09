<?php

require_once __DIR__.'/synth_runtime.php';

const SYNTH_FALLOUT_CONTENT_FIELDS = ['core','npc_static_bio','worldknowledge_tags',
    'personality','occupation','appearance','skills','speechstyle','goals'];
const SYNTH_FALLOUT_CONTENT_PLUGINS = ['Fallout4.esm','DLCRobot.esm','DLCworkshop01.esm',
    'DLCCoast.esm','DLCworkshop02.esm','DLCworkshop03.esm','DLCNukaWorld.esm'];

// IDs are plugin-local metadata, never the current game's load-order prefix.
function synthFalloutContentIdentity(array $row,bool $allowReference=true): ?string
{
    $kind=array_key_exists('mapping_kind',$row) ? $row['mapping_kind'] : 'base';
    if (!is_string($kind) || !in_array($kind,['base','reference'],true)
        || ($kind==='reference' && !$allowReference)
        || array_key_exists('reference_plugin',$row) || array_key_exists('base_plugin',$row)
        || array_key_exists('base_local_formid',$row)) return null;
    // A reference-only identity must never accidentally bind a shared NPC base.
    if (($kind==='reference' && array_key_exists('baseid',$row))
        || ($kind==='base' && array_key_exists('reference_local_formid',$row))) return null;
    if (array_key_exists('base_aliases',$row)) {
        if ($kind!=='base' || !$allowReference || !is_array($row['base_aliases'])
            || !array_is_list($row['base_aliases']) || count($row['base_aliases'])>8) return null;
        foreach ($row['base_aliases'] as $alias)
            if (!is_string($alias) || !preg_match('/^00[0-9A-F]{6}$/D',$alias) || $alias==='00000000') return null;
    }
    $plugin=$row['plugin'] ?? null;
    $id=$row[$kind==='reference' ? 'reference_local_formid' : 'baseid'] ?? null;
    if (!is_string($plugin) || !in_array($plugin,SYNTH_FALLOUT_CONTENT_PLUGINS,true)
        || !is_string($id) || !preg_match('/^00[0-9A-F]{6}$/D',$id) || $id==='00000000') return null;
    return $kind.'|'.strtolower($plugin).'|'.$id;
}

// Curated lore is data. It cannot assign voices, relationship scores, actions or save outcomes.
function synthLoadFalloutContentPack(string $root): array
{
    $path=$root.'/data/fallout4_content_pack.json';
    if (!is_readable($path) || filesize($path)>1048576) throw new RuntimeException('Fallout content pack unavailable or oversized');
    $pack=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    if (($pack['schema'] ?? '')!=='synth.fallout4-content.v1'
        || !is_array($pack['npcs'] ?? null) || !array_is_list($pack['npcs'])
        || !is_array($pack['items'] ?? null) || !array_is_list($pack['items'])
        || count($pack['npcs'])>300 || count($pack['items'])>1000) throw new RuntimeException('Invalid Fallout content pack');
    $identities=[];$names=[];
    foreach ($pack['npcs'] as $row) {
        $identity=is_array($row) ? synthFalloutContentIdentity($row) : null;
        if ($identity===null
            || !preg_match('/^[a-z0-9_]{1,100}$/D',$row['npc_name'] ?? '')
            || !is_string($row['display_name'] ?? null) || $row['display_name']===''
            || strlen($row['display_name'])>128 || !preg_match('//u',$row['display_name'])
            || str_contains($row['display_name'],"\0")
            || !is_bool($row['is_nonverbal_creature'] ?? null) || !is_array($row['fields'] ?? null)
            || array_diff(array_keys($row['fields']),SYNTH_FALLOUT_CONTENT_FIELDS)
            || array_diff(SYNTH_FALLOUT_CONTENT_FIELDS,array_keys($row['fields']))
            || isset($identities[$identity]) || isset($names[$row['npc_name']])) throw new RuntimeException('Invalid or duplicate NPC content identity');
        $identities[$identity]=true;$names[$row['npc_name']]=true;
        foreach ($row['base_aliases'] ?? [] as $alias) {
            $aliasIdentity=synthFalloutContentIdentity(['plugin'=>$row['plugin'],'baseid'=>$alias]);
            if (isset($identities[$aliasIdentity])) throw new RuntimeException('Duplicate NPC base alias identity');
            $identities[$aliasIdentity]=true;
        }
        foreach ($row['fields'] as $value) if (!is_string($value) || strlen($value)>4096 || !preg_match('//u',$value)
            || str_contains($value,"\0")) throw new RuntimeException('Invalid NPC content text');
        if ($row['fields']['core']==='' || $row['fields']['npc_static_bio']==='') throw new RuntimeException('NPC content lacks identity/background');
        foreach (explode(',',$row['fields']['worldknowledge_tags']) as $tag)
            if (!preg_match('/^[a-z0-9_]{1,100}$/D',$tag)) throw new RuntimeException('Invalid NPC knowledge tag');
        if (!preg_match('~^https://[^\s]{1,2040}$~D',$row['source_url'] ?? '')) throw new RuntimeException('Missing NPC source');
    }
    $identities=[];
    foreach ($pack['items'] as $row) {
        $identity=is_array($row) ? synthFalloutContentIdentity($row,false) : null;
        if ($identity===null
            || isset($identities[$identity]) || !is_string($row['name'] ?? null) || $row['name']===''
            || strlen($row['name'])>255 || !preg_match('//u',$row['name']) || str_contains($row['name'],"\0")
            || !is_string($row['description'] ?? null) || $row['description']==='' || strlen($row['description'])>2048
            || !preg_match('//u',$row['description']) || str_contains($row['description'],"\0")
            || !preg_match('~^https://[^\s]{1,2040}$~D',$row['source_url'] ?? '')) throw new RuntimeException('Invalid item content identity or source');
        $identities[$identity]=true;
    }
    return $pack;
}

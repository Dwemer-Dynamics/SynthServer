<?php

require_once __DIR__.'/synth_runtime.php';

require_once __DIR__.'/fallout_content_catalog.php';

const SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION = 20261008003;

// Install standard template/description rows; custom overlays and existing standard edits win.
function synthInstallFalloutContentPack(object $db,string $root,bool $fillExistingProfiles=false,bool $manageTransaction=true): array
{
    $pack=synthLoadFalloutContentPack($root);
    if (!$manageTransaction && (!method_exists($db,'isTransactionIdle') || $db->isTransactionIdle()!==false))
        throw new LogicException('Fallout content import requires an active caller-owned transaction');
    if ($manageTransaction && method_exists($db,'isTransactionIdle') && $db->isTransactionIdle()!==true)
        throw new LogicException('Fallout content import cannot own an existing transaction');
    if ($manageTransaction && $db->execQuery('BEGIN')===false) throw new RuntimeException('Unable to begin Fallout content import');
    $filled=0;
    try {
        // Apply the reference-only map upgrade inside the same owned/borrowed
        // transaction as content, so failed imports retain the previous schema.
        $mapSchema=file_get_contents(__DIR__.'/core/database_schema/bio_template_actor_map.sql');
        if ($mapSchema===false || trim($mapSchema)==='' || $db->execQuery($mapSchema)===false)
            throw new RuntimeException('Unable to upgrade NPC stable mapping schema');
        foreach ($pack['npcs'] as $row) {
            $fields=['npc_name'=> $row['npc_name']]+$row['fields'];
            $placeholders=array_map(static fn($position)=>'$'.$position,range(1,count($fields)));
            if ($db->execQuery('INSERT INTO synth.bio_templates ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',$placeholders).') ON CONFLICT(npc_name) DO NOTHING',array_values($fields))===false)
                throw new RuntimeException('Unable to import NPC template');
            $reference=($row['mapping_kind'] ?? 'base')==='reference';
            $mappingColumns=$reference ? 'reference_plugin,reference_local_formid' : 'base_plugin,base_local_formid';
            $mappingIds=$reference ? [$row['reference_local_formid']]
                : array_merge([$row['baseid']],$row['base_aliases'] ?? []);
            foreach ($mappingIds as $mappingId)
                if ($db->execQuery('INSERT INTO synth.bio_template_actor_map(template_name,'.$mappingColumns.',exact_name,is_nonverbal_creature) VALUES ($1,$2,$3,$4,$5) ON CONFLICT DO NOTHING',
                    [$row['npc_name'],$row['plugin'],$mappingId,$row['display_name'],$row['is_nonverbal_creature'] ? 'true' : 'false'])===false)
                    throw new RuntimeException('Unable to import NPC stable mapping');
        }
        foreach ($pack['items'] as $row) {
            $values=array_map(static fn($key)=>$row[$key],['plugin','baseid','name','description']);
            if ($db->execQuery('INSERT INTO synth.descriptions(plugin,baseid,name,description) VALUES ($1,$2,$3,$4) ON CONFLICT(plugin,baseid) DO NOTHING',$values)===false)
                throw new RuntimeException('Unable to import item description');
        }
        if ($fillExistingProfiles) {
            $names=array_column($pack['npcs'],'npc_name');
            $profiles=$db->fetchAll('SELECT n.*,a.origin_plugin FROM synth.core_npc_master n JOIN synth.actor_identities a ON a.id=n.actor_identity_id WHERE COALESCE(n.lock_profile,0)<>1 ORDER BY n.id FOR UPDATE OF n');
            foreach ($profiles ?: [] as $profile) {
                $template=synth_fetch_bio_template($db,$profile['npc_name'],$profile['refid'] ?? '',$profile['base'] ?? '',$profile['origin_plugin']);
                if (!$template || !in_array($template['npc_name'],$names,true)) continue;
                $updates=[];$values=[];
                foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) {
                    $current=trim(strval($profile[$field] ?? ''));
                    $placeholder=($field==='personality' && $current==='A Fallout 4 wasteland resident. Replace this generated SYNTH seed profile with richer character data when available.')
                        || ($field==='speechstyle' && $current==='Speaks plainly and reacts to the Sole Survivor and the current situation.');
                    $value=trim(strval($template[$field] ?? ''));
                    if (($current==='' || $placeholder) && $value!=='') {
                        $values[]=$value;$updates[]=$field.'=$'.count($values);
                    }
                }
                if ($updates!==[]) {
                    $values[]=intval($profile['id']);
                    if ($db->execQuery('UPDATE synth.core_npc_master SET '.implode(',',$updates).' WHERE id=$'.count($values).' AND COALESCE(lock_profile,0)<>1',$values)===false)
                        throw new RuntimeException('Unable to fill existing NPC profile');
                    ++$filled;
                }
            }
        }
        if ($manageTransaction && $db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to commit Fallout content import');
    } catch (Throwable $error) { if ($manageTransaction) $db->execQuery('ROLLBACK');throw $error; }
    return ['npc_templates'=>count($pack['npcs']),'item_descriptions'=>count($pack['items']),
        'profiles_filled'=>$filled,'custom_content_preserved'=>true];
}

// The CLI installs one content release as a single unit. Standalone importers
// retain their own transactions; this owner rolls back both imports and version
// recording together without committing any transaction supplied by its caller.
function synthInstallFalloutContentBundle(object $db,string $root,bool $fillExistingProfiles=false): array
{
    require_once __DIR__.'/worldknowledge_catalog.php';
    synthLoadFalloutContentPack($root);
    synthWorldKnowledgeLoadFactoryCatalog($root);
    synthWorldKnowledgeLoadNpcAccessTags($root);
    if (!method_exists($db,'isTransactionIdle') || $db->isTransactionIdle()!==true)
        throw new LogicException('Fallout content bundle requires an idle PostgreSQL transaction');
    if ($db->execQuery('BEGIN')===false) throw new RuntimeException('Unable to begin Fallout content bundle');
    try {
        $report=synthInstallFalloutContentPack($db,$root,$fillExistingProfiles,false);
        $report['worldknowledge']=synthWorldKnowledgeInstallFactoryCatalog($db,$root,false);
        // Older populated installations have no unique key on this legacy table.
        if ($db->execQuery('LOCK TABLE synth.database_versioning IN SHARE ROW EXCLUSIVE MODE')===false
            || $db->execQuery('INSERT INTO synth.database_versioning(tablename,version) SELECT $1,$2 WHERE NOT EXISTS (SELECT 1 FROM synth.database_versioning WHERE tablename=$1)',
                ['fallout4_content_pack',SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION])===false
            || $db->execQuery('UPDATE synth.database_versioning SET version=GREATEST(version,$2) WHERE tablename=$1',
                ['fallout4_content_pack',SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION])===false)
            throw new RuntimeException('Unable to record Fallout content bundle version');
        if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to commit Fallout content bundle');
    } catch (Throwable $error) { $db->execQuery('ROLLBACK');throw $error; }
    $report['content_version']=SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION;
    return $report;
}

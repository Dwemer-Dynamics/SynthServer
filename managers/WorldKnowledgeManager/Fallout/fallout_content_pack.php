<?php

require_once dirname(__DIR__, 3).'/lib/synth_runtime.php';
require_once dirname(__DIR__, 3).'/lib/synth_biography.php';

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
        foreach ($pack['npcs'] as $row) {
            $fields=['npc_name'=> $row['npc_name']]+$row['fields'];
            if (!\Synth\Core\DbManager\Dao\NpcBiographyTemplateDao::current($db)->insertIfMissing($fields))
                throw new RuntimeException('Unable to import NPC template');
            $reference=($row['mapping_kind'] ?? 'base')==='reference';
            $mappingIds=$reference ? [$row['reference_local_formid']]
                : array_merge([$row['baseid']],$row['base_aliases'] ?? []);
            foreach ($mappingIds as $mappingId)
                if (!\Synth\Core\DbManager\Dao\NpcBiographyTemplateActorDao::current($db)->insertMappingIfMissing(
                    $row['npc_name'],$reference,$row['plugin'],$mappingId,$row['display_name'],(bool)$row['is_nonverbal_creature']))
                    throw new RuntimeException('Unable to import NPC stable mapping');
        }
        foreach ($pack['items'] as $row) {
            if (!\Synth\Core\DbManager\Dao\ItemDescriptionDao::current($db)->insertIfMissing($row['plugin'],$row['baseid'],$row['name'],$row['description']))
                throw new RuntimeException('Unable to import item description');
        }
        if ($fillExistingProfiles) {
            $names=array_column($pack['npcs'],'npc_name');
            $profiles=\Synth\Core\DbManager\Dao\NpcDao::current($db)->lockUnlockedProfilesWithOrigin();
            foreach ($profiles as $profile) {
                $template=synth_fetch_bio_template($db,$profile['npc_name'],$profile['refid'] ?? '',$profile['base'] ?? '',$profile['origin_plugin']);
                if (!$template || !in_array($template['npc_name'],$names,true)) continue;
                $updates=[];
                foreach (SYNTH_FALLOUT_CONTENT_FIELDS as $field) {
                    // Authored expertise is filled only as part of the background, never into skills.
                    if ($field==='skills') continue;
                    $current=trim(strval($profile[$field] ?? ''));
                    $placeholder=($field==='personality' && $current==='A Fallout 4 wasteland resident. Replace this generated SYNTH seed profile with richer character data when available.')
                        || ($field==='speechstyle' && $current==='Speaks plainly and reacts to the Sole Survivor and the current situation.');
                    $value=trim(strval($template[$field] ?? ''));
                    if ($field==='npc_static_bio') $value=synth_biography_background($value,strval($template['skills'] ?? ''));
                    if (($current==='' || $placeholder) && $value!=='') {
                        $updates[$field]=$value;
                    }
                }
                if ($updates!==[]) {
                    if (!\Synth\Core\DbManager\Dao\NpcDao::current($db)->fillUnlockedProfile(intval($profile['id']),$updates))
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
    require_once dirname(__DIR__, 3).'/lib/worldknowledge_catalog.php';
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
        if ($db->execQuery('LOCK TABLE public.core_catalog_version IN SHARE ROW EXCLUSIVE MODE')===false
            || !\Synth\Core\DbManager\Dao\CatalogVersionDao::current($db)->recordVersion('fallout4_content_pack',SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION))
            throw new RuntimeException('Unable to record Fallout content bundle version');
        if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to commit Fallout content bundle');
    } catch (Throwable $error) { $db->execQuery('ROLLBACK');throw $error; }
    $report['content_version']=SYNTH_FALLOUT_CONTENT_MIGRATION_VERSION;
    return $report;
}

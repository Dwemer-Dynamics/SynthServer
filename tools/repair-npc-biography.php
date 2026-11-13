<?php
// Operator repair: move authored expertise of factory-imported NPC profiles into the background.
// Dry run by default; --apply writes once, in one transaction, guarded by the values it read.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$root=dirname(__DIR__);
require_once $root.'/lib/runtime_bootstrap.php';
synthRuntimeBootstrap($root,['run_db_updates'=>false,'load_general_settings'=>false,
    'load_stt_connector'=>false,'load_tts_connector'=>false,'load_narrator'=>false]);
require_once $root.'/lib/synth_runtime.php';
require_once $root.'/lib/synth_biography.php';
require_once $root.'/lib/fallout_content_catalog.php';
$factories=array_column(synthLoadFalloutContentPack($root)['npcs'],'fields','npc_name');
$apply=in_array('--apply',$argv,true);
$db=$GLOBALS['db'];
$operation=static function($connection) use ($db,$factories,$apply): array {
$counts=['repair'=>0,'already_repaired'=>0,'no_expertise'=>0,'customized'=>0,'ambiguous'=>0,'locked'=>0,'provenance_missing'=>0];
$applied=0;
$reports=[];
$fetch=static function(string $sql,array $params) use ($connection): array {
    $result=@pg_query_params($connection,$sql,$params);
    if ($result===false) throw new RuntimeException('Repair query failed');
    return pg_fetch_all($result) ?: [];
};
    // Keep identity mappings/custom overlays stable; profile updates lock only rows that still match the candidate.
    if ($apply && @pg_query($connection,'LOCK TABLE synth.bio_template_actor_map,synth.bio_templates_custom IN SHARE MODE')===false)
        throw new RuntimeException('Unable to lock biography mappings');
    $rows=$fetch('SELECT n.id,n.actor_identity_id,n.npc_name,n.refid,n.base,n.lock_profile,n.npc_static_bio,n.skills,n.metadata::text AS metadata,
        n.extended_data::text AS extended_data,a.origin_plugin,a.full_form_id,a.game
        FROM synth.core_npc_master n LEFT JOIN synth.actor_identities a ON a.id=n.actor_identity_id ORDER BY n.id',[]);
    foreach ($rows ?: [] as $row) {
        $metadata=json_decode(strval($row['metadata'] ?? ''),true);
        $template=is_array($metadata) && is_string($metadata['bio_template'] ?? null) ? $metadata['bio_template'] : '';
        // Compare only the shipped authored source, never a mutable database template or a display name.
        $factory=$factories[$template] ?? null;
        $resolved=null;
        if ($factory!==null && !empty($row['actor_identity_id']) && $row['game']==='fo4') {
            $extended=json_decode(strval($row['extended_data'] ?? ''),true);
            $basePlugin=strval($extended['base_origin_plugin'] ?? '');
            $stable=synthParseStableFormReference(strval($row['base'] ?? ''));
            $base=is_array($stable) || $basePlugin!==''
                ? synth_resolve_actor_form_identity($db,strval($row['base'] ?? ''),$basePlugin) : null;
            $reference=synth_resolve_actor_form_identity($db,strval($row['full_form_id'] ?? ''),strval($row['origin_plugin'] ?? ''));
            $matches=$fetch('SELECT DISTINCT template_name FROM synth.bio_template_actor_map
                WHERE (lower(base_plugin)=lower($1) AND upper(base_local_formid)=$2)
                   OR (lower(reference_plugin)=lower($3) AND upper(reference_local_formid)=$4)',
                [$base['plugin'] ?? '',$base['local_formid'] ?? '',$reference['plugin'] ?? '',$reference['local_formid'] ?? '']);
            if (count($matches)===1) $resolved=strval($matches[0]['template_name']);
        }
        $result=synth_biography_repair_classify($row,$factory,$resolved);
        if ($factory!==null && $result['status']!=='locked' && $fetch('SELECT npc_name FROM synth.bio_templates_custom WHERE npc_name=$1',[$template])!==[])
            $result=['status'=>'customized','reason'=>'custom_template'];
        ++$counts[$result['status']];
        if ($result['status']!=='no_expertise' && $result['status']!=='already_repaired')
            $reports[]=['id'=>intval($row['id']),'npc_name'=>$row['npc_name'],'template'=>$template]+$result;
        if (!$apply || $result['status']!=='repair') continue;
        $updated=$fetch('UPDATE synth.core_npc_master SET npc_static_bio=$1,skills=NULL WHERE id=$2 AND COALESCE(lock_profile,0)<>1
            AND npc_static_bio IS NOT DISTINCT FROM $3 AND skills IS NOT DISTINCT FROM $4
            AND actor_identity_id=$5 AND metadata IS NOT DISTINCT FROM $6::jsonb
            AND base IS NOT DISTINCT FROM $7 AND refid IS NOT DISTINCT FROM $8
            AND EXISTS (SELECT 1 FROM synth.actor_identities a WHERE a.id=$5 AND a.full_form_id=$9 AND a.origin_plugin=$10)
            RETURNING id',[$result['npc_static_bio'],intval($row['id']),$row['npc_static_bio'],$row['skills'],
                $row['actor_identity_id'],$row['metadata'],$row['base'],$row['refid'],$row['full_form_id'],$row['origin_plugin']]);
        if (!is_array($updated) || count($updated)!==1) throw new RuntimeException('NPC profile changed during repair: '.intval($row['id']));
        ++$applied;
    }
    return [$reports,['mode'=>$apply ? 'apply' : 'dry_run','rows'=>array_sum($counts),'applied'=>$applied]+$counts];
};
// The management transaction pins this backend and bounds statement/lock waits; a failure cannot reconnect/replay writes.
[$reports,$summary]=$db->withManagementTransaction($operation);
foreach ($reports as $report) echo json_encode($report,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
echo json_encode($summary,JSON_THROW_ON_ERROR).PHP_EOL;

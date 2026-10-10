<?php
declare(strict_types=1);

require_once __DIR__.'/fallout_content_catalog.php';
require_once __DIR__.'/worldknowledge_catalog.php';

const SYNTH_FALLOUT_CATALOGUE_SQL_VERSION = 20261009002;
const SYNTH_FALLOUT_CATALOGUE_SQL_SOURCES = [
    'data/fallout4_content_pack.json',
    'data/fallout4_named_bios.csv',
    'data/fallout4_common_descriptions.csv',
    'data/fallout_worldknowledge_parity_v1.csv',
    'data/fallout_worldknowledge_manifest.json',
];

/** A catalogue transaction stays on its captured backend; no query may reconnect. */
class SynthFalloutCatalogueSqlConnection
{
    protected \PgSql\Connection $connection;
    private int $backendPid;

    public function __construct(\PgSql\Connection $connection)
    {
        $this->connection=$connection;
        if (pg_connection_status($connection)!==PGSQL_CONNECTION_OK)
            throw new RuntimeException('Fallout catalogue SQL connection is unavailable');
        $this->backendPid=pg_get_pid($connection);
    }

    private function assertSession(): void
    {
        try {
            if (pg_connection_status($this->connection)!==PGSQL_CONNECTION_OK)
                throw new RuntimeException('Fallout catalogue SQL connection is unavailable');
            if (pg_get_pid($this->connection)!==$this->backendPid)
                throw new RuntimeException('Fallout catalogue SQL session changed during migration');
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException) throw $error;
            throw new RuntimeException('Fallout catalogue SQL connection is unavailable',0,$error);
        }
    }

    public function isTransactionIdle(): bool
    {
        $this->assertSession();
        return pg_transaction_status($this->connection)===PGSQL_TRANSACTION_IDLE;
    }

    public function assertTransactionCurrent(): void
    {
        $this->assertSession();
        if (pg_transaction_status($this->connection)!==PGSQL_TRANSACTION_INTRANS)
            throw new RuntimeException('Fallout catalogue SQL owned transaction is no longer active');
    }

    public function execQuery(string $query,array $parameters=[])
    {
        if ($query==='BEGIN') {
            if (!$this->isTransactionIdle()) throw new LogicException('Fallout catalogue SQL requires an idle transaction');
        } elseif ($query==='ROLLBACK') {
            // An SQL error leaves INERROR; rollback still uses this original backend.
            $this->assertSession();
        } else {
            $this->assertTransactionCurrent();
        }
        $result=$parameters===[] ? @pg_query($this->connection,$query)
            : @pg_query_params($this->connection,$query,$parameters);
        if ($result===false) return false;
        if (in_array($query,['COMMIT','ROLLBACK'],true)) {
            $this->assertSession();
            if (pg_transaction_status($this->connection)!==PGSQL_TRANSACTION_IDLE)
                throw new RuntimeException('Fallout catalogue SQL transaction completion was not confirmed');
        } else {
            $this->assertTransactionCurrent();
        }
        return $result;
    }

    public function fetchOne(string $query): array
    {
        $this->assertTransactionCurrent();
        $result=@pg_query($this->connection,$query);
        if ($result===false) throw new RuntimeException('Fallout catalogue SQL verification query failed');
        $this->assertTransactionCurrent();
        return pg_fetch_assoc($result) ?: [];
    }

    public function escapeLiteral(string $value): string
    {
        $this->assertTransactionCurrent();
        $literal=pg_escape_literal($this->connection,$value);
        if ($literal===false) throw new RuntimeException('Fallout catalogue SQL literal encoding failed');
        return $literal;
    }
}

/** Read only fixed, checked-in release paths; manifests cannot select another SQL file. */
function synthFalloutCatalogueSqlRead(string $root,string $relative,int $limit): string
{
    $rootReal=realpath($root);
    $path=realpath(rtrim($root,'/\\').'/'.$relative);
    $normalize=static fn(string $value): string => strtolower(str_replace('\\','/',$value));
    if ($rootReal===false || $path===false
        || !str_starts_with($normalize($path),rtrim($normalize($rootReal),'/').'/')
        || !is_file($path) || !is_readable($path) || filesize($path)>$limit)
        throw new RuntimeException('Fallout catalogue release file is unavailable or oversized');
    $contents=file_get_contents($path);
    if ($contents===false || $contents==='' || strlen($contents)>$limit)
        throw new RuntimeException('Fallout catalogue release file is empty or unreadable');
    return $contents;
}

/** Mask SQL data and procedural bodies before checking top-level transaction ownership. */
function synthFalloutCatalogueSqlStructure(string $sql): string
{
    $length=strlen($sql);$structure='';
    for ($i=0;$i<$length;) {
        $pair=substr($sql,$i,2);
        if ($pair==='--') {
            $end=strpos($sql,"\n",$i+2);$i=$end===false ? $length : $end+1;$structure.=' ';continue;
        }
        if ($pair==='/*') {
            $depth=1;$i+=2;
            while ($i<$length && $depth>0) {
                $pair=substr($sql,$i,2);
                if ($pair==='/*') { ++$depth;$i+=2; }
                elseif ($pair==='*/') { --$depth;$i+=2; }
                else ++$i;
            }
            if ($depth!==0) throw new RuntimeException('Unterminated Fallout catalogue SQL comment');
            $structure.=' ';continue;
        }
        if ($sql[$i]==="'" || $sql[$i]==='"') {
            $quote=$sql[$i];$escaped=$quote==="'" && $i>0 && in_array($sql[$i-1],['e','E'],true)
                && ($i<2 || !preg_match('/[a-z0-9_$]/i',$sql[$i-2]));
            $closed=false;++$i;
            while ($i<$length) {
                if ($escaped && $sql[$i]==='\\') { $i+=2;continue; }
                if ($sql[$i]===$quote) {
                    if (($sql[$i+1] ?? '')===$quote) { $i+=2;continue; }
                    ++$i;$closed=true;break;
                }
                ++$i;
            }
            if (!$closed) throw new RuntimeException('Unterminated Fallout catalogue SQL literal');
            $structure.=' ';continue;
        }
        if ($sql[$i]==='$' && preg_match('/\G\$(?:[a-z_][a-z0-9_]*)?\$/Ai',$sql,$match,0,$i)) {
            $delimiter=$match[0];$end=strpos($sql,$delimiter,$i+strlen($delimiter));
            if ($end===false) throw new RuntimeException('Unterminated Fallout catalogue SQL body');
            $i=$end+strlen($delimiter);$structure.=' ';continue;
        }
        $structure.=$sql[$i++];
    }
    return $structure;
}

/** Validate the complete release before starting any database writes. */
function synthLoadFalloutCatalogueSql(string $root): array
{
    $manifest=json_decode(synthFalloutCatalogueSqlRead($root,'data/fallout4_catalogue_sql_manifest.json',65536),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || array_is_list($manifest)
        || ($manifest['schema'] ?? '')!=='synth.fallout4-catalogue-sql.v1'
        || ($manifest['migration_version'] ?? null)!==SYNTH_FALLOUT_CATALOGUE_SQL_VERSION
        || ($manifest['sql_file'] ?? '')!=='fallout4_catalogue_seed.sql'
        || !is_string($manifest['sql_sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D',$manifest['sql_sha256'])
        || !is_array($manifest['source_files'] ?? null) || array_is_list($manifest['source_files'])
        || !is_array($manifest['counts'] ?? null) || array_is_list($manifest['counts'])
        || !is_array($manifest['catalog'] ?? null) || array_is_list($manifest['catalog']))
        throw new RuntimeException('Invalid Fallout catalogue SQL manifest');
    $sourcePaths=array_keys($manifest['source_files']);sort($sourcePaths);
    $expectedPaths=SYNTH_FALLOUT_CATALOGUE_SQL_SOURCES;sort($expectedPaths);
    if ($sourcePaths!==$expectedPaths) throw new RuntimeException('Unexpected Fallout catalogue source paths');
    foreach ($manifest['source_files'] as $relative=>$checksum) {
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/D',$checksum)
            || !hash_equals($checksum,hash('sha256',synthFalloutCatalogueSqlRead($root,$relative,4194304))))
            throw new RuntimeException('Fallout catalogue source checksum mismatch');
    }
    $sql=synthFalloutCatalogueSqlRead($root,'data/fallout4_catalogue_seed.sql',4194304);
    if (!preg_match('//u',$sql) || str_contains($sql,"\0") || !hash_equals($manifest['sql_sha256'],hash('sha256',$sql)))
        throw new RuntimeException('Fallout catalogue SQL checksum or encoding mismatch');
    $structure=synthFalloutCatalogueSqlStructure($sql);
    if (trim($structure)==='' || preg_match('/(?:^|;)\s*(?:BEGIN|COMMIT|END|ROLLBACK|ABORT|START\s+TRANSACTION|PREPARE\s+TRANSACTION|SET\s+(?:LOCAL\s+)?TRANSACTION)\b/i',$structure))
        throw new RuntimeException('Fallout catalogue SQL must not manage transactions');
    $pack=synthLoadFalloutContentPack($root);
    $catalog=synthWorldKnowledgeLoadFactoryCatalog($root);
    $mappings=count($pack['npcs']);
    foreach ($pack['npcs'] as $row) $mappings+=count($row['base_aliases'] ?? []);
    $counts=['npcs'=>count($pack['npcs']),'mappings'=>$mappings,'items'=>count($pack['items']),'lore'=>count($catalog['rows'])];
    $countKeys=array_keys($manifest['counts']);sort($countKeys);$expectedKeys=array_keys($counts);sort($expectedKeys);
    if ($countKeys!==$expectedKeys) throw new RuntimeException('Unexpected Fallout catalogue counts');
    foreach ($counts as $key=>$count)
        if (!is_int($manifest['counts'][$key]) || $manifest['counts'][$key]!==$count)
            throw new RuntimeException('Fallout catalogue counts differ from validated sources');
    if (($manifest['catalog']['catalog_id'] ?? null)!==$catalog['manifest']['catalog_id']
        || ($manifest['catalog']['catalog_version'] ?? null)!==$catalog['manifest']['catalog_version'])
        throw new RuntimeException('Fallout catalogue identity differs from validated sources');
    return ['manifest'=>$manifest,'sql'=>$sql,'pack'=>$pack,'catalog'=>$catalog];
}

/** Assert source identities, allowing existing operator-authored fields and mappings to win. */
function synthFalloutCatalogueSqlAssertInstalled(object $db,array $release): void
{
    $literal=static fn(string $value): string => $db->escapeLiteral($value);
    $names=[];$maps=[];$items=[];
    foreach ($release['pack']['npcs'] as $row) {
        $names[]='('.$literal($row['npc_name']).')';
        $reference=($row['mapping_kind'] ?? 'base')==='reference';
        $ids=$reference ? [$row['reference_local_formid']] : array_merge([$row['baseid']],$row['base_aliases'] ?? []);
        foreach ($ids as $id) $maps[]='('.$literal($reference ? 'reference' : 'base').','.$literal($row['plugin']).','.$literal($id).')';
    }
    foreach ($release['pack']['items'] as $row) $items[]='('.$literal($row['plugin']).','.$literal($row['baseid']).')';
    $queries=[];
    if ($names!==[]) $queries['npcs']='WITH expected(npc_name) AS (VALUES '.implode(',',$names).') SELECT count(*) AS n FROM expected WHERE EXISTS (SELECT 1 FROM synth.bio_templates installed WHERE installed.npc_name=expected.npc_name)';
    if ($maps!==[]) $queries['mappings']='WITH expected(kind,plugin,formid) AS (VALUES '.implode(',',$maps).') SELECT count(*) AS n FROM expected WHERE EXISTS (SELECT 1 FROM synth.bio_template_actor_map installed WHERE (expected.kind=\'base\' AND lower(installed.base_plugin)=lower(expected.plugin) AND upper(installed.base_local_formid)=expected.formid) OR (expected.kind=\'reference\' AND lower(installed.reference_plugin)=lower(expected.plugin) AND upper(installed.reference_local_formid)=expected.formid))';
    if ($items!==[]) $queries['items']='WITH expected(plugin,baseid) AS (VALUES '.implode(',',$items).') SELECT count(*) AS n FROM expected WHERE EXISTS (SELECT 1 FROM synth.descriptions installed WHERE installed.plugin=expected.plugin AND installed.baseid=expected.baseid)';
    foreach ($queries as $key=>$query) {
        $result=$db->fetchOne($query);
        if (!is_array($result) || !array_key_exists('n',$result) || intval($result['n'])!==$release['manifest']['counts'][$key])
            throw new RuntimeException('Fallout catalogue source identity coverage is incomplete');
    }
    $catalog=$release['catalog'];$id=$literal($catalog['manifest']['catalog_id']);$version=$literal($catalog['manifest']['catalog_version']);
    $record=$db->fetchOne('SELECT row_count,checksum_sha256 FROM synth.worldknowledge_catalogs WHERE catalog_id='.$id.' AND catalog_version='.$version);
    if (!is_array($record) || intval($record['row_count'] ?? -1)!==count($catalog['rows'])
        || ($record['checksum_sha256'] ?? '')!==$catalog['checksum_sha256']
        || synthWorldKnowledgeAssertCatalogComplete($db,$catalog['manifest']['catalog_id'],$catalog['manifest']['catalog_version'])!==count($catalog['rows']))
        throw new RuntimeException('Fallout lore catalogue coverage or checksum is incomplete');
    $expected=[];
    foreach ($catalog['rows'] as $row) $expected[]='('.$literal($row['canonical_topic']).','.$literal($row['content_hash']).')';
    if ($expected!==[]) {
        $result=$db->fetchOne('WITH expected(topic,content_hash) AS (VALUES '.implode(',',$expected).') SELECT count(*) AS n FROM expected WHERE EXISTS (SELECT 1 FROM synth.worldknowledge installed WHERE installed.source_kind=\'factory\' AND installed.catalog_id='.$id.' AND installed.catalog_version='.$version.' AND installed.canonical_topic=expected.topic AND installed.content_hash=expected.content_hash)');
        if (!is_array($result) || intval($result['n'] ?? -1)!==count($catalog['rows']))
            throw new RuntimeException('Fallout lore source hashes are incomplete');
    }
}

/** Install only the reviewed SQL release; never autofill or merge encountered profiles. */
function synthInstallFalloutCatalogueSql(object $db,string $root): array
{
    $release=synthLoadFalloutCatalogueSql($root);
    if (!($db instanceof SynthFalloutCatalogueSqlConnection)) {
        if (!method_exists($db,'getCatalogueSqlConnection'))
            throw new InvalidArgumentException('Fallout catalogue SQL requires the PostgreSQL adapter');
        // Recovery is permitted before BEGIN only. All later work bypasses reconnecting wrappers.
        $db=new SynthFalloutCatalogueSqlConnection($db->getCatalogueSqlConnection());
    }
    if ($db->isTransactionIdle()!==true) throw new LogicException('Fallout catalogue SQL requires an idle transaction');
    $commitAttempted=false;
    try {
        if ($db->execQuery('BEGIN')===false) throw new RuntimeException('Unable to begin Fallout catalogue SQL migration');
        if ($db->execQuery("SET LOCAL statement_timeout='30s'")===false
            || $db->execQuery("SET LOCAL lock_timeout='5s'")===false)
            throw new RuntimeException('Unable to bound Fallout catalogue SQL migration');
        if ($db->execQuery('LOCK TABLE synth.database_versioning IN SHARE ROW EXCLUSIVE MODE')===false)
            throw new RuntimeException('Unable to lock Fallout catalogue migration state');
        $installed=$db->fetchOne("SELECT max(version) AS version FROM synth.database_versioning WHERE tablename='fallout4_catalogue_sql'");
        if (!is_array($installed) || !array_key_exists('version',$installed))
            throw new RuntimeException('Unable to inspect Fallout catalogue migration version');
        if ($installed['version']!==null && intval($installed['version'])>SYNTH_FALLOUT_CATALOGUE_SQL_VERSION)
            throw new RuntimeException('A newer Fallout catalogue SQL migration is installed; refusing an older release');
        if ($db->execQuery('LOCK TABLE synth.worldknowledge_catalogs IN SHARE ROW EXCLUSIVE MODE')===false)
            throw new RuntimeException('Unable to lock Fallout catalogue selection');
        $activeBefore=$db->fetchOne('SELECT catalog_id,catalog_version FROM synth.worldknowledge_catalogs WHERE is_active');
        if ($db->execQuery($release['sql'])===false) throw new RuntimeException('Fallout catalogue SQL did not execute cleanly');
        synthFalloutCatalogueSqlAssertInstalled($db,$release);
        $activeAfter=$db->fetchOne('SELECT catalog_id,catalog_version FROM synth.worldknowledge_catalogs WHERE is_active');
        $ownId=$release['manifest']['catalog']['catalog_id'];
        if (($activeBefore['catalog_id'] ?? $ownId)!==$ownId) {
            if ($activeAfter!==$activeBefore) throw new RuntimeException('Fallout catalogue changed another active catalogue');
        } elseif (($activeAfter['catalog_id'] ?? null)!==$ownId
            || ($activeAfter['catalog_version'] ?? null)!==$release['manifest']['catalog']['catalog_version']) {
            throw new RuntimeException('Fallout catalogue release was not activated');
        }
        if ($db->execQuery('INSERT INTO synth.database_versioning(tablename,version) SELECT $1,$2 WHERE NOT EXISTS (SELECT 1 FROM synth.database_versioning WHERE tablename=$1)',
                ['fallout4_catalogue_sql',SYNTH_FALLOUT_CATALOGUE_SQL_VERSION])===false
            || $db->execQuery('UPDATE synth.database_versioning SET version=GREATEST(version,$2) WHERE tablename=$1',
                ['fallout4_catalogue_sql',SYNTH_FALLOUT_CATALOGUE_SQL_VERSION])===false)
            throw new RuntimeException('Unable to record Fallout catalogue SQL migration version');
        $db->assertTransactionCurrent();
        $commitAttempted=true;
        if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Fallout catalogue SQL commit was not acknowledged');
    } catch (Throwable $error) {
        if (!$commitAttempted) {
            // A lost or reset original backend cannot be rolled back through a new session.
            try { $db->execQuery('ROLLBACK'); } catch (Throwable) {}
        }
        else throw new RuntimeException('Fallout catalogue commit outcome is uncertain; verify the database version before retrying',0,$error);
        throw $error;
    }
    return ['migration_version'=>SYNTH_FALLOUT_CATALOGUE_SQL_VERSION,'counts'=>$release['manifest']['counts'],
        'catalog'=>$release['manifest']['catalog'],'profiles_filled'=>0,'custom_content_preserved'=>true];
}

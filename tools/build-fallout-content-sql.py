#!/usr/bin/env python3
"""Generate the reviewed Fallout catalogue SQL, or verify its source parity."""
import argparse
import csv
import hashlib
import json
import re
from pathlib import Path

VERSION = 20261009002
FIELDS = ['core', 'npc_static_bio', 'worldknowledge_tags', 'personality',
          'occupation', 'appearance', 'skills', 'speechstyle', 'goals']
BIO_FIELDS = ['npc_name', 'core', 'worldknowledge_tags', 'npc_static_bio', 'personality',
              'appearance', 'relationships', 'occupation', 'skills', 'speechstyle',
              'goals', 'voiceid', 'gender', 'race', 'refid']
LORE_FIELDS = ['topic', 'aliases', 'topic_desc', 'knowledge_class', 'topic_desc_basic',
               'knowledge_class_basic', 'tags', 'category']
SOURCES = ['data/fallout4_content_pack.json', 'data/fallout4_named_bios.csv',
           'data/fallout4_common_descriptions.csv', 'data/fallout_worldknowledge_parity_v1.csv',
           'data/fallout_worldknowledge_manifest.json']
PLUGINS = {'Fallout4.esm', 'DLCRobot.esm', 'DLCworkshop01.esm', 'DLCworkshop02.esm',
           'DLCworkshop03.esm', 'DLCCoast.esm', 'DLCNukaWorld.esm'}
POSTWAR_VAULT_118 = {'fo4_pearl', 'fo4_maxwell', 'fo4_bert_riggs',
                    'fo4_gilda_broscoe', 'fo4_keith_mckinney', 'fo4_santiago_avida'}


def sha(content):
    return hashlib.sha256(content).hexdigest()


def compact(value):
    # Match PHP's JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE content hashes.
    return json.dumps(value, ensure_ascii=False, separators=(',', ':')).replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')


def literal(value):
    if value is None:
        return 'NULL'
    if isinstance(value, bool):
        return 'TRUE' if value else 'FALSE'
    if isinstance(value, int):
        return str(value)
    assert isinstance(value, str) and '\0' not in value
    value.encode('utf-8', errors='strict')
    return "'" + value.replace("'", "''") + "'"


def values(rows):
    return ',\n'.join('(' + ','.join(literal(value) for value in row) + ')' for row in rows)


def read_csv(path, width, header=None):
    assert not path.read_bytes().startswith(b'\xef\xbb\xbf'), 'Unexpected BOM'
    with path.open(encoding='utf-8', newline='') as stream:
        rows=list(csv.reader(stream, strict=True))
    if header is not None:
        assert rows.pop(0) == header, 'Unexpected CSV header'
    assert all(len(row) == width for row in rows), 'Invalid CSV width'
    return rows


def build(root):
    pack=json.loads((root/SOURCES[0]).read_text(encoding='utf-8'))
    manifest=json.loads((root/SOURCES[4]).read_text(encoding='utf-8'))
    bios=read_csv(root/SOURCES[1],15,BIO_FIELDS)
    items_csv=read_csv(root/SOURCES[2],4,['plugin','baseid','name','description'])
    lore=read_csv(root/SOURCES[3],8,LORE_FIELDS)
    assert pack['schema']=='synth.fallout4-content.v1'
    assert len(pack['npcs'])==len(bios)==204 and len(pack['items'])==len(items_csv) and 83 < len(items_csv) <= 1000
    assert len(lore)==manifest['row_count']==125
    assert sha((root/SOURCES[3]).read_bytes())==manifest['checksum_sha256']
    assert manifest['editorial_review']['status']=='approved'
    bio_by_name={row[0]:dict(zip(BIO_FIELDS,row)) for row in bios}
    assert len(bio_by_name)==204, 'Duplicate biography key'
    npc_rows=[]
    map_rows=[]
    keys=set()
    for npc in pack['npcs']:
        name=npc['npc_name']
        assert re.fullmatch(r'[a-z0-9_]{1,100}',name)
        assert set(npc['fields'])==set(FIELDS)
        assert all(npc['fields'][field]==bio_by_name[name][field] for field in FIELDS)
        assert all(bio_by_name[name][field]=='' for field in ['relationships','voiceid','gender','race','refid'])
        assert npc['plugin'] in PLUGINS and isinstance(npc['is_nonverbal_creature'],bool)
        assert npc['source_url'].startswith('https://')
        npc_rows.append([name]+[npc['fields'][field] for field in FIELDS])
        reference=npc.get('mapping_kind','base')=='reference'
        ids=[npc['reference_local_formid']] if reference else [npc['baseid']]+npc.get('base_aliases',[])
        for form_id in ids:
            assert re.fullmatch(r'00[0-9A-F]{6}',form_id) and form_id!='00000000'
            key=(reference,npc['plugin'].lower(),form_id)
            assert key not in keys, 'Duplicate mapping identity'
            keys.add(key)
            map_rows.append([name,None if reference else npc['plugin'],None if reference else form_id,
                             npc['plugin'] if reference else None,form_id if reference else None,
                             npc['display_name'],npc['is_nonverbal_creature']])
    assert len(map_rows)==207
    item_rows=[[item[field] for field in ['plugin','baseid','name','description']] for item in pack['items']]
    assert item_rows==items_csv, 'Equipment CSV differs from canonical content'
    assert len({tuple(row[:2]) for row in item_rows})==len(item_rows)
    catalog_id=manifest['catalog_id']
    catalog_version=manifest['catalog_version']
    assert re.fullmatch(r'[a-z0-9][a-z0-9._-]{2,100}',catalog_id)
    assert re.fullmatch(r'[a-z0-9][a-z0-9._-]{0,100}',catalog_version)
    topics=set()
    lore_rows=[]
    for source in lore:
        row=dict(zip(LORE_FIELDS,[value.strip(' \t\n\r\v\0') for value in source]))
        assert re.fullmatch(r'[a-z0-9_]+',row['topic']) and row['topic'] not in topics
        topics.add(row['topic'])
        lore_rows.append([row[field] for field in LORE_FIELDS]+[row['topic'],sha(compact(row).encode('utf-8'))])
    schema=(root/'lib/core/database_schema/bio_template_actor_map.sql').read_text(encoding='utf-8')
    sql='''-- Generated Fallout 4 catalogue. Run tools/build-fallout-content-sql.py to rebuild.
-- Authored sources and attribution are checked in beside this file.
-- The versioned PHP installer owns the transaction and version marker.
SET LOCAL standard_conforming_strings = on;
'''+schema+'\n'
    sql+='INSERT INTO synth.bio_templates (npc_name,'+','.join(FIELDS)+') VALUES\n'+values(npc_rows)+'\nON CONFLICT (npc_name) DO NOTHING;\n\n'
    # Repair only untouched copies of the six earlier factory templates. Any
    # operator edit to a seeded field, including the old tag list, is preserved.
    sql+='-- Correct the exclusive era tag on unchanged earlier Vault 118 templates.\n'
    for npc in pack['npcs']:
        if npc['npc_name'] not in POSTWAR_VAULT_118:
            continue
        fields=npc['fields']
        assert 'prewar' not in fields['worldknowledge_tags'].split(',')
        predicates=[('npc_name',npc['npc_name'])]
        predicates += [(field, 'prewar,'+fields[field] if field=='worldknowledge_tags' else fields[field]) for field in FIELDS]
        sql+='UPDATE synth.bio_templates SET worldknowledge_tags='+literal(fields['worldknowledge_tags'])+'\nWHERE '
        sql+=' AND '.join(field+'='+literal(value) for field,value in predicates)+';\n'
    sql+='\n'
    # Refine only exact untouched earlier factory rows; all nine authored fields guard the update.
    npc_by_name={row['npc_name']:row for row in pack['npcs']}
    for repair in pack.get('factory_appearance_repairs',[]):
        assert set(repair)=={'npc_name','previous_appearance'}
        name=repair['npc_name'];fields=npc_by_name[name]['fields']
        assert isinstance(repair['previous_appearance'],str) and repair['previous_appearance']!=fields['appearance']
        predicates=[('npc_name',name)]+[(field,repair['previous_appearance'] if field=='appearance' else fields[field]) for field in FIELDS]
        sql+='UPDATE synth.bio_templates SET appearance='+literal(fields['appearance'])+'\nWHERE '
        sql+=' AND '.join(field+'='+literal(value) for field,value in predicates)+';\n'
    sql+='\n'
    sql+='INSERT INTO synth.bio_template_actor_map (template_name,base_plugin,base_local_formid,reference_plugin,reference_local_formid,exact_name,is_nonverbal_creature) VALUES\n'+values(map_rows)+'\nON CONFLICT DO NOTHING;\n\n'
    sql+='INSERT INTO synth.descriptions (plugin,baseid,name,description) VALUES\n'+values(item_rows)+'\nON CONFLICT (plugin,baseid) DO NOTHING;\n\n'
    metadata=[catalog_id,catalog_version,manifest['display_name'],manifest['checksum_sha256'],125,compact(manifest)]
    sql+='INSERT INTO synth.worldknowledge_catalogs (catalog_id,catalog_version,display_name,checksum_sha256,row_count,manifest) VALUES\n'+values([metadata])+'''
ON CONFLICT (catalog_id,catalog_version) DO UPDATE SET
display_name=EXCLUDED.display_name, checksum_sha256=EXCLUDED.checksum_sha256,
row_count=EXCLUDED.row_count, manifest=EXCLUDED.manifest
WHERE (worldknowledge_catalogs.display_name,worldknowledge_catalogs.checksum_sha256,worldknowledge_catalogs.row_count,worldknowledge_catalogs.manifest)
IS DISTINCT FROM (EXCLUDED.display_name,EXCLUDED.checksum_sha256,EXCLUDED.row_count,EXCLUDED.manifest);

'''
    lore_columns=LORE_FIELDS+['canonical_topic','content_hash']
    # Existing per-article disabled choices survive both a retry and a new version.
    vector_fields=[('topic','A'),('aliases','A'),('topic_desc','B'),('topic_desc_basic','C')]
    vector='||'.join("setweight(to_tsvector('simple',coalesce(seed."+field+",'')),"+literal(weight)+')' for field,weight in vector_fields)
    sql+='INSERT INTO synth.worldknowledge ('+','.join(lore_columns)+',native_vector,source_kind,catalog_id,catalog_version,is_active)\nSELECT '+','.join('seed.'+field for field in lore_columns)+','+vector+",'factory',"+literal(catalog_id)+','+literal(catalog_version)+''',
COALESCE((SELECT prior.is_active FROM synth.worldknowledge prior
WHERE prior.source_kind='factory' AND prior.catalog_id='''+literal(catalog_id)+''' AND prior.canonical_topic=seed.canonical_topic
ORDER BY (prior.catalog_version='''+literal(catalog_version)+''') DESC,prior.updated_at DESC,prior.entry_id DESC LIMIT 1),TRUE)
FROM (VALUES
'''+values(lore_rows)+') AS seed('+','.join(lore_columns)+''')
ON CONFLICT (catalog_id,catalog_version,canonical_topic) WHERE source_kind='factory' DO UPDATE SET
'''+',\n'.join(field+'=EXCLUDED.'+field for field in LORE_FIELDS+['content_hash','native_vector'])+''',
updated_at=CURRENT_TIMESTAMP
WHERE ('''+','.join('worldknowledge.'+field for field in LORE_FIELDS+['content_hash','native_vector'])+') IS DISTINCT FROM ('+','.join('EXCLUDED.'+field for field in LORE_FIELDS+['content_hash','native_vector'])+''');

-- Retain unrelated catalogue rows and any explicit foreign active selection.
DO $catalogue_activation$
BEGIN
IF NOT EXISTS (SELECT 1 FROM synth.worldknowledge_catalogs WHERE is_active AND catalog_id<>'''+literal(catalog_id)+''') THEN
  UPDATE synth.worldknowledge_catalogs SET is_active=FALSE
    WHERE is_active AND catalog_id='''+literal(catalog_id)+' AND catalog_version<>'+literal(catalog_version)+''';
  UPDATE synth.worldknowledge_catalogs SET is_active=TRUE,activated_at=COALESCE(activated_at,CURRENT_TIMESTAMP)
    WHERE NOT is_active AND catalog_id='''+literal(catalog_id)+' AND catalog_version='+literal(catalog_version)+''';
END IF;
END;
$catalogue_activation$;
'''
    sql=sql.replace('\r\n','\n')
    release={'schema':'synth.fallout4-catalogue-sql.v1','migration_version':VERSION,
             'sql_file':'fallout4_catalogue_seed.sql','sql_sha256':sha(sql.encode('utf-8')),
             'source_files':{path:sha((root/path).read_bytes()) for path in SOURCES},
             'counts':{'npcs':204,'mappings':207,'items':len(item_rows),'lore':125},
             'catalog':{'catalog_id':catalog_id,'catalog_version':catalog_version}}
    return sql,json.dumps(release,ensure_ascii=False,indent=2)+'\n'


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check',action='store_true',help='Fail if checked-in SQL or manifest differs from the sources')
    options=parser.parse_args()
    root=Path(__file__).resolve().parent.parent
    sql,manifest=build(root)
    outputs={'data/fallout4_catalogue_seed.sql':sql,'data/fallout4_catalogue_sql_manifest.json':manifest}
    for relative,content in outputs.items():
        target=root/relative
        if options.check:
            assert target.is_file() and target.read_bytes()==content.encode('utf-8'),f'Stale generated catalogue: {relative}'
        else:
            target.write_text(content,encoding='utf-8',newline='\n')
    print('Fallout catalogue SQL/source parity verified.' if options.check else 'Generated catalogue SQL and manifest from validated sources.')


if __name__=='__main__':
    main()

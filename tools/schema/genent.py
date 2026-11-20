"""Generate read-only mapped entities, system classes and read DAOs from the baseline structure manifest.

usage: genent.py [--root <repo>] table[,table...]   (public.core_* tables with a primary key)
"""
import re,sys,os,collections
import os
def _root():
    """Repo root: --root <dir> (consumed from argv) or two levels above this script."""
    if '--root' in sys.argv:
        i=sys.argv.index('--root'); r=sys.argv[i+1]; del sys.argv[i:i+2]; return r.replace(chr(92),'/').rstrip('/')+'/'
    return os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..')).replace(chr(92),'/')+'/'
ROOT=_root()
G=ROOT+'core/dbmanager/migrations/Version20261117000000.structure.txt'
lines=open(G,encoding='utf8').read().split('\n')
cols=collections.OrderedDict(); pk={}
for l in lines:
    p=l.split('|')
    if p[0]=='column' and p[1]=='public': cols.setdefault(p[2],[]).append(p[3:])
    if p[0]=='constraint' and p[1]=='public' and p[4]=='p': pk[p[2]]=[c.strip() for c in re.search(r"PRIMARY KEY \((.*)\)",p[6]).group(1).split(',')]
def camel(s,first=False):
    parts=s.split('_'); r=parts[0]+''.join(w.capitalize() for w in parts[1:])
    return r[0].upper()+r[1:] if first else r
SKIP_UDT={'vector','tsvector','bytea','point','USER-DEFINED'}
def mapping(c):
    name,dtype,udt,nullable,default,maxlen=(c+['']*6)[:6]
    if dtype=='ARRAY' or dtype=='USER-DEFINED' or udt in SKIP_UDT or dtype in('point','tsvector','bytea'): return None
    n=nullable=='YES'
    t=udt
    if t in('int4',): return ("'integer'",'int',n,'')
    if t=='int2': return ("'smallint'",'int',n,'')
    if t=='int8': return ("'bigint'",'int|string',n,'')
    if t=='text': return ("'text'",'string',n,'')
    if t=='varchar': return ("'string'",'string',n,(", length: %s"%maxlen) if maxlen else '')
    if t=='bpchar': return ("'string'",'string',n,", length: %s, options: ['fixed' => true]"%maxlen)
    if t=='bool': return ("'boolean'",'bool',n,'')
    if t=='jsonb': return ("'json'",'mixed',n,", options: ['jsonb' => true]")
    if t=='json': return ("'json'",'mixed',n,'')
    if t=='numeric': return ("'decimal'",'string',n,', precision: 38, scale: 10')
    if t in('float8','float4'): return ("'float'",'float',n,'')
    if t=='timestamptz': return ('PreciseDateTimeType::NAME','DateTimeImmutable',n,'')
    if t=='timestamp': return ("'datetime_immutable'",'DateTimeImmutable',n,'')
    if t=='date': return ("'date_immutable'",'DateTimeImmutable',n,'')
    if t=='uuid': return ("'guid'",'string',n,'')
    return None
def gen(table):
    if table not in pk: print('NO PK',table); return
    cls=camel(table[5:],True)
    mapped=[];skipped=[]
    for c in cols[table]:
        m=mapping(c)
        (mapped if m else skipped).append((c,m))
    usesdt=any(m[1]=='DateTimeImmutable' for c,m in mapped)
    precise=any(m[0].startswith('Precise') for c,m in mapped)
    uses=[]
    if usesdt: uses.append('use DateTimeImmutable;')
    uses.append('use Doctrine\\ORM\\Mapping as ORM;')
    if precise: uses.append('use Synth\\Core\\DbManager\\Types\\PreciseDateTimeType;')
    out=['<?php','','// The mapped row of public.%s. Read-only mapping: reads go through %sDao; writes stay in the'%(table,cls),'// statements that own them, so Doctrine never flushes it.']
    if skipped: out.append('// Not mapped (DAO-only or unsupported types): '+', '.join(c[0] for c,m in skipped)+'.')
    out+=['','declare(strict_types=1);','','namespace Synth\\Core\\Entities\\Base;','']+uses+['','#[ORM\\Entity(readOnly: true)]',"#[ORM\\Table(name: '%s', schema: 'public')]"%table,'class Abstract%s'%cls,'{']
    for c,m in mapped:
        col=c[0]; prop=camel(col)
        typ,php,n,extra=m
        if col in pk[table]: out.append('    #[ORM\\Id]')
        np='' if camel(col)==col else "name: '%s', "%col
        if np=='' and False: pass
        namepart="name: '%s', "%col if prop!=col else ''
        out.append("    #[ORM\\Column(%stype: %s%s%s)]"%(namepart,typ,', nullable: true' if n else '',extra))
        if php=='mixed': out.append("    protected mixed $%s = null;"%prop)
        elif php=='DateTimeImmutable': out.append("    protected %sDateTimeImmutable $%s%s;"%('?' if n else '',prop,' = null' if n else ''))
        elif n: out.append("    protected ?%s $%s = null;"%(php,prop) if '|' not in php else "    protected %s|null $%s = null;"%(php,prop))
        else: out.append("    protected %s $%s;"%(php,prop))
        out.append('')
    out+=['    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */','    public function toArray(): array','    {','        return [']
    for c,m in mapped: out.append("            '%s' => $this->%s,"%(c[0],camel(c[0])))
    out+=['        ];','    }','}','']
    open(ROOT+'core/entities/base/Abstract%s.php'%cls,'w',encoding='utf8',newline='').write('\n'.join(out))
    sysc='''<?php

// One row of %(table)s as pages and managers see it, copied out of the mapped row so changing it never touches the database.

declare(strict_types=1);

namespace Synth\\Core\\Entities\\System;

use Synth\\Core\\Entities\\Base\\Abstract%(cls)s;

/** One row of %(table)s. */
final class %(cls)s extends Abstract%(cls)s
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(Abstract%(cls)s $mapped): self
    {
        return self::copyOf($mapped);
    }
}
'''%dict(table=table,cls=cls)
    open(ROOT+'core/entities/system/%s.php'%cls,'w',encoding='utf8',newline='').write(sysc)
    keys=[camel(k) for k in pk[table]]
    cmap={k:next(m for c,m in mapped if c[0]==k) for k in pk[table]}
    params=', '.join('%s $%s'%('int|string' if cmap[k][1]=='int|string' else cmap[k][1] if cmap[k][1] in('int','string','bool','float') else 'string',camel(k)) for k in pk[table])
    idarr=', '.join("'%s' => $%s"%(camel(k),camel(k)) for k in pk[table]) if len(keys)>1 else '$%s'%keys[0]
    order=', '.join("'%s' => 'ASC'"%camel(k) for k in pk[table])
    dao='''<?php

// Reads %(table)s. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\\Core\\DbManager\\Dao;

use Synth\\Core\\Entities\\Base\\Abstract%(cls)s;

/** Finds rows of %(table)s. */
final class %(cls)sDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(%(params)s): ?Abstract%(cls)s
    {
        return $this->fresh()->find(Abstract%(cls)s::class, %(idarr)s);
    }

    /** Rows ordered by primary key, at most $limit. @return list<Abstract%(cls)s> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(Abstract%(cls)s::class)->findBy([], [%(order)s], max(1, $limit)));
    }
}
'''%dict(table=table,cls=cls,params=params,idarr=idarr if len(keys)==1 else '['+idarr+']',order=order)
    open(ROOT+'core/dbmanager/dao/%sDao.php'%cls,'w',encoding='utf8',newline='').write(dao)
    print('gen',table,cls,'skipped:',[c[0] for c,m in skipped])
    return cls
if __name__=='__main__':
    res=[gen(t) for t in sys.argv[1].split(',')]
    print(','.join(c for c in res if c))

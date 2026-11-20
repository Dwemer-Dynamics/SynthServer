import sys,re
import os
def _root():
    """Repo root: --root <dir> (consumed from argv) or two levels above this script."""
    if '--root' in sys.argv:
        i=sys.argv.index('--root'); r=sys.argv[i+1]; del sys.argv[i:i+2]; return r.replace(chr(92),'/').rstrip('/')+'/'
    return os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..')).replace(chr(92),'/')+'/'
ROOT=_root()
f=ROOT+'core/dbmanager/Database.php'
t=open(f,encoding='utf8',newline='').read()
names=[n+'Dao' for n in sys.argv[1].split(',')]
for n in names:
    if 'Dao\\%s;'%n in t: continue
    t=t.replace("use Synth\\Core\\DbManager\\Types\\PreciseDateTimeType;","use Synth\\Core\\DbManager\\Dao\\%s;\nuse Synth\\Core\\DbManager\\Types\\PreciseDateTimeType;"%n,1)
    m=re.search(r"public const DAOS = \[(.*?)\n    \];",t,re.S)
    body=m.group(1)
    t=t[:m.start(1)]+body+"\n        %s::class,"%n+t[m.end(1):]
# keep the use list sorted
us=re.findall(r"^use Synth\\Core\\DbManager\\Dao\\\w+;\n",t,re.M)
block=''.join(us)
first=t.index(us[0]); last=t.index(us[-1])+len(us[-1])
t=t[:first]+''.join(sorted(set(us)))+t[last:]
open(f,'w',encoding='utf8',newline='').write(t)
print('ok')

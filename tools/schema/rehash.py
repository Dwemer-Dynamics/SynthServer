import json,subprocess,os,re,sys
import os
def _root():
    """Repo root: --root <dir> (consumed from argv) or two levels above this script."""
    if '--root' in sys.argv:
        i=sys.argv.index('--root'); r=sys.argv[i+1]; del sys.argv[i:i+2]; return r.replace(chr(92),'/').rstrip('/')+'/'
    return os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..')).replace(chr(92),'/')+'/'
ROOT=_root()
os.chdir(ROOT)
def hashes(): return json.loads(subprocess.check_output(['php','tools/schema-check.php','--hashes']))['migrations']
h=hashes()
m='core/dbmanager/migrations/Version20261117000000.php'
s=open(m,encoding='utf8',newline='').read()
s=re.sub(r"(FRESH_INSTALL_SHA256\s*=\s*')[0-9a-f]{64}'", r"\g<1>"+h['Version20261117000000']['files']['Version20261117000000.fresh.sql']+"'", s)
open(m,'w',encoding='utf8',newline='').write(s)
h=hashes()
p='core/dbmanager/migrations/schema-manifest.json'
d=json.load(open(p,encoding='utf8'))
def n(f): return sum(1 for l in open(f,encoding='utf8') if l.strip() and not l.startswith('#'))
for v,e in d['migrations'].items():
    e['files']=h[v]['files']; e['structure']['entries']=n('core/dbmanager/migrations/'+e['structure']['file'])
open(p,'w',encoding='utf8',newline='').write(json.dumps(d,indent=4)+'\n')

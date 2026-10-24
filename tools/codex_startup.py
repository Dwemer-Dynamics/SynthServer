# SPDX-License-Identifier: GPL-3.0-only
"""Refresh an updated desktop executable only after isolation and ChatGPT readiness."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import tempfile
import uuid

import codex_bridge as bridge
from codex_isolation import verify_isolation


class StartupError(RuntimeError):
    def __init__(self,code):self.code=code;super().__init__(code)


def resolve_cli(configured):
    cli=Path(configured).absolute()
    root=cli.parent.parent
    # Desktop updates replace a hash-named directory inside this exact installation.
    desktop=cli.name=='codex.exe' and root.name=='bin' and root.parent.name=='Codex' and root.parent.parent.name=='OpenAI'
    candidates=[]
    if desktop and root.is_dir() and root.resolve()==root:
        for folder in root.iterdir():
            p=folder/'codex.exe'
            if re.fullmatch('[0-9a-f]{8,64}',folder.name) and not folder.is_symlink() and p.is_file() and not p.is_symlink() and p.resolve()==p:
                candidates.append(p)
    elif cli.is_file() and not cli.is_symlink() and cli.resolve()==cli:
        return cli
    if not candidates:raise StartupError('codex_executable_missing')
    return max(candidates,key=lambda p:(p.parent.stat().st_mtime_ns,p.parent.name))


def private_write(path,data):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())


def atomic_write(path,data):
    stage=path.with_name(path.name+'.stage-'+uuid.uuid4().hex)
    try:private_write(stage,data);os.replace(stage,path)
    finally:
        if stage.exists():stage.unlink()


def prepare_startup(config_path,check_only=False):
    config_path=Path(config_path)
    if config_path.is_symlink():raise StartupError('unsafe_configuration')
    try:
        original=config_path.read_bytes()
        if len(original)>65536:raise ValueError()
        config=bridge.strict_json(original.decode('utf-8'))
        address=ipaddress.ip_address(config['bind'])
        if not address.is_private or address.is_unspecified or not config.get('bridge_key'):raise ValueError()
        proof_path=Path(config['proof'])
        if not proof_path.is_absolute() or proof_path==config_path or proof_path.is_symlink() or proof_path.parent.resolve()!=proof_path.parent:raise ValueError()
        if not isinstance(config['windows_cwd'],str) or not config['windows_cwd'] or not Path(config['cwd']).is_dir():raise ValueError()
    except Exception:raise StartupError('invalid_private_configuration') from None
    selected=resolve_cli(config['cli']);working=dict(config,cli=str(selected))
    try:
        proof=verify_isolation(working)
        if not proof.get('isolation_passed') or proof.get('tool_count')!=0:raise ValueError()
    except Exception:raise StartupError('isolation_failed') from None
    encoded=(json.dumps(proof,indent=2)+'\n').encode()
    with tempfile.TemporaryDirectory(prefix='.synth-chatgpt-probe-',dir=config_path.parent) as temporary:
        probe_path=Path(temporary)/'proof.json';private_write(probe_path,encoded)
        probe=dict(working,proof=str(probe_path))
        fixture={'model':'gpt-6.1-sol','messages':[{'role':'user','content':'Synthetic startup check only. Return {"synth_startup":"ok"}.'}],
                 'stream':False,'max_tokens':64,'response_format':{'type':'json_object'}}
        try:
            reply=bridge.codex_generate(fixture,probe)
            if json.loads(reply)!={'synth_startup':'ok'}:raise ValueError()
        except Exception:raise StartupError('chatgpt_signin_or_reply_failed') from None
    try:
        if proof['fingerprint']!=bridge.isolation_fingerprint(selected):raise ValueError()
    except Exception:raise StartupError('executable_changed_during_startup') from None
    report={'status':'ready','model':'gpt-6.1-sol','provider_mode':'chatgpt_only','isolation_passed':True,'tool_count':0,
            'primary_reply_verified':True,'executable_refreshed':str(selected)!=config['cli'],'check_only':check_only}
    if not check_only:
        previous_proof=proof_path.read_bytes() if proof_path.exists() else None
        updated=(json.dumps(working,indent=2)+'\n').encode()
        try:
            if config_path.read_bytes()!=original:raise StartupError('configuration_changed_during_startup')
            backup=config_path.parent/'backups'/('chatgpt-startup-'+uuid.uuid4().hex)
            backup.mkdir(mode=0o700,parents=True)
            private_write(backup/'config-before.json',original)
            if previous_proof is not None:private_write(backup/'proof-before.json',previous_proof)
            atomic_write(proof_path,encoded)
            try:atomic_write(config_path,updated)
            except BaseException:
                if previous_proof is not None:atomic_write(proof_path,previous_proof)
                else:proof_path.unlink()
                raise
        except StartupError:raise
        except Exception:raise StartupError('private_configuration_update_failed') from None
    working['_startup_verified']=True;working['_startup_fingerprint']=proof['fingerprint']
    working['_startup_signature']=bridge.runtime_signature(selected,proof_path)
    return working,report


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--config',type=Path,default=Path.home()/'.local/share/synth-stu/codex-bridge.json');parser.add_argument('--check-only',action='store_true');parser.add_argument('--health-url',action='store_true')
    args=parser.parse_args()
    try:
        if args.health_url:
            try:
                config=bridge.strict_json(args.config.read_text());address=ipaddress.ip_address(config['bind'])
                if not address.is_private or address.is_unspecified or address.version!=4:raise ValueError()
            except Exception:raise StartupError('invalid_private_configuration') from None
            print('http://'+str(address)+':8033/health')
        else:_,report=prepare_startup(args.config,args.check_only);print(json.dumps(report))
    except StartupError as error:print(json.dumps({'status':'blocked','code':error.code,'provider_mode':'chatgpt_only'}));raise SystemExit(1)

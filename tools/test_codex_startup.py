# SPDX-License-Identifier: GPL-3.0-only
import json
import os
from pathlib import Path
import tempfile
import subprocess
import sys
import threading
import unittest
from unittest.mock import patch
from http.server import ThreadingHTTPServer
from urllib.request import Request,build_opener,ProxyHandler
from urllib.error import HTTPError

import codex_bridge as bridge
import codex_startup as startup


class StartupTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.addCleanup(self.temporary.cleanup)
        self.root=Path(self.temporary.name);self.bin=self.root/'OpenAI/Codex/bin';self.bin.mkdir(parents=True)
        self.old=self.binary('a'*16,10);self.new=self.binary('b'*16,20)
        self.proof=self.root/'proof.json';self.proof.write_text('{"old":true}')
        cwd=self.root/'empty';cwd.mkdir()
        self.config={'cli':str(self.old),'proof':str(self.proof),'cwd':str(cwd),'windows_cwd':'C:\\empty',
                     'bind':'127.0.0.1','bridge_key':'synthetic-local-key','fallback_key':'preserve-unused-value','unrelated':{'keep':True}}
        self.path=self.root/'config.json';self.path.write_text(json.dumps(self.config))
        self.original=self.path.read_bytes();self.original_proof=self.proof.read_bytes()
    def binary(self,name,stamp):
        folder=self.bin/name;folder.mkdir();p=folder/'codex.exe';p.write_bytes(b'fixture '+name.encode());os.utime(folder,(stamp,stamp));return p
    def patches(self):
        return patch.object(startup,'verify_isolation',return_value={'isolation_passed':True,'tool_count':0,'fingerprint':'current'}),patch.object(bridge,'isolation_fingerprint',return_value='current'),patch.object(bridge,'codex_generate',return_value='{"synth_startup":"ok"}')
    def test_removed_desktop_binary_is_rediscovered(self):
        self.old.unlink();self.assertEqual(startup.resolve_cli(self.old),self.new)
    def test_new_desktop_install_wins_even_before_old_binary_removed(self):
        self.assertEqual(startup.resolve_cli(self.old),self.new)
    def test_missing_or_unrelated_executable_is_not_guessed_from_path(self):
        self.old.unlink();self.new.unlink();self.binary('not-an-install',30)
        with self.assertRaises(startup.StartupError):startup.resolve_cli(self.old)
        with self.assertRaises(startup.StartupError):startup.resolve_cli(self.root/'missing.exe')
    def test_symlinked_candidate_is_not_selected(self):
        if os.name=='nt':self.skipTest('POSIX link boundary exercised in WSL')
        target=self.root/'other';target.write_bytes(b'outside');self.new.unlink();self.new.symlink_to(target)
        self.assertEqual(startup.resolve_cli(self.old),self.old)
    def test_isolation_failure_changes_nothing_and_does_not_probe_chatgpt(self):
        with patch.object(startup,'verify_isolation',side_effect=RuntimeError('private diagnostic')),patch.object(bridge,'codex_generate') as generate:
            with self.assertRaisesRegex(startup.StartupError,'isolation_failed'):startup.prepare_startup(self.path)
            generate.assert_not_called()
        self.assertEqual(self.path.read_bytes(),self.original);self.assertEqual(self.proof.read_bytes(),self.original_proof)
    def test_nonzero_tools_are_refused_even_if_proof_claims_success(self):
        with patch.object(startup,'verify_isolation',return_value={'isolation_passed':True,'tool_count':1}):
            with self.assertRaisesRegex(startup.StartupError,'isolation_failed'):startup.prepare_startup(self.path)
    def test_signin_failure_changes_neither_config_nor_proof(self):
        a,b,c=self.patches()
        with a,b,c as generate:
            generate.side_effect=RuntimeError('private account diagnostic')
            with self.assertRaisesRegex(startup.StartupError,'chatgpt_signin_or_reply_failed'):startup.prepare_startup(self.path)
        self.assertEqual(self.path.read_bytes(),self.original);self.assertEqual(self.proof.read_bytes(),self.original_proof)
    def test_success_refreshes_only_cli_and_proof_and_preserves_private_backup(self):
        a,b,c=self.patches()
        with a,b,c:config,report=startup.prepare_startup(self.path)
        saved=json.loads(self.path.read_text());self.assertEqual(saved,dict(self.config,cli=str(self.new)))
        self.assertTrue(config['_startup_verified']);self.assertEqual(report['provider_mode'],'chatgpt_only')
        backup=list((self.root/'backups').iterdir());self.assertEqual(len(backup),1)
        self.assertEqual((backup[0]/'config-before.json').read_bytes(),self.original)
        self.assertEqual((backup[0]/'proof-before.json').read_bytes(),self.original_proof)
        if os.name!='nt':self.assertEqual(self.path.stat().st_mode&0o777,0o600)
    def test_check_only_keeps_configuration_proof_and_backup_untouched(self):
        a,b,c=self.patches()
        with a,b,c:_,report=startup.prepare_startup(self.path,True)
        self.assertTrue(report['check_only']);self.assertEqual(self.path.read_bytes(),self.original)
        self.assertEqual(self.proof.read_bytes(),self.original_proof);self.assertFalse((self.root/'backups').exists())
        self.assertFalse(list(self.root.glob('.synth-chatgpt-probe-*')))
    def test_binary_changed_during_probe_is_refused(self):
        a,b,c=self.patches()
        with a,b as fingerprint,c:
            fingerprint.return_value='changed'
            with self.assertRaisesRegex(startup.StartupError,'executable_changed_during_startup'):startup.prepare_startup(self.path)
        self.assertEqual(self.path.read_bytes(),self.original)
    def test_config_write_failure_restores_previous_proof(self):
        a,b,c=self.patches();actual=startup.atomic_write
        def fail_config(path,data):
            if path==self.path:raise OSError('fixture write refused')
            actual(path,data)
        with a,b,c,patch.object(startup,'atomic_write',side_effect=fail_config):
            with self.assertRaisesRegex(startup.StartupError,'private_configuration_update_failed'):startup.prepare_startup(self.path)
        self.assertEqual(self.path.read_bytes(),self.original);self.assertEqual(self.proof.read_bytes(),self.original_proof)
    def test_concurrent_configuration_change_is_not_overwritten(self):
        a,b,c=self.patches()
        def changed(*args):self.path.write_text('{"changed":true}');return '{"synth_startup":"ok"}'
        with a,b,c as generate:
            generate.side_effect=changed
            with self.assertRaisesRegex(startup.StartupError,'configuration_changed_during_startup'):startup.prepare_startup(self.path)
        self.assertEqual(self.path.read_text(),'{"changed":true}');self.assertEqual(self.proof.read_bytes(),self.original_proof)
    def test_health_url_cli_needs_no_inline_shell_code_and_does_not_change_config(self):
        result=subprocess.run([sys.executable,str(Path(startup.__file__)),'--config',str(self.path),'--health-url'],capture_output=True,text=True)
        self.assertEqual(result.returncode,0);self.assertEqual(result.stdout.strip(),'http://127.0.0.1:8033/health')
        self.assertEqual(self.path.read_bytes(),self.original);self.assertEqual(self.proof.read_bytes(),self.original_proof)
    def test_health_url_cli_rejects_nonprivate_bind_without_exposing_config(self):
        self.config['bind']='8.8.8.8';self.path.write_text(json.dumps(self.config))
        result=subprocess.run([sys.executable,str(Path(startup.__file__)),'--config',str(self.path),'--health-url'],capture_output=True,text=True)
        self.assertEqual(result.returncode,1);self.assertIn('invalid_private_configuration',result.stdout)
        self.assertNotIn('synthetic-local-key',result.stdout+result.stderr)
    def test_readiness_signature_detects_replacement_without_reading_binary_contents(self):
        with patch.object(bridge.Path,'read_bytes',side_effect=AssertionError('Health must not hash large binary')):
            before=bridge.runtime_signature(self.new,self.proof)
            replacement=self.new.with_suffix('.replacement');replacement.write_bytes(b'replacement');replacement.replace(self.new)
            self.assertNotEqual(before,bridge.runtime_signature(self.new,self.proof))
            self.new.unlink()
            with self.assertRaises(FileNotFoundError):bridge.runtime_signature(self.new,self.proof)


class HealthAndFailureTests(unittest.TestCase):
    def setUp(self):
        self.server=ThreadingHTTPServer(('127.0.0.1',0),bridge.Handler)
        self.server.provider=bridge.Provider({'cli':'fixture','bridge_key':'fixture','subnet':'127.0.0.0/8','_startup_verified':True,'_startup_signature':'current'})
        threading.Thread(target=self.server.serve_forever,daemon=True).start()
        self.addCleanup(self.server.server_close);self.addCleanup(self.server.shutdown)
        self.url='http://127.0.0.1:'+str(self.server.server_port);self.opener=build_opener(ProxyHandler({}))
    def test_health_requires_current_binary_and_verified_primary(self):
        with patch.object(bridge,'runtime_signature',return_value='current'),patch.object(bridge,'isolation_fingerprint',side_effect=AssertionError('Health must not hash')):
            with self.opener.open(self.url+'/health') as r:
                value=json.load(r);self.assertEqual(r.status,200);self.assertEqual(value['provider_mode'],'chatgpt_only');self.assertTrue(value['startup_verified'])
        with patch.object(bridge,'runtime_signature',return_value='changed'):
            with self.assertRaises(HTTPError) as error:self.opener.open(self.url+'/health')
            self.assertEqual(error.exception.code,503)
        self.server.provider.config['_startup_verified']=False
        with patch.object(bridge,'runtime_signature',return_value='current'):
            with self.assertRaises(HTTPError):self.opener.open(self.url+'/health')
    def test_failed_chatgpt_request_is_a_safe_error_with_no_fallback_route(self):
        data={'model':'gpt-6.1-sol','messages':[{'role':'user','content':'synthetic'}],'max_tokens':64,'stream':False}
        request=Request(self.url+'/v1/chat/completions',json.dumps(data).encode(),{'Authorization':'Bearer fixture','Content-Type':'application/json'})
        with patch.object(bridge,'codex_generate',side_effect=RuntimeError('SECRET-should-never-be-exposed')) as primary:
            with self.assertRaises(HTTPError) as error:self.opener.open(request)
            primary.assert_called_once();self.assertEqual(error.exception.code,502)
            self.assertIsNone(error.exception.headers.get('X-SYNTH-Provider'))
            body=error.exception.read().decode();self.assertIn('No fallback was used',body);self.assertNotIn('SECRET',body)


if __name__=='__main__':unittest.main()

# SPDX-License-Identifier: GPL-3.0-only
import copy
import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
from http.server import ThreadingHTTPServer
from urllib.request import Request,build_opener,ProxyHandler
from urllib.error import HTTPError

import codex_bridge as bridge
import codex_isolation as isolation
from test_codex_bridge import fixture


class SettingsRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        root=Path(self.temp.name)
        self.cli=root/'bin/codex.exe';self.cli.parent.mkdir();self.cli.write_bytes(b'fixture executable')
        settings=root/'.codex';settings.mkdir()
        self.settings=settings/'config.toml';self.settings.write_text('model_reasoning_effort="low"\n')
        self.policy=settings/'AGENTS.md';self.policy.write_text('Fixture user policy')
        self.proof=root/'proof.json';self.proof.write_text('{"original_proof":true}')
        self.config={'cli':str(self.cli),'proof':str(self.proof),'cwd':str(root),'windows_cwd':str(root),
            'bridge_key':'synthetic-key','subnet':'127.0.0.0/8','_startup_verified':True,
            '_startup_signature':bridge.runtime_signature(self.cli,self.proof),'_startup_fingerprint':'old'}
        self.provider=bridge.Provider(copy.deepcopy(self.config));self.addCleanup(self.provider.close)
        self.original_proof=self.proof.read_bytes()
        self.good={'isolation_passed':True,'tool_count':0,'contexts_verified':2,
            'warm_transport_verified':True,'fingerprint':'a'*64}
    def change_settings(self, text='model_reasoning_effort="medium"\n'):
        self.settings.write_text(text)
    def test_settings_edit_recovers_before_first_generation_without_private_file_writes(self):
        self.change_settings();edited=self.settings.read_bytes()
        self.assertEqual(self.provider.readiness()[0],'revalidation_required')
        with patch.object(isolation,'verify_isolation',return_value=self.good) as verify, \
             patch.object(bridge,'codex_generate',return_value='{"action":"Talk"}') as generate:
            self.assertEqual(self.provider.generate(fixture())[1],'codex')
            self.assertEqual(self.provider.generate(fixture())[1],'codex')
            verify.assert_called_once_with(self.provider.config,timeout=10)
            self.assertEqual(generate.call_count,2)
        self.assertEqual(self.provider.readiness()[0],'ready')
        self.assertEqual(self.proof.read_bytes(),self.original_proof)
        self.assertEqual(self.settings.read_bytes(),edited)
        self.assertEqual(self.provider.config['bridge_key'],'synthetic-key')
        self.assertFalse(list(Path(self.temp.name).rglob('*backup*')))
    def test_recovery_retires_previous_process_before_rechecking(self):
        class Old:
            closed=False
            def close(self):self.closed=True
        old=Old();self.provider.session.rpc=old
        self.change_settings()
        def verify(config,timeout):
            self.assertTrue(old.closed);self.assertIsNone(self.provider.session.rpc)
            return self.good
        with patch.object(isolation,'verify_isolation',side_effect=verify):self.provider.ensure_ready()
    def test_nonzero_tools_or_incomplete_proof_never_reach_model(self):
        for mutation in ({'tool_count':1},{'isolation_passed':False},{'contexts_verified':1},{'warm_transport_verified':False}):
            with self.subTest(mutation=mutation):
                self.change_settings(json.dumps(mutation))
                with patch.object(isolation,'verify_isolation',return_value=dict(self.good,**mutation)), \
                     patch.object(bridge,'codex_generate') as generate:
                    with self.assertRaisesRegex(bridge.BridgeReadinessError,'isolation_recheck_failed'):
                        self.provider.generate(fixture())
                    generate.assert_not_called()
        self.assertEqual(self.proof.read_bytes(),self.original_proof)
    def test_failed_stable_settings_are_not_rechecked_on_every_game_turn(self):
        self.change_settings()
        with patch.object(isolation,'verify_isolation',side_effect=RuntimeError('SECRET diagnostic')) as verify, \
             patch.object(bridge,'codex_generate') as generate:
            for _ in range(2):
                with self.assertRaisesRegex(bridge.BridgeReadinessError,'^isolation_recheck_failed$'):
                    self.provider.generate(fixture())
            verify.assert_called_once();generate.assert_not_called()
        self.assertEqual(self.provider.readiness()[0],'isolation_recheck_failed')
        self.change_settings('model_reasoning_effort="low"\n# fixed\n')
        with patch.object(isolation,'verify_isolation',return_value=self.good):self.provider.ensure_ready()
        self.assertEqual(self.provider.readiness()[0],'ready')
    def test_settings_changing_during_recheck_do_not_publish_a_ready_state(self):
        self.change_settings()
        def changed(config,timeout):
            self.change_settings('# intervening edit\n');return self.good
        with patch.object(isolation,'verify_isolation',side_effect=changed):
            with self.assertRaises(bridge.BridgeReadinessError):self.provider.ensure_ready()
        self.assertEqual(self.provider.config['_startup_signature'],self.config['_startup_signature'])
        self.assertEqual(self.provider.readiness()[0],'revalidation_required')
    def test_executable_replacement_requires_restart_and_is_not_auto_approved(self):
        self.cli.write_bytes(b'different executable')
        with patch.object(isolation,'verify_isolation') as verify:
            with self.assertRaisesRegex(bridge.BridgeReadinessError,'restart_required'):self.provider.ensure_ready()
            verify.assert_not_called()
    def test_bridge_source_replacement_requires_restart(self):
        current=bridge.runtime_signature(self.cli,self.proof)
        changed=copy.deepcopy(current);changed[1]=('different source',1,2,3,4,5)
        with patch.object(bridge,'runtime_signature',return_value=changed),patch.object(isolation,'verify_isolation') as verify:
            with self.assertRaisesRegex(bridge.BridgeReadinessError,'restart_required'):self.provider.ensure_ready()
            verify.assert_not_called()
    def test_external_proof_edit_requires_restart(self):
        self.proof.write_text('{"external_edit":true}')
        with patch.object(isolation,'verify_isolation') as verify:
            with self.assertRaisesRegex(bridge.BridgeReadinessError,'restart_required'):self.provider.ensure_ready()
            verify.assert_not_called()
    def test_added_removed_configuration_and_policy_are_detected(self):
        self.policy.unlink()
        self.assertEqual(self.provider.readiness()[0],'revalidation_required')
        with patch.object(isolation,'verify_isolation',return_value=self.good):self.provider.ensure_ready()
        self.policy.write_text('different fixture policy')
        self.assertEqual(self.provider.readiness()[0],'revalidation_required')
        with patch.object(isolation,'verify_isolation',return_value=self.good):self.provider.ensure_ready()
        self.settings.unlink()
        self.assertEqual(self.provider.readiness()[0],'revalidation_required')
    def test_unverified_startup_is_not_repaired_by_a_settings_probe(self):
        self.provider.config['_startup_verified']=False;self.change_settings()
        with patch.object(isolation,'verify_isolation') as verify:
            with self.assertRaisesRegex(bridge.BridgeReadinessError,'restart_required'):self.provider.ensure_ready()
            verify.assert_not_called()
    def test_concurrent_requests_cannot_start_two_rechecks(self):
        self.change_settings();entered=threading.Event();release=threading.Event();errors=[]
        def verify(config,timeout):entered.set();release.wait(2);return self.good
        def run():
            try:self.provider.generate(fixture())
            except Exception as error:errors.append(type(error).__name__)
        with patch.object(isolation,'verify_isolation',side_effect=verify) as checker, \
             patch.object(bridge,'codex_generate',return_value='{"action":"Talk"}'):
            worker=threading.Thread(target=run);worker.start()
            try:
                self.assertTrue(entered.wait(1))
                with self.assertRaises(BlockingIOError):self.provider.generate(fixture())
            finally:release.set();worker.join(2)
            checker.assert_called_once();self.assertFalse(errors)
    def start_http(self):
        server=ThreadingHTTPServer(('127.0.0.1',0),bridge.Handler);server.daemon_threads=True
        server.provider=self.provider
        self.addCleanup(server.server_close);self.addCleanup(server.shutdown)
        threading.Thread(target=server.serve_forever,daemon=True).start()
        return 'http://127.0.0.1:'+str(server.server_port),build_opener(ProxyHandler({}))
    def test_health_is_read_only_and_request_recovers_to_ready(self):
        self.change_settings();url,opener=self.start_http()
        with patch.object(isolation,'verify_isolation',return_value=self.good) as verify, \
             patch.object(bridge,'codex_generate',return_value='{"action":"Talk"}'):
            with self.assertRaises(HTTPError) as error:opener.open(url+'/health')
            self.assertEqual(json.load(error.exception)['status'],'revalidation_required')
            verify.assert_not_called()
            request=Request(url+'/v1/chat/completions',json.dumps(fixture()).encode(),
                {'Authorization':'Bearer synthetic-key','Content-Type':'application/json'})
            with opener.open(request) as response:
                self.assertEqual(response.headers['X-SYNTH-Provider'],'codex');response.read()
            with opener.open(url+'/health') as response:self.assertEqual(json.load(response)['status'],'ready')
            verify.assert_called_once()
    def test_failed_recheck_reports_fixed_code_without_private_exception(self):
        self.change_settings();url,opener=self.start_http()
        request=Request(url+'/v1/chat/completions',json.dumps(fixture()).encode(),
            {'Authorization':'Bearer synthetic-key','Content-Type':'application/json'})
        with patch.object(isolation,'verify_isolation',side_effect=RuntimeError('SECRET-config-detail')):
            with self.assertRaises(HTTPError) as error:opener.open(request)
        self.assertEqual(error.exception.code,503)
        body=error.exception.read().decode()
        self.assertIn('isolation_recheck_failed',body);self.assertNotIn('SECRET',body)
        self.assertIsNone(error.exception.headers.get('X-SYNTH-Provider'))

if __name__=='__main__':unittest.main()

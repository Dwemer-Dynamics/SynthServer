# SPDX-License-Identifier: GPL-3.0-only
import copy
import io
import json
import queue
import socket
import threading
import time
import unittest
from unittest.mock import patch

import codex_bridge as bridge
from codex_rpc import RPC
from test_codex_bridge import fixture
from http.server import ThreadingHTTPServer
from urllib.request import Request,build_opener,ProxyHandler


class FakeRPC:
    instances = []
    fault = None
    def __init__(self, *args):
        self.events = []
        self.calls = []
        self.closed = False
        self.number = 0
        self.__class__.instances.append(self)
    def send(self, *args, **kwargs):
        pass
    def call(self, method, params, *args):
        self.calls.append((method, copy.deepcopy(params)))
        if method == 'thread/start':
            self.number += 1
            return {'thread':{'id':'scope'+str(self.number)},'model':'gpt-6.1-sol'}
        if method == 'account/read':
            return {'account':{'type':'chatgpt'}}
        if method == 'turn/start':
            scope = params['threadId']
            self.events = [
                {'method':'item/agentMessage/delta','params':{'threadId':scope,'turnId':'turn','delta':'{"action":"Talk"}'}},
                {'method':'item/completed','params':{'threadId':scope,'turnId':'turn','item':{'type':'agentMessage','text':'{"action":"Talk"}'}}},
                {'method':'turn/completed','params':{'threadId':scope,'turn':{'id':'turn','status':'completed'}}}]
            if self.fault:
                self.__class__.fault(self, scope)
            return {'turn':{'id':'turn'}}
        return {}
    def close(self):
        self.closed = True


class WarmBridgeTests(unittest.TestCase):
    def setUp(self):
        FakeRPC.instances = []
        FakeRPC.fault = None
        self.patch = patch.object(bridge,'RPC',FakeRPC)
        self.patch.start();self.addCleanup(self.patch.stop)
        self.session = bridge.WarmSession()
        self.addCleanup(self.session.close)
        self.config = {'cli':'fixture','cwd':'empty','windows_cwd':'empty'}
    def generate(self, data=None, **kwargs):
        return bridge.codex_generate(data or fixture(),self.config,'http://stub',session=self.session,**kwargs)
    def test_warm_transport_initializes_once_but_every_input_uses_a_new_thread(self):
        first = fixture();second = fixture()
        first['messages'][0]['content']='first private synthetic scope'
        second['messages'][0]['content']='second unrelated synthetic scope'
        cold={};warm={}
        self.generate(first,timings=cold);self.generate(second,timings=warm)
        self.assertEqual(len(FakeRPC.instances),1)
        rpc=FakeRPC.instances[0]
        self.assertEqual([m for m,_ in rpc.calls].count('initialize'),1)
        starts=[p for m,p in rpc.calls if m=='thread/start']
        self.assertEqual(len(starts),2)
        self.assertTrue(all(p['ephemeral'] and p['approvalPolicy']=='never' for p in starts))
        turns=[p for m,p in rpc.calls if m=='turn/start']
        self.assertNotEqual(turns[0]['threadId'],turns[1]['threadId'])
        self.assertNotIn('first private',turns[1]['input'][0]['text'])
        self.assertNotIn('second unrelated',turns[0]['input'][0]['text'])
        self.assertFalse(cold['transport_reused']);self.assertTrue(warm['transport_reused'])
        self.assertIn('first_text_ms',warm)
    def test_late_output_from_previous_scope_closes_transport(self):
        self.generate()
        def late(rpc,scope): rpc.events[0]['params']['threadId']='scope1'
        FakeRPC.fault=late
        with self.assertRaisesRegex(RuntimeError,'thread scope'): self.generate()
        self.assertIsNone(self.session.rpc)
        self.assertTrue(FakeRPC.instances[0].closed)
    def test_previous_scope_housekeeping_is_allowed_but_not_model_output(self):
        self.generate()
        def housekeeping(rpc,scope):
            rpc.events.insert(0,{'method':'thread/status/changed','params':{'threadId':'scope1'}})
        FakeRPC.fault=housekeeping
        self.assertIn('Talk',self.generate())
    def test_wrong_turn_fails_closed(self):
        def wrong(rpc,scope): rpc.events[0]['params']['turnId']='unrelated'
        FakeRPC.fault=wrong
        with self.assertRaisesRegex(RuntimeError,'turn scope'):self.generate()
        self.assertIsNone(self.session.rpc)
    def test_tool_attempt_cannot_become_game_dialogue(self):
        def tool(rpc,scope):
            rpc.events.insert(0,{'method':'item/started','params':{'threadId':scope,'item':{'type':'commandExecution'}}})
        FakeRPC.fault=tool
        with self.assertRaisesRegex(RuntimeError,'tool item'):self.generate()
        self.assertIsNone(self.session.rpc)
    def test_bad_json_and_failed_turn_close_and_next_call_starts_fresh(self):
        for text,status in [('bad','completed'),('{"action":"Talk"}','failed')]:
            def invalid(rpc,scope):
                rpc.events[1]['params']['item']['text']=text
                rpc.events[2]['params']['turn']['status']=status
            FakeRPC.fault=invalid
            with self.assertRaises((ValueError,RuntimeError)):self.generate()
            self.assertIsNone(self.session.rpc)
        FakeRPC.fault=None
        self.assertIn('Talk',self.generate())
    def test_oversized_deltas_abort_without_waiting_for_completion(self):
        def huge(rpc,scope):rpc.events[0]['params']['delta']='x'*(bridge.MAX_OUTPUT+1)
        FakeRPC.fault=huge
        with self.assertRaisesRegex(RuntimeError,'too large'):self.generate()
        self.assertIsNone(self.session.rpc)
    def test_provider_configuration_cannot_reuse_other_transport(self):
        self.generate();self.config['windows_cwd']='different'
        with self.assertRaisesRegex(RuntimeError,'configuration changed'):self.generate()
        self.assertIsNone(self.session.rpc)
    def test_runtime_proof_cache_is_stat_bound_and_requires_warm_isolation(self):
        config=dict(self.config,proof='proof')
        proof={'isolation_passed':True,'contexts_verified':2,'warm_transport_verified':True,'fingerprint':'good'}
        with patch.object(bridge,'runtime_signature',return_value=['one']), \
             patch.object(bridge,'isolation_fingerprint',return_value='good') as fingerprint, \
             patch.object(bridge.Path,'read_text',return_value=json.dumps(proof)):
            self.session.verify(config);self.session.verify(config)
            fingerprint.assert_called_once()
        with patch.object(bridge,'runtime_signature',return_value=['two']), \
             patch.object(bridge,'isolation_fingerprint',return_value='changed'), \
             patch.object(bridge.Path,'read_text',return_value=json.dumps(proof)):
            with self.assertRaisesRegex(RuntimeError,'isolation proof'):self.session.verify(config)
        self.assertIsNone(self.session.rpc)
    def test_signature_change_during_hashing_is_rejected(self):
        proof={'isolation_passed':True,'contexts_verified':2,'warm_transport_verified':True,'fingerprint':'good'}
        with patch.object(bridge,'runtime_signature',side_effect=[['one'],['two']]), \
             patch.object(bridge,'isolation_fingerprint',return_value='good'), \
             patch.object(bridge.Path,'read_text',return_value=json.dumps(proof)):
            with self.assertRaisesRegex(RuntimeError,'runtime changed'):self.session.verify(dict(self.config,proof='proof'))
    def test_idle_retirement_releases_process_without_interrupting_busy_work(self):
        provider=bridge.Provider(self.config);self.addCleanup(provider.close)
        provider.session.rpc=FakeRPC()
        provider.lock.acquire()
        provider.retire_idle();self.assertIsNotNone(provider.session.rpc)
        provider.lock.release();provider.retire_idle();self.assertIsNone(provider.session.rpc)
    def test_provider_recycles_after_eight_requests(self):
        provider=bridge.Provider(self.config);self.addCleanup(provider.close)
        provider.session.rpc=FakeRPC();provider.session.uses=8
        old=provider.session.rpc
        with patch.object(bridge,'codex_generate',return_value='{"action":"Talk"}'):
            provider.generate(fixture())
        self.assertTrue(old.closed);self.assertIsNone(provider.session.rpc)
    def test_rpc_process_death_is_reported_immediately(self):
        rpc=RPC.__new__(RPC);rpc.q=queue.Queue();rpc.q.put({'transport_closed':True})
        with self.assertRaisesRegex(RuntimeError,'transport closed'):rpc.next(bridge.time.monotonic()+1)
    def test_cancelled_http_owner_discards_warm_transport_and_partial_text(self):
        self.generate()
        checks=[]
        def cancelled():
            checks.append(1)
            return len(checks)>=4
        with self.assertRaisesRegex(RuntimeError,'request cancelled'):
            self.generate(cancelled=cancelled)
        self.assertTrue(FakeRPC.instances[0].closed)
        self.assertIsNone(self.session.rpc)
    def test_cancellation_during_provider_wait_is_polled_and_not_retried(self):
        def waiting(rpc,scope):rpc.events=[]
        FakeRPC.fault=waiting
        checks=[]
        def cancelled():
            checks.append(1)
            return len(checks)>=6
        def next_event(deadline):raise TimeoutError()
        with patch.object(FakeRPC,'next',side_effect=next_event,create=True) as receive:
            with self.assertRaisesRegex(RuntimeError,'request cancelled'):self.generate(cancelled=cancelled)
        self.assertGreaterEqual(receive.call_count,1)
        self.assertIsNone(self.session.rpc)
    def test_rpc_never_executes_server_requests(self):
        rpc=RPC.__new__(RPC);rpc.q=queue.Queue()
        rpc.q.put({'method':'item/tool/call','id':99,'params':{}})
        rpc.proc=type('Process',(),{'stdin':io.StringIO()})()
        with self.assertRaisesRegex(RuntimeError,'unexpected server request'):rpc.next(bridge.time.monotonic()+1)
        self.assertIn('-32601',rpc.proc.stdin.getvalue())


class WarmHTTPTests(unittest.TestCase):
    def setUp(self):
        self.server=ThreadingHTTPServer(('127.0.0.1',0),bridge.Handler)
        self.server.daemon_threads=True
        self.server.provider=bridge.Provider({'bridge_key':'synthetic','subnet':'127.0.0.0/8'})
        self.addCleanup(self.server.provider.close)
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        threading.Thread(target=self.server.serve_forever,daemon=True).start()
    def test_validated_reply_exposes_only_safe_timing_metadata(self):
        self.server.provider.last_timings={'proof_ms':12.3,'setup_ms':4.5,'generation_ms':67.8,
            'total_ms':84.6,'transport_reused':True,'private':'must not be sent'}
        request=Request('http://127.0.0.1:'+str(self.server.server_port)+'/v1/chat/completions',
            json.dumps(fixture()).encode(),{'Authorization':'Bearer synthetic','Content-Type':'application/json'})
        with patch.object(self.server.provider,'generate',return_value=('{"action":"Talk"}','codex')):
            with build_opener(ProxyHandler({})).open(request,timeout=2) as response:
                self.assertEqual(response.headers['X-SYNTH-Transport'],'warm')
                self.assertIn('setup;dur=4.5',response.headers['Server-Timing'])
                self.assertNotIn('private',str(response.headers))
                self.assertIn(b'[DONE]',response.read())
    def test_closed_http_owner_is_seen_during_generation(self):
        started=threading.Event();cancelled=threading.Event()
        def generate(data,cancelled=None):
            started.set();end=time.monotonic()+2
            while time.monotonic()<end:
                if cancelled():
                    observed.set();raise RuntimeError('cancelled')
                time.sleep(0.01)
            raise AssertionError('HTTP disconnect was not observed')
        observed=cancelled
        body=json.dumps(fixture()).encode()
        client=socket.create_connection(self.server.server_address,timeout=2)
        with patch.object(self.server.provider,'generate',side_effect=generate):
            client.sendall(b'POST /v1/chat/completions HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer synthetic\r\nContent-Type: application/json\r\nContent-Length: '+str(len(body)).encode()+b'\r\n\r\n'+body)
            self.assertTrue(started.wait(1));client.close()
            self.assertTrue(observed.wait(1))

if __name__=='__main__':unittest.main()

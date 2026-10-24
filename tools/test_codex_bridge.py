# SPDX-License-Identifier: GPL-3.0-only
import copy
import json
import unittest
from unittest.mock import patch, Mock

import codex_bridge as bridge

def fixture():
    return {'model':'gpt-6.1-sol','messages':[{'role':'user','content':'Only this NPC history.'}],
            'response_format':{'type':'json_object'},'max_tokens':128,'stream':True}

class BridgeTests(unittest.TestCase):
    def test_optional_action_amount_preserves_original_schema(self):
        schema={'type':'object','properties':{'action':{'type':'string','enum':['Talk']},'amount':{'type':'integer'},
                'note':{'type':['string','null']}},'required':['action'],'additionalProperties':False}
        strict=bridge.codex_schema(schema)
        self.assertEqual(strict['required'],['action','amount','note'])
        self.assertEqual(schema['required'],['action'])
        data=fixture()
        data['response_format']={'type':'json_schema','json_schema':{'schema':schema}}
        normalized=bridge.validate_output('{"action":"Talk","amount":null,"note":null}',data,restore_optional=True)
        self.assertEqual(json.loads(normalized),{'action':'Talk','note':None})
        with self.assertRaises(Exception): bridge.validate_output('{"action":null,"amount":null}',data,restore_optional=True)
        actual=bridge.validate_output('{"action":"Talk","amount":5}',data,restore_optional=True)
        self.assertEqual(json.loads(actual)['amount'],5)

    def test_only_container_network_and_exact_host_alias_allowed(self):
        config={'subnet':'172.20.0.0/16','local_addresses':['10.255.255.254']}
        for address in ('172.20.0.2','127.0.0.1','10.255.255.254'):
            self.assertTrue(bridge.source_allowed(address,config))
        for address in ('10.255.255.253','192.168.1.30','8.8.8.8'):
            self.assertFalse(bridge.source_allowed(address,config))

    def test_text_dialogue_and_schema_requests(self):
        data = fixture()
        self.assertEqual(bridge.validate_request(data),data)
        data['response_format']={'type':'json_schema','json_schema':{'schema':{
            'type':'object','required':['action'],'properties':{'action':{'enum':['Talk']}}}}}
        bridge.validate_request(data)
        self.assertEqual(bridge.validate_output('{"action":"Talk"}',data),'{"action":"Talk"}')
        with self.assertRaises(Exception): bridge.validate_output('{"action":"Shell"}',data)

    def test_untrusted_tools_and_multimodal_rejected(self):
        for mutation in ({'tools':[{'name':'shell'}]}, {'model':'other'}, {'messages':[]},
                         {'messages':[{'role':'user','content':[{'type':'image_url','image_url':'file:///private'}]}]},
                         {'messages':[{'role':'assistant','content':'x','tool_calls':[{}]}]},
                         {'max_tokens':True},{'max_tokens':5000},{'stream':'false'}):
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                bridge.validate_request(dict(fixture(),**mutation))

    def test_schema_external_references_rejected(self):
        for reference in ('file:///secret','https://example.com/schema','/private'):
            data = fixture()
            data['response_format']={'type':'json_schema','json_schema':{'schema':{'$ref':reference}}}
            with self.assertRaises(ValueError): bridge.validate_request(data)

    def test_duplicate_nonfinite_and_invalid_output_rejected(self):
        for text in ('{"x":1,"x":2}','{"x":NaN}','[]','bad','', 'x'*32769):
            with self.subTest(text=text[:20]), self.assertRaises(ValueError): bridge.validate_output(text,fixture())

    def test_primary_failure_never_calls_another_provider(self):
        provider = bridge.Provider({'private':'not model data'})
        data = fixture()
        with patch.object(bridge,'codex_generate',side_effect=TimeoutError) as primary:
            with self.assertRaises(TimeoutError):provider.generate(data)
            primary.assert_called_once_with(data,provider.config)
        self.assertFalse(hasattr(bridge,'fallback_generate'))
        with patch.object(bridge,'codex_generate',return_value='{"action":"Talk"}'):
            self.assertEqual(provider.generate(data)[1],'codex')

    def test_busy_and_primary_failure_release_slot(self):
        provider = bridge.Provider({})
        provider.lock.acquire()
        with self.assertRaises(BlockingIOError): provider.generate(fixture())
        provider.lock.release()
        with patch.object(bridge,'codex_generate',side_effect=TimeoutError):
            with self.assertRaises(TimeoutError): provider.generate(fixture())
        self.assertTrue(provider.lock.acquire(blocking=False))
        provider.lock.release()

    def test_fresh_ephemeral_scopes_and_no_tools(self):
        seen=[]
        class FakeRPC:
            def __init__(self,*args): self.events=[]; self.methods=[]; self.closed=False; seen.append(self)
            def send(self,*args,**kwargs): pass
            def call(self,method,params,*args):
                self.methods.append((method,params))
                if method=='account/read': return {'account':{'type':'chatgpt'}}
                if method=='thread/start': return {'thread':{'id':'fresh'+str(len(seen))},'model':'gpt-6.1-sol'}
                if method=='turn/start':
                    self.events=[{'method':'item/completed','params':{'threadId':params['threadId'],'item':{'type':'agentMessage','text':'{"action":"Talk"}'}}},
                                 {'method':'turn/completed','params':{'threadId':params['threadId'],'turn':{'id':'turn','status':'completed'}}}]
                    return {'turn':{'id':'turn'}}
                return {}
            def close(self): self.closed=True
        config={'cli':'unused','cwd':'empty','windows_cwd':'empty','codex_timeout':5}
        with patch.object(bridge,'RPC',FakeRPC):
            bridge.codex_generate(fixture(),config,'http://stub')
            bridge.codex_generate(fixture(),config,'http://stub')
        self.assertEqual(len(seen),2)
        for rpc in seen:
            start=dict(rpc.methods)['thread/start']
            self.assertTrue(start['ephemeral'])
            self.assertEqual(start['approvalPolicy'],'never')
            self.assertFalse(dict(rpc.methods)['turn/start']['sandboxPolicy']['networkAccess'])
            self.assertTrue(rpc.closed)

    def test_unexpected_model_tool_aborts_and_closes(self):
        class BadRPC:
            events=[]
            def __init__(self,*args): self.events=[]
            def send(self,*args,**kwargs): pass
            def call(self,method,params,*args):
                if method=='thread/start': return {'thread':{'id':'fresh'},'model':'gpt-6.1-sol'}
                if method=='turn/start':
                    self.events=[{'method':'item/started','params':{'item':{'type':'commandExecution'}}}]
                    return {'turn':{'id':'turn'}}
                return {}
            def close(self): pass
        with patch.object(bridge,'RPC',BadRPC), self.assertRaises(RuntimeError):
            bridge.codex_generate(fixture(),{'cli':'x','cwd':'x','windows_cwd':'x'},'http://stub')

if __name__=='__main__': unittest.main()

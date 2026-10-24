# SPDX-License-Identifier: GPL-3.0-only
"""Offline, current-executable proof of model tool and context isolation."""
import json
from pathlib import Path
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from codex_bridge import BASE, codex_generate, isolation_fingerprint


def verify_isolation(config):
    fixture={'model':'gpt-6.1-sol','messages':[{'role':'user','content':'synthetic_synth_fixture: say hello; action is Talk only.'}],
             'response_format':{'type':'json_object'},'max_tokens':128,'stream':False}
    policy=''
    for parent in Path(config['cli']).parents:
        settings=parent/'.codex'/'config.toml';candidate=settings.with_name('AGENTS.md')
        if settings.is_file() or candidate.is_file():
            if candidate.is_file():policy=candidate.read_text(encoding='utf-8').strip()
            break
    expected=json.dumps({'messages':fixture['messages'],'response_format':fixture['response_format'],'response_token_budget':128},ensure_ascii=False)
    result={};before=isolation_fingerprint(config['cli'])
    class Stub(BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def do_GET(self):self.send_response(404);self.end_headers()
        def do_POST(self):
            size=int(self.headers.get('Content-Length','0'))
            if not 0<size<=1048576:self.send_response(400);self.end_headers();return
            request=json.loads(self.rfile.read(size));extra=[];user_texts=[]
            for item in request.get('input',[]):
                if item.get('role')!='user':continue
                joined='\n'.join(p.get('text','') for p in item.get('content',[]) if isinstance(p,dict))
                user_texts.append(joined)
                if joined.strip()!=expected:extra.append(joined.strip())
            def policy_only(text):
                return bool(policy and text.startswith('# AGENTS.md instructions') and '<INSTRUCTIONS>' in text and '</INSTRUCTIONS>' in text
                    and text.split('<INSTRUCTIONS>',1)[1].split('</INSTRUCTIONS>',1)[0].strip()==policy
                    and not text.split('</INSTRUCTIONS>',1)[1].strip())
            flat=json.dumps(request)
            result.update(tool_count=len(request.get('tools',[])),extra_user_policy_only=all(policy_only(x) for x in extra),
                our_instructions_present=BASE in (request.get('instructions') or '') or BASE in flat,
                synthetic_input_present=expected in user_texts,
                no_personal_markers=not any(x in flat.lower() for x in ['<environment_context>','<skills_instructions>']))
            item={'id':'msg_synthetic','type':'message','role':'assistant','status':'completed','content':[{'type':'output_text','text':'{"text":"Hello.","action":"Talk"}','annotations':[]}]}
            response={'id':'synthetic','object':'response','status':'completed','output':[item],'usage':{'input_tokens':1,'output_tokens':1,'total_tokens':2}}
            events=[{'type':'response.created','response':{'id':'synthetic','object':'response','status':'in_progress','output':[]}},
                {'type':'response.output_item.added','output_index':0,'item':dict(item,status='in_progress',content=[])},
                {'type':'response.content_part.added','item_id':'msg_synthetic','output_index':0,'content_index':0,'part':dict(item['content'][0],text='')},
                {'type':'response.output_text.delta','item_id':'msg_synthetic','output_index':0,'content_index':0,'delta':item['content'][0]['text']},
                {'type':'response.output_text.done','item_id':'msg_synthetic','output_index':0,'content_index':0,'text':item['content'][0]['text']},
                {'type':'response.content_part.done','item_id':'msg_synthetic','output_index':0,'content_index':0,'part':item['content'][0]},
                {'type':'response.output_item.done','output_index':0,'item':item},{'type':'response.completed','response':response}]
            raw=''.join('data: '+json.dumps(x)+'\n\n' for x in events).encode()
            self.send_response(200);self.send_header('Content-Type','text/event-stream');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
    server=ThreadingHTTPServer(('127.0.0.1',0),Stub);server.daemon_threads=True
    threading.Thread(target=server.serve_forever,daemon=True).start()
    try:
        reply=codex_generate(fixture,dict(config,codex_timeout=25),'http://127.0.0.1:'+str(server.server_port))
        result['output_valid']=json.loads(reply)=={'text':'Hello.','action':'Talk'}
    finally:server.shutdown();server.server_close()
    result['isolation_passed']=bool(result.get('tool_count')==0 and all(result.get(k) for k in
        ['extra_user_policy_only','our_instructions_present','synthetic_input_present','no_personal_markers','output_valid']))
    result['fingerprint']=isolation_fingerprint(config['cli'])
    if before!=result['fingerprint']:raise RuntimeError('Executable changed during isolation check')
    if not result['isolation_passed']:raise RuntimeError('Isolation check refused')
    return result

"""Exercise auth, CSRF, scoped ingestion and user isolation against a disposable MySQL DB."""
import http.cookiejar
import json
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone, timedelta
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
BASE='http://127.0.0.1:8099/'
def request(client,path,body=None,headers=None):
    data=None if body is None else json.dumps(body).encode()
    req=urllib.request.Request(BASE+path,data=data,headers={'Content-Type':'application/json',**(headers or {})})
    try:
        with client.open(req,timeout=20) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
def account(name):
    client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    code,boot=request(client,'api.php?action=csrf');assert code==200
    code,created=request(client,'api.php?action=register',{'email':name+'@test.invalid','name':name,'password':'Test-only-password-93'}, {'X-CSRF-Token':boot['csrf']});assert code==200,created
    return client,created['csrf']
def post(client,csrf,action,body):return request(client,'api.php?action='+action,body,{'X-CSRF-Token':csrf})
with open('/tmp/daypilot-connections-test.log','w') as log:
    server=subprocess.Popen(['php','-S','127.0.0.1:8099','-t',str(ROOT)],stdout=log,stderr=log)
    try:
        for _ in range(50):
            try:
                with urllib.request.urlopen(BASE+'api.php?action=csrf',timeout=1):break
            except Exception:time.sleep(.1)
        a,csrf=account('connections-user-a');b,csrf_b=account('connections-user-b')
        code,_=request(a,'api.php?action=connections_create_token',{'source':'sql','origin':'https://tracker.example'});assert code==419
        code,token=post(a,csrf,'connections_create_token',{'source':'sql','origin':'https://tracker.example'});assert code==200,token
        url='tracker_ingest.php?connection='+token['id'];headers={'Origin':'https://tracker.example','Authorization':'Bearer '+token['token']}
        summary={'source':'sql','captured_at':datetime.now(timezone.utc).isoformat(),'total':10,'completed':2,'in_progress':1,'attempts':4,'minutes':0,'notes':'DO NOT STORE THIS','solution':'PRIVATE CODE'}
        code,_=request(urllib.request.build_opener(),url,summary,{**headers,'Origin':'https://wrong.example'});assert code==403
        code,_=request(urllib.request.build_opener(),url,summary,{**headers,'Authorization':'Bearer '+'0'*64});assert code==401
        code,_=request(urllib.request.build_opener(),url,{**summary,'source':'web'},headers);assert code==403
        code,result=request(urllib.request.build_opener(),url,summary,headers);assert code==200,result
        code,overview=request(a,'api.php?action=connections_overview');assert code==200,overview
        assert len(overview['sources'])==1 and overview['sources'][0]['data']['completed']==2
        assert 'DO NOT STORE THIS' not in json.dumps(overview) and 'PRIVATE CODE' not in json.dumps(overview)
        code,other=request(b,'api.php?action=connections_overview');assert code==200 and other['sources']==[] and other['connections']==[]
        code,_=post(b,csrf_b,'connections_revoke_token',{'id':token['id']});assert code==200
        code,still_owned=request(a,'api.php?action=connections_overview');assert still_owned['connections'][0]['revoked_at'] is None
        code,second=post(a,csrf,'connections_create_token',{'source':'sql','origin':'https://tracker.example'});assert code==200
        code,_=request(urllib.request.build_opener(),'tracker_ingest.php?connection='+second['id'],{**summary,'completed':0,'captured_at':(datetime.now(timezone.utc)-timedelta(hours=1)).isoformat()},{**headers,'Authorization':'Bearer '+second['token']});assert code==409
        code,_=post(a,csrf,'connections_revoke_token',{'id':token['id']});assert code==200
        code,_=request(urllib.request.build_opener(),url,summary,headers);assert code==403
        code,final=request(a,'api.php?action=connections_overview');assert len(final['sources'])==1 and final['sources'][0]['data']['completed']==2
        print('HTTP auth, CSRF, origin, token scope, isolation, privacy, stale-summary, and revocation checks passed.')
    finally:
        server.terminate();server.wait(timeout=5)

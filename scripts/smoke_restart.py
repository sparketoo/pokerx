"""Kill our Hyperf process group with a solve pending, then verify durable recovery."""
import os
os.environ['SMOKE_HTTP']='http://127.0.0.1:19480'
os.environ['SMOKE_WS']='ws://127.0.0.1:19481'
from smoke_common import *
import signal
processes=[]
env=dict(os.environ,XDEBUG_MODE='off',POKER_PROVIDER='mock',MOCK_DELAY_MS='5000',POKER_INCOMPLETE_AFTER='2',HTTP_PORT='19480',POKER_WS_PORT='19481')
log=open(ROOT/'runtime/smoke-restart.log','w')
def start():
    p=subprocess.Popen(['php','bin/hyperf.php','start'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
    processes.append(p)
    for _ in range(100):
        if p.poll() is not None: raise RuntimeError('Hyperf restart failed')
        try:
            with urllib.request.urlopen(HTTP+'/health',timeout=.5) as r: assert r.status==200
            return p
        except Exception:time.sleep(.1)
    raise RuntimeError('Server readiness timed out')
try:
    first=start()
    name,password,user=account()
    command('credit:grant',name,'10','--id='+str(uuid.uuid4()))
    token=api('POST','/api/auth/login',{'account':name,'password':password})['token']
    ws=WebSocket();ws.auth(token);game=None
    for event in events()[:8]:
        event['id']=str(uuid.uuid4())
        if game:event['payload']['hand_id']=game
        ws.send(event);ack=ws.recv();assert ack['type']=='event_ack',ack
        game=ack['payload']['hand_id']
    assert api('GET','/api/mine/credit',token=token)['reserved']==100
    os.killpg(first.pid,signal.SIGKILL);first.wait(timeout=5);ws.sock.close()
    time.sleep(2.1);start()
    for _ in range(60):
        detail=api('GET','/api/mine/games/detail',{'game_id':game},token)
        credit=api('GET','/api/mine/credit',token=token)
        if detail['game'] and detail['game']['status']=='incomplete' and credit['reserved']==0:break
        time.sleep(.2)
    else:raise RuntimeError('Pending solve did not recover')
    records=api('GET','/api/mine/credit/record',token=token)
    assert credit['balance']==1000 and records['summary']['consume']==0
    print(json.dumps({'result':'passed','scenario':'hard_restart_pending_solve','reserved':0,'credit_cost':0}))
finally:
    for p in processes:
        if p.poll() is None:
            os.killpg(p.pid,signal.SIGTERM)
            try:p.wait(timeout=12)
            except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait()
    log.close()

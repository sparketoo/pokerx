"""Failure, isolation, idempotency and contention checks over actual local sockets."""
from smoke_common import *
name,password,user=account();command('credit:grant',name,'1','--id='+str(uuid.uuid4()))
t1=api('POST','/api/auth/login',{'account':name,'password':password})['token'];t2=api('POST','/api/auth/login',{'account':name,'password':password})['token']
a=WebSocket();a.auth(t1);b=WebSocket();b.auth(t2)
def prepare(ws):
    game=None;timeline=events()
    for e in timeline[:7]:
        e['id']=str(uuid.uuid4())
        if game:e['payload']['hand_id']=game
        ws.send(e);ack=ws.recv();assert ack['type']=='event_ack',ack;game=ack['payload']['hand_id']
    solve=timeline[7];solve['id']=str(uuid.uuid4());solve['payload']['hand_id']=game
    return game,solve
one,solve1=prepare(a);two,solve2=prepare(b)
# A second authenticated connection may continue the same user's game.
b.send(solve1);a.send(solve2)
out=[]
for ws in [a,b]:
    assert ws.recv()['type']=='event_ack'
    out.append(ws.recv())
assert sorted(v['type'] for v in out)==['error','game_play_action'],out
assert next(v for v in out if v['type']=='error')['payload']['code']=='insufficient_points'
credit=api('GET','/api/mine/credit',token=t1);assert credit['balance']==0 and credit['reserved']==0
# Repeat accepted operation cannot spend again.
a.send(solve1);assert a.recv()['payload']['status']=='duplicate'
bad=json.loads(json.dumps(solve1));bad['payload']['delay']=6000;a.send(bad);assert a.recv()['payload']['code']=='event_conflict'
# Another account cannot use this game's UUID in HTTP or WS.
other,pwd,uid=account();other_token=api('POST','/api/auth/login',{'account':other,'password':pwd})['token']
api('GET','/api/mine/games/detail',{'game_id':one},other_token,expected='not_found')
w=WebSocket();w.auth(other_token);w.send(solve1);assert w.recv()['payload']['code']=='hand_not_started';w.close()
# Invalid JSON causes a safe error without crashing the listener.
a.send_frame(b'{broken');assert a.recv()['type']=='error'
ping={'id':str(uuid.uuid4()),'type':'heatbeat_ping','timestamp':int(time.time()*1000),'payload':{'timestamp':123}};a.send(ping);assert a.recv()['type']=='heatbeat_pong'
# A newer real action invalidates the in-flight recommendation and releases its reservation.
command('credit:grant',name,'1','--id='+str(uuid.uuid4()))
third,solve3=prepare(a)
a.send(solve3)
acted=events()[8];acted['id']=str(uuid.uuid4());acted['payload']['hand_id']=third
a.send(acted)
messages=[a.recv(),a.recv(),a.recv()]
assert sum(m['type']=='event_ack' for m in messages)==2,messages
assert any(m['type']=='error' and m['payload']['code']=='solve_stale' for m in messages),messages
credit=api('GET','/api/mine/credit',token=t1);assert credit['balance']==100 and credit['reserved']==0,credit
# Token revocation is enforced on existing sockets.
api('POST','/api/auth/logout',{},t2)
try:
    b.send(ping);message=b.recv();assert message['type']=='error' and message['payload']['code']=='auth_expired'
except (EOFError,OSError):pass
b.close();a.close()
print(json.dumps({'result':'passed','scenarios':['same_user_continuation','last_credit_contention','duplicate_no_charge','conflicting_replay','cross_user_http','cross_user_ws','malformed_frame','revoked_socket','new_fact_invalidates_pending']}))

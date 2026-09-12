"""Exercise all 20 HTTP endpoints plus the actual WebSocket game flow."""
from smoke_common import *
import hmac
name,password,user=account()
command('credit:grant',name,'10','--id='+str(uuid.uuid4()))
login=api('POST','/api/auth/login',{'account':name,'password':password});token=login['token']
api('GET','/api/mine',token=token)
api('POST','/api/mine/update_nickname',{'nickname':'冒烟测试'},token)
api('POST','/api/mine/update_language',{'language':'en'},token)
ws=WebSocket();ws.auth(token)
ping={'id':str(uuid.uuid4()),'type':'heatbeat_ping','payload':{'timestamp':int(time.time()*1000)}}
ws.send(ping);assert ws.recv()['type']=='heatbeat_pong'
game=None;request=None
for event in events():
    event['id']=str(uuid.uuid4())
    if game:event['payload']['hand_id']=game
    ws.send(event);ack=ws.recv();assert ack['type']=='event_ack',(event['type'],ack)
    if game is None:
        game=ack['payload']['hand_id'];ws.send(event);duplicate=ws.recv();assert duplicate['payload']['status']=='duplicate' and duplicate['payload']['hand_id']==game
    if event['type']=='game_get_solve':
        request=event['id'];answer=ws.recv();assert answer['type']=='game_play_action',answer;assert answer['reply_to']==request
ws.close()
for _ in range(100):
    persisted=api('GET','/api/mine/games',token=token)
    if any(g['uuid']==game and g['status']=='settled' for g in persisted['items']):break
    time.sleep(.15)
else:raise RuntimeError('Octane task worker did not persist the game')
credit=api('GET','/api/mine/credit',token=token);assert credit['balance']==700 and credit['reserved']==0,credit
records=api('GET','/api/mine/credit/record',token=token);assert len(records['items'])==4 and records['summary']['consume']==300,records
items=api('GET','/api/mine/games',token=token);assert any(g['uuid']==game for g in items['items'])
detail=api('GET','/api/mine/games/detail',{'game_id':game},token);assert detail['game']['profit']==1350,detail
history=api('GET','/api/mine/games/events',{'game_id':game},token);assert len(history['items'])==22
solve=api('GET','/api/mine/solves/detail',{'game_id':game,'request_id':request},token);assert solve['success'] and not solve['executable']
summary=api('GET','/api/mine/stats/summary',token=token);assert summary['lifetime']['profit']==1350
trend=api('GET','/api/mine/stats/trend',{'snapshot':summary['snapshot']},token);assert trend['items'][-1]['cumulative']==1350
logs=api('GET','/api/mine/logs',token=token);assert logs['items']
api('GET','/api/mine/logs/detail',{'log_id':logs['items'][0]['id']},token)
export=api('POST','/api/mine/logs/export',{},token);assert export['text'] and token not in export['text']
setup=api('POST','/api/mine/security/create_two_factor',{},token)
api('POST','/api/mine/security/cancel_two_factor',{'setup_id':setup['setup_id']},token)
new_password=uuid.uuid4().hex
api('POST','/api/mine/security/change_password',{'current_password':password,'new_password':new_password,'confirmation':new_password},token)
api('GET','/api/mine',token=token,expected='auth_required')
token=api('POST','/api/auth/login',{'account':name,'password':new_password})['token']
setup=api('POST','/api/mine/security/create_two_factor',{},token)
def totp(secret,offset_seconds=0):
    digest=hmac.new(base64.b32decode(secret),struct.pack('!Q',int(time.time()+offset_seconds)//30),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('!I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
code=totp(setup['secret'])
api('POST','/api/mine/security/confirm_two_factor',{'setup_id':setup['setup_id'],'current_password':new_password,'code':code},token)
api('POST','/api/auth/login',{'account':name,'password':new_password},expected='two_factor_required')
token=api('POST','/api/auth/login',{'account':name,'password':new_password,'code':totp(setup['secret'],30)})['token']
api('POST','/api/auth/logout',{},token)
api('GET','/api/mine',token=token,expected='auth_required')
print(json.dumps({'result':'passed','http_endpoints':20,'game_events':22,'successful_solves':3,'profit':1350,'credit_cost':300,'game_id':game}))

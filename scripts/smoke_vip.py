"""Verify VIP via the real local HTTP and WebSocket services and asynchronous persistence."""
from smoke_common import *
name, password, user = account(is_vip=True)
token = api('POST', '/api/auth/login', {'account': name, 'password': password})['token']
assert api('GET', '/api/mine', token=token)['is_vip'] is True
ws = WebSocket()
ws.auth(token)
game = None
requests = []
for event in events():
    event['id'] = str(uuid.uuid4())
    if game:
        event['payload']['hand_id'] = game
    ws.send(event)
    ack = ws.recv()
    assert ack['type'] == 'event_ack', ack
    game = ack['payload']['hand_id']
    if event['type'] == 'game_get_solve':
        requests.append(event['id'])
        answer = ws.recv()
        assert answer['type'] == 'game_play_action', answer
ws.close()
for _ in range(100):
    data = api('GET', '/api/mine/games/detail', {'game_id': game}, token)
    if data['game'] and data['game']['status'] == 'settled':
        break
    time.sleep(.15)
else:
    raise RuntimeError('VIP game was not persisted')
for request in requests:
    solve = api('GET', '/api/mine/solves/detail', {'game_id': game, 'request_id': request}, token)
    assert solve['success'] and solve['cost'] == 0, solve
credit = api('GET', '/api/mine/credit', token=token)
assert credit['balance'] == 0 and credit['reserved'] == 0, credit
assert api('GET', '/api/mine/credit/record', token=token)['items'] == []
print(json.dumps({'result': 'passed', 'scenario': 'vip_zero_balance', 'successful_solves': len(requests), 'credit_cost': 0}))

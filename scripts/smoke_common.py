"""Standard-library HTTP/WebSocket client for local smoke checks."""
import base64,hashlib,json,os,socket,ssl,struct,subprocess,time,urllib.request,urllib.parse,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
HTTP=os.getenv('SMOKE_HTTP','http://127.0.0.1:18080')
WS=os.getenv('SMOKE_WS','ws://127.0.0.1:18081')

def api(method,path,data=None,token=None,expected='success'):
    if method=='GET' and data: path+='?'+urllib.parse.urlencode(data)
    headers={'Content-Type':'application/json','Accept':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    req=urllib.request.Request(HTTP+path,data=json.dumps(data or {}).encode() if method=='POST' else None,headers=headers,method=method)
    with urllib.request.urlopen(req,timeout=20) as response:
        value=json.load(response)
        assert response.status==200
    assert value['code']==expected, (path,value['code'],value.get('message'))
    return value.get('data')

def command(*args):
    r=subprocess.run(['php','bin/hyperf.php',*args],cwd=ROOT,capture_output=True,text=True)
    assert r.returncode==0,(args[0],r.stdout[-1200:],r.stderr[-500:])
    return r.stdout

def account(is_vip=False):
    name='smoke_'+uuid.uuid4().hex[:12];password=uuid.uuid4().hex
    p=subprocess.run(['php','scripts/seed_smoke.php'],cwd=ROOT,input=json.dumps({'account':name,'password':password,'is_vip':is_vip}),capture_output=True,text=True)
    assert p.returncode==0,p.stderr
    return name,password,json.loads(p.stdout.strip().splitlines()[-1])['user_id']

class WebSocket:
    def __init__(self,url=WS):
        u=urllib.parse.urlparse(url);self.sock=socket.create_connection((u.hostname,u.port or (443 if u.scheme=='wss' else 80)),10)
        if u.scheme=='wss':self.sock=ssl.create_default_context().wrap_socket(self.sock,server_hostname=u.hostname)
        self.sock.settimeout(20);key=base64.b64encode(os.urandom(16)).decode()
        req=f'GET {u.path or "/"} HTTP/1.1\r\nHost: {u.netloc}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {key}\r\n\r\n'
        self.sock.sendall(req.encode());header=b''
        while not header.endswith(b'\r\n\r\n'):header+=self.sock.recv(1)
        assert b'101' in header.split(b'\r\n')[0],header[:100]
        accept=base64.b64encode(hashlib.sha1((key+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest())
        assert accept.lower() in header.lower()
    def send_frame(self,data,opcode=1):
        mask=os.urandom(4);n=len(data);head=bytes([0x80|opcode]);head+=bytes([0x80|n]) if n<126 else bytes([0xfe])+struct.pack('!H',n) if n<65536 else bytes([0xff])+struct.pack('!Q',n)
        self.sock.sendall(head+mask+bytes(b^mask[i%4] for i,b in enumerate(data)))
    def send(self,obj):self.send_frame(json.dumps(obj).encode())
    def exact(self,n):
        data=b''
        while len(data)<n:
            part=self.sock.recv(n-len(data))
            if not part:raise EOFError('WebSocket closed')
            data+=part
        return data
    def recv(self):
        while True:
            a,b=self.exact(2);op=a&15;n=b&127
            if n==126:n=struct.unpack('!H',self.exact(2))[0]
            elif n==127:n=struct.unpack('!Q',self.exact(8))[0]
            mask=self.exact(4) if b&128 else None;data=self.exact(n)
            if mask:data=bytes(v^mask[i%4] for i,v in enumerate(data))
            if op==9:self.send_frame(data,10);continue
            if op==10:continue
            if op==8:raise EOFError('WebSocket closed')
            return json.loads(data)
    def auth(self,token):self.send({'token':token,'client_version':'smoke','client_platform':'chrome'});v=self.recv();assert v['type']=='auth_ok',v;return v
    def close(self):
        try:self.send_frame(b'',8)
        except OSError:pass
        self.sock.close()

def events():
    x=json.loads((ROOT.parent/'docs/GATEWAY-V1-EXAMPLES.json').read_text())
    return sorted([v for k,v in x['packets'].items() if k.startswith('client_') and v.get('type','').startswith('game_')],key=lambda v:v['seq'])

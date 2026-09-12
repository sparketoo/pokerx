"""Independent raw TCP peer verifies framing, short reads, handshake rejection and close draining."""
import base64, hashlib, json, os, socket, struct, subprocess, threading, time, ssl, tempfile
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]

def exact(conn, n):
    data = b''
    while len(data) < n:
        part = conn.recv(n-len(data))
        if not part:
            raise RuntimeError('Unexpected EOF')
        data += part
    return data

def read_frame(conn):
    a,b = exact(conn,2)
    assert a & 128 and b & 128, 'client must send final masked frames'
    n = b & 127
    if n == 126: n = struct.unpack('!H',exact(conn,2))[0]
    if n == 127: n = struct.unpack('!Q',exact(conn,8))[0]
    mask = exact(conn,4)
    data = exact(conn,n)
    return a & 15, bytes(x ^ mask[i % 4] for i,x in enumerate(data))

certificates = tempfile.TemporaryDirectory(prefix='pokerx-socket-tls-')
cert = str(Path(certificates.name) / 'cert.pem')
keyfile = str(Path(certificates.name) / 'key.pem')
subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',keyfile,'-out',cert,'-days','1','-subj','/CN=localhost'],check=True,capture_output=True)
results = []
for mode in ['fragmented_ping', 'large_write', 'bad_accept', 'masked_server', 'eof', 'handshake_timeout', 'untrusted_tls']:
    listener = socket.socket()
    listener.bind(('127.0.0.1', 0))
    listener.listen(1)
    port = listener.getsockname()[1]
    errors = []
    def serve():
        try:
            conn, _ = listener.accept()
            if mode == 'untrusted_tls':
                conn.settimeout(5)
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                context.load_cert_chain(cert, keyfile)
                try:
                    with context.wrap_socket(conn, server_side=True) as tls:
                        assert tls.recv(1) == b'', 'Client sent HTTP bytes to an untrusted server'
                        return
                except ssl.SSLError:
                    return
            with conn:
                conn.settimeout(5)
                data = b''
                while b'\r\n\r\n' not in data: data += exact(conn,1)
                key = next(line.split(b':',1)[1].strip() for line in data.split(b'\r\n') if line.lower().startswith(b'sec-websocket-key:'))
                if mode == 'handshake_timeout':
                    assert conn.recv(1) == b''
                    return
                accept = base64.b64encode(hashlib.sha1(key+b'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest())
                if mode == 'bad_accept': accept = b'wrong'
                header = b'HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: '+accept+b'\r\n\r\n'
                conn.sendall(header)
                if mode == 'bad_accept':
                    assert conn.recv(1) == b''
                    return
                assert read_frame(conn) == (1,b'hello')
                if mode == 'eof': return
                if mode == 'masked_server':
                    conn.sendall(b'\x81\x80')
                    assert conn.recv(1) == b''
                    return
                # A fragmented text message with a control ping between its fragments.
                wire = b'\x01\x02he\x89\x01p\x80\x03llo'
                for byte in wire: conn.sendall(bytes([byte]))
                time.sleep(.1)
                frames = [read_frame(conn) for _ in range(3)]
                assert frames[0] == (10,b'p'), frames
                assert frames[1] == (1,b'x' * 1048576 if mode == 'large_write' else b'final'), frames
                assert frames[2] == (8,struct.pack('!H',1000)), frames
        except Exception as error:
            errors.append(str(error))
        finally:
            listener.close()
    thread = threading.Thread(target=serve, daemon=True)
    thread.start()
    run = subprocess.run(['php','scripts/smoke_socket.php'], cwd=ROOT, env=dict(os.environ, XDEBUG_MODE='off', SOCKET_TEST_URL=f'{"wss" if mode == "untrusted_tls" else "ws"}://127.0.0.1:{port}/test?x=1', SOCKET_LARGE_WRITE='1' if mode == 'large_write' else ''), text=True,capture_output=True,timeout=8)
    thread.join(6)
    assert run.returncode == 0, run.stderr
    report = json.loads(run.stdout.strip().splitlines()[-1])
    assert report['closed'] == 1 and not errors and not thread.is_alive(), (mode,report,errors)
    assert report['messages'] == (['hello'] if mode in ['fragmented_ping','large_write'] else []), report
    assert report['opened'] == (mode not in ['bad_accept','handshake_timeout','untrusted_tls']), report
    results.append(mode)
certificates.cleanup()
print(json.dumps({'result':'passed','scenarios':results}))

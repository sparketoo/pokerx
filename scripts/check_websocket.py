"""Real TCP peer checks the default Hyperf client and connection lifecycle."""
import base64
import hashlib
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import threading


def exact(conn, count):
    data = b''
    while len(data) < count:
        part = conn.recv(count - len(data))
        if not part:
            raise EOFError()
        data += part
    return data


listener = socket.socket()
listener.bind(('127.0.0.1', 0))
listener.listen(5)
listener.settimeout(6)
errors = []


def serve():
    try:
        for attempt in range(3):
            conn, _ = listener.accept()
            with conn:
                conn.settimeout(5)
                request = b''
                while b'\r\n\r\n' not in request:
                    request += exact(conn, 1)
                assert request.startswith(b'GET /dynamic HTTP/1.1')
                assert f'x-attempt: {attempt + 1}'.encode() in request.lower()
                if attempt == 0:  # EOF during handshake must retry.
                    continue
                key = next(line.split(b':', 1)[1].strip() for line in request.split(b'\r\n') if line.lower().startswith(b'sec-websocket-key:'))
                accept = base64.b64encode(hashlib.sha1(key + b'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest())
                conn.sendall(b'HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ' + accept + b'\r\n\r\n')
                if attempt == 1:  # Drop established connection.
                    continue
                conn.sendall(b'\x81\x02ok\x82\x02\x00\xff')
                assert conn.recv(1) == b''
    except Exception as error:
        errors.append(repr(error))
    finally:
        listener.close()


thread = threading.Thread(target=serve, daemon=True)
thread.start()
run = subprocess.run(['php', 'scripts/check_websocket.php'], cwd=Path(__file__).resolve().parents[1], env=dict(os.environ, XDEBUG_MODE='off', SOCKET_TEST_URL=f'ws://127.0.0.1:{listener.getsockname()[1]}/'), text=True, capture_output=True, timeout=8)
thread.join(6)
assert run.returncode == 0, (run.stdout, run.stderr)
report = json.loads(run.stdout.strip().splitlines()[-1])
assert not errors and not thread.is_alive(), errors
assert report['opens'] == 2 and report['closes'] == 2, report
assert len(report['messages']) == 2 and report['messages'] == [['ok', 1], ['00ff', 2]], report
assert report['errors'], report
assert report['pings'] >= 2, report
print('PASS: Hyperf default client, subclass hooks, dynamic URL/headers, handshake EOF retry, EOF reconnect, text/binary delivery, idempotent connect, explicit close, offline write, scheduled onPing and stop after close')

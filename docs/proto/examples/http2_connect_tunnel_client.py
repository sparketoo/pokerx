"""
HTTP/2 CONNECT 隧道客户端示例

本示例演示如何通过 HTTP/2 CONNECT 隧道
建立 WebSocket 连接。

适用场景：
- 需要通过 HTTP/2 CONNECT 隧道传输 WebSocket
- 网络/代理要求使用 CONNECT 方法
- 企业代理环境阻止直接建立 WebSocket 连接

更简单的 WebSocket 连接方式请参见 websocket_async_client.py。

依赖：
    pip install h2 hpack

用法（从仓库根目录运行）：
    python doc/examples/http2_connect_tunnel_client.py

TLS 说明：
    本示例关闭证书验证，以便使用自签名证书进行测试。
    在生产环境中，请修改 SSL 上下文以验证证书：
    - 删除 `ctx.check_hostname = False`
    - 删除 `ctx.verify_mode = ssl.CERT_NONE`
"""

import ssl
import socket
import json
import h2.connection
import h2.events
import h2.config
from base64 import b64encode
import os


class HTTP2TunnelWebSocketClient:
    """
    HTTP/2 CONNECT 隧道，通过隧道完成 WebSocket 握手。
    """
    
    def __init__(self, host: str, port: int = 443):
        self.host = host
        self.port = port
        self.socket = None
        self.conn = None
        self.stream_id = None
        self.ws_established = False
    
    def connect(self, player_id: str, verify_tls: bool = False) -> bool:
        """
        建立 HTTP/2 CONNECT 隧道，并在其中执行 WS 握手。
        
        参数：
            player_id: 用于会话亲和性的玩家标识符
            verify_tls: 为 True 时验证 TLS 证书（生产环境推荐）
        """
        # 创建 SSL 上下文
        ctx = ssl.create_default_context()
        if not verify_tls:
            # 警告：仅在使用自签名证书进行测试时关闭验证！
            # 在生产环境中，请设置 verify_tls=True 或完全删除此代码块。
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        ctx.set_alpn_protocols(['h2'])
        
        # 建立 TCP + TLS 连接
        raw_socket = socket.create_connection((self.host, self.port))
        self.socket = ctx.wrap_socket(raw_socket, server_hostname=self.host)
        
        if self.socket.selected_alpn_protocol() != 'h2':
            raise Exception("HTTP/2 not negotiated")
        
        # 初始化 HTTP/2 连接
        config = h2.config.H2Configuration(client_side=True)
        self.conn = h2.connection.H2Connection(config=config)
        self.conn.initiate_connection()
        self.socket.sendall(self.conn.data_to_send())
        
        # 发送 CONNECT 请求以建立隧道
        headers = [
            (':method', 'CONNECT'),
            (':authority', f'{self.host}:{self.port}'),
        ]
        
        self.stream_id = self.conn.get_next_available_stream_id()
        self.conn.send_headers(self.stream_id, headers)
        self.socket.sendall(self.conn.data_to_send())
        
        # 等待 2xx 响应（表示隧道已建立）
        if not self._wait_for_tunnel():
            raise Exception("Tunnel not established")
        
        print("HTTP/2 CONNECT tunnel established")
        
        # 现在通过隧道执行 WebSocket 握手
        return self._websocket_handshake(player_id)
    
    def _wait_for_tunnel(self) -> bool:
        """等待 CONNECT 响应。"""
        while True:
            data = self.socket.recv(65535)
            if not data:
                return False
            
            events = self.conn.receive_data(data)
            for event in events:
                if isinstance(event, h2.events.ResponseReceived):
                    status = dict(event.headers).get(':status')
                    return status and status.startswith('2')
            
            self.socket.sendall(self.conn.data_to_send())
    
    def _websocket_handshake(self, player_id: str) -> bool:
        """通过隧道执行 WebSocket 握手。"""
        ws_key = b64encode(os.urandom(16)).decode('ascii')
        
        # 构建 HTTP/1.1 WebSocket 升级请求
        handshake = (
            f"GET /?pid={player_id} HTTP/1.1\r\n"
            f"Host: {self.host}\r\n"
            f"Upgrade: websocket\r\n"
            f"Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {ws_key}\r\n"
            f"Sec-WebSocket-Version: 13\r\n"
            f"\r\n"
        ).encode('utf-8')
        
        # 作为 DATA 帧通过隧道发送
        self.conn.send_data(self.stream_id, handshake)
        self.socket.sendall(self.conn.data_to_send())
        
        # 读取握手响应
        response = self._recv_tunnel_data()
        
        if b'101 Switching Protocols' in response:
            self.ws_established = True
            print("WebSocket handshake complete through tunnel")
            return True
        
        return False
    
    def _recv_tunnel_data(self) -> bytes:
        """从隧道接收数据。"""
        while True:
            data = self.socket.recv(65535)
            if not data:
                return b''
            
            events = self.conn.receive_data(data)
            for event in events:
                if isinstance(event, h2.events.DataReceived):
                    self.conn.acknowledge_received_data(
                        event.flow_controlled_length,
                        event.stream_id
                    )
                    self.socket.sendall(self.conn.data_to_send())
                    return event.data
            
            self.socket.sendall(self.conn.data_to_send())
    
    def send(self, message: dict):
        """将 JSON 消息编码为 WebSocket 帧并通过隧道发送。"""
        payload = json.dumps(message).encode('utf-8')
        frame = self._encode_ws_frame(payload)
        self.conn.send_data(self.stream_id, frame)
        self.socket.sendall(self.conn.data_to_send())
    
    def recv(self) -> dict:
        """通过隧道从 WebSocket 接收 JSON 消息。"""
        data = self._recv_tunnel_data()
        payload = self._decode_ws_frame(data)
        return json.loads(payload.decode('utf-8'))
    
    def _encode_ws_frame(self, payload: bytes) -> bytes:
        """编码带掩码的 WebSocket 文本帧。"""
        frame = bytearray()
        frame.append(0x81)  # FIN 标志 + TEXT 文本类型
        
        length = len(payload)
        if length <= 125:
            frame.append(0x80 | length)
        elif length <= 65535:
            frame.append(0x80 | 126)
            frame.extend(length.to_bytes(2, 'big'))
        else:
            frame.append(0x80 | 127)
            frame.extend(length.to_bytes(8, 'big'))
        
        mask = os.urandom(4)
        frame.extend(mask)
        
        for i, byte in enumerate(payload):
            frame.append(byte ^ mask[i % 4])
        
        return bytes(frame)
    
    def _decode_ws_frame(self, data: bytes) -> bytes:
        """解码 WebSocket 帧。"""
        if len(data) < 2:
            return b''
        
        payload_len = data[1] & 0x7F
        offset = 2
        
        if payload_len == 126:
            payload_len = int.from_bytes(data[2:4], 'big')
            offset = 4
        elif payload_len == 127:
            payload_len = int.from_bytes(data[2:10], 'big')
            offset = 10
        
        return data[offset:offset + payload_len]
    
    def close(self):
        """关闭隧道和连接。"""
        if self.conn and self.stream_id:
            self.conn.end_stream(self.stream_id)
            self.socket.sendall(self.conn.data_to_send())
        if self.socket:
            self.socket.close()


# 使用示例
def http2_tunnel_example():
    client = HTTP2TunnelWebSocketClient("api.example.com", 443)
    
    try:
        client.connect("player123")
        
        # 身份认证（令牌是网关运营方提供的 UUID）
        # descr 是可选字段，用于服务端日志记录/调试
        client.send({
            "token": "a0321e5f-4d84-4e32-bef3-48814dfe7e70",
            "descr": "HTTP/2 Tunnel Client"
        })
        auth = client.recv()
        print(f"Auth: {auth}")
        
        if auth.get("result"):
            # 发送牌局事件
            client.send({
                "structType": "gameEvents",
                "game": {
                    "gameId": "test_game_001",
                    "pokerNetwork": "TEST",
                    "gameType": "NL",
                    "bigBlind": 100,
                    "ante": 0,
                    "currency": "USD",
                    "gameDate": "1706789012345",
                    "numPlayers": 2,
                    "buttonSetToSeat": 1
                },
                "events": [
                    {"eventType": "playerSeated", "seat": 1, "name": "Hero", "stack": 10000},
                    {"eventType": "playerSeated", "seat": 2, "name": "Villain", "stack": 10000},
                    {"eventType": "blindPosted", "name": "Hero", "blindType": "SB", "amount": 50},
                    {"eventType": "blindPosted", "name": "Villain", "blindType": "BB", "amount": 100},
                    {"eventType": "stageStarted", "stage": "preflop", "cards": ""},
                    {"eventType": "handDealt", "name": "Hero", "cards": "As,Ks"},
                    {"eventType": "knownPlayerCards", "name": "Hero", "cards": "As,Ks"}
                ]
            })
            
            # 请求决策
            client.send({
                "structType": "getAnswer",
                "gameId": "test_game_001",
                "potForAlpha": 150,
                "delay": 0
            })
            
            response = client.recv()
            print(f"Bot decision: {response}")
        
    finally:
        client.close()


if __name__ == "__main__":
    http2_tunnel_example()

"""
HTTP/2 WebSocket 客户端示例（RFC 8441）

本示例演示如何使用扩展 CONNECT 方法
在 HTTP/2 上建立 WebSocket 连接。

适用场景：
- 需要支持多路复用的 HTTP/2 WebSocket
- 在单个 TCP 连接上传输多个 WebSocket 流
- 基础设施要求使用 HTTP/2

更简单的 WebSocket 连接方式请参见 websocket_async_client.py。

依赖：
    pip install h2 hpack

用法（从仓库根目录运行）：
    python doc/examples/http2_websocket_client.py

TLS 说明：
    本示例关闭证书验证，以便使用自签名证书进行测试。
    在生产环境中，请修改 SSL 上下文以验证证书：
    - 删除 `ctx.check_hostname = False`
    - 删除 `ctx.verify_mode = ssl.CERT_NONE`
"""

import ssl
import json
import socket
import h2.connection
import h2.events
import h2.config
from base64 import b64encode
import os


class HTTP2WebSocketClient:
    """
    使用扩展 CONNECT（RFC 8441）的 HTTP/2 WebSocket 客户端。
    在 HTTP/2 上建立 WebSocket 连接。
    """
    
    def __init__(self, host: str, port: int = 443):
        self.host = host
        self.port = port
        self.socket = None
        self.conn = None
        self.stream_id = None
    
    def connect(self, player_id: str, verify_tls: bool = False) -> bool:
        """
        建立 HTTP/2 连接并升级为 WebSocket。
        
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
        
        # 确认已协商使用 HTTP/2
        if self.socket.selected_alpn_protocol() != 'h2':
            raise Exception("HTTP/2 not negotiated")
        
        # 初始化 HTTP/2 连接
        config = h2.config.H2Configuration(client_side=True)
        self.conn = h2.connection.H2Connection(config=config)
        self.conn.initiate_connection()
        self.socket.sendall(self.conn.data_to_send())
        
        # 生成 WebSocket 密钥
        ws_key = b64encode(os.urandom(16)).decode('ascii')
        
        # 为 WebSocket 发送扩展 CONNECT 请求
        headers = [
            (':method', 'CONNECT'),
            (':protocol', 'websocket'),
            (':scheme', 'https'),
            (':authority', f'{self.host}:{self.port}'),
            (':path', f'/?pid={player_id}'),
            ('sec-websocket-version', '13'),
            ('sec-websocket-key', ws_key),
        ]
        
        self.stream_id = self.conn.get_next_available_stream_id()
        self.conn.send_headers(self.stream_id, headers)
        self.socket.sendall(self.conn.data_to_send())
        
        # 读取响应
        while True:
            data = self.socket.recv(65535)
            if not data:
                raise Exception("Connection closed")
            
            events = self.conn.receive_data(data)
            for event in events:
                if isinstance(event, h2.events.ResponseReceived):
                    status = dict(event.headers).get(':status')
                    if status == '200':
                        print("HTTP/2 WebSocket established")
                        return True
                    else:
                        raise Exception(f"Unexpected status: {status}")
            
            self.socket.sendall(self.conn.data_to_send())
        
        return False
    
    def send_ws_frame(self, payload: str):
        """通过 HTTP/2 DATA 帧发送 WebSocket 帧。"""
        # 构建带掩码的 WebSocket 帧
        ws_frame = self._encode_ws_frame(payload.encode('utf-8'))
        
        # 作为 HTTP/2 DATA 帧发送
        self.conn.send_data(self.stream_id, ws_frame)
        self.socket.sendall(self.conn.data_to_send())
    
    def recv_ws_frame(self) -> str:
        """从 HTTP/2 DATA 帧中接收 WebSocket 帧。"""
        while True:
            data = self.socket.recv(65535)
            if not data:
                raise Exception("Connection closed")
            
            events = self.conn.receive_data(data)
            for event in events:
                if isinstance(event, h2.events.DataReceived):
                    # 解码 WebSocket 帧
                    payload = self._decode_ws_frame(event.data)
                    self.conn.acknowledge_received_data(
                        event.flow_controlled_length, 
                        event.stream_id
                    )
                    self.socket.sendall(self.conn.data_to_send())
                    return payload.decode('utf-8')
            
            self.socket.sendall(self.conn.data_to_send())
    
    def _encode_ws_frame(self, payload: bytes) -> bytes:
        """编码带掩码的 WebSocket 文本帧。"""
        frame = bytearray()
        frame.append(0x81)  # FIN 标志 + TEXT 操作码
        
        length = len(payload)
        if length <= 125:
            frame.append(0x80 | length)  # MASK 掩码位 + 长度
        elif length <= 65535:
            frame.append(0x80 | 126)
            frame.extend(length.to_bytes(2, 'big'))
        else:
            frame.append(0x80 | 127)
            frame.extend(length.to_bytes(8, 'big'))
        
        # 添加掩码密钥
        mask = os.urandom(4)
        frame.extend(mask)
        
        # 对载荷应用掩码
        for i, byte in enumerate(payload):
            frame.append(byte ^ mask[i % 4])
        
        return bytes(frame)
    
    def _decode_ws_frame(self, data: bytes) -> bytes:
        """解码 WebSocket 帧（服务端帧不带掩码）。"""
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
        """关闭连接。"""
        if self.conn and self.stream_id:
            self.conn.end_stream(self.stream_id)
            self.socket.sendall(self.conn.data_to_send())
        if self.socket:
            self.socket.close()


# 使用示例
def http2_websocket_example():
    client = HTTP2WebSocketClient("api.example.com", 443)
    
    try:
        client.connect("player123")
        
        # 身份认证（令牌是网关运营方提供的 UUID）
        # descr 是可选字段，用于服务端日志记录/调试
        client.send_ws_frame(json.dumps({
            "token": "a0321e5f-4d84-4e32-bef3-48814dfe7e70",
            "descr": "HTTP/2 WebSocket Client"
        }))
        
        auth_response = client.recv_ws_frame()
        print(f"Auth response: {auth_response}")
        
        # 发送牌局事件……
        
    finally:
        client.close()


if __name__ == "__main__":
    http2_websocket_example()

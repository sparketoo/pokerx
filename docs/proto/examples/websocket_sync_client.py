"""
网关协议的 WebSocket 同步客户端示例

本示例演示如何使用同步的 websocket-client 库，
通过 HTTP/1.1 WebSocket 连接网关。

适用场景：
- 使用简单脚本或同步代码库
- 不需要并发连接
- 可以接受阻塞 I/O（注意：会阻塞当前线程）

基于 asyncio 的应用请参见 websocket_async_client.py。

依赖：
    pip install websocket-client

用法（从仓库根目录运行）：
    python doc/examples/websocket_sync_client.py

TLS 说明：
    本示例关闭证书验证，以便使用自签名证书进行测试。
    在生产环境中，请删除 sslopt 的自定义配置，
    以启用正确的 TLS 证书验证。
"""

import json
import ssl
import threading
import time
from websocket import create_connection


def start_keepalive(ws, interval=20):
    """可选：在后台线程中发送 ping 帧，仅在需要持续连接时使用。"""
    while True:
        time.sleep(interval)
        if ws.sock and ws.sock.connected:
            ws.ping()
            print("Ping sent")


def connect_and_play(
    host: str = "api.example.com",
    port: int = 443,
    player_id: str = "player123",
    token: str = "a0321e5f-4d84-4e32-bef3-48814dfe7e70",
    verify_tls: bool = True,
    previous_session_id: str | None = None
):
    """
    连接服务并演示基本牌局流程。
    
    参数：
        host: 服务主机名
        port: 服务端口（TLS 使用 443）
        player_id: 玩家标识符（可选，用于会话亲和性）
        token: 认证令牌（UUID 格式）
        verify_tls: 是否验证 TLS 证书（默认值：True）
        previous_session_id: 先前连接的会话 ID，用于恢复状态
    """
    ws_url = f"wss://{host}:{port}/?pid={player_id}"
    
    sslopt = None
    if not verify_tls:
        sslopt = {"cert_reqs": ssl.CERT_NONE, "check_hostname": False}
    
    ws = create_connection(ws_url, sslopt=sslopt, timeout=15)
    print(f"Connected to {ws_url}")
    
    # 可选：如果需要持续连接，请取消以下代码的注释
    # 如果应用仅在进行牌局时连接，可以跳过此步骤
    # ping_thread = threading.Thread(target=start_keepalive, args=(ws, 20), daemon=True)
    # ping_thread.start()
    
    # 身份认证
    # 携带 descr（可选），用于服务端日志记录/调试
    auth_payload = {
        "token": token,
        "descr": "Python Sync Client"  # 可选
    }
    if previous_session_id:
        # 携带 sessionId，以在重连时恢复之前的会话
        auth_payload["sessionId"] = previous_session_id
    
    ws.send(json.dumps(auth_payload))
    auth_response = json.loads(ws.recv())
    
    if not auth_response.get("result"):
        print(f"Auth failed: {auth_response}")
        ws.close()
        return
    
    # 保存 sessionId，以备之后重连
    session_id = auth_response.get("sessionId")
    print(f"Authenticated with session: {session_id}")
    
    # 发送牌局事件
    game_events = {
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
    }
    ws.send(json.dumps(game_events))
    
    # 请求决策
    get_answer = {
        "structType": "getAnswer",
        "gameId": "test_game_001",
        "potForAlpha": 150,
        "delay": 0
    }
    ws.send(json.dumps(get_answer))
    
    # 接收机器人的决策
    response = json.loads(ws.recv())
    print(f"Bot decision: {response}")
    
    ws.close()


if __name__ == "__main__":
    connect_and_play()

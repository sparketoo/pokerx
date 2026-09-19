"""
网关协议的 WebSocket 异步客户端示例

本示例演示如何使用基于 asyncio 的 websockets 库，
通过 HTTP/1.1 WebSocket 连接网关。

适用场景：
- 应用已经使用 asyncio
- 需要大量非阻塞的并发连接
- 需要非阻塞 I/O

同步/阻塞代码请参见 websocket_sync_client.py。

依赖：
    pip install websockets

用法（从仓库根目录运行）：
    python doc/examples/websocket_async_client.py

TLS 说明：
    本示例关闭证书验证，以便使用自签名证书进行测试。
    在生产环境中，请删除 ssl_context 的自定义配置，
    以启用正确的 TLS 证书验证。
"""

import asyncio
import websockets
import json
import ssl


class GatewayClient:
    def __init__(self, host: str, port: int, player_id: str, token: str):
        self.url = f"wss://{host}:{port}/?pid={player_id}"
        self.token = token
        self.session_id = None
        self.ws = None
    
    async def connect(self, verify_tls: bool = True):
        """
        通过 TLS 建立 WebSocket 连接。
        
        参数：
            verify_tls: 为 True（默认值）时验证 TLS 证书。
                        为 False 时跳过验证（仅用于测试）。
        """
        ssl_context = ssl.create_default_context()
        if not verify_tls:
            ssl_context.check_hostname = False
            ssl_context.verify_mode = ssl.CERT_NONE
        
        self.ws = await websockets.connect(self.url, ssl=ssl_context)
        print(f"Connected to {self.url}")
    
    async def authenticate(self, descr: str = "Python Async Client") -> dict:
        """
        发送认证消息并接收响应。
        
        参数：
            descr: 可选的客户端描述，用于日志记录/调试
        
        应保存成功认证响应中的 sessionId，
        并在重连时回传，以恢复牌局状态。
        """
        auth_message = {"token": self.token, "descr": descr}  # descr 是可选字段
        if self.session_id:
            # 携带 sessionId，以在重连时恢复之前的会话
            auth_message["sessionId"] = self.session_id
        
        await self.ws.send(json.dumps(auth_message))
        response = await self.ws.recv()
        auth_result = json.loads(response)
        
        if auth_result.get("result"):
            # 保存 sessionId，以备后续重连
            self.session_id = auth_result.get("sessionId")
            print(f"Authenticated. Session ID: {self.session_id}")
        else:
            print(f"Authentication failed: {auth_result.get('info')}")
        
        return auth_result
    
    async def send_game_events(self, game_data: dict, events: list):
        """向网关发送牌局事件。"""
        message = {
            "structType": "gameEvents",
            "game": game_data,
            "events": events
        }
        await self.ws.send(json.dumps(message))
    
    async def request_answer(self, game_id: str, pot_for_alpha: int, delay: int = 0) -> dict:
        """请求机器人的决策并等待响应。"""
        message = {
            "structType": "getAnswer",
            "gameId": game_id,
            "potForAlpha": pot_for_alpha,
            "delay": delay
        }
        await self.ws.send(json.dumps(message))
        response = await self.ws.recv()
        return json.loads(response)
    
    async def send_full_game_log(self, game_data: dict, events: list):
        """牌局结束后发送完整牌局历史。"""
        message = {
            "structType": "fullGameLog",
            "game": game_data,
            "events": events
        }
        await self.ws.send(json.dumps(message))
    
    async def close(self):
        """关闭 WebSocket 连接。"""
        if self.ws:
            await self.ws.close()

    async def keep_alive(self, interval: float = 20.0):
        """
        可选：定期发送 ping 帧以维持连接。
        
        仅在需要持续连接时推荐使用。
        如果应用仅在进行牌局时连接，
        可以跳过此步骤，需要时重新连接即可。
        
        网关会在空闲 55 秒后关闭连接。
        每 20 秒发送一次 ping，以保持在该时间窗口内。
        
        参数：
            interval: 两次 ping 帧之间的间隔秒数（默认值：20）
            
        注意：仅在需要时将其作为后台任务运行：
            ping_task = asyncio.create_task(client.keep_alive())
        """
        import asyncio
        while True:
            await asyncio.sleep(interval)
            if self.ws and self.ws.open:
                await self.ws.ping()
                print("Ping sent")


# 使用示例
async def main():
    client = GatewayClient(
        host="api.example.com",
        port=443,
        player_id="player123",
        token="a0321e5f-4d84-4e32-bef3-48814dfe7e70"
    )
    
    await client.connect()
    
    # 可选：如果需要持续连接，请取消以下代码的注释
    # 如果应用仅在进行牌局时连接，可以跳过此步骤
    # ping_task = asyncio.create_task(client.keep_alive(interval=20))
    
    auth_result = await client.authenticate()
    
    if auth_result.get("result"):
        # 发送牌局事件
        game_data = {
            "gameId": "game_12345_player123",
            "pokerNetwork": "WE",
            "gameType": "NL",
            "bigBlind": 200,
            "ante": 0,
            "currency": "USD",
            "gameDate": "1706789012345",
            "numPlayers": 6,
            "buttonSetToSeat": 3
        }
        
        events = [
            {"eventType": "playerSeated", "seat": 1, "name": "player123", "stack": 10000},
            {"eventType": "playerSeated", "seat": 2, "name": "opponent1", "stack": 15000},
            {"eventType": "blindPosted", "name": "player123", "blindType": "SB", "amount": 100},
            {"eventType": "blindPosted", "name": "opponent1", "blindType": "BB", "amount": 200},
            {"eventType": "stageStarted", "stage": "preflop", "cards": ""},
            {"eventType": "handDealt", "name": "player123", "cards": "As,Kd"},
            {"eventType": "knownPlayerCards", "name": "player123", "cards": "As,Kd"}
        ]
        
        await client.send_game_events(game_data, events)
        
        # 请求机器人的决策
        response = await client.request_answer("game_12345_player123", pot_for_alpha=300)
        print(f"Bot decision: {response}")
    
    await client.close()


if __name__ == "__main__":
    asyncio.run(main())

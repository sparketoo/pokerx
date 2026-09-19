"""
HTTP 请求/响应 API 客户端示例

本示例演示如何使用 HTTP 请求/响应 API 与网关交互。
相较于 HTTP/1.1，推荐使用 HTTP/2。

适用场景：
- 无法维持 WebSocket 长连接
- 处于无服务器计算/云函数环境
- 需要简单、无状态的请求/响应模式
- 使用 curl 或简单 HTTP 客户端进行集成

如需使用长连接获得更低延迟，请参见 websocket_async_client.py。

依赖：
    pip install httpx

用法（从仓库根目录运行）：
    python doc/examples/http_api_client.py

TLS 说明：
    本示例关闭证书验证（`verify=False`），以便使用自签名证书测试。
    在生产环境中，请设置 `verify=True` 或删除此参数，
    以启用正确的 TLS 证书验证。
"""

import httpx
import json
import time
import threading


class GatewayHTTPClient:
    """API 的 HTTP 请求/响应客户端，使用基于 TLS 的 HTTP/2。"""
    
    def __init__(self, base_url: str, player_id: str, token: str, verify_tls: bool = True):
        self.base_url = base_url
        self.player_id = player_id
        self.token = token
        self.session_id = None
        
        self.client = httpx.Client(
            http2=True,
            verify=verify_tls,
            timeout=30.0
        )
    
    def _make_request(self, payload: dict) -> dict:
        """向网关 API 发送 HTTP POST 请求。"""
        url = f"{self.base_url}/api/command"
        
        headers = {
            "Content-Type": "application/json",
            "X-Player-Id": self.player_id
        }
        
        if self.session_id:
            headers["X-Session-Id"] = self.session_id
        
        params = {"pid": self.player_id}
        
        response = self.client.post(
            url,
            json=payload,
            headers=headers,
            params=params
        )
        
        return response.json()
    
    def authenticate(self, descr: str = "HTTP API Client") -> dict:
        """
        向网关进行身份认证。
        
        参数：
            descr: 可选的客户端描述，用于日志记录/调试
        
        应保存成功认证响应中的 sessionId，
        并在重连时回传，以恢复牌局状态。
        """
        payload = {
            "token": self.token,
            "descr": descr  # 可选，用于服务端日志记录/调试
        }
        if self.session_id:
            # 携带 sessionId，以在重连时恢复之前的会话
            payload["sessionId"] = self.session_id
        
        result = self._make_request(payload)
        
        if result.get("result"):
            # 保存 sessionId，以备后续重连
            self.session_id = result.get("sessionId")
        
        return result
    
    def send_game_events(self, game_data: dict, events: list) -> dict:
        """向网关发送牌局事件。"""
        return self._make_request({
            "structType": "gameEvents",
            "game": game_data,
            "events": events
        })
    
    def get_answer(self, game_id: str, pot_for_alpha: int) -> dict:
        """请求机器人的决策。"""
        return self._make_request({
            "structType": "getAnswer",
            "gameId": game_id,
            "potForAlpha": pot_for_alpha,
            "delay": 0
        })
    
    def send_full_game_log(self, game_data: dict, events: list) -> dict:
        """发送完整牌局历史。"""
        return self._make_request({
            "structType": "fullGameLog",
            "game": game_data,
            "events": events
        })
    
    def ping(self) -> dict:
        """
        发送 ping，使会话在超过 295 秒后仍保持有效。
        
        此功能可选，仅在需要于长时间空闲期间维持会话时使用。
        约每 120 秒发送一次，以保持会话有效。
        
        如果不发送 ping，会话将在空闲 295 秒后过期。
        """
        return self._make_request({
            "structType": "ping"
        })
    
    def close(self):
        """关闭 HTTP 连接池。"""
        self.client.close()


def http_api_example():
    client = GatewayHTTPClient(
        base_url="https://api.example.com",
        player_id="player123",
        token="a0321e5f-4d84-4e32-bef3-48814dfe7e70"
    )
    
    try:
        auth_result = client.authenticate()
        print(f"Auth result: {auth_result}")
        
        if auth_result.get("result"):
            print(f"Session ID: {client.session_id}")
            
            game_data = {
                "gameId": "test_game_001",
                "pokerNetwork": "TEST",
                "gameType": "NL",
                "bigBlind": 100,
                "ante": 0,
                "currency": "USD",
                "gameDate": "1706789012345",
                "numPlayers": 2,
                "buttonSetToSeat": 1
            }
            
            events = [
                {"eventType": "playerSeated", "seat": 1, "name": "Hero", "stack": 10000},
                {"eventType": "playerSeated", "seat": 2, "name": "Villain", "stack": 10000},
                {"eventType": "blindPosted", "name": "Hero", "blindType": "SB", "amount": 50},
                {"eventType": "blindPosted", "name": "Villain", "blindType": "BB", "amount": 100},
                {"eventType": "stageStarted", "stage": "preflop", "cards": ""},
                {"eventType": "handDealt", "name": "Hero", "cards": "As,Ks"},
                {"eventType": "knownPlayerCards", "name": "Hero", "cards": "As,Ks"}
            ]
            
            client.send_game_events(game_data, events)
            
            answer = client.get_answer("test_game_001", 150)
            print(f"Bot decision: {answer}")
            
            # 可选：让会话在超过 295 秒后仍保持有效
            # 如果需要在长时间空闲期间维持会话，请取消以下代码的注释
            # def keepalive_loop():
            #     while True:
            #         time.sleep(120)  # 每 120 秒发送一次
            #         client.ping()
            # ping_thread = threading.Thread(target=keepalive_loop, daemon=True)
            # ping_thread.start()
    
    finally:
        client.close()


if __name__ == "__main__":
    http_api_example()

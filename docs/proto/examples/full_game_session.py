"""
完整牌局会话示例

本示例演示完整的扑克游戏会话，包括：
- 连接和身份认证
- 发送初始牌局状态
- 在每个下注轮请求决策
- 处理牌局的增量更新
- 在牌局结束时发送完整日志

建议从此示例入手，了解从建立连接到牌局结束的
完整协议流程。

依赖：
    pip install websockets

用法（从仓库根目录运行）：
    python doc/examples/full_game_session.py
"""

import asyncio
import websockets
import json
import ssl


async def play_full_game():
    uri = "wss://api.example.com/?pid=hero123"
    
    ssl_context = ssl.create_default_context()
    
    async with websockets.connect(uri, ssl=ssl_context) as ws:
        # 1. 身份认证（令牌是网关运营方提供的 UUID）
        # descr 是可选字段，用于服务端日志记录/调试
        await ws.send(json.dumps({
            "token": "a0321e5f-4d84-4e32-bef3-48814dfe7e70",
            "descr": "Full Game Session Example"
        }))
        auth = json.loads(await ws.recv())
        print(f"Auth: {auth}")
        # 保存 auth["sessionId"]，以便需要时重连
        
        if not auth.get("result"):
            return
        
        game_id = "game_12345_hero123"
        
        # 2. 初始牌局状态
        await ws.send(json.dumps({
            "structType": "gameEvents",
            "game": {
                "gameId": game_id,
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
                {"eventType": "playerSeated", "seat": 1, "name": "hero123", "stack": 10000},
                {"eventType": "playerSeated", "seat": 2, "name": "villain", "stack": 10000},
                {"eventType": "blindPosted", "name": "hero123", "blindType": "SB", "amount": 50},
                {"eventType": "blindPosted", "name": "villain", "blindType": "BB", "amount": 100},
                {"eventType": "stageStarted", "stage": "preflop", "cards": ""},
                {"eventType": "handDealt", "name": "hero123", "cards": "As,Ah"},
                {"eventType": "knownPlayerCards", "name": "hero123", "cards": "As,Ah"}
            ]
        }))
        
        # 3. 请求翻牌前行动建议
        await ws.send(json.dumps({
            "structType": "getAnswer",
            "gameId": game_id,
            "potForAlpha": 150,
            "delay": 0
        }))
        
        action = json.loads(await ws.recv())
        print(f"Preflop action: {action}")
        # 预期响应：{"structType": "playerAction", "gameId": "...", "name": "hero123", "action": "raise", "amount": 300}
        
        # 4. 加入己方行动和对手响应，更新牌局
        await ws.send(json.dumps({
            "structType": "gameEvents",
            "game": {
                "gameId": game_id,
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
                {"eventType": "playerSeated", "seat": 1, "name": "hero123", "stack": 10000},
                {"eventType": "playerSeated", "seat": 2, "name": "villain", "stack": 10000},
                {"eventType": "blindPosted", "name": "hero123", "blindType": "SB", "amount": 50},
                {"eventType": "blindPosted", "name": "villain", "blindType": "BB", "amount": 100},
                {"eventType": "stageStarted", "stage": "preflop", "cards": ""},
                {"eventType": "handDealt", "name": "hero123", "cards": "As,Ah"},
                {"eventType": "knownPlayerCards", "name": "hero123", "cards": "As,Ah"},
                {"eventType": "playerActed", "name": "hero123", "action": "raise", "amount": 300},
                {"eventType": "playerActed", "name": "villain", "action": "call", "amount": 250},
                {"eventType": "stageStarted", "stage": "flop", "cards": "Kh,7d,2c"}
            ]
        }))
        
        # 5. 请求翻牌圈行动建议
        await ws.send(json.dumps({
            "structType": "getAnswer",
            "gameId": game_id,
            "potForAlpha": 600,
            "delay": 0
        }))
        
        flop_action = json.loads(await ws.recv())
        print(f"Flop action: {flop_action}")
        
        # 6. 牌局结束，发送完整牌局日志
        await ws.send(json.dumps({
            "structType": "fullGameLog",
            "game": {
                "gameId": game_id,
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
                {"eventType": "playerSeated", "seat": 1, "name": "hero123", "stack": 10000},
                {"eventType": "playerSeated", "seat": 2, "name": "villain", "stack": 10000},
                {"eventType": "blindPosted", "name": "hero123", "blindType": "SB", "amount": 50},
                {"eventType": "blindPosted", "name": "villain", "blindType": "BB", "amount": 100},
                {"eventType": "stageStarted", "stage": "preflop", "cards": ""},
                {"eventType": "handDealt", "name": "hero123", "cards": "As,Ah"},
                {"eventType": "playerActed", "name": "hero123", "action": "raise", "amount": 300},
                {"eventType": "playerActed", "name": "villain", "action": "call", "amount": 250},
                {"eventType": "stageStarted", "stage": "flop", "cards": "Kh,7d,2c"},
                {"eventType": "playerActed", "name": "villain", "action": "check", "amount": 0},
                {"eventType": "playerActed", "name": "hero123", "action": "bet", "amount": 400},
                {"eventType": "playerActed", "name": "villain", "action": "fold", "amount": 0},
                {"eventType": "playerWon", "name": "hero123", "amount": 1000},
                {"eventType": "handShown", "name": "hero123", "cards": "As,Ah"},
                {"eventType": "noHandShown", "name": "villain"},
                {"eventType": "gameOver"}
            ]
        }))
        
        print("Game complete!")


if __name__ == "__main__":
    asyncio.run(play_full_game())

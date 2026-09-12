# PokerX Hyperf 后端接口文档

本文档以当前代码为准，供前端和调用方联调。

| 服务 | 默认地址 |
| --- | --- |
| HTTP API | http://127.0.0.1:18080 |
| WebSocket | ws://127.0.0.1:18081/ |
| 健康检查 | GET /health |

## 启动

    composer install
    cp .env.example .env
    # 配置 DB_*、Redis、APP_KEY、PROTO_URL、PROTO_TOKEN
    php bin/hyperf.php migrate --force
    php bin/hyperf.php start

APP_KEY 必须长期保持一致，否则已保存的双因素密钥和分页游标无法解密。

最新迁移增加了 game_type 和牌局唯一约束：
user_id + game_type + room_number + hand_number。
已有库若有重复牌局，需先处理重复数据才能迁移。

## HTTP 通用约定

除 POST /api/auth/login 外，所有 /api/* 请求必须带：

    Authorization: Bearer <id>|<secret>
    Content-Type: application/json
    Accept: application/json

Token 由 user_tokens 表保存，格式为 id|secret；数据库只保存 secret 的 SHA-256 哈希。Token 过期、登出或用户被禁用后不可使用。

成功响应：

    {
      "code": "success",
      "message": "ok",
      "data": {}
    }

错误响应：

    {
      "code": "auth_required",
      "message": "请先登录。",
      "data": null
    }

参数验证失败使用 code=event_invalid，并返回 details。限流使用 code=rate_limited。登录限制为每 IP 每分钟 10 次、每账号每分钟 5 次；已认证 API 每用户每分钟 120 次。

模型中的 id、user_id、game_id 在 JSON 中会转成字符串。时间为 ISO 8601。日期参数按 Asia/Shanghai 的自然日处理。

## HTTP 接口

### 健康检查

GET /health，无需鉴权：

    {"status":"ok"}

### 认证

#### POST /api/auth/login

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| account | string | 是 | 最长 64 字符，服务端转小写查询 |
| password | string | 是 | 最长 255 字符 |
| code | string | 否 | 已开启 TOTP 时必填，6 位验证码 |

请求：

    {"account":"alice","password":"correct-password","code":"123456"}

成功的 data：

    {
      "token": "12|plain-secret",
      "expires_at": "2026-10-12T12:00:00+00:00",
      "user": {
        "id": "1",
        "account": "alice",
        "nickname": "Alice",
        "language": "zh-CN",
        "is_vip": false,
        "two_factor_enabled": false
      }
    }

可能错误：auth_failed、two_factor_required、two_factor_invalid、rate_limited。

#### POST /api/auth/logout

需要鉴权，无请求字段。删除当前 Bearer Token，成功 data 为 null。

### 个人资料

#### GET /api/mine

需要鉴权。data：

    {
      "id": "1",
      "account": "alice",
      "nickname": "Alice",
      "language": "zh-CN",
      "is_vip": false,
      "two_factor_enabled": true,
      "credits": 1000
    }

credits 为数据库中的积分余额，单位为最小积分单位。

#### POST /api/mine/update_nickname

| 字段 | 类型 | 必填 | 约束 |
| --- | --- | --- | --- |
| nickname | string | 是 | 1–20 字符 |

请求：{"nickname":"新的昵称"}
成功 data：{"nickname":"新的昵称"}。

#### POST /api/mine/update_language

| 字段 | 类型 | 必填 | 允许值 |
| --- | --- | --- | --- |
| language | string | 是 | zh-CN、en |

请求：{"language":"en"}
成功 data：{"language":"en"}。

### 账户安全与双因素认证

#### POST /api/mine/security/change_password

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| current_password | string | 是 | 当前密码 |
| new_password | string | 是 | 10–255 字符 |
| confirmation | string | 是 | 必须与 new_password 一致 |
| code | string | 否 | 开启 TOTP 时必填，6 位 |

成功后撤销该用户全部 Token，data 为：

    {"requires_login":true}

#### POST /api/mine/security/create_two_factor

无请求字段。创建一个 5 分钟有效且绑定当前 Token 的临时设置。data：

    {
      "setup_id":"0c0d2ad0-a9a1-4f95-8b6a-853c435e1fb7",
      "secret":"BASE32SECRET",
      "otpauth_uri":"otpauth://totp/PokerX%3Aalice?secret=BASE32SECRET&issuer=PokerX"
    }

可能错误：two_factor_already_enabled。

#### POST /api/mine/security/confirm_two_factor

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| setup_id | UUID | 是 | create_two_factor 返回的 ID |
| current_password | string | 是 | 当前密码 |
| code | string | 是 | 6 位 TOTP |

成功后写入双因素密钥并撤销全部 Token，data 为 {"requires_login":true}。可能错误：setup_expired、auth_failed、two_factor_invalid。

#### POST /api/mine/security/cancel_two_factor

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| setup_id | UUID | 是 | 要取消的临时设置 ID |

只取消未确认的临时设置，不会关闭已启用的双因素认证。

### 列表查询和分页

列表请求通用参数：

| 参数 | 类型 | 说明 |
| --- | --- | --- |
| start / end | Y-m-d | 按 Asia/Shanghai 处理，包含起止日 |
| order | asc / desc | 默认 desc |
| limit | integer | 1–100；不同接口有默认值 |
| cursor | string | 上页返回的 next_cursor，必须原样传回 |

next_cursor 为加密游标，不可自行解析、修改或跨账号使用。

### 游戏

#### GET /api/mine/games

额外参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| result | all / win / loss | all | 按 profit 筛选 |
| snapshot | string | 无 | stats/summary 返回的快照 ID |

普通查询默认每页 5 条：

    {
      "items":[
        {
          "id":"9",
          "uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
          "room_number":"6437707",
          "hand_number":81,
          "game_type":"NL",
          "provider":"proto",
          "status":"closed",
          "profit":null
        }
      ],
      "next_cursor":"encrypted-cursor-or-null",
      "pending":[]
    }

snapshot 模式读取统计快照，返回 items、snapshot、next_cursor。game_over 后牌局进入 CLOSED，并以已写入的 winnings、bet_amount、profit 参与统计快照计算；本项目不使用 SETTLED 作为额外状态。

#### GET /api/mine/games/detail?game_id=<UUID>

game_id 必填。响应：

    {
      "game":{"uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55","status":"closed","players":[]},
      "live":null,
      "pending":false
    }

live 和 pending 来自 Redis 实时投影；没有实时投影时 live 为 null。找不到当前用户牌局时返回 not_found。

#### GET /api/mine/games/events?game_id=<UUID>

game_id 必填。额外参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| scope | all / mine | all | mine 仅返回本人相关行动及开始、求解、结束事件 |

默认每页 50 条：

    {
      "items":[
        {
          "id":"42",
          "uuid":"client-event-id",
          "seq":1,
          "type":"game_start",
          "payload":{},
          "created_at":"2026-09-12T12:00:00+00:00"
        }
      ],
      "next_cursor":null
    }

### 统计

#### GET /api/mine/stats/summary

参数为 start、end、snapshot。时间范围不得超过 3660 天，未传日期时默认为当天。

    {
      "lifetime":{"hands":0,"wins":0,"invested":0,"profit":0,"win_rate":null},
      "range":{"hands":0,"wins":0,"invested":0,"profit":0,"win_rate":null},
      "snapshot":"a2c7da00-480d-4868-9fca-bb8b9c6c98ea",
      "as_of":"2026-09-12T12:00:00+00:00"
    }

首次请求生成有效期 15 分钟的 Redis 快照。可把 snapshot 传给 trend 或 games 以使用同一数据集合。统计只计算 status=SETTLED 的游戏。

#### GET /api/mine/stats/trend

参数与 summary 相同。响应：

    {
      "items":[{"label":"2026-09-12","delta":120,"cumulative":120}],
      "snapshot":"a2c7da00-480d-4868-9fca-bb8b9c6c98ea",
      "as_of":"2026-09-12T12:00:00+00:00"
    }

同一天按牌局显示；2–93 天按日聚合；超过 93 天按月聚合。

### 积分

积分使用整数最小单位：1 积分 = 100 最小单位。人工发放：

    php bin/hyperf.php credit:grant alice 10 --id=<UUID> --description="manual grant"

#### GET /api/mine/credit

从 Redis 实时状态读取：

    {
      "balance":1000,
      "reserved":0,
      "available":1000,
      "sync_pending":false
    }

当前用户没有 Redis 实时状态时返回 state_recovering。

#### GET /api/mine/credit/record

支持通用分页参数，以及：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| type | all / grant / consume | all | 流水类型 |

默认每页 10 条：

    {
      "items":[
        {
          "id":"7",
          "uuid":"idempotency-uuid",
          "type":"grant",
          "amount":1000,
          "balance":1000,
          "before":0,
          "description":"manual grant"
        }
      ],
      "next_cursor":null,
      "summary":{"grant":1000,"consume":0}
    }

## WebSocket

### 连接和认证

认证在握手阶段完成，必须把 HTTP 登录得到的 Token 放入 URL 查询参数：

    ws://127.0.0.1:18081/?token=12|plain-secret

服务端校验 Token 哈希、有效期和用户状态。认证失败会直接断开，不会发送 auth_ok 帧。

### 通用消息信封

每个上行消息均为 JSON：

    {
      "id":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "type":"game_start",
      "timestamp":1789185600000,
      "payload":{}
    }

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | string | 是 | 非空客户端事件 ID，写入 events.uuid |
| type | string | 是 | 下方支持的事件名 |
| timestamp | integer | 是 | Unix 毫秒，不能早于服务器当前时间 10 秒以上 |
| payload | object | 事件而定 | 事件载荷 |

服务端帧结构：

    {
      "id":"server-uuid",
      "type":"game_start.ack",
      "timestamp":1789185600001,
      "reply_to":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "payload":{"game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

reply_to 对应客户端 id。成功接收游戏事件的回执名为事件名加 .ack；错误事件 type 为 error。当前版本未实现事件重传幂等，客户端不应重复发送已成功接收的事件。

### 心跳

上行 ping：

    {"id":"77210ea4-7807-4788-9002-138542b8a5d9","type":"ping","timestamp":1789185600000,"payload":{}}

下行 pong：

    {"id":"server-uuid","type":"pong","timestamp":1789185600001,"reply_to":"77210ea4-7807-4788-9002-138542b8a5d9","payload":[]}

### 游戏生命周期

可用事件：

    game_start
    game_stage
    game_play_acted
    game_known_play_cards
    game_request_action
    game_over

推荐顺序：

    game_start
      → game_stage / game_play_acted / game_known_play_cards / game_request_action ...
      → game_over

game_start 回执的 payload.game_uuid 是服务端牌局标识。之后所有游戏事件必须在 payload.game_uuid 中携带它。服务端校验当前用户的牌局归属，并按接收顺序保存 events 和递增 seq。

#### game_start

    {
      "id":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "type":"game_start",
      "timestamp":1789185600000,
      "payload":{
        "room_number":"6437707",
        "hand_number":81,
        "provider":"proto",
        "game_type":"NL",
        "big_blind":100,
        "small_blind":50,
        "ante":0,
        "players":[
          {
            "seat":1,
            "name":"Hero",
            "hero":true,
            "stack":10000,
            "seat_type":"SB",
            "amount":null,
            "cards":["As","Qd"]
          },
          {
            "seat":2,
            "name":"Villain",
            "hero":false,
            "stack":10000,
            "seat_type":"BB",
            "amount":100,
            "cards":[]
          }
        ]
      }
    }

| 字段 | 类型 | 必填 | 约束/说明 |
| --- | --- | --- | --- |
| room_number | string | 是 | 最长 64 字符 |
| hand_number | integer | 是 | 最小 1 |
| provider | string | 是 | 最长 32，例如 proto 或 mock |
| game_type | string | 否 | 最长 32，默认 NL |
| big_blind | number | 是 | 0.0001–9999999999.9999，最多 4 位小数 |
| small_blind | number | 是 | 同上，且不大于 big_blind |
| ante | number | 否 | 默认 0，最多 4 位小数 |
| players | array | 是 | 至少一名玩家 |
| players[].seat | integer | 是 | 最小 1，数组内唯一 |
| players[].name | string | 是 | 最长 64，数组内唯一 |
| players[].hero | boolean | 是 | 是否本人 |
| players[].stack | number | 是 | 非负，最多 4 位小数 |
| players[].seat_type | string | 是 | SB、BB、BTN、UTG、UTG1、UTG2、MP、HJ、CO |
| players[].amount | number/null | 是 | 非负，最多 4 位小数；当前保存原始开局 payload |
| players[].cards | array | 否 | 已知手牌 |

成功回执：

    {
      "type":"game_start.ack",
      "reply_to":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "payload":{"game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

同一用户的 game_type、room_number、hand_number 组合只能创建一局。

#### game_stage

    {
      "id":"a2e11c7b-25a9-4e9f-bc4d-b5f862623a3f",
      "type":"game_stage",
      "timestamp":1789185601000,
      "payload":{
        "game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "stage":"preflop",
        "cards":[]
      }
    }

game_uuid 必填。当前版本保存完整 payload；Provider 使用 stage 和 cards 构建上游历史。回执为 game_stage.ack。

#### game_play_acted

    {
      "id":"d6f20a72-0a24-4274-9f1a-6bf7f0b5d31f",
      "type":"game_play_acted",
      "timestamp":1789185602000,
      "payload":{
        "game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "name":"Villain",
        "action":"raise",
        "amount":200
      }
    }

game_uuid 必填。完整 payload 会被保存；Provider 读取 name、action、amount。回执为 game_play_acted.ack。

#### game_known_play_cards

    {
      "id":"c52f75c9-337d-4df8-a406-5d1adb2b345e",
      "type":"game_known_play_cards",
      "timestamp":1789185603000,
      "payload":{
        "game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "name":"Villain",
        "cards":["Ks","Kd"]
      }
    }

game_uuid 必填。完整 payload 会被保存；Provider 读取 name、cards。回执为 game_known_play_cards.ack。

#### game_request_action

    {
      "id":"c4c1a621-c03a-47d0-a528-1ab00c099c87",
      "type":"game_request_action",
      "timestamp":1789185604000,
      "payload":{"game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

先收到接收回执：

    {
      "type":"game_request_action.ack",
      "reply_to":"c4c1a621-c03a-47d0-a528-1ab00c099c87",
      "payload":{"game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

Provider 成功后异步下发建议：

    {
      "id":"server-uuid",
      "type":"game_play_action",
      "timestamp":1789185604500,
      "reply_to":"c4c1a621-c03a-47d0-a528-1ab00c099c87",
      "payload":{
        "game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "action":"raise",
        "amount":200
      }
    }

action 可为 fold、check、call、bet、raise、all-in。只有这个事件会向 ProtoProvider 上游 WebSocket 发送求解请求。

#### game_over

    {
      "id":"69bd1f44-4970-471d-8e51-c1d6ee03f0af",
      "type":"game_over",
      "timestamp":1789185605000,
      "payload":{
        "game_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "winner":{"name":"Hero","amount":2650},
        "shown":[{"name":"Hero","cards":["As","Qd"]}]
      }
    }

game_uuid、winner.name、winner.amount 必填；shown 可选，出现时每项均须含 name 和两张 cards。成功后状态从 OPEN 变为 CLOSED（不会进入 SETTLED）；若 winner 是 Hero，则 winnings 为 winner.amount，否则为 0；profit = winnings - bet_amount。回执为 game_over.ack。

### Provider 连接模型

PokerManager 和 Provider 均按 Hyperf Worker 常驻。首次使用 proto Provider 时，服务会启动到 PROTO_URL 的连接循环；连接断开后 SocketProvider 自动重连。一个连接可处理多局游戏，上游以 game_uuid（Proto 的 gameId）关联请求和响应。

start、game_stage、game_play_acted、game_known_play_cards 的 Provider 空实现是当前设计；只有 game_request_action 会向上游发送请求。设置 POKER_PROVIDER=mock 可使用本地模拟 Provider。

## 开发校验

    composer test
    composer analyse
    composer pint

发布前应使用独立 MySQL、Redis 和端口补充覆盖登录、WebSocket 建局、事件写入、game_over 状态与 Provider 回调的集成测试。

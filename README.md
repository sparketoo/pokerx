# PokerX Hyperf 后端接口文档

本文档以当前代码为准，供前端和调用方联调。

| 服务 | 默认地址 |
| --- | --- |
| HTTP API | http://127.0.0.1:18080 |
| WebSocket | ws://127.0.0.1:18081/ |
| 健康检查 | GET /api/health |

## 启动

    composer install
    cp .env.example .env
    # 配置 DB_*、Redis、APP_KEY、PROTO_URL、PROTO_TOKEN
    php bin/hyperf.php migrate --force
    php bin/hyperf.php start

APP_KEY 必须长期保持一致，否则已保存的双因素密钥和分页游标无法解密。

最新迁移增加了 network 和牌局唯一约束：
user_id + network + room_number + hand_number。
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

GET /api/health，无需鉴权：

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

无请求字段。创建一个 5 分钟有效且绑定当前 Token 的临时设置。state 是加密状态，确认时必须原样提交；服务端不保存临时设置。data：

    {
      "state":"encrypted-state",
      "secret":"BASE32SECRET",
      "otpauth_uri":"otpauth://totp/PokerX%3Aalice?secret=BASE32SECRET&issuer=PokerX"
    }

可能错误：two_factor_already_enabled。

#### POST /api/mine/security/confirm_two_factor

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| state | string | 是 | create_two_factor 返回的绑定状态 |
| current_password | string | 是 | 当前密码 |
| code | string | 是 | 6 位 TOTP |

成功后写入双因素密钥并撤销全部 Token，data 为 {"requires_login":true}。可能错误：setup_expired、auth_failed、two_factor_invalid。

#### POST /api/mine/security/cancel_two_factor

无请求字段。临时设置只保存在客户端的 state 中，取消即丢弃 state，不会关闭已启用的双因素认证。

### 列表查询和分页

列表请求通用参数：

| 参数 | 类型 | 说明 |
| --- | --- | --- |
| start / end | Y-m-d | 按 Asia/Shanghai 处理，包含起止日 |
| order | asc / desc | 默认 desc |
| limit | integer | 1–100；不同接口有默认值 |
| cursor | string | 上页返回的 next_cursor，必须原样传回 |

next_cursor 为 Hyperf 原生游标，必须原样传回；不要自行修改，也不要跨筛选条件使用。

### 游戏

#### GET /api/mine/games

额外参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| result | all / win / loss | all | 按 profit 筛选 |

普通查询默认每页 5 条：

    {
      "items":[
        {
          "id":"9",
          "uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
          "room_number":"6437707",
          "hand_number":81,
          "network":"we",
          "provider":"proto",
          "status":"closed",
          "profit":null
        }
      ],
      "next_cursor":"encrypted-cursor-or-null",
      "pending":[]
    }

游戏列表直接查询数据库。hand_over 后牌局进入 CLOSED，并以已写入的 winnings、bet_amount、profit 参与统计；本项目不使用 SETTLED 作为额外状态。

#### GET /api/mine/games/detail?game_id=<UUID>

game_id 必填。响应：

    {
      "game":{"uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55","status":"closed","players":[]},
      "live":null,
      "pending":false
    }

该接口直接查询数据库；live 固定为 null、pending 固定为 false。找不到当前用户牌局时返回 not_found。

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
          "type":"hand_start",
          "payload":{},
          "created_at":"2026-09-12T12:00:00+00:00"
        }
      ],
      "next_cursor":null
    }

#### GET /api/mine/events

查询当前用户的全部事件，并在每条事件中附带所属牌局的基础上下文。支持通用列表参数
`cursor`、`limit`、`order`，以及可选的 `keyword`；关键字匹配事件类型与 JSON 载荷。

    {
      "items":[
        {
          "id":"42",
          "uuid":"client-event-id",
          "user_id":"1",
          "game_id":"9",
          "seq":1,
          "type":"hand_start",
          "payload":{},
          "created_at":"2026-09-12T12:00:00+00:00",
          "updated_at":"2026-09-12T12:00:00+00:00",
          "game":{
            "uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
            "room_number":"6437707",
            "hand_number":81,
            "network":"we"
          }
        }
      ],
      "next_cursor":"encrypted-cursor-or-null"
    }

### 统计

#### GET /api/mine/stats/summary

参数为 start、end。时间范围不得超过 3660 天，未传日期时默认为当天。

    {
      "lifetime":{"hands":0,"wins":0,"invested":0,"profit":0,"win_rate":null},
      "range":{"hands":0,"wins":0,"invested":0,"profit":0,"win_rate":null},
      "as_of":"2026-09-12T12:00:00+00:00"
    }

每次请求直接计算数据库中的 status=CLOSED 牌局，不使用缓存。

#### GET /api/mine/stats/trend

参数与 summary 相同。响应：

    {
      "items":[{"label":"2026-09-12 20:00","delta":120,"cumulative":120}],
      "as_of":"2026-09-12T12:00:00+00:00"
    }

同一天按牌局显示，标签为牌局结束时间（上海时区 `YYYY-MM-DD HH:mm`）；2–93 天按日聚合；超过 93 天按月聚合。

### 积分

积分使用整数最小单位：1 积分 = 100 最小单位。人工发放：

    php bin/hyperf.php credit:grant alice 10 --id=<UUID> --description="manual grant"

#### GET /api/mine/credit

直接读取用户账户余额：

    {
      "credit_balance":1000
    }

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
      "type":"hand_start",
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
      "type":"hand_start.ack",
      "timestamp":1789185600001,
      "reply_to":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "payload":{"hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

reply_to 对应客户端 id。成功接收游戏事件的回执名为事件名加 .ack；错误事件 type 为 error。当前版本未实现事件重传幂等，客户端不应重复发送已成功接收的事件。

### 心跳

上行 ping：

    {"id":"77210ea4-7807-4788-9002-138542b8a5d9","type":"ping","timestamp":1789185600000,"payload":{}}

下行 pong：

    {"id":"server-uuid","type":"pong","timestamp":1789185600001,"reply_to":"77210ea4-7807-4788-9002-138542b8a5d9","payload":[]}

### 扑克金额单位

所有扑克金额（筹码、大小盲、前注、补盲、下注、底池、建议金额及结算）统一使用整数筹码。
OK 原生数值、Game、PokerServer、数据库和 Provider 之间不做缩放，执行建议也直接使用整数。
只有 `game.vue` 展示时除以 100，固定保留两位小数：例如协议金额 `125` 展示为 `$1.25`。
PokerServer 仅接受 JSON 整数，拒绝小数、数字字符串、负数及超过 `9007199254740991` 的金额；
大小盲和补盲必须大于零。Provider 的小数建议会被拒绝，不能截断后执行。
原始建表迁移直接使用 BIGINT，金额字段非负、收益字段可为负；模型及金额计算同样使用整数。
本次不兼容旧金额数据，不提供数据换算迁移。修改原迁移不会改变已创建的表；已有开发库需重建后才采用新字段类型。
`player_acted.amount` 是本次实际投入，raise 也包含本次投入中的跟注部分；不是单独加注幅度。
该单位规则不涉及积分，积分接口仍使用独立约定的最小单位。

### 游戏生命周期

可用事件：

    hand_start
    hand_refresh
    force_bet
    hand_card
    stage_start
    player_acted
    known_play_cards
    request_action
    hand_over

推荐顺序：

    hand_start
      → force_bet
      → hand_card
      → stage_start / player_acted / known_play_cards / request_action ...
      → hand_over

hand_start 回执的 payload.hand_uuid 是服务端牌局标识。之后所有游戏事件必须在 payload.hand_uuid 中携带它。服务端校验当前用户的牌局归属，并按接收顺序保存 events 和递增 seq。

#### hand_refresh

`hand_refresh` 用于断线重连或中途开始采集时补交整局快照，因此不需要
`hand_uuid`。`payload` 采用 `hand_start` 的全部开局字段，并额外携带按发生顺序排列的
`events`；其中每项由 `type` 和 `payload` 组成。`events` 必须先包含初始化 `force_bet`，随后可追加仅含 `extra_bets` 的 `force_bet`，
再包含唯一的 `hand_card`，之后才允许 `stage_start`、`player_acted`、`known_play_cards` 和 `hand_over`。
`hand_over` 只能出现一次且必须在最后。

    {
      "id":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "type":"hand_refresh",
      "timestamp":1789185600000,
      "payload":{
        "room_number":"6437707",
        "hand_number":81,
        "network":"WE",
        "players":[
          {"seat":1,"name":"Hero","hero":true,"stack":10000,"seat_type":"SB"},
          {"seat":2,"name":"Villain","hero":false,"stack":10000,"seat_type":"BB"}
        ],
        "events":[
          {"type":"force_bet","payload":{"ante":0,"small_blind":50,"big_blind":100}},
          {"type":"hand_card","payload":{"cards":["As","Qd"]}},
          {"type":"stage_start","payload":{"stage":"preflop","cards":[]}},
          {"type":"player_acted","payload":{"name":"Hero","action":"raise","amount":200}},
          {"type":"hand_over","payload":{"winners":[{"name":"Hero","amount":300}]}}
        ]
      }
    }

服务端以当前用户的 `network + room_number + hand_number` 查找牌局：不存在时新建并扣除一次
牌局积分；存在时保留原 `hand_uuid`，以该快照完整替换玩家、事件和汇总金额，不重复扣费。
无论新建还是更新，都会返回 `hand_refresh.ack.payload.hand_uuid`。

#### hand_start

    {
      "id":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "type":"hand_start",
      "timestamp":1789185600000,
      "payload":{
        "room_number":"6437707",
        "hand_number":81,
        "network":"WE",
        "players":[
          {
            "seat":1,
            "name":"Hero",
            "hero":true,
            "stack":10000,
            "seat_type":"SB"
          },
          {
            "seat":2,
            "name":"Villain",
            "hero":false,
            "stack":10000,
            "seat_type":"BB"
          }
        ]
      }
    }

| 字段 | 类型 | 必填 | 约束/说明 |
| --- | --- | --- | --- |
| room_number | string | 是 | 最长 64 字符 |
| hand_number | integer | 是 | 最小 1 |
| network | enum | 是 | 前端上报游戏平台；`OK`、`WE` |
| players | array | 是 | 至少一名玩家 |
| players[].seat | integer | 是 | 最小 1，数组内唯一 |
| players[].name | string | 是 | 最长 64，数组内唯一 |
| players[].hero | boolean | 是 | 是否本人 |
| players[].stack | number | 是 | 非负，最多 4 位小数 |
| players[].seat_type | string | 是 | SB、BB、BTN、UTG、UTG1、UTG2、MP、HJ、CO |

成功回执：

    {
      "type":"hand_start.ack",
      "reply_to":"c753a3ce-26de-47e2-b249-b7db5654b944",
      "payload":{"hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

`provider` 不接收前端指定值，始终采用后端 `POKER_PROVIDER` 的默认 Provider。

同一用户的 network、room_number、hand_number 组合只能创建一局。

#### force_bet

    {
      "id":"c2e11c7b-25a9-4e9f-bc4d-b5f862623a3f",
      "type":"force_bet",
      "timestamp":1789185600500,
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "ante":0,
        "small_blind":50,
        "big_blind":100
      }
    }

首次 `force_bet` 必须紧跟 `hand_start`，`hand_uuid`、`small_blind`、`big_blind` 必填；
`ante` 可选，默认 0。服务端根据座位类型写入大小盲，并更新底池与 Hero 投入。
首次事件可附带 `extra_bets`，表示大小盲和统一前注之外的实际额外投入：

    {"hand_uuid":"…","small_blind":50,"big_blind":100,"ante":0,
     "extra_bets":[{"name":"Player3","type":"post","amount":100},
                   {"name":"Player4","type":"straddle","amount":200}]}

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| extra_bets | array | 可选；出现时 1–18 项，按发生顺序排列 |
| extra_bets[].name | string | 本手已声明的玩家名称 |
| extra_bets[].type | string | `post`（入桌补盲）或 `straddle` |
| extra_bets[].amount | number | 本次实际新增投入，必须是大于 0 的整数；不是累计桌面金额 |

动态 Straddle 等后续强制投入继续使用 `force_bet`，但只能发送 `hand_uuid` 和非空
`extra_bets`；不得重传 `small_blind`、`big_blind` 或 `ante`。允许在 `hand_card` 之前
或 preflop 阶段追加，进入 flop 等后续阶段、牌局关闭后拒绝追加：

    {"hand_uuid":"…","extra_bets":[{"name":"Player4","type":"straddle","amount":200}]}

服务端将额外投入累加到底池；属于 Hero 的部分也累加到 Hero 投入。追加不会重新计入基础
盲注，也不会更改牌桌名义大小盲。Proto Provider 按原始顺序输出 `blindPosted` 的
`POST`、`STRADDLE`，统一前注输出每位玩家的 `ANTE`，不会将强制投入伪装成普通跟注。
该映射依据 [Proto 协议](../proto/PROTOCOL.md#blindposted)；尚需真实上游联调确认执行效果。
`hand_refresh` 同样保留这些强制投入和顺序。客户端事件仍无重传幂等，成功接收的增量不能重发。

#### hand_card

    {
      "id":"d2e11c7b-25a9-4e9f-bc4d-b5f862623a3f",
      "type":"hand_card",
      "timestamp":1789185600750,
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "cards":["As","Qd"]
      }
    }

hand_uuid 与两张 cards 必填。该事件只表示当前客户端的 Hero 手牌，因此不接收 name；服务端会
写入 Hero 玩家记录。它必须在初始化 force_bet 后、普通行动及阶段事件前出现，且只能出现一次。

#### stage_start

    {
      "id":"a2e11c7b-25a9-4e9f-bc4d-b5f862623a3f",
      "type":"stage_start",
      "timestamp":1789185601000,
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "stage":"preflop",
        "cards":[]
      }
    }

hand_uuid 必填。当前版本保存完整 payload；Provider 使用 stage 和 cards 构建上游历史。回执为 stage_start.ack。

#### player_acted

    {
      "id":"d6f20a72-0a24-4274-9f1a-6bf7f0b5d31f",
      "type":"player_acted",
      "timestamp":1789185602000,
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "name":"Villain",
        "action":"raise",
        "amount":200
      }
    }

hand_uuid 必填。完整 payload 会被保存；Provider 读取 name、action、amount。回执为 player_acted.ack。

#### known_play_cards

    {
      "id":"c52f75c9-337d-4df8-a406-5d1adb2b345e",
      "type":"known_play_cards",
      "timestamp":1789185603000,
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "name":"Villain",
        "cards":["Ks","Kd"]
      }
    }

hand_uuid 必填。完整 payload 会被保存；Provider 读取 name、cards。回执为 known_play_cards.ack。

#### game_request_action

    {
      "id":"c4c1a621-c03a-47d0-a528-1ab00c099c87",
      "type":"request_action",
      "timestamp":1789185604000,
      "payload":{"hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55"}
    }

Provider 成功后，服务端通过该事件的回执返回求解建议：

    {
      "id":"server-uuid",
      "type":"request_action.ack",
      "timestamp":1789185604500,
      "reply_to":"c4c1a621-c03a-47d0-a528-1ab00c099c87",
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "action":"raise",
        "amount":200
      }
    }

action 可为 fold、check、call、bet、raise、all-in。只有这个事件会向 ProtoProvider 上游 WebSocket 发送求解请求。

#### hand_over

    {
      "id":"69bd1f44-4970-471d-8e51-c1d6ee03f0af",
      "type":"hand_over",
      "timestamp":1789185605000,
      "payload":{
        "hand_uuid":"c69424e0-71bd-4711-8bb3-31b2ad650d55",
        "winners":[{"name":"Hero","amount":1325},{"name":"Villain","amount":1325}],
        "shown":[{"name":"Hero","cards":["As","Qd"]}]
      }
    }

hand_uuid 和非空 winners 数组必填；每项的 name 必须是本手玩家且不能重复，amount 为非负安全整数（最大 9007199254740991），拒绝小数和数字字符串。单赢家也用单项数组，不再接受旧 winner 字段。shown 可选，出现时每项均须含 name 和两张 cards，表示实际摊牌者，与赢家名单独立。

winners 只表示主池获奖者及各自实际分配的金额，不包括边池收益；按游戏平台实际结果上报，不自行平均分配或调整余数筹码。该列表保存在 events.payload JSON 内，无需新增表。旧数据如含 winner，应一次性转成单项 winners；前后端须协调更新。

成功后状态从 OPEN 变为 CLOSED（不会进入 SETTLED）；winnings 为 winners 中 Hero 的金额，没有 Hero 则为 0；profit = winnings - bet_amount。这里仍是主池结算口径，不能代表包含边池收益的完整盈亏。回执为 hand_over.ack，确认的是本地保存；第三方异步拒绝继续通过 hand_over.error 反馈，不代表第三方已确认入账。

ProtoProvider 在一次 fullGameLog 中为每位赢家生成一条 playerWon，随后仅生成一条 gameOver；实际 handShown 在这些获奖事件之前。实时上报与 hand_refresh 使用相同结算校验。

### Provider 连接模型

PokerManager 和 Provider 均按 Hyperf Worker 常驻。首次使用 proto Provider 时，服务会启动到 PROTO_URL 的连接循环；连接断开后 SocketProvider 自动重连。一个连接可处理多局游戏，上游以 game_uuid（Proto 的 gameId）关联请求和响应。
求解回调只结算一次；默认从受理请求起最多等待 20 秒，可通过 `PROTO_REQUEST_TIMEOUT`（秒）
配置，必须是正有限数。超时返回 `solve_timeout`，断连或主动关闭返回 `provider_unavailable`，
错误帧保留原请求的 `reply_to`。成功、失败、关闭都会清理回调及计时器，不自动重发。
由于上游只用牌局 ID 关联响应，超时后会断开该上游连接，其他在途请求也返回连接不可用，
连接循环随后重连；客户端可按当前行动机会手动重试。

hand_card、stage_start、player_acted、known_play_cards 的 Provider 空实现是当前设计；只有 request_action 会向上游发送请求。设置 POKER_PROVIDER=mock 可使用本地模拟 Provider。

## 开发校验

    composer test
    composer analyse
    composer pint

发布前应使用独立 MySQL、Redis 和端口补充覆盖登录、WebSocket 建局、事件写入、hand_over 状态与 Provider 回调的集成测试。

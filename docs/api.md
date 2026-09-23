# PokerX API 与 GameServer 协议

本文面向调用 PokerX 的客户端。HTTP API 用于登录、账户设置和查询已保存的牌局；GameServer WebSocket 用于实时上报牌局及获取行动建议。两者使用同一登录令牌，但消息格式不同。部署方提供 HTTP 和 WebSocket 的实际地址；下文只规定路径及报文。

## 1. 通用约定

### 1.1 地址、格式和身份认证

| 通道 | 地址 | 格式 | 认证 |
| --- | --- | --- | --- |
| HTTP API | 部署方提供的 HTTP(S) 地址下的 `/api/*` | JSON；GET 参数放在查询字符串，POST 参数放在 JSON 请求体 | 除登录和健康检查外，`Authorization: Bearer <token>` |
| GameServer | 部署方提供的 WebSocket(S) 地址下的 `/` | UTF-8 JSON 文本帧 | 连接 URL 的 `token` 查询参数，例如 `wss://<host>/?token=<token>` |

登录返回的 `token` 形如 `<令牌记录 ID>|<密钥>`。令牌默认在签发后 30 天过期；退出登录、修改密码、确认或取消两步验证后，相关令牌会失效。令牌应作为完整字符串传递。WebSocket 可附加 `locale` 查询参数设置错误消息语言，例如 `?token=...&locale=en-US`。

HTTP 请求可用 `X-Language: zh-CN` 或 `X-Language: en-US` 选择错误消息语言；未提供时使用用户设置的语言。业务判断应依赖 `code`，不要依赖本地化的 `message`。

### 1.2 HTTP 响应和错误

成功响应为：

```json
{"code":"success","message":"ok","data":{}}
```

无数据的成功响应中 `data` 为 `null`。业务错误和参数校验错误通常仍返回 HTTP 200：

```json
{"code":"event_invalid","message":"参数无效","details":{"game_id":["..."]},"data":null}
```

`details` 仅在有附加信息时出现。未匹配的 HTTP 路由可返回 404；未处理的服务端错误可返回 500。限流的协议错误码为 `rate_limited`，目前也以 HTTP 200 返回。客户端应同时检查 HTTP 状态码和响应体 `code`。

HTTP 的通用错误码包括 `auth_failed`、`auth_required`、`two_factor_required`、`two_factor_invalid`、`two_factor_already_enabled`、`setup_expired`、`event_invalid`、`not_found`、`rate_limited`、`server_error`。错误消息会随语言变化。

登录请求按来源 IP 每分钟最多 10 次、按标准化账号每分钟最多 5 次；其他需要认证的 HTTP API 按用户每分钟最多 120 次。

### 1.3 数据表示

- JSON 中的数据库 `id` 和以 `_id` 结尾的数据库字段以**字符串**返回；GameServer 的 `game_uuid` 与 HTTP 的 `uuid` 是同一个 16 字符牌局标识。接口参数 `game_id` 也填写这个牌局标识，而非数据库数值 ID。
- HTTP 时间字段是带时区偏移的 ISO 8601 字符串；GameServer `timestamp` 是 Unix 毫秒时间戳。日期筛选使用 `YYYY-MM-DD`，按 `Asia/Shanghai` 日历日计算，结束日包含整天。
- 筹码、盲注、下注、奖金、收益均为整数；`profit = winnings - total`。客户端不要把这些整数直接解释成带小数的货币金额。
- 列表中的 `next_cursor` 为不透明字符串或 `null`。获取下一页时原样回传，并保持其余筛选及排序参数不变。

## 2. HTTP API

### 2.1 端点总览

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| GET | `/api/health` | 健康检查，无需认证 |
| POST | `/api/auth/login` | 登录并取得令牌，无需认证 |
| POST | `/api/auth/logout` | 注销当前令牌 |
| GET | `/api/mine` | 当前用户资料 |
| POST | `/api/mine/update_nickname` | 修改昵称 |
| POST | `/api/mine/update_language` | 修改语言 |
| POST | `/api/mine/security/change_password` | 修改密码 |
| POST | `/api/mine/security/create_two_factor` | 创建两步验证绑定信息 |
| POST | `/api/mine/security/confirm_two_factor` | 确认启用两步验证 |
| POST | `/api/mine/security/cancel_two_factor` | 取消两步验证 |
| GET | `/api/mine/stats/summary` | 累计及日期范围统计 |
| GET | `/api/mine/stats/trend` | 日期范围收益走势 |
| GET | `/api/mine/games` | 牌局列表 |
| GET | `/api/mine/games/detail` | 牌局详情 |
| GET | `/api/mine/games/events` | 指定牌局事件 |
| GET | `/api/mine/events` | 跨牌局事件搜索 |

下文的 `data` 示例只展示成功响应中的 `data` 值；完整响应仍包在 1.2 节的 `code/message/data` 结构中。

### 2.2 健康检查与登录

`GET /api/health` 返回 `{"code":"success","message":"ok","data":{"status":"ok"}}`。

`POST /api/auth/login` 请求体：

| 字段 | 类型 | 必填 | 约束及含义 |
| --- | --- | --- | --- |
| `account` | string | 是 | 最长 64 字符；匹配前会去首尾空白并转小写 |
| `password` | string | 是 | 最长 255 字符 |
| `two_factor_code` | string | 启用两步验证时是 | 6 位数字验证码 |

成功 `data`：

```json
{
  "token": "12|<secret>",
  "expires_at": "2026-10-23T12:00:00+08:00",
  "user": {
    "id": "1",
    "account": "alice@example.com",
    "nickname": "Alice",
    "language": "zh-CN",
    "two_factor_enabled": false
  }
}
```

`POST /api/auth/logout` 无请求体，注销当前 Bearer 令牌，成功时 `data: null`。

### 2.3 用户资料与设置

`GET /api/mine` 的 `data` 是登录响应中的 `user` 对象，字段相同。

| 端点 | JSON 请求体 | 成功 `data` |
| --- | --- | --- |
| `POST /api/mine/update_nickname` | `{"nickname":"新昵称"}`，1–20 字符 | `{"nickname":"新昵称"}` |
| `POST /api/mine/update_language` | `{"language":"en-US"}`，只能是 `zh-CN` 或 `en-US` | `{"language":"en-US"}` |

### 2.4 密码与两步验证

| 端点 | JSON 请求体 | 成功 `data` |
| --- | --- | --- |
| `POST /api/mine/security/change_password` | `current_password`、`new_password`（10–255 字符）、`confirmation`（与新密码一致）；启用两步验证时还需 `code`（6 位数字） | `{"requires_login":true}` |
| `POST /api/mine/security/create_two_factor` | 无 | `state`、`secret`、`otpauth_uri` |
| `POST /api/mine/security/confirm_two_factor` | `state`、`current_password`、`code`（6 位数字） | `{"requires_login":true}` |
| `POST /api/mine/security/cancel_two_factor` | 已启用时需 `current_password` 和 `code`（6 位数字） | 已启用时为 `{"requires_login":true}`；原本未启用时为 `null` |

绑定流程：先调用 `create_two_factor`，向用户展示 `otpauth_uri` 或 `secret`，再以同一登录令牌调用 `confirm_two_factor`。`state` 为不透明字符串，仅在 5 分钟内、同一用户及同一令牌下有效。确认、取消及修改密码成功后，**该用户全部登录令牌被撤销**，客户端应重新登录。`create_two_factor` 只生成待确认的绑定信息，不会立即启用两步验证。

### 2.5 牌局统计

`GET /api/mine/stats/summary` 与 `GET /api/mine/stats/trend` 接受相同的可选查询参数：`start`、`end`，格式均为 `YYYY-MM-DD`。两者都省略时，范围是上海时区的当天；仅提供一端时，另一端默认为当天。起始日期不得晚于结束日期，范围不能超过 3660 天。

`summary` 的 `data` 示例：

```json
{
  "lifetime": {"hands": 12, "wins": 5, "total": 1200, "profit": 180, "win_rate": 0.4166666666666667},
  "range": {"hands": 2, "wins": 1, "total": 200, "profit": 40, "win_rate": 0.5},
  "as_of": "2026-09-23T12:00:00+08:00"
}
```

| 字段 | 含义 |
| --- | --- |
| `hands` | 已保存的牌局数，包含不同状态的牌局 |
| `wins` | `profit > 0` 的牌局数 |
| `total` | Hero 的累计投入 |
| `profit` | Hero 的累计收益，可为负数 |
| `win_rate` | `wins / hands`；没有牌局时为 `null`，值在 0–1 之间 |
| `as_of` | 本次统计生成时间 |

`trend` 返回 `items` 数组和 `as_of` 时间；例如单日查询中的一项是 `{"label":"2026-09-23 12:30","profit":40,"cumulative_profit":40}`。查询范围为 1 天时，每条记录对应一局，`label` 格式为 `YYYY-MM-DD HH:mm`；范围为 2–93 天时按日汇总，超过 93 天时按月汇总。日/月汇总会包含无牌局的日期或月份，收益为 0。`cumulative_profit` 是本次查询范围内从首个点累计的收益。

### 2.6 牌局列表与详情

`GET /api/mine/games` 查询参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `start`、`end` | `YYYY-MM-DD` | 不限制 | 按牌局创建时间筛选，上海时区，包含结束日 |
| `result` | `all`、`win`、`loss` | `all` | `win` 为 `profit > 0`，`loss` 为 `profit < 0`；收益为 0 只在 `all` 中 |
| `limit` | 1–100 的整数 | 5 | 每页条数 |
| `order` | `asc`、`desc` | `desc` | 按牌局数据库 ID 排序 |
| `cursor` | string，最长 4096 字符 | 无 | 上一页返回的 `next_cursor` |

成功 `data` 包含 `items`（牌局对象数组）、`next_cursor`（字符串或 `null`）、`pending`（目前固定为空数组）。`items` 中每个牌局对象的字段如下；部分值由牌局结束或异步保存后确定。

| 字段 | 类型 | 含义 |
| --- | --- | --- |
| `id`、`user_id` | string | 数据库记录 ID、所属用户 ID |
| `uuid` | string | 16 字符牌局标识 |
| `network` | string | `OK`、`WE`、`WPK`、`WPK_CLUB` |
| `room_number`、`hand_number` | string、integer | 房间号、第几手 |
| `provider`、`players` | string、integer | 决策服务标识、玩家数 |
| `status` | string | `OPEN`、`OVER`、`ABORT`、`CLOSED` |
| `big_blind`、`small_blind`、`ante` | integer | 大盲、小盲、前注 |
| `pot`、`total`、`winnings`、`profit` | integer | 底池、Hero 投入、Hero 奖金、Hero 净收益 |
| `created_at`、`updated_at` | ISO 8601 string | 创建及更新时间 |

`GET /api/mine/games/detail?game_id=<uuid>` 的 `game_id` 必须是 16 位字母数字。成功 `data` 为：

```json
{
  "game": {
    "id":"42","user_id":"1","uuid":"abc123def456gh78",
    "network":"WE","room_number":"table-42","hand_number":1,
    "provider":"mock","players":2,"status":"OVER",
    "big_blind":100,"small_blind":50,"ante":0,
    "pot":300,"total":150,"winnings":200,"profit":50,
    "created_at":"2026-09-23T12:00:00+08:00",
    "updated_at":"2026-09-23T12:01:00+08:00",
    "gamePlayers":[
      {
        "id":"81","game_id":"42","seat":1,"uid":"hero",
        "name":"Hero","is_hero":true,"stack":1000,"ante":0,
        "blind":50,"bet":100,"total":150,"cards":"As,Kh",
        "created_at":"2026-09-23T12:01:00+08:00",
        "updated_at":"2026-09-23T12:01:00+08:00"
      }
    ]
  },
  "live":null,
  "pending":false
}
```

这里的 `game` 包含上表全部字段及按 `seat` 升序排列的 `gamePlayers`。每个玩家包含 `id`、`game_id`（字符串），`uid`、`name`、`seat`、`is_hero`、`stack`、`ante`、`blind`、`bet`、`total`、`cards`、`created_at`、`updated_at`；`cards` 为已知手牌的逗号分隔字符串，未知时为 `null`。`live` 目前为 `null`，`pending` 为 `false`。牌局不存在或不属于当前用户时返回 `not_found`。

### 2.7 牌局事件与事件搜索

`GET /api/mine/games/events` 必填 `game_id`（16 位字母数字），还可提供：

| 参数 | 默认值 | 说明 |
| --- | --- | --- |
| `start`、`end` | 不限制 | `YYYY-MM-DD`，按事件创建时间筛选，上海时区 |
| `scope` | `all` | `all` 返回全部事件；`me` 只返回 `DEALT` 和载荷 `uid` 等于 Hero UID 的事件 |
| `limit` | 50 | 1–100 |
| `order` | `desc` | `asc` 或 `desc`；先按事件创建时间、再按 ID 排序 |
| `cursor` | 无 | 上一页的 `next_cursor`，最长 4096 字符 |

`GET /api/mine/events` 跨牌局搜索当前用户的事件。可选 `keyword`（最多 100 字符，搜索事件类型和载荷文本）、`limit`（默认 50，1–100）、`order`（默认 `desc`）、`cursor`（最长 4096 字符）。

两种事件接口均返回 `items`（事件对象数组）和 `next_cursor`（字符串或 `null`）。每个事件对象包含 `id`、`user_id`、`game_id`（字符串），`type`（`STAGE`、`DEALT`、`ACTION`、`SHOW`、`OVER` 或 `ABORT`），`timestamp`（原始 Unix 毫秒时间戳）、`payload`（原始消息载荷对象）、`created_at`、`updated_at`。`/api/mine/events` 中的每项另含 `game` 简要对象：`id`、`uuid`、`room_number`、`hand_number`、`network`。`START` 不作为独立事件保存；它建立牌局和玩家记录。

## 3. GameServer WebSocket 协议

### 3.1 连接与消息信封

连接 `/` 时在 URL 查询参数中携带登录令牌，例如 `wss://<game-host>/?token=12%7C<secret>`。无令牌、无效令牌、过期令牌或被禁用的用户无法建立可用会话；这里没有额外的 JSON 登录消息或 `sessionId`。连接成功后发送 UTF-8 JSON 文本帧。

客户端消息信封格式如下。此处的空 `payload` 仅用于展示信封结构；实际发送 `START` 时须填入 3.2 节规定的字段。

```json
{
  "id": "client-unique-001",
  "type": "START",
  "timestamp": 1790136000000,
  "payload": {}
}
```

| 字段 | 类型 | 规则 |
| --- | --- | --- |
| `id` | 非空字符串 | 客户端为每条消息分配；服务端用 `reply_to` 关联回应 |
| `type` | 非空字符串 | 区分大小写；见下表 |
| `timestamp` | Unix 毫秒时间戳 | 必填、非零；发送时不得早于服务端当前时间 10 秒以上。建议使用当前时间重新生成，不复用旧帧 |
| `payload` | object | 业务消息必填；`PING` 可省略 |

文中的时间戳均为示意值；实际发送时每条消息都应填写**发送当时**的 Unix 毫秒时间戳。

服务端消息统一格式：

```json
{
  "id": "server-generated-id",
  "type": "START.ACK",
  "timestamp": 1790136000100,
  "reply_to": "client-unique-001",
  "payload": {"game_uuid":"abc123def456gh78"}
}
```

`id` 为服务端生成的消息 ID；`reply_to` 对应客户端的 `id`。牌局命令成功处理时回复 `<请求 type>.ACK`；`PING` 成功时回复 `PING`；校验或处理失败时回复 `error`，不会再回复 ACK。`REQUEST_ACTION` 的结果可能稍后返回，也可能因决策失败返回 `error`。客户端应按 `reply_to` 匹配响应。客户端消息 `id` 只用于关联响应，**不是幂等键**；重复发送业务事件可能重复计入牌局。协议没有定义自动重发或去重确认；不要把未收到回复的消息直接当作已经成功处理。

| 客户端 `type` | 用途 | 成功处理时的回复 |
| --- | --- | --- |
| `PING` | 应用层探活 | `PING`，`payload: []`；没有 `.ACK` |
| `START` | 创建一手牌局 | `START.ACK`，返回 `game_uuid` |
| `STAGE` | 报告阶段及本阶段新公共牌 | `STAGE.ACK`，`payload: []` |
| `DEALT` | 报告 Hero 两张手牌 | `DEALT.ACK`，`payload: []` |
| `ACTION` | 报告玩家行动 | `ACTION.ACK`，`payload: []` |
| `SHOW` | 报告已知玩家手牌 | `SHOW.ACK`，`payload: []` |
| `REQUEST_ACTION` | 请求 Hero 下一步建议 | `REQUEST_ACTION.ACK`，返回行动与金额 |
| `OVER` | 正常结束并提交结果 | `OVER.ACK`，`payload: []` |
| `ABORT` | 中止牌局 | `ABORT.ACK`，`payload: []` |

上表列的是成功路径，不能据此假定每条消息都有 ACK。未识别的 `type`、无效字段、找不到牌局、牌局已结束或保存任务提交失败等情况会走 `error` 路径。连接认证失败时可能直接断开，且没有对应的 JSON ACK。

`PING` 同样需要有效 `id` 和 `timestamp`，示例：`{"id":"p-1","type":"PING","timestamp":1790136000000}`。服务端返回 `PING`，并保留相同的 `reply_to`。WebSocket 协议层 Ping/Pong 可用于传输层保活；应用层 `PING` 用于请求一个可关联的 JSON 回复。服务端配置的空闲关闭时间为 60 秒；客户端应在低于该时长的间隔内保持连接活动。服务端单个消息包上限配置为 1 MiB。

### 3.2 `START`：创建牌局

```json
{
  "id":"1","type":"START","timestamp":1790136000000,
  "payload":{
    "room_number":"table-42","hand_number":1,"network":"WE",
    "ante":0,"big_blind":100,"small_blind":50,"button_seat_number":1,
    "players":[
      {"seat":1,"uid":"hero","name":"Hero","stack":10000,"hero":true},
      {"seat":2,"uid":"villain","name":"Villain","stack":10000,"hero":false}
    ]
  }
}
```

| `payload` 字段 | 类型 | 约束 |
| --- | --- | --- |
| `room_number` | string | 必填，最长 64 字符 |
| `hand_number` | integer | 必填，至少 1 |
| `network` | string | 必填：`OK`、`WE`、`WPK`、`WPK_CLUB` |
| `ante`、`big_blind`、`small_blind` | integer | 必填，非负整数 |
| `button_seat_number` | integer | 必填，1–10；必须对应一名玩家的座位 |
| `players` | array | 必填；校验至少 1 人，实际创建需要至少 2 人及一名 Hero |
| `players[].seat` | integer | 必填，1–9，牌局内互不相同 |
| `players[].uid` | string | 必填，最长 64 字符，牌局内互不相同 |
| `players[].name` | string | 可选，最长 64 字符；省略时使用 `uid` |
| `players[].hero` | boolean | 必填，标识当前用户的玩家；请仅指定一名 |
| `players[].stack` | integer | 必填，非负整数，发送 JSON 整数而非字符串或浮点数 |

成功回复 `payload` 为 `{"game_uuid":"abc123def456gh78"}`。后续所有牌局消息都携带这个 16 字符 ID。两人牌局中按钮位同时是小盲位；多人牌局中按钮后第一个在座玩家是小盲位，再下一个是大盲位。

### 3.3 阶段、手牌与行动

以下表格只列各消息的 `payload`；消息信封仍须按 3.1 节填写。

| `type` | `payload` 示例 | 约束及含义 |
| --- | --- | --- |
| `STAGE` | `{"game_uuid":"abc123def456gh78","stage":"PREFLOP","cards":[]}` | `stage` 为 `PREFLOP`、`FLOP`、`TURN`、`RIVER`；`cards` 必填且为数组，分别要求 0、3、1、1 张**本阶段新公共牌** |
| `DEALT` | `{"game_uuid":"abc123def456gh78","cards":["As","Kh"]}` | Hero 的两张手牌；`cards` 为恰好 2 项的数组 |
| `ACTION` | `{"game_uuid":"abc123def456gh78","uid":"villain","action":"CALL","amount":50}` | `uid` 为玩家 UID，最多 64 字符；`action` 为 `FOLD`、`CHECK`、`CALL`、`BET`、`RAISE`、`ALL_IN`；`amount` 为非负 JSON 整数，表示**本次新增投入** |
| `SHOW` | `{"game_uuid":"abc123def456gh78","uid":"villain","cards":["Qs","Qh"]}` | 报告已知玩家的两张手牌；`uid` 必填且最多 64 字符 |

上述 `game_uuid` 均必填，长度恰好为 16。`cards` 中单张牌为字符串，长度最多 3 字符；常规扑克牌记法为点数 `2`–`9`、`T`、`J`、`Q`、`K`、`A` 加花色 `c`、`d`、`h`、`s`，例如 `As`、`Th`。服务端当前只校验卡牌数组项的长度和类型，客户端仍应发送有效牌码。`FOLD`、`CHECK` 发送 `amount: 0`。`ACTION` 事件记录的是客户端实际观察到的行动；收到行动建议后，客户端还需另发 `ACTION` 上报实际行动。

### 3.4 `REQUEST_ACTION`：获取行动建议

```json
{"id":"8","type":"REQUEST_ACTION","timestamp":1790136000800,"payload":{"game_uuid":"abc123def456gh78"}}
```

成功响应示例：

```json
{
  "id":"server-generated-id","type":"REQUEST_ACTION.ACK",
  "timestamp":1790136001000,"reply_to":"8",
  "payload":{"game_uuid":"abc123def456gh78","action":"call","amount":50}
}
```

建议 `action` 使用小写：`fold`、`check`、`call`、`bet`、`raise`、`all-in`。`amount` 是非负整数，`fold` 和 `check` 时为 0。

### 3.5 `OVER` 与 `ABORT`：结束牌局

正常结束：

```json
{
  "id":"9","type":"OVER","timestamp":1790136002000,
  "payload":{
    "game_uuid":"abc123def456gh78",
    "winners":[{"uid":"hero","amount":200}],
    "shown":[{"uid":"hero","cards":["As","Kh"]}],
    "no_hand_shown":["villain"],
    "result_order":"WINNER_FIRST"
  }
}
```

| 字段 | 约束及含义 |
| --- | --- |
| `game_uuid` | 必填，16 字符 |
| `winners` | 必填、非空数组；每项有 `uid`（最长 64 字符，不能重复）和 `amount`（0–9007199254740991 的 JSON 整数），表示该玩家获得的奖金 |
| `shown` | 可选数组；每项有 `uid`（最长 64 字符）和恰好 2 张牌的 `cards` |
| `no_hand_shown` | 可选数组；玩家 UID 字符串，最多 64 字符，不能重复 |
| `result_order` | 可选，`WINNER_FIRST` 或 `SHOWN_FIRST`；结果顺序标记 |

提前终止时发送 `ABORT`，其 `payload` 只有必填的 `game_uuid`：

```json
{"id":"10","type":"ABORT","timestamp":1790136002500,"payload":{"game_uuid":"abc123def456gh78"}}
```

`OVER.ACK` 和 `ABORT.ACK` 的 `payload` 均为空 JSON 数组 `[]`。收到 ACK 表示结束事件已处理并提交保存任务，**不保证该牌局已能立即通过 HTTP 查询到**；查询方需允许短暂的异步落库间隔。正常结束后状态为 `OVER`，终止后为 `ABORT`。已结束或已终止牌局不能继续追加事件，通常返回 `status_invalid`。没有收到结束事件的牌局在延迟保存后仍可能显示 `OPEN`。

### 3.6 WebSocket 错误

错误帧示例：

```json
{
  "id":"server-generated-id","type":"error","timestamp":1790136000900,
  "reply_to":"8",
  "payload":{"code":"event_invalid","message":"参数无效","details":{"game_uuid":["..."]}}
}
```

校验错误的 `details` 是字段到错误消息数组的映射。其他业务错误的附加信息可能直接放在 `payload` 中，例如 `{"code":"game_uuid_not_found","message":"...","uuid":"..."}`；不要假定所有错误都有相同的 `details` 结构。若入站消息无法解析出 `id`，`reply_to` 可能是 `null`。

常见错误码：`event_invalid`（信封或字段无效）、`event_type_invalid`、`game_uuid_not_found`、`status_invalid`、`hero_not_found`、`big_blind_not_found`、`player_not_found`、`request_action_in_progress`、`provider_failed`、`action_amount_invalid`、`auth_required`、`server_error`。认证错误可能伴随连接关闭。客户端遇到 `error` 时应按 `reply_to` 识别失败的请求，并以 `code` 分支处理。

### 3.7 典型调用顺序

```text
POST /api/auth/login  → 取得 token
连接 GameServer /?token=<token>
START                → START.ACK，保存 game_uuid
STAGE(PREFLOP, [])   → STAGE.ACK
DEALT(两张 Hero 手牌) → DEALT.ACK
REQUEST_ACTION       → REQUEST_ACTION.ACK，读取建议
ACTION(Hero 实际行动) → ACTION.ACK
ACTION(对手行动)      → ACTION.ACK
STAGE(FLOP, 三张牌)   → STAGE.ACK
...                  → 按实际牌局持续上报
OVER 或 ABORT         → 对应 .ACK
稍后 GET /api/mine/games/detail?game_id=<game_uuid> 查询保存结果
```

GameServer 客户端按发生顺序发送**单条** `STAGE`、`DEALT`、`ACTION`、`SHOW` 消息。连接断开后可用有效令牌重新连接并继续引用尚有效的 `game_uuid`；无需单独的会话恢复消息。实时牌局状态在创建及每次事件保存后保留 1 小时；超过该时限未更新时，后续请求可能返回 `game_uuid_not_found`，即使牌局已在 HTTP 历史记录中可见。每条新消息都应使用新的 `id` 和当前 `timestamp`。

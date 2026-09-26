# PokerX API 与 GameServer 协议

本文面向调用 PokerX 的客户端。HTTP API 用于登录、账户设置和查询已保存的牌局；GameServer WebSocket 用于实时上报牌局及获取行动建议。两者使用同一登录令牌，但消息格式不同。部署方提供 HTTP 和 WebSocket 的实际地址；下文只规定路径及报文。

## 1. 通用约定

### 1.1 地址、格式和身份认证

| 通道 | 地址 | 格式 | 认证 |
| --- | --- | --- | --- |
| HTTP API | 部署方提供的 HTTP(S) 地址下的 `/api/*` | JSON；GET 参数放在查询字符串，POST 参数放在 JSON 请求体 | 除登录和健康检查外，`Authorization: Bearer <token>` |
| GameServer | 部署方提供的 WebSocket(S) 地址下的 `/` | UTF-8 JSON 文本帧 | 连接 URL 的 `token` 查询参数；重连原客户端时还需附带上次 `ACCEPT` 返回的 `client_id` |

登录返回的 `token` 形如 `<令牌记录 ID>|<密钥>`。令牌默认在签发后 30 天过期；退出登录、修改密码、确认或取消两步验证后，相关令牌会失效。令牌应作为完整字符串传递。WebSocket 可附加 `locale` 查询参数设置错误消息语言，例如 `?token=...&locale=en-US`。

HTTP 请求可用 `X-Language: zh-CN` 或 `X-Language: en-US` 选择错误消息语言；未提供时使用用户设置的语言。业务判断应依赖 `code`，不要依赖本地化的 `message`。

### 1.2 HTTP 响应和错误

成功响应为：

```json
{"code":"success","message":"ok","data":{}}
```

无数据的成功响应中 `data` 为 `null`。业务错误和参数校验错误返回 HTTP 200：

```json
{"code":"event_invalid","message":"参数无效","details":{"game_id":["..."]},"data":null}
```

`details` 仅在有附加信息时出现。未匹配的 HTTP 路由返回 404；未处理的服务端错误返回 500。限流错误码为 `rate_limited`，返回 HTTP 200。客户端应同时检查 HTTP 状态码和响应体 `code`。

HTTP 的通用错误码包括 `auth_failed`、`auth_required`、`two_factor_required`、`two_factor_invalid`、`two_factor_already_enabled`、`setup_expired`、`event_invalid`、`not_found`、`rate_limited`、`server_error`。错误消息会随语言变化。

登录请求按来源 IP 每分钟最多 10 次、按标准化账号每分钟最多 5 次；其他需要认证的 HTTP API 按用户每分钟最多 120 次。

### 1.3 数据表示

- JSON 中的数据库 `id` 和以 `_id` 结尾的数据库字段以**字符串**返回；GameServer 的 `game_uuid` 与 HTTP 的 `uuid` 是同一个标准 UUID（36 字符，含连字符）。接口参数 `game_id` 也填写这个牌局标识，而非数据库数值 ID。`game_key` 是平台提供的牌局标识，与 UUID 分开保存。
- HTTP 时间字段是带时区偏移的 ISO 8601 字符串；GameServer `timestamp` 是 Unix 毫秒时间戳。日期筛选使用 `YYYY-MM-DD`，按 `Asia/Shanghai` 日历日计算，结束日包含整天。
- 筹码、盲注、下注、奖金、收益均为整数；`profit = winnings - total`。客户端不要把这些整数直接解释成带小数的货币金额。
- 列表中的 `next_cursor` 为不透明字符串或 `null`。获取下一页时原样回传，并保持其余筛选及排序参数不变。
- 大小写区分：GameServer 的业务事件 `type`、成功响应 `type`、`network`、`stage` 及行动代码使用文中列出的大写值；错误响应的 `type` 固定为小写 `error`，错误码 `code` 使用小写下划线形式。HTTP 的 `code` 及 `result`、`scope`、`order` 查询值使用文中列出的小写值。

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
| GET | `/api/mine/game_config` | 查询当前用户的游戏配置 |
| POST | `/api/mine/game_config/save` | 保存当前用户的游戏配置 |

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

成功 `data` 包含 `items`（已保存的牌局对象数组）、`next_cursor`（字符串或 `null`）、`pending`（固定为空数组）。`items` 中每个牌局对象的字段如下。

| 字段 | 类型 | 含义 |
| --- | --- | --- |
| `id`、`user_id` | string | 数据库记录 ID、所属用户 ID |
| `uuid` | string | 标准 UUID（36 字符，含连字符） |
| `network` | string | `OK`、`WE`、`WPK`、`WPK_CLUB` |
| `game_key` | string | 平台牌局标识，最长 32 字符 |
| `provider`、`players` | string、integer | 决策服务标识、玩家数 |
| `status` | string | `OPEN`、`OVER`、`ABORT`、`CLOSED` |
| `big_blind`、`small_blind`、`ante` | integer | 大盲、小盲、前注 |
| `pot`、`total`、`winnings`、`profit` | integer | 最终底池、Hero 净投入、Hero 实际奖金、Hero 净收益；`pot` 和 `total` 已扣除 `returns`，`winnings` 不含退回额 |
| `created_at`、`updated_at` | ISO 8601 string | 创建及更新时间 |

`GET /api/mine/games/detail?game_id=<uuid>` 的 `game_id` 必须是有效的 UUID。成功 `data` 为：

```json
{
  "game": {
    "id":"42","user_id":"1","uuid":"12345678-90ab-4cde-8f01-23456789abcd",
    "network":"WE","game_key":"table-42#1",
    "provider":"mock","players":2,"status":"OVER",
    "big_blind":100,"small_blind":50,"ante":0,
    "pot":300,"total":150,"winnings":200,"profit":50,
    "created_at":"2026-09-23T12:00:00+08:00",
    "updated_at":"2026-09-23T12:01:00+08:00",
    "gamePlayers":[
      {
        "id":"81","game_id":"42","seat":1,"uid":"aa1001",
        "name":"Hero","is_hero":true,"stack":1000,"ante":0,
        "blind":50,"post_blind":0,"straddle_blind":0,"bet":100,"returned":0,"total":150,"cards":"As,Kh",
        "created_at":"2026-09-23T12:01:00+08:00",
        "updated_at":"2026-09-23T12:01:00+08:00"
      }
    ]
  },
  "live":null,
  "pending":false
}
```

这里的 `game` 包含上表全部字段及按 `seat` 升序排列的 `gamePlayers`。每个玩家包含 `id`、`game_id`（字符串），`uid`、`name`、`seat`、`is_hero`、`stack`、`ante`、`blind`、`post_blind`、`straddle_blind`、`bet`、`returned`、`total`、`cards`、`created_at`、`updated_at`；`blind` 仅为普通大小盲，`post_blind` 为额外补交的活盲，`straddle_blind` 为自愿盲注，`returned` 为未被跟注而退回的筹码；`total` 为前注、普通盲注、补盲、自愿盲注和主动下注之和减去退回额；`cards` 为已知手牌的逗号分隔字符串，未知时为 `null`。`live` 目前为 `null`，`pending` 为 `false`。牌局不存在或不属于当前用户时返回 `not_found`。

### 2.7 牌局事件与事件搜索

`GET /api/mine/games/events` 必填 `game_id`（有效的 UUID），还可提供：

| 参数 | 默认值 | 说明 |
| --- | --- | --- |
| `start`、`end` | 不限制 | `YYYY-MM-DD`，按事件创建时间筛选，上海时区 |
| `scope` | `all` | `all` 返回全部事件；`me` 只返回 `DEALT` 和载荷 `uid` 等于 Hero UID 的事件 |
| `limit` | 50 | 1–100 |
| `order` | `desc` | `asc` 或 `desc`；先按事件创建时间、再按 ID 排序 |
| `cursor` | 无 | 上一页的 `next_cursor`，最长 4096 字符 |

`GET /api/mine/events` 跨牌局搜索当前用户的事件。可选 `keyword`（最多 100 字符，搜索事件类型和载荷文本）、`limit`（默认 50，1–100）、`order`（默认 `desc`）、`cursor`（最长 4096 字符）。

两种事件接口均返回 `items`（事件对象数组）和 `next_cursor`（字符串或 `null`）。每个事件对象包含 `id`、`user_id`、`game_id`（字符串），`type`（`POST_BLIND`、`STRADDLE_BLIND`、`STAGE`、`DEALT`、`ACTION`、`SHOW`、`OVER` 或 `ABORT`），`timestamp`（原始 Unix 毫秒时间戳）、`payload`（原始消息载荷对象）、`created_at`、`updated_at`。`/api/mine/events` 中的每项另含 `game` 简要对象：`id`、`uuid`、`game_key`、`network`。`START` 不作为独立事件保存；它建立牌局和玩家记录。

### 2.8 游戏配置

两个接口都需要登录令牌，并按当前用户及 `network` 独立保存配置。这里的 HTTP `network` 使用大写值 `OK`、`WE`、`WPK`、`WPK_CLUB`。

`GET /api/mine/game_config?network=OK` 返回该网络下的全部配置，按 `key` 升序排列；无配置时 `items` 为空数组。成功响应示例：

```json
{"code":"success","message":"ok","data":{"items":[{"key":"insurance_default","value":"MAX"},{"key":"insurance_outs_2","value":"1/8"}]}}
```

`POST /api/mine/game_config/save` 接收 JSON 请求体：

```json
{
  "network": "ok",
  "items": [
    {"key": "insurance_default", "value": "MAX"},
    {"key": "insurance_outs_2", "value": "1/8"}
  ]
}
```

| 字段 | 约束及含义 |
| --- | --- |
| `network` | 必填；`OK`、`WE`、`WPK` 或 `WPK_CLUB` |
| `items` | 必填；1–13 项，不能重复 `key`；只修改提交的配置项 |
| `items[].key` | `insurance_default`、`insurance_outs_1` 至 `insurance_outs_8`，或 `auto_bet_check_fold`、`auto_bet_bet_raise`、`auto_bet_call_all_in`、`auto_bet_insurance` |
| `items[].value` | 保险键接受 `MIN`、`MAX`、`1`、`1/2`、`1/3`、`1/5`、`1/8`；自动下注键接受 0–10 秒的 `min-max` 字符串；`null` 删除对应配置。 |

自动下注延迟的四个配置键分别对应过牌/弃牌、下注/加注、跟注/全押及保险。值为 `min-max` 秒，例如 `0-10`；两端均为 0–10 的整数，且 `min <= max`。未配置时客户端使用 2–5 秒。

比例档按 `floor(pot × 比例 ÷ odds)` 计算原始投保额，然后限制在服务端报价的 `min` 与 `max` 内；`1` 使用报价中的 `breakeven`，`MAX` 使用 `max`，`MIN` 使用 `min`，即使 `min` 为 0 也返回 0。未设置具体 outs 档位时使用 `insurance_default`；两者都未设置时，若报价有效则使用 `min`。有效报价下返回的每个投保额都不低于 `min`。

保存成功后返回该用户、该网络的完整配置列表，格式与 GET 相同。这两个接口只读写用户游戏设置，不写入保险购买记录。

## 3. GameServer WebSocket 协议

### 3.1 连接与消息信封

连接 `/` 时在 URL 查询参数中携带登录令牌，例如 `wss://<game-host>/?token=12%7C<secret>`。无令牌、无效令牌、过期令牌或被禁用的用户无法建立可用会话；这里没有额外的 JSON 登录消息。每个独立的客户端连接首次建立时不传 `client_id`，服务端在 `ACCEPT.payload.client_id` 中签发一个不透明的客户端标识。客户端应分别保存每条独立连接的标识（包括同一用户打开的不同页面）；原连接断开后重连时，在查询参数中带回对应的 `client_id`，例如 `?token=...&client_id=...`（须 URL 编码）。服务端校验标识属于当前登录用户，为每条 WebSocket 建立新的上游连接，并尝试用该标识对应的上游 `sessionId` 恢复会话。即使多条连接使用同一个登录令牌，它们也各有自己的 `client_id`、上游连接和牌局；登录令牌不会用作客户端标识。客户端不直接发送上游 `sessionId`。

认证及上游连接成功后，服务端主动发送 `ACCEPT` 表示连接可用；客户端收到后即可发送业务消息，无需先发送 `PING` 等待确认。失败时连接会断开，不发送 `ACCEPT`。同一个 `client_id` 不能同时用于两条活跃连接；如果重连早于旧连接的清理完成，客户端应保留原 `client_id` 并稍后重试，不要改为不带标识重新连接原牌局。随后双方使用 UTF-8 JSON 文本帧通信。

认证成功后的首条服务端消息示例：

```json
{"id":"server-generated-id","type":"ACCEPT","timestamp":1790136000000,"reply_to":null,"payload":{"client_id":"server-issued-id"}}
```

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
| `timestamp` | Unix 毫秒时间戳整数 | 必填、正整数；发送时不得早于服务端当前时间 10 秒以上；同一连接内不得小于上一条通过信封校验的消息的时间戳，相等允许 |
| `payload` | object | 业务消息必填；`PING` 可省略 |

文中的时间戳均为示意值；实际发送时每条消息都应填写**发送当时**的 Unix 毫秒时间戳。
此顺序校验适用于 `PING` 和所有业务事件。消息通过信封校验后，即使后续业务处理返回 `error`，其时间戳也会成为该连接后续消息的比较基准；时间戳无效或倒退时返回 `event_invalid`，且不会更新基准。重新建立 WebSocket 连接后，时间戳顺序从新连接的第一条消息开始计算。

服务端消息统一格式：

```json
{
  "id": "server-generated-id",
  "type": "START.ACK",
  "timestamp": 1790136000100,
  "reply_to": "client-unique-001",
  "payload": {"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd"}
}
```

`id` 为服务端生成的消息 ID；应答消息的 `reply_to` 对应客户端的 `id`。认证成功后主动发送的 `ACCEPT` 使用相同信封，`reply_to: null`，`payload` 含服务端签发的 `client_id`，不对应任何客户端请求。`PING` 和每个业务事件成功处理后都返回对应的 `<请求 type>.ACK`；校验或处理失败时返回 `error`。`REQUEST_ACTION` 的结果可能稍后返回，也可能因决策失败返回 `error`。客户端应按 `reply_to` 匹配响应。客户端消息 `id` 只用于关联响应，**不是幂等键**；重复发送业务事件可能重复计入牌局。协议没有定义自动重发或去重确认；不要把未收到回复的消息直接当作已经成功处理。

| 客户端 `type` | 用途 | 成功处理时的回复 |
| --- | --- | --- |
| `PING` | 应用层探活 | `PING.ACK`，`payload: []` |
| `START` | 创建一手牌局 | `START.ACK`，返回 `game_uuid` |
| `POST_BLIND` | 上报任意参局玩家补交的活盲 | `POST_BLIND.ACK`，`payload: []` |
| `STRADDLE_BLIND` | 上报玩家自愿下的活盲 | `STRADDLE_BLIND.ACK`，`payload: []` |
| `STAGE` | 报告阶段及本阶段新公共牌 | `STAGE.ACK`，`payload: []` |
| `DEALT` | 报告 Hero 两张手牌 | `DEALT.ACK`，`payload: []` |
| `ACTION` | 报告玩家行动 | `ACTION.ACK`，`payload: []` |
| `SHOW` | 报告已知玩家手牌 | `SHOW.ACK`，`payload: []` |
| `REQUEST_ACTION` | 请求 Hero 下一步建议 | `REQUEST_ACTION.ACK`，返回行动与金额 |
| `REQUEST_INSURANCE` | 根据保险报价与用户配置请求投保额建议 | `REQUEST_INSURANCE.ACK`，返回 `amount`（整数或 `null`） |
| `OVER` | 正常结束并提交结果 | `OVER.ACK`，`payload: []` |
| `ABORT` | 中止牌局 | `ABORT.ACK`，`payload: []` |

上表列出的业务消息在成功处理后均有对应的 `.ACK`。未识别的 `type`、无效字段、找不到牌局、牌局已结束或保存失败等情况返回 `error`。连接认证失败时可能直接断开，且没有对应的 JSON 回复。

`PING` 同样需要有效 `id` 和 `timestamp`，示例：`{"id":"p-1","type":"PING","timestamp":1790136000000}`。服务端返回 `PING.ACK`，并保留相同的 `reply_to`。WebSocket 协议层 Ping/Pong 可用于传输层保活；应用层 `PING` 用于请求一个可关联的 JSON 应答，不能代替认证成功时的 `ACCEPT`。服务端配置的空闲关闭时间为 60 秒；客户端应在低于该时长的间隔内保持连接活动。服务端单个消息包上限配置为 1 MiB。

### 3.2 `START`：创建牌局

```json
{
  "id":"1","type":"START","timestamp":1790136000000,
  "payload":{
    "game_key":"table-42#1","network":"WE",
    "ante":0,"big_blind":100,"small_blind":50,"button_seat_number":1,
    "players":[
      {"seat":1,"uid":"Aa1001","name":"Hero","stack":10000,"hero":true},
      {"seat":2,"uid":"Bb1002","name":"Villain","stack":10000,"hero":false}
    ]
  }
}
```

| `payload` 字段 | 类型 | 约束 | 含义 |
| --- | --- | --- | --- |
| `game_key` | string | 必填，最长 32 字符，首尾不能有空白字符 | 平台牌局标识；支持手数的平台使用 `roomNumber#handNumber`，其他平台使用平台游戏局 ID |
| `network` | string | 必填：`OK`、`WE`、`WPK`、`WPK_CLUB` | 牌局所属的平台或网络 |
| `ante` | integer | 必填，非负整数 | 每名玩家本手需支付的前注金额 |
| `big_blind` | integer | 必填，至少 1 | 本手大盲注金额 |
| `small_blind` | integer | 必填，非负整数 | 本手小盲注金额 |
| `button_seat_number` | integer | 必填，1–10；允许该座位没有参局玩家 | 庄家按钮所在座位号，用于确定大小盲位置 |
| `players` | array | 必填，至少 2 名玩家，且至少包含一名 Hero | 参与本手牌局的玩家列表 |
| `players[].seat` | integer | 必填，1–10，牌局内互不相同 | 玩家在牌桌上的座位号 |
| `players[].uid` | string | 必填，1–16 位英文字母或数字，牌局内按不区分大小写的规则互不相同；须发送 JSON 字符串，不能发送 JSON 数字 | 玩家标识；后续 `ACTION`、`SHOW`、`OVER` 等事件使用此值引用玩家 |
| `players[].name` | string | 可选，最长 16 字符；省略时使用 `uid` | 玩家显示名称 |
| `players[].hero` | boolean | 必填；至少一名玩家为 `true`，客户端应只标记一名 | `true` 表示该玩家是当前用户（Hero），`false` 表示其他玩家 |
| `players[].stack` | integer | 必填，非负整数；不能传字符串或浮点数 | 玩家本手开始时、支付前注、普通盲注、补盲和自愿盲注之前的初始筹码 |

同一用户的同一 `network` 下，`game_key` 必须唯一，且区分大小写。`START` 时若 Redis 中已有正在进行的牌局，或数据库中已有保存的牌局，返回 `game_already_exists`；重复 `START` 不会创建新的 UUID。成功回复 `payload` 为 `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd"}`。后续所有牌局消息都携带这个 UUID。两人牌局中，按钮位有参局玩家时，该玩家同时是小盲位；按钮位空缺时，顺时针下一名参局玩家是小盲位。多人牌局中，按钮后第一名参局玩家是小盲位。所有情况下，小盲位后的下一名参局玩家是大盲位；座位号到末尾后从头继续查找。若客户端附带 `small_blind_seat_number` 或 `big_blind_seat_number`，服务端忽略这两个字段，始终从按钮位推算。普通大小盲由 `START` 自动计入；额外补盲须通过 `POST_BLIND` 上报，自愿盲注通过 `STRADDLE_BLIND` 上报。

UID 不区分大小写；例如 `Aa1001` 与 `aA1001` 指向同一玩家。新建牌局的 UID 统一以小写保存并返回；后续事件使用牌局中保存的 UID 形式记录。

### 3.3 阶段、手牌与行动

以下表格只列各消息的 `payload`；消息信封仍须按 3.1 节填写。

| `type` | `payload` 示例 | 约束及含义 |
| --- | --- | --- |
| `POST_BLIND` | `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","uid":"bB1002","amount":100}` | `uid` 可为本手任意参局玩家；`amount` 为实际补交的正 JSON 整数（1–100000000），不得超过该玩家支付前注和普通盲注后的剩余筹码。每名玩家每手最多上报一次，必须在 `START.ACK` 后、任何 `STAGE`、`DEALT`、`ACTION` 等事件之前上报。它是活盲，计入翻牌前已投入和底池，不得再作为 `ACTION` 重复上报 |
| `STRADDLE_BLIND` | `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","uid":"bB1002","amount":200}` | 玩家自愿下的活盲；`uid` 指向本手玩家，`amount` 为正 JSON 整数（1–100000000），不得超过该玩家当前剩余筹码。允许按平台广播顺序在 `STAGE(PREFLOP)` 之后上报，但必须仍处于翻牌前；可在翻牌前累计上报多笔。计入本轮已投入和底池，不得再以 `ACTION` 重复上报 |
| `STAGE` | `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","stage":"PREFLOP","cards":[]}` | `stage` 为 `PREFLOP`、`FLOP`、`TURN`、`RIVER`；`cards` 必填且为数组，分别要求 0、3、1、1 张**本阶段新公共牌** |
| `DEALT` | `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","cards":["As","Kh"]}` | Hero 的两张手牌；`cards` 为恰好 2 项的数组 |
| `ACTION` | `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","uid":"bB1002","action":"CALL","amount":50}` | `uid` 为牌局中玩家的 1–16 位字母或数字字符串；`action` 为 `FOLD`、`CHECK`、`CALL`、`BET`、`RAISE`、`ALL_IN`；`amount` 为非负 JSON 整数，表示**本次新增投入** |
| `SHOW` | `{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","uid":"bB1002","cards":["Qs","Qh"]}` | 报告已知玩家的两张手牌；`uid` 为牌局中玩家的 1–16 位字母或数字字符串 |

上述 `game_uuid` 均必填，必须是有效的 UUID（36 字符，含连字符）。`cards` 中单张牌为字符串，长度最多 3 字符；常规扑克牌记法为点数 `2`–`9`、`T`、`J`、`Q`、`K`、`A` 加花色 `c`、`d`、`h`、`s`，例如 `As`、`Th`。服务端当前只校验卡牌数组项的长度和类型，客户端仍应发送有效牌码。`FOLD`、`CHECK` 发送 `amount: 0`。`POST_BLIND` 上报重复、迟到或超过剩余筹码时返回 `event_invalid`；`STRADDLE_BLIND` 在翻牌后或超过剩余筹码时也返回 `event_invalid`，未知玩家返回 `player_not_found`。`ACTION` 事件记录的是客户端实际观察到的行动；收到行动建议后，客户端还需另发 `ACTION` 上报实际行动。

### 3.4 `REQUEST_ACTION`：获取行动建议

```json
{"id":"8","type":"REQUEST_ACTION","timestamp":1790136000800,"payload":{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd"}}
```

成功响应示例：

```json
{
  "id":"server-generated-id","type":"REQUEST_ACTION.ACK",
  "timestamp":1790136001000,"reply_to":"8",
  "payload":{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd","action":"CALL","amount":50}
}
```

`REQUEST_ACTION.ACK` 返回的 `payload.action` 与客户端上报 `ACTION` 事件的 `payload.action` 使用相同的大写枚举值：`FOLD`、`CHECK`、`CALL`、`BET`、`RAISE`、`ALL_IN`。客户端可直接使用返回的行动代码上报实际发生的行动；响应中的 `amount` 是非负整数，`FOLD` 和 `CHECK` 时为 0。

### 3.5 `REQUEST_INSURANCE`：获取投保额建议

客户端取得保险报价后，发送报价数据及当前牌局 UUID。示例假设该用户为 2 个 outs 配置了 `RATIO_2`（`1/2`）：

```json
{
  "id":"insurance-1","type":"REQUEST_INSURANCE","timestamp":1790136001500,
  "payload":{
    "game_uuid":"12345678-90ab-4cde-8f01-23456789abcd",
    "stage":"FLOP","outs":2,"pot":299,"odds":16.25,
    "min":0,"max":18,"breakeven":9
  }
}
```

| `payload` 字段 | 约束及含义 |
| --- | --- |
| `game_uuid` | 必填；已创建牌局的有效 UUID |
| `stage` | 必填；`FLOP` 或 `TURN` |
| `outs` | 必填；1–52 的整数，表示报价中的 outs 数量 |
| `pot` | 必填；1–100000000 的整数，表示用于计算投保额的底池 |
| `odds` | 必填；大于 0、至多 2 位小数的数值或数字字符串，表示保险赔率 |
| `min`、`max` | 必填；各为 0–100000000 的整数，表示报价允许的最低及最高投保额 |
| `breakeven` | 必填；0–100000000 的整数，表示报价中的保底投保额 |

成功时服务端按该牌局用户及网络读取保险配置，优先使用 `insurance_outs_1` 至 `insurance_outs_8` 中对应的 outs 配置，否则使用 `insurance_default`。超过 8 个 outs 时只使用默认配置。比例档按 `floor(pot × 比例 ÷ odds)` 计算，并限制在 `min` 与 `max` 之间；`RATIO_1` 使用 `breakeven`，`RATIO_MAX` 使用 `max`，`RATIO_MIN` 使用 `min`（包括 0）。有效报价下未配置策略时也返回 `min`，所有整数决策均不低于 `min`。仅当报价边界无效、无法形成有效决策时，`amount` 才为 `null`。上述示例的成功响应为：

```json
{
  "id":"server-generated-id","type":"REQUEST_INSURANCE.ACK",
  "timestamp":1790136001600,"reply_to":"insurance-1",
  "payload":{"amount":9}
}
```

此消息只计算并返回投保额建议，不执行买保险，也不保存购买记录。字段不符合校验规则时返回 `error`（`event_invalid`），牌局 UUID 未找到时返回 `game_uuid_not_found`。

### 3.6 `OVER` 与 `ABORT`：结束牌局

正常结束：

```json
{
  "id":"9","type":"OVER","timestamp":1790136002000,
  "payload":{
    "game_uuid":"12345678-90ab-4cde-8f01-23456789abcd",
    "winners":[{"uid":"aA1001","amount":200}],
    "returns":[{"uid":"Bb1002","amount":7}],
    "shown":[{"uid":"aA1001","cards":["As","Kh"]}]
  }
}
```

| 字段 | 约束及含义 |
| --- | --- |
| `game_uuid` | 必填，有效的 UUID（36 字符，含连字符） |
| `winners` | 必填、非空数组；每项有 `uid`（牌局中玩家的 1–16 位字母或数字字符串，不区分大小写且不能重复）和 `amount`（0–100000000 的 JSON 整数），表示该玩家实际获得的奖金，不含退回筹码 |
| `returns` | 可选数组；每项有 `uid`（牌局中玩家的 1–16 位字母或数字字符串，数组内不区分大小写且不能重复）和 `amount`（1–100000000 的正 JSON 整数），表示本手未被跟注而退回的筹码，不得超过该玩家累计投入；不退回时可省略 |
| `shown` | 可选数组；每项有 `uid`（牌局中玩家的 1–16 位字母或数字字符串）和恰好 2 张牌的 `cards` |

提前终止时发送 `ABORT`，其 `payload` 只有必填的 `game_uuid`：

```json
{"id":"10","type":"ABORT","timestamp":1790136002500,"payload":{"game_uuid":"12345678-90ab-4cde-8f01-23456789abcd"}}
```

`OVER` 中 `returns` 会从玩家及牌局最终投入扣除，`winners` 仍表示实际奖金；向 Proto 提交 `fullGameLog` 时，对每个玩家发送的 `playerWon.amount` 为实际奖金加退回额，只有退回额的玩家也会发送。

`OVER.ACK` 和 `ABORT.ACK` 均返回 `payload: []`；收到回复表示结束事件已处理且保存任务已提交，数据库保存由异步队列完成。查询历史牌局时可能需要等待保存完成。正常结束的状态为 `OVER`，提前终止的状态为 `ABORT`。

### 3.7 WebSocket 错误

错误帧示例：

```json
{
  "id":"server-generated-id","type":"error","timestamp":1790136000900,
  "reply_to":"8",
  "payload":{"code":"event_invalid","message":"参数无效","details":{"game_uuid":["..."]}}
}
```

校验错误的 `details` 是字段到错误消息数组的映射。其他业务错误的附加信息可能直接放在 `payload` 中，例如 `{"code":"game_uuid_not_found","message":"...","uuid":"..."}`；不要假定所有错误都有相同的 `details` 结构。若入站消息无法解析出 `id`，`reply_to` 可能是 `null`。

常见错误码：`event_invalid`（信封或字段无效）、`event_type_invalid`、`game_already_exists`、`game_uuid_not_found`、`status_invalid`、`hero_not_found`、`big_blind_not_found`、`player_not_found`、`request_action_in_progress`、`provider_failed`、`action_amount_invalid`、`auth_required`、`server_error`。认证错误可能伴随连接关闭。客户端遇到 `error` 时应按 `reply_to` 识别失败的请求，并以 `code` 分支处理。

### 3.8 典型调用顺序

```text
POST /api/auth/login  → 取得 token
首次连接 GameServer /?token=<token> → 服务端 ACCEPT，保存 payload.client_id
START                → START.ACK，保存 game_uuid
STAGE(PREFLOP, [])   → STAGE.ACK
DEALT(两张 Hero 手牌) → DEALT.ACK
REQUEST_ACTION       → REQUEST_ACTION.ACK，读取建议
（如发生断线）重连 /?token=<token>&client_id=<保存的标识> → ACCEPT，继续原 game_uuid
ACTION(Hero 实际行动) → ACTION.ACK
ACTION(对手行动)      → ACTION.ACK
STAGE(FLOP, 三张牌)   → STAGE.ACK
REQUEST_INSURANCE    → REQUEST_INSURANCE.ACK，读取投保额建议（出现保险报价时）
...                  → 按实际牌局持续上报
OVER 或 ABORT         → 对应 .ACK
GET /api/mine/games/detail?game_id=<game_uuid> 查询保存结果
```

GameServer 客户端按发生顺序发送**单条** `STAGE`、`DEALT`、`ACTION`、`SHOW` 消息。连接断开后，须用同一用户的有效令牌和上次 `ACCEPT` 返回的 `client_id` 重新连接，才能继续引用该客户端尚有效的 `game_uuid`；只带令牌会得到新的客户端标识，不能继续原牌局。令牌更新后，只要仍属于同一用户，也可带回原 `client_id`。即使已收到 `REQUEST_ACTION.ACK`，重连后仍使用同一 `game_uuid` 继续上报；无需单独的会话恢复消息。实时牌局状态在创建及每次事件保存后保留 1 小时；超过该时限未更新时，后续请求可能返回 `game_uuid_not_found`，即使牌局已在 HTTP 历史记录中可见。每条新消息都应使用新的 `id` 和当前 `timestamp`。

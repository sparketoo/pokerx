# 用户游戏配置与保险策略

## 存储与职责

`user_game_config` 仅包含以下五个字段，不记录时间戳：

| 字段 | 类型 | 约束 |
| --- | --- | --- |
| id | unsigned bigint | 自增主键 |
| user_id | unsigned bigint | 外键 users.id |
| network | enum(OK, WE, WPK) | 非空 |
| key | varchar(64) | 非空 |
| value | json | 非空，保存原生 JSON 值 |

唯一索引为 `(user_id, network, key)`。模型为 `UserGameConfig`，JSON 值由模型编码、解码；保险比例保存为 JSON 字符串，避免浮点精度损失。

`UserGameConfigService` 统一管理配置：

- `all(User, NetworkEnum)`：返回当前用户、当前平台的 key/value 映射，按 key 排序。
- `save(User, NetworkEnum, items)`：事务内更新提交项；`value: null` 删除该项。未提交项保持不变，同一用户的保存通过用户行锁串行处理。

服务支持 JSON 字符串、数字、布尔值、数组及对象；HTTP 接口当前只开放已确认的九个保险配置键及其值校验。以后新增配置时再添加对应校验，不提前引入其他业务配置。

`InsuranceService` 通过配置服务读取策略，负责报价校验、金额计算和成功购买记录；控制器只负责请求与响应。无配置缓存、内置保险策略、开关或兼容层。

## 配置接口

需要登录，用户身份从认证上下文取得。网络参数为 `ok`、`we`、`wpk`。

- `GET /api/mine/game_config?network=ok`
- `POST /api/mine/game_config/save`

保存请求：

```json
{
  "network": "ok",
  "items": [
    { "key": "insurance_default", "value": "0.125" },
    { "key": "insurance_outs_1", "value": "1" },
    { "key": "insurance_outs_2", "value": null },
    { "key": "insurance_outs_8", "value": "full" }
  ]
}
```

读取和保存均返回当前全部已设置项：

```json
{
  "code": "success",
  "message": "ok",
  "data": {
    "items": [
      { "key": "insurance_default", "value": "0.125" },
      { "key": "insurance_outs_1", "value": "1" },
      { "key": "insurance_outs_8", "value": "full" }
    ]
  }
}
```

允许的键为 `insurance_outs_1` 至 `insurance_outs_8`，以及 `insurance_default`。一次提交 1～9 项，键不可重复；每项必须包含 `key`、`value`，不接受额外字段。整批校验通过后才写入，非法项不会造成部分保存。

保险值仅接受 `null` 或最长 32 字符的字符串：`"0"`、`"1"`、`"full"`、0 与 1 之间的非零十进制比例。数字类型、空字符串、分数写法、科学计数法无效。

`null` 表示取消该配置，读取时不返回被删除的项。精确项删除后使用默认项；默认项也可清除。`"0"` 是显式不买，不能当作未配置。

## 保险建议

根据有效报价的实际 outs 数量选择策略：

1. 1～8 个 outs，先查 `insurance_outs_N`。
2. 精确项未配置，或 outs 超过 8，查 `insurance_default`。
3. 两者均未配置，返回 `amount: null`，不自动生成保本或最小保费建议。

配置只在当前用户、当前牌局 network 内匹配。无配置时仍正常校验报价与牌局归属，非法输入仍返回错误。

| 配置值 | 建议保费 |
| --- | --- |
| `"0"` | 0；若平台强制最低保费大于 0，则返回 `event_invalid`，reason 为 `insurance_minimum_required` |
| `"1"` | 平台给出的 breakeven |
| `"full"` | 平台给出的 max_insurance |
| 非零小数字符串 | floor(pot × value / odds) |
| 未配置 | null |

有购买建议时按平台 min/max 限制金额。金额单位为整数筹码。比例计算使用 `brick/math`，不使用浮点乘法取整；赔率使用平台解码原值，支持最多 18 位小数，不先四舍五入。

## PokerServer 事件

继续使用消息信封 `id/type/timestamp/payload`，ACK 的 `reply_to` 对应原请求 id。

`request_insurance` 的 payload：

```json
{
  "hand_uuid": "当前牌局 UUID",
  "pot_id": 1,
  "stage": "flop",
  "outs": ["6s", "6h"],
  "remaining_card_num": 35,
  "odds": "16",
  "breakeven": 9,
  "min_insurance": 0,
  "max_insurance": 18,
  "pot": 299
}
```

无配置时返回：

```json
{
  "type": "request_insurance.ack",
  "reply_to": "原请求 id",
  "payload": {
    "hand_uuid": "当前牌局 UUID",
    "amount": null
  }
}
```

`amount: null` 表示没有建议，客户端不得据此自动购买或把它转换为 0；`amount: 0` 才是明确的不买建议；正整数表示建议保费。建议请求只读配置，不写购买记录。

`insurance_submitted` 只表示平台已经确认购买成功。payload 为完整报价加 `amount`（实际正整数保费），ACK 为 `insurance_submitted.ack`，payload 包含 `hand_uuid`。允许手动购买后上报，不要求此前获得建议；实际购买金额无需等于建议金额。

成功购买继续写入独立的 `user_insurances` 表：

| 字段 | 类型/含义 |
| --- | --- |
| id | unsigned bigint，自增主键 |
| uuid | char(36)，上报消息 id |
| user_id | unsigned bigint，users 外键 |
| game_id | unsigned bigint，games 外键 |
| pot_id | unsigned int，平台底池编号 |
| stage | enum(FLOP, TURN)，原报价阶段 |
| outs | json，承保牌数组 |
| remaining_card_num | unsigned tinyint，剩余牌数 |
| odds | decimal(24,18)，平台赔率，模型返回字符串 |
| breakeven | unsigned bigint，保本保费 |
| min_insurance | unsigned bigint，最低保费 |
| max_insurance | unsigned bigint，最高保费 |
| pot | unsigned bigint，对应底池金额 |
| amount | unsigned bigint，实际正整数保费 |
| created_at / updated_at | timestamp(6) |

唯一索引 `(user_id, uuid)` 和 `(game_id, pot_id, stage)`，另有 `(user_id, created_at, id)` 查询索引。提交时锁定本人牌局；重复且内容相同返回成功，内容冲突返回 `event_conflict`。允许牌局结束后补报，不重开牌局，不改投注、收益或 events。

stage 是报价出现的阶段：FLOP 买转牌保险，TURN 买河牌保险；pot_id 区分同一阶段多个底池。客户端需要保留原报价上下文，不能使用购买确认到达时的新阶段替代。

## 迁移与接入

使用新的 `2026_09_19_000002_create_user_game_config_table.php` 建表，已删除旧保险配置迁移、模型、控制器和请求类，不转换旧数据。购买记录迁移保持独立。

执行新迁移后重启后端进程。客户端必须改用新路径及 `key/value` 数据结构，并处理 `amount: null`；旧配置接口不再提供。本次修改范围为后端，前端接入需按此契约更新。

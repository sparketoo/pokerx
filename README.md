# PokerX Hyperf 后端

PHP 8.4、Swoole >= 6.2.1、Hyperf 3.2、MySQL、Redis。启动一个服务即可提供HTTP、WebSocket及持久化恢复进程。外部HTTP/WS协议保持snake_case。

## 目录与代码规范

```text
app/
  Controller/       HTTP 控制器，Mine/ 保存个人接口
  Request/          FormRequest 请求校验
  Middleware/       HTTP 中间件
  Model/            数据模型与关联
  Exception/        业务异常，Handler/ 统一处理
  Service/          跨入口复用的游戏持久化、TOTP 逻辑
  Command/          账户创建、人工积分发放
  Process/          超时牌局处理进程
  Listener/         服务生命周期监听
  Enum/             PHP 原生枚举
  Vo/Game/          游戏上下文及子值对象
  Gateway/          WebSocket 会话与实时状态
  Poker/            Provider 管理、协议及客户端
config/autoload/    Hyperf 组件与 poker 配置
migrations/        每张表独立迁移
```

命名空间与目录一致，例如 `App\Controller\Mine\GamesController`、`App\Model\Game`。历史查询、分页、统计和导出在对应控制器方法中实现；`App\Service\GameService` 供网关、恢复进程及积分命令复用。代码遵循 Pint 的 Laravel 预设，PHPStan 使用 level 8。

## 配置与启动

```bash
composer install
cp .env.example .env
# 填写数据库、Redis、APP_KEY、PROTO_URL、PROTO_TOKEN
php bin/hyperf.php migrate --force
php bin/hyperf.php start
```

已有环境请保留.env；APP_KEY必须沿用现有密钥才能读取已加密TOTP。新环境可用`php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`生成密钥。

默认HTTP为127.0.0.1:18080，WS为127.0.0.1:18081，健康检查GET /health。监听地址、端口和worker数可配置。公网由反向代理终结TLS并转发WebSocket升级请求。

Provider配置在config/autoload/poker.php，使用poker.default、poker.proto、poker.mock。Proto网络为WE；真实域名与token仅放.env。POKER_PROVIDER=mock用于本地模拟。

MySQL和Redis使用Hyperf协程连接池。实时上下文、幂等操作及积分预占通过同用户 hash tag 下的 Lua CAS 原子更新。每条合法游戏事件在返回 `event_ack` 前直接写入 MySQL 事务；求解完成后也立即写入求解结果和积分流水。Redis不保存连接、Token 活跃状态、通信日志或待归档队列。

## 账户与积分

```bash
php bin/hyperf.php user:create alice --nickname=Alice
php bin/hyperf.php credit:grant alice 10.00 --id=有效UUID --description=人工发放
```

创建账户时交互输入密码。积分命令使用必填UUID保证幂等；1积分=100最小单位，VIP求解不预占或扣费。HTTP鉴权兼容personal_access_tokens表、id|secret令牌格式和既有加密密文，由Hyperf中间件完成验证。

## 验证

本地预先建立独立数据库pokerx_hyperf_test，测试默认使用该库和pokerx_hyperf_test: Redis前缀；服务冒烟使用.env配置，创建随机smoke_账号。

```bash
DB_DATABASE=pokerx_hyperf_test php bin/hyperf.php migrate --force
composer test
composer analyse
composer pint
python3 scripts/smoke_up.py
python3 scripts/smoke_restart.py
```

smoke_up启动实际服务并在结束后关闭所创建进程，覆盖公开 HTTP 接口、22个游戏事件、积分/VIP、异常场景、第三方协议场景及TCP/TLS场景。smoke_restart使用19480/19481端口，在求解等待时结束自身进程组并重启，验证预占释放与中断牌局落库。脚本需要测试端口空闲，以及可用的MySQL、Redis和openssl。

真实Proto检查：`php scripts/smoke_provider.php --expect=provider_rejected`。当前WSS/TLS/认证正常，样例仍被上游以“翻牌前下注未完成”拒绝；该命令验证明确失败路径，不代表真实求解成功。

[完整实现方案](../docs/TECHNICAL-IMPLEMENTATION-PLAN.md) · [数据库](../docs/DATABASE-DESIGN.md) · [HTTP/WS协议](../docs/CLIENT-SERVER-PROTOCOL.md) · [测试报告](../docs/BACKEND-TEST-REPORT.md)

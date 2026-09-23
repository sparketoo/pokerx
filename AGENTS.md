# PokerX · Codex 开发指南

## 基础约束

- 本项目是 PHP 8.4、Hyperf 3.2、Swoole（要求 `ext-swoole >= 6.2.1`）的常驻进程服务，不是 Laravel 应用；不要套用 Laravel、Octane 或 PHP-FPM 的生命周期和 API。
- 以当前代码和已安装版本为准。使用框架或第三方 API 前，先查看 `composer.json`、`composer.lock`，可用 `composer show --direct` 或 `composer show <包名>` 核对版本，必要时查看 `vendor/` 中对应版本的实现；不要凭其他版本的记忆编写。
- 涉及 Hyperf 的 API、配置、生命周期或组件行为时，先查 [Hyperf 官方文档](https://hyperf.wiki/)并选择与项目一致的 3.2 版本；文档与当前安装版本有出入时，以 `composer.lock` 和 `vendor/` 的实际实现为准。第三方组件查其官方文档，不把 Laravel 文档当作 Hyperf 文档。
- 沿用现有目录、命名、依赖注入、异常、测试风格；修改前查看相邻文件，优先复用已有组件。名称使用完整、清楚的业务词，遵循现有 Enum 约定。不要顺手重构无关代码，保留工作区里已有的改动。
- 新增顶层目录、独立文档或依赖前先确认现有结构和组件无法满足任务；确有必要时说明原因与影响。新增或升级依赖还要核查协程兼容性、版本约束和锁文件变化。任务未涉及的文档与依赖不要改动。
- 涉及专门领域时查找 `.agents/skills/**/SKILL.md` 中适用的技能。修改代码前查看 `.agents/rules/index.md` 并读取与改动路径匹配的规则；若规则与本文件或当前 Hyperf 代码冲突，以本文件和项目实际实现为准，并在修改规则时同步更新索引。

## PHP 与代码风格

- 业务代码使用 `declare(strict_types=1)`，为参数和返回值声明类型；复杂数组结构用 PHPDoc 的 array shape 标明。控制结构即使只有一行也使用大括号。
- 构造器已有依赖时，优先沿用项目的构造器属性提升风格；不要保留无用途的空构造器。遵循现有 Enum 命名和表示方式。
- 优先用清楚的类型、命名和小方法表达意图；需要解释非显然约束时使用 PHPDoc，行内注释只用于确实复杂的逻辑。新增配置遵循 `config/autoload/` 的现有方式。

## 协程化优先

- HTTP、WebSocket、队列消费等请求链路应保持协程友好。遇到网络、数据库、Redis、定时等待等 I/O，优先选用项目已有的 Hyperf 协程组件和连接池；不要在 Worker 中引入会长时间阻塞线程的同步调用。
- 当前项目已有 `hyperf/coroutine`、`hyperf/context`、`hyperf/db-connection`、`hyperf/redis`、`hyperf/guzzle`、`hyperf/async-queue` 等组件。并发任务优先评估 `Hyperf\Coroutine\Parallel`、`Hyperf\Coroutine\WaitGroup` 或相应官方能力；共享请求数据使用 `Hyperf\Context\Context`。先核对所用 API 在安装版本中的行为。
- 仅在相互独立的 I/O 能缩短等待时间时并发；纯 CPU 计算不会因协程而加速。并发必须有合理上限，考虑连接池容量、超时、错误传播和结果收集；不要无界创建协程或把关键写入做成无人等待的后台任务。
- 新建子协程时确认所需上下文是否会传递，尤其是用户、语言、追踪信息和事务连接；不得假定父协程的 Context、数据库事务会自动安全地共享。请求结束时清理协程局部状态和资源。
- Swoole Hook 的存在不等于任意扩展、SDK 或文件/网络操作都具备协程兼容性。引入第三方包前核查其 I/O 实现、Swoole/Hyperf 兼容性、连接复用、超时与取消行为；不兼容时优先寻找官方适配、协程客户端或异步队列方案，并说明选择依据。
- 对外部 HTTP、Redis、数据库等调用设置明确的超时和失败处理；避免 `sleep()`、阻塞式轮询、同步命令执行和耗时文件操作占住 Worker。确需执行 CPU 密集型或阻塞任务时，评估独立进程、队列或专用服务。

## 常驻进程与状态隔离

- 服务对象、静态属性和全局变量可能在一个 Worker 内服务多个请求，也可能被多个协程交错访问。不得把用户身份、请求参数、语言、事务状态等请求级数据保存在这些位置；优先使用方法参数或协程 Context，并在 `finally` 中恢复临时状态。
- 对会跨请求复用的可变对象，检查并发访问、重入性与生命周期；连接池借出的连接要按组件约定归还。不要把一个协程正在使用的连接或可变客户端实例直接交给另一个协程。
- WebSocket 连接和 fd 属于特定进程/节点。进程内映射只能作为本地连接索引；跨 Worker、跨机器的在线状态、路由、广播或会话恢复必须设计共享的定位/消息机制，不能只靠内存数组或本地 fd。

## Hyperf 组件与开发命令

- 优先使用已安装的 Hyperf 核心组件：依赖注入与配置、`Hyperf\Context\Context`、`Hyperf\Validation\Request\FormRequest`、`hyperf/db-connection` 的模型与事务、`hyperf/redis`、`hyperf/cache`、`hyperf/model-cache`、`hyperf/paginator`、`hyperf/async-queue` 与 `hyperf/amqp`、`hyperf/guzzle`、`hyperf/logger`、`hyperf/translation`、`hyperf/process` 及 HTTP/WebSocket 服务端和客户端。按任务需要选择组件，先检查本项目现有封装和配置。
- 命令入口是 `php bin/hyperf.php`。用 `php bin/hyperf.php list` 发现实际可用命令，用 `<命令> --help` 核对参数；当前已提供 `describe:routes`、`gen:controller`、`gen:request`、`gen:command`、`gen:model`、`gen:migration`、`gen:resource` 等命令。生成文件后检查其目录、基类和风格是否符合现有代码；不要假定所有命令都运行在协程上下文。
- 查询路由时使用 `php bin/hyperf.php describe:routes` 并查看 `config/routes.php`；请求校验沿用 `app/Request/FormRequest.php`，API 输出沿用 `app/Controller/ApiController.php` 的契约。创建模型或资源前先看现有模型、迁移、响应格式和生成器选项，不照搬 Eloquent Resource 或自动 API 版本化约定。
- 排查服务地址时查看 `config/autoload/server.php` 与实际部署入口，不把本地监听地址直接当作公网 URL；排查日志时查看 `config/autoload/logger.php` 和当前运行环境的日志，不假定存在 Laravel Boost 的专用 MCP 工具。
- 数据库结构以迁移文件和实际数据库为准。优先使用现有只读工具查看数据；需要在应用上下文排查时优先复用现有命令或测试，不为简单验证另建临时脚本，也不把调试代码留在请求链路中。执行写入或破坏性数据库命令前确认任务授权与作用环境。

## 多机器、多 Worker 正确性

- 默认同一业务操作可能由不同机器或 Worker 同时处理，也可能因重试、重连、超时而重复到达。关键写入需有明确的幂等键或唯一约束，并用数据库事务、条件更新、Redis 原子操作等保证一致性；先分析并发读写，再选锁。
- 进程内锁、静态计数器、定时器和内存缓存只约束当前进程，不可用作全局互斥、全局计数或唯一数据来源。需要分布式协调时使用共享存储或消息系统；分布式锁应考虑过期、持有者校验、安全释放及业务超时。
- 队列任务按可能重复消费设计：保存足够的标识和必要数据，处理过程可重试、可幂等，明确失败与重试策略。不要假定单个队列消费者、单个 Worker 或固定执行顺序；`config/autoload/async_queue.php` 的当前并发配置只描述本次部署的进程设置。
- 缓存和 Redis 键要考虑节点共享、命名空间、TTL 与原子性；不要让本地缓存成为跨节点状态的唯一来源。跨进程或跨机器生效的变更需要同时检查数据库、缓存和消息的先后关系。

## 性能与资源边界

- 优先减少不必要的 I/O、重复查询、N+1 查询、大量对象复制和大 payload 序列化。使用批量操作、索引、连接池和有界并发时，要先确认业务语义与实际负载。
- 设计并发量时同时估算每机 Worker 数、每 Worker 协程数、数据库/Redis/HTTP 连接池上限以及外部服务限流；配置值不是吞吐能力的证明。关键链路关注延迟、超时、队列积压、连接池等待和内存增长。
- 长生命周期对象不得无限积累连接、定时器、回调或业务数据。为缓存、会话、任务和订阅设置可解释的生命周期，确认断连、异常、超时与 Worker 重启后的清理或恢复行为。

## 本项目入口与验证

- HTTP 路由在 `config/routes.php`，请求处理在 `app/Controller`、`app/Middleware`、`app/Request`；游戏与 WebSocket 逻辑在 `app/Game`、`app/Service`、`app/Vo`；异步任务在 `app/Job`、`app/Process`。改动前沿调用链检查相关配置和测试。
- 测试位于 `test/`，使用 Pest 和 `hyperf/testing`。优先运行覆盖改动的最小测试文件或过滤目标用例；修改测试后重跑该用例。单元测试与功能测试按实际契约分工，优先复用现有测试、Fixture 和引导文件，不为已有测试能证明的行为另建临时验证脚本，也不要无故删除测试。
- 创建测试数据前先找现有 Fixture 或工厂；项目没有现成工厂时按已有测试风格构造数据，不为了套用其他框架约定而新增工厂、Seeder。涉及协程、并发或分布式状态时，增加能验证交错执行、重复处理或失败重试的测试，而不是只测单次顺序路径。
- 常用命令：`composer test`、`composer phpstan`；服务启动为 `composer start`。改动 PHP 后仅对本次修改的文件运行 `vendor/bin/pint <文件路径>`，再运行适用的静态分析和相关测试；无法在本地验证的运行时或多机行为，要明确说明验证范围。
- 修改完成前检查 diff，确认没有误改用户已有工作、没有把密钥写入代码或日志，并用实际命令输出支持“已通过”的结论。
- 回复简洁，说明改动、验证结果和重要限制，不重复显而易见的实现细节。

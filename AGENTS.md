# AGENTS.md —— 给 AI 协作者的上下文交接

> **这份文档是入口。** 任何 AI（或新加入的人）接手这个仓库前，请先读完本文，
> 再按第 10 节的「文件地图」去读对应细节文档。
>
> 本文记录的是**现状 + 为什么是这样**，不是愿望清单。
> 如果你打算改某处，先看第 6 节「关键决策」——那些是踩过坑之后定下来的，改了会退回去。

---

## 1. 这是什么

**8W社区（8w.bgjq.top）** —— 一个 Minecraft 服务器「邦国崛起」的社区站点，包含世界动态、
邦国议会、社区大会（提案投票）、国际法庭、贸易等板块。

站点原本把用户身份直接放在社区库里（`users` 表）。本次重构把身份抽成独立的
**8W通行证系统**（`passport/`），成为全站统一身份服务，并对外开放给第三方接入。

**技术栈**：原生 PHP（无框架、无 Composer）+ MySQL/MariaDB + 原生 JS/CSS。
生产环境 PHP 8.4 + nginx + php-fpm。**刻意保持零依赖**——不要引入 Composer、npm 或任何框架。

**当前阶段**：重构已完成并全部推送。通行证可运行、可测试、可部署，
但**注册流程依赖的 3 个外部接口还没接入**（见第 3 节），因此现在还不能真正注册成功。

---

## 2. 一分钟速览

| 项 | 值 |
|---|---|
| 新数据库 | `bgjq8w`（旧库 `bgjq` **已废弃**） |
| 表 | 23 张表 + 1 个只读兼容视图 `users` |
| 通行证模块 | `passport/`，74 个文件 |
| 入口 | 用户中心 `/passport/`；API `/passport/api/v1/*`；OAuth `/oauth/*` |
| 提交前闸门 | `pwsh ./bin/test.ps1`（PHP 语法 + 255 项逻辑测试） |
| 测试数 | **255 项，必须全绿才能提交** |
| 分支 | `master`，远端 `origin` = GitHub `BGJQ-TOP/8w.bgjq.top` |
| 敏感配置 | 全部在 `.env`（已 gitignore），**绝不入库** |

---

## 3. 当前状态：已完成 vs 待办

### ✅ 已完成

- **数据库重建**：新库 `bgjq8w`，一键建库+建账号+授权+建表（`bin/init-database.ps1`）
- **8W通行证系统**：注册 / 登录 / 会话 / 改密 / 绑定管理 / 用户中心 UI（含深色模式与响应式）
- **第三方接入**：OAuth 2.0 完整实现（授权码+PKCE、刷新令牌轮换、client_credentials、内省、吊销）
- **FanVerify 真实接入**：7 个 openAPI 接口全覆盖，两条绑定路径（扫码 / 手填）
- **旧链路迁移**：社区站点的身份读写全部改走通行证
- **UI 重做**：完整设计系统，深色模式、三档响应式、无障碍、打印样式

### ⏳ 待办：还有 3 个外部接口没接入

注册**必填**校验依赖它们，**未接入时注册会明确返回 501，不会放行**（这是刻意设计）。

| 接口 | `.env` 开关 | 未接入时的行为 |
|---|---|---|
| **游戏内玩家信息** | `PLAYER_API_BASE` | 注册第一步就 501，无法注册 |
| **简幻通** | `SIMPASS_API_URL` | 注册第二步 501，无法注册 |
| **邦国信息** | `COUNTRY_API_BASE` | 仅影响邦国缓存预热，不挡注册 |

> 这三个接口的对接方式**已经是配置驱动的**：接口地址、路径模板、响应字段路径都在 `.env` 里。
> 拿到真实接口后**通常不需要改代码**，只需填 `.env`。
> 返回结构用点路径表达不了时，才改对应 Provider 的一个 `map*()` 方法。
> 详见 `passport/README.md` 第七节。

### ⏳ 待办：遗留兼容层（迁移期用，后续应移除）

`online_players`、`api_keys`、`api_logs` 三张表 + `users` 视图 + `php/classes/Auth.php` 兼容类。
**现在还有旧代码依赖它们，不要直接删**，先确认引用已清零。清单见 `docs/MIGRATION.md`。

---

## 4. ⚠️ 必须知道的陷阱清单

**这一节是本文最有价值的部分。** 这些都是实际踩过的坑，不是理论风险。

### 4.1 FanVerify 的令牌只允许绑定一个出口 IP

出口 IP 与白名单不一致时，**所有** FanVerify 接口都返回 `401 {"error":"Unauthorized"}`，
**看起来像"令牌无效"，实际是 IP 没放行**。

换服务器、加负载均衡、走 CDN 出站都会触发。排查入口：
```bash
php bin/fanverify-check.php   # 会先打印「本机出口 IP」，直接和后台登记值比对
```

### 4.2 FanVerify 官方文档有 4 处与实现不符（实测得出，代码以实测为准）

| 事项 | 文档说 | 实测 |
|---|---|---|
| POST 类接口的 `accesstoken` | 放 JSON body | **放 body 会 401，必须放 query string** |
| `seeotp` 限流 | 返回 429 | **HTTP 200 + `{"status":"rate_limit"}`** |
| `user_verify` 失败码 | 只写了 401 | **403=验证码错 / 404=账号不存在 / 400=参数错** |
| `getuserdata` | 403 = 未被验证过 | **任何输入都返回 `{"code":400}`，当前不可用** |
| 接口根地址 | `servers: []`（空） | `https://api.fanverify.cn` |

**其中 `user_verify` 的 404 最坑**：不能复用通用错误翻译，否则用户把账号 ID 填错会看到
「接口路径不存在，可能根地址配错了」。已单独实现在 `FanVerifyClient::userVerifyError()`。

### 4.3 `need_end_level` 调高会让两种失败无法区分

FanVerify 后台的 `need_end_level` 当前是 **0**。若调高，**「账号等级不足」与「验证码错误」返回同一个 403**，
`FanVerifyClient::userVerifyError()` 里的 403 文案必须相应放宽——代码里留了注释提醒。

### 4.4 `users` 是视图，且刻意不暴露密码哈希

`users` 不是表，是 `passport_accounts` 的**只读视图**。它**故意没有 `password` 列**：
视图一旦带上密码哈希，任何旧的 `SELECT u.*` 都会把它返回给前端（这个坑真踩过，
见 `api/v1/countries.php`）。**需要校验密码请直接读 `passport_accounts`。**

### 4.5 密码一律走请求体，绝不进 URL

URL 会进 Web 服务器访问日志、浏览器历史和 `Referer`。
`DELETE /passport/api/v1/bindings` 的 `password` 只从 body 读。

### 4.6 出站 URL 里的令牌会被日志记下

`HttpClient` 会把 URL 写进调试日志，而 FanVerify 的令牌就在 query string 上。
已统一走 `HttpClient::sanitizeUrl()` 打码。**新增出站接口时不要绕开它。**

### 4.7 绑定与解绑都要求当前密码

这不是多余的摩擦：绑定会改变账号的找回途径，若只凭登录态就能绑邮箱，
一个被盗用的会话就能把攻击者的邮箱挂上来再夺号。**不要为了"顺手"去掉这个校验。**

### 4.8 用户输入的密码不能出现在任何提交里

`.env` 已 gitignore，`.env.example` 只放占位符。
提交前用 `git status` 确认 `.env` 不在暂存区，并且**绝不要把令牌/密码写进 .sql、文档或测试**。

### 4.9 简幻通的环境变量名单双 P 混用

`SIMPASS_API_URL`（单 P）+ `SIMPPASS_ACCESS_TOKEN`（双 P）。沿用了旧 `.env` 的名字没改，
**极易写错**。改名前先确认没有部署方依赖。

### 4.10 `Application` 是自动装配的

`Application::instance()` 在未装配时会**自动 boot**。以前需要显式 `boot()`，
结果漏调用导致所有端点抛"应用尚未启动"——已修复并加了装配测试，**别再改回必须显式 boot**。

### 4.11 根目录有两个 nginx 配置，用新的那个

| 文件 | 状态 |
|---|---|
| `nginx-8w.bgjq.top.conf` | ✅ **生效的就是它**（含通行证与 OAuth 路由、`deny all` 保护、安全头） |
| `8w.bgjq.top`（无扩展名） | ❌ 重构前的旧配置，`root` 指向 `/var/www/8w.bgjq.top/html`，**已被取代** |

改 nginx 时**别改错文件**。新增 API 路由要同步加进 `nginx-8w.bgjq.top.conf`，
否则线上 404（本地用 `php -S` 测不出来，因为它不做 URL 重写）。

### 4.12 本地测试环境的两个限制

- 本地 PHP 可能缺 `pdo_mysql` → 页面渲染"数据库不可用"分支，这是**预期**，说明渲染正常
- 本地 PHP 可能缺 `curl` → FanVerify 与所有外部接口调用必然失败，是**环境**问题不是代码问题

这两点会让人误判"代码坏了"。用 `php -m` 确认扩展，或直接在服务器上验证。

---

## 5. 架构与分层

### 5.1 依赖方向（严格单向，不要打破）

```
api/            ← HTTP 端点，只做参数校验 + 调服务 + 组装响应
  ↓
Identity/       ← 业务：注册、登录、会话、绑定
OAuth/          ← 业务：OAuth 2.0 协议
Directory/      ← 业务：权威数据 + 本地缓存
  ↓
Contracts/      ← 接口定义（数据源的插拔点）
Verification/   ← 外部接口实现
  ↓
Support/        ← 基础设施：Config / Database / Logger / HttpClient / Arr / Str
Http/           ← Request / Response / Endpoint / ApiException
```

**规则**：`Support` 和 `Http` 不知道业务；业务层不直接写 SQL（走 `Database`），
不直接碰超全局变量（走 `Request`）。

### 5.2 请求生命周期

```
nginx 美化 URL
  → passport/api/*.php
  → require src/bootstrap.php（注册自动加载 + 提供 passport() 入口）
  → Endpoint::run(闭包)
      ├─ CORS / OPTIONS 预检
      ├─ 调业务，返回 Response
      ├─ 捕获 ApiException → 统一错误格式（OAuth 端点用 RFC 6749 格式）
      ├─ 捕获 Throwable → 500，只记日志不外泄细节
      └─ 写 passport_api_logs
```

### 5.3 统一响应格式

成功 `{"ok":true,"data":{…}}`；失败 `{"ok":false,"error":{"code","message","details?"}}`。

**例外**：OAuth 协议端点（`/oauth/token`、`/oauth/introspect`、`/oauth/revoke`）
按 RFC 6749 返回 `{"error","error_description"}`——第三方 SDK 只认这个格式。

### 5.4 权威数据缓存策略

游戏内数据由第三方权威服务提供，本地只是缓存：

- **权威主键**：玩家名、邦国ID。**除这两个键以外全是缓存**，随时可被覆盖。
- `country_id` 上**刻意不加外键**：缓存可能先于权威数据落库，加外键会让合法记录写不进来。
- 读取策略：缓存未过期用缓存；过期则回源；**回源失败时降级用旧缓存**（可用性优先）；
  只有"从未缓存且回源失败"才报错。
- `PlayerDirectory::find()` 的 by-ref 出参 `$servedFromCache` 会如实反映降级，
  对外 API 的 `source` 字段据此填写——**降级时也要报 `cache`，不能冒充权威结果**。

---

## 6. 关键设计决策（改了会退回去，先问为什么）

| 决策 | 原因 |
|---|---|
| **邮箱是可选绑定**，不是必填 | 邮件接口长期没接入，必填会让注册永远走不通；且简幻通已是必填的找回通道 |
| **未接入的接口明确 501，绝不静默放行** | 宁可注册失败并说清原因，也不允许出现未经权威校验的账号。这是硬约束 |
| **`users` 视图不暴露密码哈希** | 防旧 `SELECT u.*` 把哈希送到前端 |
| **绑定/解绑都验当前密码** | 防止被盗登录态挂上攻击者邮箱后夺号 |
| **二维码由服务端代理** | FanVerify 的 `genqrcode` 要求令牌放 query string，让浏览器直连会泄露令牌 |
| **落库前再轮询一次 OTP** | 不轻信前端"已确认"的说法 |
| **校验顺序：玩家名 → 简幻通 → 邮箱 → FanVerify** | 从"最贵最易失败"到"最便宜一次性"；邮箱码放后面，避免前两步失败时白烧验证码 |
| **令牌只存哈希** | 会话令牌、OAuth 令牌、邮箱验证码一律存 SHA-256 |
| **`Application` 自动装配** | 少一个"必须记得调用"的前置步骤，就少一类线上故障 |
| **无 Composer / 无框架** | 部署环境简单，零依赖；测试用自写的最小断言器 |

---

## 7. 代码约定

- **语言**：PHP 7.3+ 兼容语法（生产是 8.4，但本地测试环境是 7.3）。
  **不要用**：箭头函数、类型化属性、`match`、命名参数、构造器提升、`str_contains`。
- **注释与文档全中文**；代码标识符英文。
- **一个类一个文件**，PSR-4 映射到 `passport/src/`。
- **SQL 一律用预处理**，`Database` 已禁用模拟预处理。
- **敏感变更要记日志**，但 `Logger` 按**完整键名**打码——新增带密钥语义的字段名时，
  必须同步加进 `Support/Logger.php` 的 `$redactKeys`。
- **前端**：原生 JS，无构建步骤。CSS 用设计令牌（`passport/assets/passport.css` 顶部的 CSS 变量），
  **不要硬编码颜色和间距**。
- **提交信息**用约定式提交（`feat:` / `fix:` / `docs:` / `chore:` / `refactor:`），中文正文说清做了什么。

---

## 8. 怎么验证

### 8.1 提交前闸门（必须全绿）

```bash
pwsh ./bin/test.ps1
```

它做两件事：
1. 对全部 PHP 文件跑 `php -l`
2. 跑 `passport/tests/smoke.php`（**255 项断言**，不依赖数据库与网络）

`smoke.php` 覆盖：Support 工具、OAuth scope、值对象、响应/异常格式、
未接入接口的失败语义、数据库 SQL 结构自检、`Application` 依赖装配、
绑定规则、FanVerify 响应映射（用假 `HttpClient` 喂真实响应体）。

**新增功能时请同步加测试。** 这个测试集已经抓出过 2 个真实缺陷
（`Application` 从未启动、`Arr::get()` 遇到非数组输入抛 TypeError）。

### 8.2 FanVerify 接入自检（需要真实网络）

```bash
php bin/fanverify-check.php
```

检查 cURL 扩展、**本机出口 IP**、配置、`/openapi/devinfo` 连通性、令牌信息、OTP 可用性。

### 8.3 本地起服务看页面

```bash
php -S 127.0.0.1:8899 -t .
# 然后访问 http://127.0.0.1:8899/passport/index.php
```

⚠ 本地 PHP 若缺 `pdo_mysql`，页面会渲染"数据库不可用"分支——这是**预期行为**，说明渲染没问题。
⚠ 本地 PHP 若缺 `curl`，FanVerify 调用必然失败；这是环境限制，不是代码问题。
⚠ `php -S` **不做 URL 重写**，所以 `/passport/api/v1/me` 这种美化 URL 测不了，
要直接访问 `/passport/api/v1/me.php`。美化 URL 只在 nginx 下生效。

---

## 9. 红线

1. **绝不把密钥写进任何会提交的文件**——`.env`、令牌、密码、连接串只放 `.env`。
   提交前用 `git status` 确认 `.env` 不在暂存区。
2. **绝不 `git push --force`** 共享分支；**绝不 `--no-verify`** 跳过钩子。
3. **绝不在测试/文档/`.sql` 里写出真实密钥**（`passport/tests/smoke.php` 里的
   "SQL 不含硬编码密码"断言就是靠结构检查，不写密钥片段）。
4. **绝不为了"让测试过"而放宽断言**——测试失败先查是代码错还是断言错。
5. **绝不让未验证的身份通过**——这是本系统的核心安全属性。
6. **不要引入 Composer / npm / 框架**，除非明确要求。

---

## 10. 文件地图：该读哪份文档

| 你想知道 | 读这个 |
|---|---|
| **本文**：现状、陷阱、决策、约定 | `AGENTS.md`（当前文件） |
| 通行证系统的维护者视角细节、TODO 接口怎么接 | `passport/README.md` |
| 第三方怎么接入（OAuth 流程、全部端点、错误码） | `docs/PASSPORT-API.md` |
| 旧库→新库怎么迁移、遗留兼容层怎么移除 | `docs/MIGRATION.md` |
| 数据库结构说明与设计意图 | `database_schema.md` |
| 怎么部署、要装什么扩展、定时任务 | `DEPLOY.md` |
| 站点最初的产品设计设想 | `设计方案.txt` |

### 关键源码位置

```
passport/
├── src/
│   ├── Application.php              依赖装配（自动 boot）
│   ├── Support/                     Config/Database/Logger/HttpClient/HttpResponse/Arr/Str
│   ├── Http/                        Request/Response/Endpoint/ApiException
│   ├── Contracts/                   五个数据源接口（玩家/邦国/邮箱/简幻通/FanVerify）
│   ├── Identity/                    Account/AccountRepository/Authenticator/
│   │                                SessionStore/RegistrationService/BindingService
│   ├── OAuth/                       OAuthServer/Scope/ClientRepository/
│   │                                TokenRepository/AuthorizationCodeRepository
│   ├── Directory/                   玩家与邦国的权威数据 + 缓存；Providers/ 是 HTTP 实现
│   └── Verification/                邮箱/简幻通/FanVerify 的验证实现
├── api/v1/                          第一方 JSON API
├── api/oauth/                       OAuth 端点 + 应用管理
├── api/_guard.php                   共用访问守卫（Bearer 或会话 Cookie）
├── assets/passport.css              设计系统（令牌 / 深色模式 / 响应式 / 无障碍）
├── index.php                        用户中心（登录/注册/绑定/授权应用/管理员面板）
└── tests/smoke.php                  255 项断言
```

### 数据模型速记

- `passport_accounts` —— **身份唯一真源**。必填绑定：`player_name`、`simpass_uid`；
  可选绑定：`email`、`fanverify_uid`（都允许 NULL，唯一索引允许多个 NULL）
- `passport_sessions` / `passport_email_codes` —— 会话与验证码，只存 SHA-256
- `passport_oauth_clients` / `_codes` / `_tokens` / `passport_api_logs` —— 第三方接入
- `countries` —— 邦国（`id` 是权威主键，其余是缓存 + 站内补充字段）
- `players` —— 游戏内玩家，**一表两用**：玩家缓存 + 邦国玩家列表
- 社区域表（`news`/`proposals`/`votes`/`cases`/…）身份列引用 `passport_accounts.id`

---

## 11. 本次重构做了什么（变更清单）

相对重构前的仓库（`9c0c3f6`），一共 20 个提交：

### 数据库
- 新库 `bgjq8w`（旧库 `bgjq` 废弃）；`database/8w_passport.sql` 含建库+建账号+授权+23 表+1 视图
- 密码位置用占位符 `__DB_PASSWORD__`，由 `bin/init-database.ps1` 从 `.env` 渲染后执行并清理临时文件
- 删除旧的 `init_database.sql` 与 `php/create_database_tables.sql`（后者硬编码了已泄露的密码）
- `database/upgrade-email-optional-fanverify.sql` —— 给已导入过旧版的库做增量升级（可重复执行）

### 通行证系统（全新）
- 注册 / 登录 / 会话 / 改密 / 绑定管理 / 用户中心
- 邮箱与 FanVerify 均为**可选绑定**，可绑可解；必填绑定不可解绑
- 新增 `bindings` 端点与 `BindingService`

### 第三方接入（全新）
- OAuth 2.0：授权码+PKCE、刷新令牌轮换、client_credentials、内省(RFC 7662)、吊销(RFC 7009)
- 8 个 scope 按需裁剪；应用管理端点 + 授权确认页；用户可查看并撤销授权

### FanVerify（从 TODO 到真实接入）
- `FanVerifyClient` 覆盖官方全部 7 个接口
- 两条绑定路径：扫码（OTP + 二维码服务端代理 + 轮询）与手填（UID + 动态验证码）
- 风险标签落库并在 UI 展示；`bin/fanverify-check.php` 与后台自检端点

### 旧链路迁移
- `users` 表 → `passport_accounts` + 只读视图
- `php/classes/Auth.php` 改为兼容层；`api/v1/auth.php`、`users.php`、`countries.php`、
  `server.php`、`public/stats.php` 改走通行证
- 注册入口统一到 `/passport/`，移除 10 个页面里的旧注册弹窗
  （`index.php` / `index.html` / `admin.html` / `assembly.php` / `court.php` / `parliament.php` /
  `services.php` / `services.html` / `world-news.php` / `complaint.html`）

### UI
- `passport.css` 重写为完整设计系统：设计令牌、深色模式、三档响应式断点、
  触屏 44px 点击目标、安全区适配、`prefers-reduced-motion`、高对比度、打印样式
- 用户中心重做为分栏仪表盘；授权确认页同步换新

### 顺带修掉的安全问题
- `users` 视图曾会泄露密码哈希（`SELECT u.*` 直接送到前端）
- 线上 6 个页面关闭了 `display_errors`
- 解绑密码从查询串改到请求体
- 出站 URL 里的令牌不再进日志

### 测试
- 从 0 到 **255 项**；抓出 2 个真实缺陷（`Application` 从未启动、`Arr::get()` 非数组崩溃）

---

## 12. 上手建议

第一次接手，按这个顺序走：

1. 读本文（尤其第 4 节陷阱、第 6 节决策）
2. `pwsh ./bin/test.ps1` —— 确认基线是绿的
3. 读 `passport/README.md` 了解通行证全貌
4. 想接那 3 个待接入接口 → 读 `passport/README.md` 第七节，通常只需填 `.env`
5. 想动 FanVerify → 先读第 4.1–4.3 节，再读 `FanVerifyClient` 的注释

有疑问时**优先相信代码与实测**，其次才是官方文档——FanVerify 的文档已经被证明有 4 处不准确。

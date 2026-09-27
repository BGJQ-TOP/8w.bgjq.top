# 8W通行证系统（passport/）

> 面向本项目维护者。第三方接入文档见 [`docs/PASSPORT-API.md`](../docs/PASSPORT-API.md)，
> 上线与数据迁移见 [`docs/MIGRATION.md`](../docs/MIGRATION.md)。

---

## 一、这是什么

8W通行证是 8W社区的统一身份服务：**一次注册，全站通用**。

- 身份主体是 `passport_accounts` 表里的一行，其 `id` 就是对外唯一的通行证 UID，也是 OAuth 2.0 的 `sub`。
- 一个通行证同时绑定三样东西：验证邮箱、游戏内玩家名、简幻通身份。
- 第三方应用不碰数据库，只能通过 OAuth 2.0 消费通行证；通行证自己也不直接读社区业务表。

### 为什么独立成子系统

| 原因 | 具体体现 |
| --- | --- |
| 身份是全局资产 | 主站、第三方应用、将来的其他子站都引用同一份 `passport_accounts.id`，不能再散落在 `users` 表里 |
| 第三方接入需要标准协议 | OAuth 2.0 授权码 + PKCE / 刷新令牌 / 客户端凭据，由 `src/OAuth/` 独立承载（RFC 6749 / 7636 / 7009 / 7662） |
| 权威数据来自游戏服务器 | 玩家名与邦国 ID 的权威方是第三方游戏服务，本地只做缓存，缓存策略必须集中一处（`src/Directory/`） |
| 外部接口还没全部到位 | 邮箱、玩家、邦国、简幻通四个接口都可能没接入，必须做到「未接入就明确报错，绝不静默放行」 |
| 便于测试 | 无 Composer / 无框架，纯 PSR-4 自动加载，`passport/tests/smoke.php` 不依赖数据库与网络即可跑 |

### 与社区主站的关系

- **主站消费通行证有两条路**：
  1. `php/classes/Auth.php` —— 兼容类。保留旧方法签名（`isLoggedIn()` / `getCurrentUser()` / `hasRole()` / `login()` / `logout()` …），内部全部委托给通行证服务；登录态以通行证会话 Cookie 为唯一真源，`$_SESSION['user']` 只是给旧页面看的镜像，不参与鉴权判定。
  2. 数据库里的 **`users` 只读视图** —— 旧接口文件里大量 `LEFT JOIN users u ON ... = u.id` 因此继续可用。视图从 `passport_accounts` 派生，列名保持旧名（`game_id` / `jhtuid` / `level` …），共 12 列。
     ⚠ 视图**刻意不提供 `password` 列**：视图一旦带上密码哈希，任何旧的 `SELECT u.*` 都会把它返回给前端。需要校验密码请直接读 `passport_accounts`（`Account::passwordHash()`）。
- **主站不写身份**：所有对 `passport_accounts` 的写入都收敛在 `src/Identity/AccountRepository.php`；主站要建号/改密/停用，走 `Auth` 兼容类或通行证 API。
- **主站自身业务表**（`news` / `proposals` / `votes` / `cases` / `trades` …）继续存在，其身份列（`author_id` / `user_id` / `proposer_id` …）统一引用 `passport_accounts.id`。
- **注册入口只有一处**：`/passport/`。主站 `index.html`、`admin.html` 的旧注册弹窗已删除，`js/main.js` 的 `initRegisterEntry()` 只负责跳转。

---

## 二、目录结构

```
passport/
├── index.php                     通行证用户中心（登录/注册/账号信息/我的邦国/已授权应用/改密/管理员应用管理）
├── README.md                     本文件
├── api/
│   ├── _guard.php                数据类接口共用访问守卫（Bearer 令牌或会话 Cookie）
│   ├── v1/                       第一方 JSON API（站内页面与第三方都可调）
│   │   ├── register.php          POST   注册（委托 RegistrationService）
│   │   ├── login.php             POST   登录（用户名或邮箱）
│   │   ├── logout.php            POST   注销当前会话
│   │   ├── me.php                GET    当前账号 + 玩家/邦国本地缓存
│   │   ├── password.php          POST   修改密码（改后其它会话全部失效）
│   │   ├── email-code.php        POST   下发邮箱验证码
│   │   ├── player.php            GET    查游戏内玩家（权威 + 缓存）
│   │   ├── country.php           GET    查邦国（按 ID 或名称）
│   │   └── authorized-apps.php   GET/DELETE  列出/撤销第三方授权
│   └── oauth/                    OAuth 2.0 端点
│       ├── authorize.php         GET/POST 授权端点（含授权确认页与错误页）
│       ├── token.php             POST     令牌端点（三种 grant_type）
│       ├── userinfo.php          GET      用户信息（按 scope 裁剪）
│       ├── introspect.php        POST     RFC 7662 令牌内省
│       ├── revoke.php            POST     RFC 7009 令牌吊销
│       └── clients.php           GET/POST/DELETE  第三方应用管理（需管理员通行证）
├── assets/passport.css           通行证页面样式（用户中心与授权页共用）
├── src/
│   ├── bootstrap.php             命名空间自动加载（W8\Passport\ → src/）+ passport() 全局入口
│   ├── Application.php           依赖装配容器，全部懒加载；instance() 未装配时自动装配
│   ├── Maintenance.php           维护任务：清理过期会话/授权码/令牌/邮箱验证码/调用日志
│   ├── Support/                  无业务的基础设施
│   │   ├── Config.php            .env 读取器（含全部默认值表）
│   │   ├── Database.php          PDO 封装（懒连接、真预处理、事务）
│   │   ├── HttpClient.php        统一出站 HTTP（超时、cURL、错误语义）
│   │   ├── HttpResponse.php      出站响应（区分「传输失败」与「HTTP 状态码」）
│   │   ├── Logger.php            文件日志 + 敏感字段自动打码
│   │   ├── Str.php               随机串 / SHA-256 / 恒定时间比较 / 邮箱脱敏
│   │   └── Arr.php               第三方响应的点路径取值
│   ├── Http/                     请求与响应
│   │   ├── Request.php           $_SERVER/$_GET/php://input 的只读视图
│   │   ├── Response.php          统一响应格式与 OAuth2 错误格式
│   │   ├── ApiException.php      业务异常 + OAuth2 错误载体
│   │   └── Endpoint.php          端点运行器（CORS、OPTIONS、异常翻译、访问日志）
│   ├── Contracts/                四个外部接口契约（接口到位后只需实现它们）
│   │   ├── PlayerProvider.php    游戏内玩家数据源
│   │   ├── CountryProvider.php   邦国数据源
│   │   ├── EmailVerifier.php     邮箱验证码发送方
│   │   └── SimpassVerifier.php   简幻通验证方
│   ├── Directory/                权威数据 + 本地缓存
│   │   ├── PlayerDirectory.php   玩家目录（读缓存/回源/降级）
│   │   ├── CountryDirectory.php  邦国目录（同步只覆盖权威字段）
│   │   ├── PlayerProfile.php     玩家值对象（player_name / player_id / country_id）
│   │   ├── CountryProfile.php    邦国值对象（含玩家列表，population 由列表长度派生）
│   │   └── Providers/            HTTP 实现与「未接入」实现
│   ├── Verification/             验证码与外部身份验证
│   │   ├── EmailCodeService.php  验证码生成/限流/落库/一次性消费
│   │   ├── HttpEmailVerifier.php 邮件发送 HTTP 实现（配置驱动）
│   │   ├── HttpSimpassVerifier.php 简幻通校验 HTTP 实现（配置驱动）
│   │   ├── SimpassIdentity.php   简幻通校验结果值对象
│   │   └── Unavailable*.php      未接入时的 Null Object（一律抛 not_implemented）
│   ├── Identity/                 身份域
│   │   ├── Account.php           账号只读视图（toPublicArray / toProfileArray）
│   │   ├── AccountRepository.php 对 passport_accounts 的唯一写入入口
│   │   ├── Authenticator.php     登录 / 登出 / 改密 / 当前用户
│   │   ├── SessionStore.php      登录态（Cookie 只放随机令牌，库里只存 SHA-256）
│   │   └── RegistrationService.php 注册流程编排
│   └── OAuth/                    OAuth 2.0 服务端
│       ├── OAuthServer.php       授权、令牌、内省、吊销、限流
│       ├── Scope.php             scope 定义（MAP / MACHINE_SCOPES / DEFAULT_SCOPE）
│       ├── ClientRepository.php  第三方应用仓库
│       ├── TokenRepository.php   令牌仓库（签发/轮换/吊销）
│       └── AuthorizationCodeRepository.php 授权码仓库（一次性消费）
├── tests/smoke.php               最小验证脚本（不依赖数据库与网络）
├── storage/logs/                 运行期日志（.gitignore 排除，Nginx 已 deny）
└── （项目根的 bin/ 下有 init-database.ps1、test.ps1、maintenance.php 三个脚本）
```

---

## 三、架构分层与依赖方向

```
api/*.php  ─┐
index.php   ┴─→ Http\Endpoint ─→ Application（唯一容器）
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        ▼                            ▼                            ▼
   Identity\*                    OAuth\*                  Directory\* / Verification\*
   （账号、会话、注册）            （授权、令牌）            （权威数据、验证码、外部身份）
        │                            │                            │
        └──────────────┬─────────────┴──────────────┬─────────────┘
                       ▼                            ▼
              Http\Request / Response / ApiException     Contracts\*（接口）
                       │                            │
                       └──────────► Support\* ◄─────┘
                       （Config / Database / HttpClient / HttpResponse / Logger / Str / Arr）
```

**依赖方向是单向的**，箭头永远从上往下，没有反向引用：

| 层 | 允许依赖 | 说明 |
| --- | --- | --- |
| `Support` | 无（只依赖 PHP 扩展） | 不含任何业务概念，可被任何层使用 |
| `Http` | `Support` | `Endpoint` 依赖 `Application`，但只用于取日志器与配置 |
| `Contracts` | `Directory` / `Verification` 的值对象 | 只声明方法签名，不含实现 |
| `Directory` / `Verification` | `Contracts`、`Support`、`Http\ApiException` | 失败一律抛 `ApiException`，不返回错误码 |
| `Identity` | `Directory`、`Verification`、`Support`、`Http` | 注册流程依赖目录与验证服务 |
| `OAuth` | `Identity\Account(Repository)`、`Support`、`Http` | 令牌只认 `account_id`，不反向依赖目录 |
| `Application` | 全部 | 唯一的装配点：`resolve*` / `shared()` / `bind()` |
| `api/*.php` | `Application`、`Http` | 端点里只写参数校验与响应组装，不写业务逻辑 |

**换实现只改一处**：`Application::playerProvider()` / `countryProvider()` / `emailVerifier()` / `simpassVerifier()` 会根据 `.env` 是否配置对应键，自动在 HTTP 实现与 `Unavailable*` 之间选择；测试或定制部署可用 `Application::bind('player_provider', $obj)` 运行期覆盖。

**装配是自动的**：`Application::boot()` 显式装配（幂等），而 `Application::instance()` 在尚未装配时会**自动装配**（从项目根的 `.env` 读配置）。刻意不做成「必须记得先 boot」——少一个前置步骤，就少一整类「忘了启动」的线上故障。`Application::reset()` 仅供测试重置单例。

---

## 四、注册流程的完整链路

入口：`POST /passport/api/v1/register`（兼容入口 `POST /api/v1/auth.php?action=register`），
实现：`src/Identity/RegistrationService.php::register()`。

### 4.1 实际校验顺序

| 步骤 | 内容 | 失败结果 |
| --- | --- | --- |
| 0 | **字段格式**：`username` 3–32 位仅 `[A-Za-z0-9_-]`；`email` ≤191 且通过 `FILTER_VALIDATE_EMAIL`；`player_name` 非空、≤32、不含 `<>` 与控制字符；`password` 8–72 位且不能纯字母/纯数字；`simpass_uid > 0`；`simpass_code` 非空 | 422 `invalid_request`，`details.field` 指向出错字段 |
| 1 | **唯一性预检**（顺序：`username` → `email` → `player_name` → `simpass_uid`） | 409 `conflict`，`details.field`；真正的唯一性由唯一索引兜底 |
| 2 | **① 游戏内玩家名权威校验**：`PlayerDirectory::find($playerName, true)` 强制回源 | 玩家不存在 → 422「游戏内不存在名为「X」的玩家」；权威返回名与输入大小写不敏感不一致 → 422「玩家名应为「Y」，请核对后重试」 |
| 3 | **② 简幻通校验**：`SimpassVerifier::verify($simpassUid, $simpassCode, $playerName)` | 业务码不为成功码 → 422「简幻通验证失败：…」（`details.field = simpass_code`）；接口未接入 → 501 |
| 4 | **③ 邮箱验证码校验**：`EmailCodeService::assertVerify($email, 'register', $emailCode)`，成功即消费 | 验证码错误/过期 → 422（`details.field = email_code`）；发送接口未接入 → 501 |
| 5 | **落库**（`Database::transaction`）：写入 `username` / `email` / `email_verified_at` / `password_hash`（`password_hash(…, PASSWORD_DEFAULT)`）/ `simpass_uid` / `simpass_level` / `simpass_verified_at` / `player_name` / `player_id` / `country_id` / `player_synced_at` / `role='observer'` / `status=1` | 写库失败 → 500 `server_error` |
| 6 | **邦国缓存预热**：`$player->hasCountry()` 时 `CountryDirectory::find($countryId, true)`；回源失败则 `CountryDirectory::touch($countryId, null)` 落一行占位（名称「邦国#ID」） | **尽力而为**：预热失败只记日志（`passport.country_warmup_skipped` / `passport.country_touch_failed`），不影响注册结果 |
| 7 | **注册即登录**：`SessionStore::create()` 下发 HttpOnly Cookie，`AccountRepository::touchLogin()` 记录登录时间与 IP | — |
| 8 | 记 `passport.registered` 日志，重新读取账号并返回 `data.account` | 读回失败 → 500「注册成功但读取账号失败，请尝试登录」 |

### 4.2 为什么是这个顺序

代码注释给出了明确理由，请勿随手调换：

> 校验顺序刻意从「最可能失败、最贵」到「最便宜、一次性」：**玩家 → 简幻通 → 邮箱验证码**。
> 邮箱验证码放最后，是为了不在前两项失败时白白烧掉一个验证码。

- 玩家名校验要打第三方接口，最贵、也最容易因为用户手抖写错而失败 → 排第一。
- 简幻通校验要打第三方接口，且需要用户去小程序取验证码 → 排第二。
- 邮箱验证码是一次性资源（校验成功即消费、同邮箱 60 秒内不能重发、每小时最多 5 次）→ 排最后。
- 唯一性预检放在所有外部调用之前，纯本地查询，最便宜。

> ⚠ 注意：部分设计描述里把顺序写成「邮箱验证码 → 玩家名 → 简幻通」，**与实现不符**。
> 以本文件与 `RegistrationService::register()` 的代码为准。

### 4.3 本地联调开关

`verificationEnabled()` 为 `false` 的条件是 **`PASSPORT_DEBUG=1` 且 `PASSPORT_DEV_BYPASS_VERIFICATION=1` 同时成立**，
此时跳过 ②（简幻通）与 ③（邮箱验证码），并每次记 `passport.verification_bypassed` 的 **WARNING** 日志。
步骤 ①（玩家名权威校验）**永不跳过**。生产环境两个开关都必须保持 `0`。

---

## 五、权威数据缓存策略

### 5.1 权威主键与缓存字段

| 位置 | 权威主键 | 权威缓存（可被接口覆盖） | 站内补充（权威接口不碰） |
| --- | --- | --- | --- |
| `players` 表 | `player_name`（主键） | `player_id`、`country_id` | — |
| `countries` 表 | `id`（主键） | `name`、`declaration`、`territory_chunks`、`population` | `government_type`、`flag_url`、`is_active` |
| `passport_accounts` 表 | `player_name`（唯一索引 `uk_player_name`） | `player_id`、`country_id`、`player_synced_at` | — |

- **玩家名的权威主键地位**：唯一可信的检索键，`PlayerProvider::findByName()` 是唯一的查询入口；
  `HttpPlayerProvider::mapPlayer()` 在三个字段一个都没解析出来时返回 `null`，
  **绝不凭查询用的名字凭空造一条记录**，否则注册校验会被绕过。
- **`country_id` 上刻意不加外键**：缓存可能先于权威数据落库，加外键会导致合法的玩家记录写不进来。
- **`population` 是派生值**：等于该邦国玩家列表长度（`CountryProfile::population()`），不是独立字段。

### 5.2 TTL

- 统一由 `DIRECTORY_CACHE_TTL` 控制，默认 **600 秒**（`PlayerDirectory::rowIsStale()` / `CountryDirectory::rowIsStale()`）。
- `DIRECTORY_CACHE_TTL <= 0` 时 `rowIsStale()` 恒返回 `false`，即缓存**永不过期**（只靠显式 `fresh=1` 回源）。
- 判定依据是行上的 `synced_at`：`synced_at` 无法解析或 `now - synced_at > ttl` 即视为过期。

### 5.3 读取与降级行为

`PlayerDirectory::find($name, $fresh)` 与 `CountryDirectory::find()/findByName()` 的策略一致：

| 情形 | 行为 |
| --- | --- |
| `fresh = false` 且缓存存在且未过期 | 直接返回缓存，**不打第三方** |
| `fresh = true`，或缓存缺失/已过期 | 尝试回源 |
| 数据源**未配置**（`.env` 缺 `PLAYER_API_BASE` / `COUNTRY_API_BASE`） | 有缓存 → 返回旧缓存（保证已上线功能不被配置拖垮）；无缓存 → 抛 `not_implemented`（501） |
| 回源抛 `ApiException`（超时、非 2xx、非法 JSON、5xx） | 有缓存 → 返回旧缓存并记 WARNING（`player_directory.degraded` / `country_directory.degraded`）；无缓存 → 原样抛出（通常是 500 `server_error`） |
| 回源成功但权威方返回 `null`（明确「没有这个人/这个邦国」） | 玩家：顺手 `forget()` 清掉可能过期的缓存行，返回 `null`；邦国：直接返回 `null` |
| 回源成功 | 写缓存后返回权威结果（**写入永远以权威为准**） |

> ⚠ `fresh = true` 只表示「跳过『缓存未过期就直接返回』这一步」，**不保证**一定拿到权威数据：
> 数据源未配置或回源失败时仍会返回旧缓存。

### 5.4 各接口的取数口径

| 接口 | 口径 |
| --- | --- |
| 注册 | `players->find($name, true)` 强制回源；邦国用 `countries->find($id, true)` 预热 |
| `GET /passport/api/v1/me` | 全部走 `findCached()`，**不回源**，避免每次打开个人页都打第三方 |
| `GET /passport/api/v1/player` | `fresh` 参数默认 `false`；返回 `source` 字段（`cache` / `authoritative`） |
| `GET /passport/api/v1/country` | `fresh` 参数默认 `false` |
| 通行证用户中心「强制同步」按钮 | 前端显式传 `fresh=1` |

### 5.5 邦国同步的覆盖范围

`CountryDirectory::sync()` 在一个事务里做两件事：

1. `countries` 表 upsert，只覆盖 `name` / `declaration` / `territory_chunks` / `population` / `synced_at`，
   **绝不动** `government_type` / `flag_url` / `is_active`。
2. 仅当 `CountryProfile::rosterProvided()` 为真时才调用 `PlayerDirectory::replaceRoster()`：
   先把不在名册里的玩家 `country_id` 置 `NULL`，再逐个 upsert。
   「返回了空列表」与「本次没返回列表」是两回事——后者不该清空本地缓存。

---

## 六、TODO 清单：四个待接入的外部接口

四个接口都遵循同一套设计：

1. 契约在 `src/Contracts/`，HTTP 实现已经写好且**由 `.env` 配置驱动**，未配置时由 `Unavailable*` 接管；
2. 对接时**通常不需要改代码，只要填 `.env`**；只有返回结构无法用点路径表达时才改一个 `map*()` / `build*()` 方法；
3. **未接入时明确报 `not_implemented`（HTTP 501），绝不静默放行**。这是硬约束：
   宁可注册失败并说清原因，也不允许出现未经权威校验的账号。

### 6.1 邮箱验证码

- **对应 `.env` 变量**（见 `.env.example` 与 `Support/Config.php` 默认值表）：
  `EMAIL_API_URL`（必填，为空即视为未接入）、`EMAIL_API_TOKEN`、`EMAIL_API_TIMEOUT`（默认 8）、
  `EMAIL_API_METHOD`（`POST` 默认 / `GET`）、`EMAIL_API_BODY_TEMPLATE`（JSON 模板，占位符
  `{email}` `{code}` `{scene}` `{ttl}` `{minutes}` `{app_name}`）、`EMAIL_API_SUCCESS_FIELD`、
  `EMAIL_API_MESSAGE_FIELD`、`EMAIL_CODE_TTL`（默认 600）、`EMAIL_FROM_NAME`。
- **需要实现的接口文件**：`src/Contracts/EmailVerifier.php`（契约）；
  HTTP 实现 `src/Verification/HttpEmailVerifier.php` 已完整可用；
  未配置时绑定 `src/Verification/UnavailableEmailVerifier.php`。
- **对接时通常无需改代码**：只要填 `EMAIL_API_URL`（以及接口要求的 token / 模板）。
  若新接口是查询串形态或需要签名头，重写 `buildBody()` / `authHeaders()` 即可。
- **验证码逻辑本身已完成**（`EmailCodeService`）：6 位数字、SHA-256 落库、同邮箱同场景 60 秒最小重发间隔、
  每小时最多 5 次、默认 10 分钟有效、最多 5 次校验失败后作废、校验成功即消费、发送失败自动作废刚写入的码。
- **未接入时的表现**：
  - `POST /passport/api/v1/email-code` → 501 `not_implemented`「邮箱验证码发送接口尚未接入（TODO）。请在 .env 中配置 EMAIL_API_URL 后重试。」
  - 注册流程的邮箱验证码校验（三处外部校验中的最后一步）→ 501 `not_implemented`「邮箱验证码发送接口尚未接入，无法完成邮箱验证。…」

### 6.2 游戏内玩家

- **对应 `.env` 变量**：`PLAYER_API_BASE`、`PLAYER_API_PATH`（默认 `/player/{player}`，占位符
  `{player}` 或 `{player_name}`，会做 URL 编码）、`PLAYER_API_TOKEN`（填了就以 `Authorization: Bearer` 带上）、
  `PLAYER_API_TIMEOUT`（默认 8）、`PLAYER_API_SUCCESS_FIELD`（该路径的值为 `false` 即判定玩家不存在）、
  `PLAYER_API_NAME_FIELD`、`PLAYER_API_ID_FIELD`、`PLAYER_API_COUNTRY_FIELD`（均可写多个候选，逗号分隔）。
- **需要实现的接口文件**：`src/Contracts/PlayerProvider.php`（契约）；
  HTTP 实现 `src/Directory/Providers/HttpPlayerProvider.php`，对接点是 `mapPlayer()`；
  未配置时绑定 `src/Directory/Providers/UnavailablePlayerProvider.php`。
- **对接时通常无需改代码**：字段路径的默认候选已覆盖常见命名
  （玩家名 `data.player_name` / `data.name` / `data.username` / `player_name` / `name`；
  玩家 ID `data.player_id` / `data.id` / `player_id` / `id`；
  邦国 ID `data.country_id` / `data.faction_id` / `data.country.id` / `country_id` / `faction_id`），
  对不上时用 `PLAYER_API_*_FIELD` 指定，或重写 `mapPlayer()`。
- **判定语义**：HTTP 404 → 玩家不存在（返回 `null`）；`PLAYER_API_SUCCESS_FIELD` 为 `false` → `null`；
  三个字段一个都没解析出来 → `null` 并记 `player_provider.unmapped_response`；
  传输失败 / 非 2xx / 非法 JSON → 抛 `ApiException`（500 `server_error`）。
- **未接入时的表现**：
  - 注册流程的玩家名校验（三处外部校验中的第一步）→ 501 `not_implemented`「玩家信息接口尚未接入，无法校验游戏内玩家名。请在 .env 中配置 PLAYER_API_BASE / PLAYER_API_PATH」
  - `GET /passport/api/v1/player`（且本地无该玩家缓存）→ 501

### 6.3 邦国信息

- **对应 `.env` 变量**：`COUNTRY_API_BASE`、`COUNTRY_API_PATH`（默认 `/country/{country_id}`，占位符
  `{country_id}` 或 `{country}`）、`COUNTRY_API_PATH_BY_NAME`（可选，占位符 `{country}` 或 `{country_name}`）、
  `COUNTRY_API_TOKEN`、`COUNTRY_API_TIMEOUT`、`COUNTRY_API_SUCCESS_FIELD`、`COUNTRY_API_ID_FIELD`、
  `COUNTRY_API_NAME_FIELD`、`COUNTRY_API_DECLARATION_FIELD`、`COUNTRY_API_TERRITORY_FIELD`、
  `COUNTRY_API_PLAYERS_FIELD`（默认 `data.players`）、`COUNTRY_API_PLAYER_NAME_FIELD`（默认 `name`）、
  `COUNTRY_API_PLAYER_ID_FIELD`（默认 `id`）。
- **需要实现的接口文件**：`src/Contracts/CountryProvider.php`（契约）；
  HTTP 实现 `src/Directory/Providers/HttpCountryProvider.php`，对接点是 `mapCountry()` / `mapPlayers()`；
  未配置时绑定 `src/Directory/Providers/UnavailableCountryProvider.php`。
- **对接时通常无需改代码**：同上，字段路径可用 `.env` 覆盖。
  玩家列表元素既支持对象（按 `COUNTRY_API_PLAYER_NAME_FIELD` / `..._ID_FIELD` 取），
  也支持「元素就是玩家名字符串」的形态。
- **注意**：`HttpCountryProvider` 类头部的 TODO 注释没有列出实际在用的 `COUNTRY_API_ID_FIELD`，
  以代码与 `.env.example` 为准（两处都有这一项）。
- **未接入时的表现**：
  - `GET /passport/api/v1/country?id=…`（且本地无缓存）→ 501「邦国信息接口尚未接入，无法查询邦国。…」
  - `GET /passport/api/v1/country?name=…`（且本地无缓存）→ 501「…无法按名称查询邦国。请在 .env 中配置 COUNTRY_API_BASE / COUNTRY_API_PATH_BY_NAME」
  - 即使只配了 `COUNTRY_API_BASE` / `COUNTRY_API_PATH` 而没配 `COUNTRY_API_PATH_BY_NAME`，按名称查询也会明确报错
    「邦国信息接口未提供按名称查询，请使用邦国ID，或配置 COUNTRY_API_PATH_BY_NAME」，不猜、不扫全表。
  - 注册时的邦国预热失败**不会**让注册失败（见 4.1 第 6 步）。

### 6.4 简幻通

- **对应 `.env` 变量**：`SIMPASS_API_URL`、`SIMPPASS_ACCESS_TOKEN`（注意是**双 P**，代码、`Config.php` 默认值表与
  `.env.example` 三处一致，请照抄）、`SIMPASS_API_TIMEOUT`、`SIMPASS_API_METHOD`（`POST` 默认 / `GET`）、
  `SIMPASS_API_SUCCESS_CODE`（默认 `200`）、`SIMPASS_API_CODE_FIELD`（默认 `code`）、
  `SIMPASS_API_MESSAGE_FIELD`（默认 `msg`）、`SIMPASS_API_UID_FIELD`（默认 `user_info.simpass_uid`）、
  `SIMPASS_API_LEVEL_FIELD`（默认 `user_info.level`）、`SIMPASS_API_PLAYER_FIELD`（可选，简幻通侧绑定的玩家名，
  用于与用户填写的玩家名交叉校验）。
- **需要实现的接口文件**：`src/Contracts/SimpassVerifier.php`（契约）；
  HTTP 实现 `src/Verification/HttpSimpassVerifier.php`，对接点是 `buildRequest()` / `mapIdentity()`；
  未配置时绑定 `src/Verification/UnavailableSimpassVerifier.php`。
- **对接时通常无需改代码**：保留了旧站已跑通的调用形态（POST + query string，返回
  `{code:200, msg, user_info:{simpass_uid, level}}`），请求参数为
  `token` / `user_id` / `verify_code` / `mc_username` / `mc_uuid`（空）/ `ip`。
  新接口若是 JSON body，把 `postForm(...)` 换成 `postJson(...)` 一行即可。
- **交叉校验**：若配置了 `SIMPASS_API_PLAYER_FIELD` 且简幻通返回的绑定玩家名与用户填写的大小写不敏感不一致，
  直接 422 拒绝（`details.field = player_name`）。
- **未接入时的表现**：注册流程的简幻通校验（三处外部校验中的第二步）→ 501 `not_implemented`「简幻通验证接口尚未接入，请在 .env 中配置 SIMPASS_API_URL / SIMPPASS_ACCESS_TOKEN」。

> 管理员可在 `/passport/` 页面的「接口接入状态」卡片里一眼看到四个接口哪些已接入、哪些待接入
> （判定依据分别是 `emailVerifier()->isConfigured()` / `playerProvider()->isConfigured()` /
> `countryProvider()->isConfigured()` / `simpassVerifier()->isConfigured()`）。

---

## 七、本地开发与部署

### 7.1 准备配置

```bash
cp .env.example .env
# 必填：DB_HOST / DB_PORT / DB_NAME=bgjq8w / DB_USER=bgjq8w / DB_PASS
# 按需填：PASSPORT_BASE_URL、四个外部接口的地址与令牌
```

`.env` 已被 `.gitignore` 排除（`.env`、`.env.*`，仅保留 `!.env.example`）。`.env.example` 里只允许出现占位值。

### 7.2 初始化数据库（`bin/init-database.ps1`）

```powershell
# 推荐：交互式输入 MySQL 管理员密码
pwsh ./bin/init-database.ps1

# 只渲染不导入，人工检查生成的 SQL
pwsh ./bin/init-database.ps1 -DryRun

# 指定管理员账号 / 主机 / mysql 客户端路径
pwsh ./bin/init-database.ps1 -RootUser root -MysqlHost localhost -MysqlExe "C:\mysql\bin\mysql.exe"
```

脚本做的事（按顺序）：

1. 校验 `database/8w_passport.sql` 与 `.env` 都存在；
2. 解析 `.env`，要求 `DB_NAME` / `DB_USER` / `DB_PASS` 三项非空；
3. 安全闸门：`DB_NAME` 若仍是旧库名 `bgjq` 直接报错；`DB_PASS` 若还是 `__DB_PASSWORD__` 直接报错；
4. 渲染 SQL：把 `__DB_PASSWORD__`、`` `bgjq8w` ``、`'bgjq8w'@'localhost'` 替换为 `.env` 里的真实值；
5. 渲染结果写到**系统临时目录**（UTF-8 无 BOM），绝不落在仓库里；
6. 用 `MYSQL_PWD` 环境变量传递管理员密码（不出现在进程命令行），执行
   `mysql --host=… --user=… --default-character-set=utf8mb4 --execute="source <渲染文件>"`；
7. `finally` 里删除临时文件（`-DryRun` 时保留，脚本会提示手动删除）。

导入内容：创建 `bgjq8w` 库、创建/同步 `bgjq8w@localhost` 账号并授权、建 23 张表 + 1 个 `users` 视图、
插入 3 条默认公共服务、插入首个管理员通行证 `LouieMAIN`（密码见 SQL 文件注释，**首次登录后立即修改**）。

> 手工导入：自己把 `__DB_PASSWORD__` 替换成真实密码后 `mysql -u root -p < 渲染文件`，
> 并且**不要把替换后的文件提交到仓库**（`database/*.rendered.sql` 也已在 `.gitignore` 里）。

### 7.3 跑起来

- 站点根指向项目根目录，按 `nginx-8w.bgjq.top.conf` 配置 Nginx（PHP 8.4 FPM）。
- 通行证相关路由：

| 对外 URL | 实际执行 |
| --- | --- |
| `/passport/` | `passport/index.php`（由 `index` 指令落到目录索引） |
| `/passport/api/v1/<name>` | `/passport/api/v1/<name>.php` |
| `/passport/api/oauth/<name>` | `/passport/api/oauth/<name>.php` |
| `/oauth/authorize` | `/passport/api/oauth/authorize.php` |
| `/oauth/token` | `/passport/api/oauth/token.php` |
| `/oauth/userinfo` | `/passport/api/oauth/userinfo.php` |
| `/oauth/introspect` | `/passport/api/oauth/introspect.php` |
| `/oauth/revoke` | `/passport/api/oauth/revoke.php` |

- `location ^~ /passport/src/` 与 `location ^~ /passport/storage/` 都是 `deny all`；
  `/passport/assets/` 缓存 7 天。
- **部署注意**：`passport/storage/logs/` 需要 PHP-FPM 用户可写（`Logger` 会自动 `mkdir`，失败则退回 `error_log`）。
- 打开 `https://<域名>/passport/`：未登录显示登录/注册；已登录显示账号、我的邦国、已授权应用、改密；
  管理员（默认角色 `secretary_general`，可用 `PASSPORT_ADMIN_ROLES` 配置）额外显示第三方应用管理与接口接入状态。

### 7.4 提交前闸门

```powershell
pwsh ./bin/test.ps1
```

该脚本先对全量 `.php` 文件跑 `php -l`（跳过 `vendor` / `node_modules` / `storage`），
再运行 `passport/tests/smoke.php`，覆盖九个部分：`Arr` 点路径取值、`Scope` 授权范围、`Str` 随机与哈希、
`Config` 配置读取、`Directory` 权威数据值对象、`Http` 响应与异常格式、
未接入接口的失败语义（关键：绝不静默放行）、`database/8w_passport.sql` 结构自检、
`Application` 依赖装配（最容易「忘了启动」的地方）。
两项全绿才允许提交。

### 7.5 日常维护任务

过期数据不会自己消失，建议每天跑一次清理（幂等，重复执行无副作用）：

```bash
# 每天凌晨 3 点清理一次（crontab）
0 3 * * * /usr/bin/php /var/www/8w.bgjq.top/bin/maintenance.php >> /var/log/8w-passport-cron.log 2>&1

# 调整调用日志保留天数（默认 30 天）
php bin/maintenance.php --log-days=60
```

`Maintenance::run()` 依次清理五类数据并返回各项删除行数，同时记一条 `maintenance.done` 日志：

| 项目 | 清理范围 |
| --- | --- |
| `sessions` | 已过期的登录会话（`passport_sessions.expires_at < NOW()`） |
| `codes` | 过期授权码（保留 1 天便于排查） |
| `tokens` | 过期令牌（访问与刷新令牌都超过 30 天才删） |
| `email_codes` | 过期邮箱验证码（保留 1 天） |
| `api_logs` | 调用日志（默认保留 30 天；它同时用于限流计数，不能清得太激进） |

`bin/maintenance.php` 只允许 CLI 运行（非 CLI 直接返回 404）。

---

## 八、安全要点备忘

- 密码：`password_hash(…, PASSWORD_DEFAULT)`；登录时账号不存在也走一次 `password_verify`，避免响应时间枚举账号。
- 会话：Cookie 只放 32 字节随机令牌（`HttpOnly`、`SameSite=Lax`、HTTPS 下 `Secure`），库里只存 SHA-256；
  令牌无效/过期时顺手清掉浏览器里的 Cookie；改密后除当前会话外全部销毁。
- 令牌与授权码：库里只存 SHA-256，明文只在签发那一刻返回一次。
- 授权端点：`client_id` / `redirect_uri` 校验失败时**绝不重定向**（防开放重定向），只渲染错误页；
  回调地址必须精确命中登记的白名单。
- 授权码：一次性消费（`UPDATE … WHERE consumed_at IS NULL`），已消费的码被再次使用时会连带吊销该码签发的令牌。
- 日志：`Logger` 会把 `password` / `password_hash` / `client_secret` / `token` / `access_token` /
  `refresh_token` / `code` / `email_code` / `simpass_code` 等键自动打码成 `***`。
- 数据库：`Support\Database` 禁用模拟预处理（`PDO::ATTR_EMULATE_PREPARES => false`）。

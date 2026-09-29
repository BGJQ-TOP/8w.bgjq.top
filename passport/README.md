# 8W通行证系统（passport/）

> 面向本项目维护者。第三方接入文档见 [`docs/PASSPORT-API.md`](../docs/PASSPORT-API.md)，
> 上线与数据迁移见 [`docs/MIGRATION.md`](../docs/MIGRATION.md)。

---

## 一、这是什么

8W通行证是 8W社区的统一身份服务：**一次注册，全站通用**。

- 身份主体是 `passport_accounts` 表里的一行，其 `id` 就是对外唯一的通行证 UID，也是 OAuth 2.0 的 `sub`。
- 一个通行证上的绑定分两类：**必填且不可解绑**的游戏内玩家名与简幻通身份，**可选**的验证邮箱与 FanVerify 账号。
- 第三方应用不碰数据库，只能通过 OAuth 2.0 消费通行证；通行证自己也不直接读社区业务表。

### 为什么独立成子系统

| 原因 | 具体体现 |
| --- | --- |
| 身份是全局资产 | 主站、第三方应用、将来的其他子站都引用同一份 `passport_accounts.id`，不能再散落在 `users` 表里 |
| 第三方接入需要标准协议 | OAuth 2.0 授权码 + PKCE / 刷新令牌 / 客户端凭据，由 `src/OAuth/` 独立承载（RFC 6749 / 7636 / 7009 / 7662） |
| 权威数据来自游戏服务器 | 玩家名与邦国 ID 的权威方是第三方游戏服务，本地只做缓存，缓存策略必须集中一处（`src/Directory/`） |
| 外部接口还没全部到位 | 玩家、邦国、简幻通、邮箱四个接口都可能没接入，必须做到「未接入就明确报错，绝不静默放行」；其中邮箱是**可选绑定**，未接入不会挡住注册。FanVerify 已真实接入（见 7.5），未配令牌时同样明确报 501 |
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
├── index.php                     通行证用户中心（未登录为登录/注册标签页；登录后为左侧导航 + 右侧内容分区的仪表盘：
│                                 概览 / 绑定管理 / 已授权应用 / 账号安全，管理员另有第三方应用与接口状态）
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
│   │   ├── authorized-apps.php   GET/DELETE  列出/撤销第三方授权
│   │   ├── bindings.php          GET/POST/DELETE  绑定管理（列出 / 绑定 / 解绑可选绑定，详见第五节）
│   │   ├── fanverify-otp.php     POST/GET  FanVerify 扫码绑定：申请 OTP / 轮询是否已被确认
│   │   └── fanverify-qr.php      GET       FanVerify OTP 二维码（服务端代理 PNG，令牌不进前端 URL）
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
│   ├── Contracts/                五个外部接口契约（玩家 / 邦国 / 邮箱 / 简幻通 / FanVerify；换实现只需实现它们）
│   │   ├── PlayerProvider.php    游戏内玩家数据源
│   │   ├── CountryProvider.php   邦国数据源
│   │   ├── EmailVerifier.php     邮箱验证码发送方（可选绑定）
│   │   ├── SimpassVerifier.php   简幻通验证方
│   │   └── FanVerifyVerifier.php FanVerify 验证方（可选绑定）
│   ├── Directory/                权威数据 + 本地缓存
│   │   ├── PlayerDirectory.php   玩家目录（读缓存/回源/降级）
│   │   ├── CountryDirectory.php  邦国目录（同步只覆盖权威字段）
│   │   ├── PlayerProfile.php     玩家值对象（player_name / player_id / country_id）
│   │   ├── CountryProfile.php    邦国值对象（含玩家列表，population 由列表长度派生）
│   │   └── Providers/            HTTP 实现与「未接入」实现
│   ├── Verification/             验证码与外部身份验证
│   │   ├── EmailCodeService.php  验证码生成/限流/落库/一次性消费（scene：register / bind / reset）
│   │   ├── HttpEmailVerifier.php 邮件发送 HTTP 实现（配置驱动）
│   │   ├── HttpSimpassVerifier.php 简幻通校验 HTTP 实现（配置驱动）
│   │   ├── SimpassIdentity.php   简幻通校验结果值对象
│   │   ├── FanVerifyClient.php   FanVerify openAPI 客户端（devinfo / otp / genqrcode / seeotp /
│   │   │                         user_verify / getuserdata / tag 共 7 个接口）
│   │   ├── HttpFanVerifyVerifier.php FanVerify 验证实现（委托给 FanVerifyClient）
│   │   ├── FanVerifyIdentity.php FanVerify 校验结果值对象（uid / level / tag / regTime）
│   │   └── Unavailable*.php      未接入时的 Null Object（一律抛 not_implemented）
│   ├── Identity/                 身份域
│   │   ├── Account.php           账号只读视图（toPublicArray / toProfileArray；email() 未绑定时返回 null）
│   │   ├── AccountRepository.php 对 passport_accounts 的唯一写入入口
│   │   ├── Authenticator.php     登录 / 登出 / 改密 / 当前用户
│   │   ├── SessionStore.php      登录态（Cookie 只放随机令牌，库里只存 SHA-256）
│   │   ├── RegistrationService.php 注册流程编排
│   │   └── BindingService.php    绑定管理（可选绑定的绑定 / 解绑，都要验当前密码）
│   └── OAuth/                    OAuth 2.0 服务端
│       ├── OAuthServer.php       授权、令牌、内省、吊销、限流
│       ├── Scope.php             scope 定义（MAP / MACHINE_SCOPES / DEFAULT_SCOPE）
│       ├── ClientRepository.php  第三方应用仓库
│       ├── TokenRepository.php   令牌仓库（签发/轮换/吊销）
│       └── AuthorizationCodeRepository.php 授权码仓库（一次性消费）
├── tests/smoke.php               最小验证脚本（不依赖数据库与网络）
├── storage/logs/                 运行期日志（.gitignore 排除，Nginx 已 deny）
└── （项目根的 bin/ 下有 init-database.ps1、test.ps1、maintenance.php、fanverify-check.php 四个脚本）
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
   （账号、会话、注册、绑定）      （授权、令牌）            （权威数据、验证码、外部身份）
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
| `Identity` | `Directory`、`Verification`、`Support`、`Http` | 注册流程与绑定流程依赖目录与验证服务 |
| `OAuth` | `Identity\Account(Repository)`、`Support`、`Http` | 令牌只认 `account_id`，不反向依赖目录 |
| `Application` | 全部 | 唯一的装配点：`resolve*` / `shared()` / `bind()` |
| `api/*.php` | `Application`、`Http` | 端点里只写参数校验与响应组装，不写业务逻辑 |

**换实现只改一处**：`Application::playerProvider()` / `countryProvider()` / `emailVerifier()` / `simpassVerifier()` / `fanVerifyVerifier()` 会根据 `.env` 是否配置对应键，自动在 HTTP 实现与 `Unavailable*` 之间选择；测试或定制部署可用 `Application::bind('player_provider', $obj)` 运行期覆盖（五个数据源的键名分别是 `player_provider` / `country_provider` / `email_verifier` / `simpass_verifier` / `fanverify_verifier`）。

**装配是自动的**：`Application::boot()` 显式装配（幂等），而 `Application::instance()` 在尚未装配时会**自动装配**（从项目根的 `.env` 读配置）。刻意不做成「必须记得先 boot」——少一个前置步骤，就少一整类「忘了启动」的线上故障。`Application::reset()` 仅供测试重置单例。

---

## 四、注册流程的完整链路

入口：`POST /passport/api/v1/register`（兼容入口 `POST /api/v1/auth.php?action=register`），
实现：`src/Identity/RegistrationService.php::register()`。

### 4.1 实际校验顺序

**必填**：游戏内玩家名（权威接口校验）、简幻通ID + 简幻通验证码。
**可选**：验证邮箱 + 邮箱验证码、FanVerify 账号ID + 验证码 —— 用户不填就跳过，账号上对应字段落 `NULL`。

| 步骤 | 内容 | 失败结果 |
| --- | --- | --- |
| ① | **字段格式**：`username` 3–32 位仅 `[A-Za-z0-9_-]`；`player_name` 非空、≤32、不含 `<>` 与控制字符；`password` 8–72 位且不能纯字母/纯数字；`simpass_uid > 0`；`simpass_code` 非空。**邮箱只在填了的时候才校验格式**（≤191 且通过 `FILTER_VALIDATE_EMAIL`） | 422 `invalid_request`，`details.field` 指向出错字段 |
| ② | **唯一性预检**（顺序：`username` → `email`（填了才查）→ `player_name` → `simpass_uid` → `fanverify_uid`（填了才查）） | 409 `conflict`，`details.field`；真正的唯一性由唯一索引兜底 |
| ③ | **游戏内玩家名权威校验（必填）**：`PlayerDirectory::find($playerName, true)` 强制回源 | 玩家不存在 → 422「游戏内不存在名为「X」的玩家」；权威返回名与输入大小写不敏感不一致 → 422「玩家名应为「Y」，请核对后重试」 |
| ④ | **简幻通校验（必填）**：`SimpassVerifier::verify($simpassUid, $simpassCode, $playerName)` | 业务码不为成功码 → 422「简幻通验证失败：…」（`details.field = simpass_code`）；接口未接入 → 501 |
| ⑤ | **邮箱验证码校验（可选）**：没填邮箱就整段跳过；填了邮箱则 `EmailCodeService::assertVerify($email, 'register', $emailCode)`，成功即消费 | 填了邮箱却没填验证码 → 422「填写了邮箱就必须填写邮箱验证码」（`details.field = email_code`）；验证码错误/过期 → 422；发送接口未接入 → 501 |
| ⑥ | **FanVerify 校验（可选，已接入）**：账号ID与验证码都没填就整段跳过；只填了其中一个 → 422；都填了才 `FanVerifyVerifier::verify($fanverifyUid, $fanverifyCode, $playerName)`（即 `GET /openapi/user_verify`） | 只填一半 → 422「请填写正确的 FanVerify 账号ID」/「请填写 FanVerify 动态验证码」；校验失败 → 422（`details.field = fanverify_code`）；**没配 `FANVERIFY_ACCESS_TOKEN`** → 501 |
| ⑦ | **落库**（`Database::transaction`）：写入 `username` / `password_hash`（`password_hash(…, PASSWORD_DEFAULT)`）/ `email` / `email_verified_at` / `simpass_uid` / `simpass_level` / `simpass_verified_at` / `fanverify_uid` / `fanverify_level` / `fanverify_tag` / `fanverify_verified_at` / `player_name` / `player_id` / `country_id` / `player_synced_at` / `role='observer'` / `status=1`。**可选绑定未绑定时落 `NULL`，绝不写空串** | 写库失败 → 500 `server_error` |
| ⑧ | **邦国缓存预热**：`$player->hasCountry()` 时 `CountryDirectory::find($countryId, true)`；回源失败则 `CountryDirectory::touch($countryId, null)` 落一行占位（名称「邦国#ID」） | **尽力而为**：预热失败只记日志（`passport.country_warmup_skipped` / `passport.country_touch_failed`），不影响注册结果 |
| ⑨ | **注册即登录**：`SessionStore::create()` 下发 HttpOnly Cookie，`AccountRepository::touchLogin()` 记录登录时间与 IP | — |
| ⑩ | 记 `passport.registered` 日志，重新读取账号并返回 `data.account` | 读回失败 → 500「注册成功但读取账号失败，请尝试登录」 |

> 代码里的行内注释把 ①~⑦ 依次标在「字段格式 / 唯一性 / 玩家名 / 简幻通 / 邮箱 / FanVerify / 落库」上，
> 随后邦国缓存那一段又标了一次 ⑦（笔误，实际是落库之后的收尾步骤）。
> 上表的 ⑧~⑩ 就对应落库之后的三个收尾步骤。

### 4.2 可选绑定的落库语义

这是本轮最容易被写错的一处，务必按代码理解：

| 用户输入 | `email` | `email_verified_at` | `fanverify_uid` | `fanverify_level` | `fanverify_tag` | `fanverify_verified_at` |
| --- | --- | --- | --- | --- | --- | --- |
| 什么都没填 | `NULL` | `NULL` | `NULL` | `NULL` | `NULL` | `NULL` |
| 只填了邮箱 + 验证码 | 邮箱 | 注册时刻 | `NULL` | `NULL` | `NULL` | `NULL` |
| 只填了 FanVerify | `NULL` | `NULL` | 账号ID | FanVerify 返回的等级 | 风险标签（无标签则 `NULL`） | 注册时刻 |
| 两样都填 | 邮箱 | 注册时刻 | 账号ID | FanVerify 返回的等级 | 风险标签（无标签则 `NULL`） | 注册时刻 |

- **落 `NULL` 而不是空串**：`uk_email` / `uk_fanverify_uid` 是唯一索引，MySQL/MariaDB 的唯一索引允许
  多个 `NULL`，但空串会互相冲突——第二个不填邮箱的账号就注册不了了。
- **填了邮箱则验证码必填**：否则等于绑了一个"未经证明属于自己"的邮箱，找回流程会被它带偏。
- **`fanverify_level` / `fanverify_tag` 是 FanVerify 侧返回的权威缓存**：`level` 在接口里是**字符串**（如 `"3"`），
  代码里统一转成 `int`；`tag` 为空串即"没有标签"，`FanVerifyIdentity::tag()` 与 `Account::fanverifyTag()`
  都会把它归一成 `null`，因此落库也是 `NULL` 而不是空串。
- `Account::email()` 的返回类型是 `?string`，未绑定时为 `null`；配套的 `Account::hasEmail()` 用于判断"有没有绑"。
- `AccountRepository::findByEmail('')` 直接返回 `null`（不去查 `WHERE email = ''`）；
  `findByLogin()` 的 SQL 是 `username = ? OR (email IS NOT NULL AND email = ?)`，未绑定的账号只按用户名匹配。

### 4.3 为什么是这个顺序

代码注释给出了明确理由，请勿随手调换：

> 校验顺序刻意从"最可能失败、最贵"到"最便宜、一次性"：
> 玩家 → 简幻通 → 邮箱 → FanVerify。
> 邮箱验证码放后面，是为了不在前两项失败时白白烧掉一个验证码。

- 玩家名校验要打第三方接口，最贵、也最容易因为用户手抖写错而失败 → 排第一。
- 简幻通校验要打第三方接口，且需要用户去小程序取验证码 → 排第二。
- 邮箱验证码是一次性资源（校验成功即消费、同邮箱 60 秒内不能重发、每小时最多 5 次）→ 排在两个必填项之后。
- FanVerify 同样要打第三方接口、同样需要用户去外部取验证码 → 排在最后。
- 唯一性预检放在所有外部调用之前，纯本地查询，最便宜。

> ⚠ 注意：部分设计描述里把顺序写成「邮箱验证码 → 玩家名 → 简幻通」，**与实现不符**。
> 以本文件与 `RegistrationService::register()` 的代码为准。

### 4.4 本地联调开关

`verificationEnabled()` 为 `false` 的条件是 **`PASSPORT_DEBUG=1` 且 `PASSPORT_DEV_BYPASS_VERIFICATION=1` 同时成立**，
此时跳过 ④（简幻通）、⑤（邮箱验证码）与 ⑥（FanVerify），并每次记 `passport.verification_bypassed` 的
**WARNING** 日志（日志里带 `step` 字段，取值为 `simpass` / `email` / `fanverify`）。
步骤 ③（玩家名权威校验）**永不跳过**。生产环境两个开关都必须保持 `0`。
跳过的 FanVerify 只会落 `fanverify_uid`，`fanverify_level` / `fanverify_tag` 都是 `NULL`
（`new FanVerifyIdentity($fanverifyUid, null, null)`，本地联调时不会去 FanVerify 取等级）。

---

## 五、绑定管理

通行证上的绑定分两类，这条边界决定了「哪些能改、哪些不能改」：

| 类别 | 绑定 | 可解绑（`bindable`） | 说明 |
| --- | --- | --- | --- |
| 必填 | 游戏内玩家名 | ✗ | 权威身份主键，注册时经权威接口实时校验 |
| 必填 | 简幻通ID | ✗ | 注册时校验通过，同时是默认的账号找回通道 |
| 可选 | 验证邮箱 | ✓ | 用户自己决定绑不绑，随时可绑可解 |
| 可选 | FanVerify 账号 | ✓ | 同上；有**扫码**与**手填**两条绑定路径（见 5.3）；未配令牌时 `available` 为 `false`，前端禁用绑定按钮（见 7.5） |

服务实现 `src/Identity/BindingService.php`（`Application::bindings()` 可取），
HTTP 端点 `passport/api/v1/bindings.php`。必填绑定不在这里管理，也无法解绑。

### 5.1 安全约定：绑定与解绑都要验当前密码

**绑定与解绑都要求提供当前密码**（字段名 `password`），缺失或错误一律 422「当前密码不正确」。

原因：绑定会改变账号的**找回途径**。若只凭登录态就能绑邮箱，一个被盗用的会话（或 XSS 偷到的 Cookie）
就能把攻击者的邮箱挂到受害者账号上，再走"邮箱找回"流程彻底夺走账号；解绑同理，
攻击者也能把受害者的真实邮箱摘掉。要求当前密码，等于强制"这是本人操作"。

校验集中在 `BindingService::assertPassword()` 一处，四种操作（绑/解绑 × 邮箱/FanVerify）共用。

### 5.2 `bindings` 端点的三种用法

`passport/api/v1/bindings.php`，鉴权是**通行证会话 Cookie**（`Authenticator::requireCurrent()`，
不是第三方接口，也不接受 Bearer 令牌）：

| 方法 | 用途 | 关键参数 |
| --- | --- | --- |
| `GET /passport/api/v1/bindings` | 列出全部绑定状态 | 无 |
| `POST /passport/api/v1/bindings` | 绑定 | `type=email`：`email` + `code` + `password`；`type=fanverify` **手填**：`uid` + `code` + `password`；`type=fanverify` **扫码**：`otp` + `password`（见 5.3） |
| `DELETE /passport/api/v1/bindings` | 解绑 | `type`（query 或 body）+ `password`（**仅 body**） |

- `bindings` 的每一项结构是 `{label, bound, required, bindable, value, detail}`；
  两个可选绑定（`email` / `fanverify`）额外带一个 `available` 字段，表示**该绑定对应的外部接口是否已接入**
  （判定依据分别是 `EmailCodeService::isDeliverable()` 与 `FanVerifyVerifier::isConfigured()`）。
  前端据此直接禁用按钮并说明原因，而不是让用户白点一次。
- `fanverify` 这一项还额外带 `tag` 字段（`Account::fanverifyTag()`，无标签为 `null`）。
  风险标签是平台侧对该 FanVerify 账号的**公开标记**，前端会在绑定项名称旁用醒目的角标展示
  （`passport/index.php` 的 `bindingRow()` 里判断 `item.tag`）；等级则拼进 `detail`，形如 `等级 3 · 风险标签：疑似小号`，
  两者都为空时 `detail` 回落到「已验证」。
- `POST` / `DELETE` 成功后返回最新的 `bindings` 与 `account`（`POST` 多一个 `bound`、`DELETE` 多一个 `unbound`），
  前端不用再补一次 `GET`。
- `GET /passport/api/v1/me` 也会带上同一份 `bindings`。
- `type` 只支持 `email` / `fanverify`，其它值 → 422「不支持的绑定类型，仅支持 email 或 fanverify」。

> ⚠ `password` 一律走**请求体**，不接受查询串 —— 放进 URL 会被 Web 服务器访问日志、
> 浏览器历史与 `Referer` 记录下来。`type` 无敏感性，允许放查询串。

字段级细节（完整返回结构、全部错误码）见 [`docs/PASSPORT-API.md`](../docs/PASSPORT-API.md) 第 10.3 节，
FanVerify 扫码流程的两个端点见同文件第 10.4 节。

### 5.3 FanVerify 的两条绑定路径

FanVerify（fanverify.cn）是**可选绑定**，已真实接入它的 openAPI（细节见 7.5）。
绑定有两条路，最终都落到 `BindingService::attachFanVerify()`：

| 路径 | 入口 | 用户要做什么 | 服务实现 |
| --- | --- | --- | --- |
| **扫码**（面板里的「扫码绑定」标签页） | `POST /passport/api/v1/fanverify-otp` → `GET /passport/api/v1/fanverify-qr` → `POST /passport/api/v1/bindings {otp, password}` | 填当前密码，用 FanVerify 微信小程序扫码并确认 | `bindFanVerifyByOtp()`（**自动落库，不需要点「确认绑定」**） |
| **手填**（面板里的「手填绑定」标签页） | `POST /passport/api/v1/bindings {uid, code, password}` | 在小程序里取动态验证码，连同 FanVerify 账号ID一起填 | `bindFanVerify()`（走 `FanVerifyVerifier::verify()`，即 `GET /openapi/user_verify`） |

> 前端两条路径**共用同一个密码框**；「确认绑定」按钮只在手填标签页可见（扫码走自动绑定）。

扫码绑定的完整流程（前端在 `passport/index.php` 的 `wireFanVerifyForm()` / `startFanVerifyPolling()` 里实现）：

1. 用户在通行证中心点「FanVerify → 绑定 → 扫码绑定」，填写当前密码；
2. 前端 `POST /passport/api/v1/fanverify-otp`，body 是 `{password}`；
   服务端**先校验一次密码**（避免用户扫完码才发现密码错），再调 FanVerify 申请 OTP，
   返回 `{otp, qr_url, expires_in, poll_interval}`；
3. 前端把 `qr_url` 当成 `<img src>` 展示（`/passport/api/v1/fanverify-qr?otp=…`，**走服务端代理**，
   令牌不进浏览器 URL，详见 7.5）；
4. 用户用 FanVerify 微信小程序扫码并确认；
5. 前端每 3 秒 `GET /passport/api/v1/fanverify-otp?otp=…` 轮询，直到 `status` 为 `ok`
   （`wait` 继续等，`rate_limit` 跳过本轮继续等，超过 `expires_in` 视为二维码过期）；
6. 前端 `POST /passport/api/v1/bindings {type:"fanverify", otp, password}` ——
   **服务端会再轮询一次 OTP**（`BindingService::bindFanVerifyByOtp()`），
   不轻信前端"已确认"的说法；OTP 未通过或已超时一律 422「扫码尚未确认或已超时，请重新扫码」。

两条路径共同的三条规则：

- **必须验当前密码**（`assertPassword()`），与邮箱绑定一致，理由见 5.1；
- **等级门槛**：落库前检查 `FANVERIFY_REQUIRED_LEVEL`（默认 `0` = 不限）。要求大于 0 且
  FanVerify 返回的等级低于门槛（或没返回等级）时，直接 422
  「该 FanVerify 账号等级不足（当前 X，要求 N）」，`details.field = fanverify_uid`，**不写库**；
- **风险标签**：`tag` 与 `level` 一起落库并对外展示（见 5.2），标签为空串即视为无标签。

> 扫码流程里"这个人是谁"由 FanVerify 侧确认（用户在小程序里点了同意），我们只负责把结果落库，
> 因此**不做交叉校验**；手填路径同样只校验 UID + 动态验证码 —— FanVerify 不返回"绑定的游戏名"，
> 传进去的 `playerName` 仅用于日志（需要交叉校验游戏名的只有简幻通）。

### 5.4 邮箱验证码的场景（scene）

登录后补绑邮箱用的是 scene `bind`，与注册时的 `register` 分开计数、互不干扰。
`EmailCodeService::normalizeScene()` 的白名单是 `register` / `bind` / `reset`，
旧写法 `rebind` 会自动映射为 `bind`；白名单以外的值一律回落为 `register`。

---

## 六、权威数据缓存策略

### 6.1 权威主键与缓存字段

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

### 6.2 TTL

- 统一由 `DIRECTORY_CACHE_TTL` 控制，默认 **600 秒**（`PlayerDirectory::rowIsStale()` / `CountryDirectory::rowIsStale()`）。
- `DIRECTORY_CACHE_TTL <= 0` 时 `rowIsStale()` 恒返回 `false`，即缓存**永不过期**（只靠显式 `fresh=1` 回源）。
- 判定依据是行上的 `synced_at`：`synced_at` 无法解析或 `now - synced_at > ttl` 即视为过期。

### 6.3 读取与降级行为

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

### 6.4 各接口的取数口径

| 接口 | 口径 |
| --- | --- |
| 注册 | `players->find($name, true)` 强制回源；邦国用 `countries->find($id, true)` 预热 |
| `GET /passport/api/v1/me` | 全部走 `findCached()`，**不回源**，避免每次打开个人页都打第三方 |
| `GET /passport/api/v1/player` | `fresh` 参数默认 `false`；返回 `source` 字段（`cache` / `authoritative`） |
| `GET /passport/api/v1/country` | `fresh` 参数默认 `false` |
| 通行证用户中心「强制同步」按钮 | 前端显式传 `fresh=1` |

### 6.5 邦国同步的覆盖范围

`CountryDirectory::sync()` 在一个事务里做两件事：

1. `countries` 表 upsert，只覆盖 `name` / `declaration` / `territory_chunks` / `population` / `synced_at`，
   **绝不动** `government_type` / `flag_url` / `is_active`。
2. 仅当 `CountryProfile::rosterProvided()` 为真时才调用 `PlayerDirectory::replaceRoster()`：
   先把不在名册里的玩家 `country_id` 置 `NULL`，再逐个 upsert。
   「返回了空列表」与「本次没返回列表」是两回事——后者不该清空本地缓存。

---

## 七、TODO 清单：四个待接入的外部接口

> **FanVerify 已从本清单移出**：它已真实接入 fanverify.cn openAPI（见 7.5），
> 不再属于"待接入"。剩下的四个接口是：邮箱验证码、游戏内玩家、邦国信息、简幻通。

这四个接口都遵循同一套设计：

1. 契约在 `src/Contracts/`，HTTP 实现已经写好且**由 `.env` 配置驱动**，未配置时由 `Unavailable*` 接管；
2. 对接时**通常不需要改代码，只要填 `.env`**；只有返回结构无法用点路径表达时才改一个 `map*()` / `build*()` 方法；
3. **未接入时明确报 `not_implemented`（HTTP 501），绝不静默放行**。这是硬约束：
   宁可注册失败并说清原因，也不允许出现未经权威校验的账号。

但**必填绑定与可选绑定的影响面不同**，这决定了"接口没接好能不能先上线"：

| 接口 | 绑定性质 | 未接入时的影响 |
| --- | --- | --- |
| 游戏内玩家（7.2） | 注册必填 | **注册直接失败**（501），且玩家查询接口不可用 |
| 简幻通（7.4） | 注册必填 | **注册直接失败**（501） |
| 邦国信息（7.3） | 查询用 | 邦国查询 501；注册时的邦国预热失败**不影响注册** |
| 邮箱验证码（7.1） | **可选绑定** | 只挡「绑定验证邮箱」这一步；用户不勾选即可正常注册 |
| ~~FanVerify（7.5）~~ | **可选绑定，已接入** | 代码已完整接入；只有**没配 `FANVERIFY_ACCESS_TOKEN`** 时才会 501，不影响注册与登录 |

### 7.1 邮箱验证码（可选绑定）

- **定位：可选绑定**（与 7.5 的 FanVerify 同级）。未接入**不影响注册与登录**——
  用户不勾选「绑定验证邮箱」就能正常注册；只有主动发起邮箱绑定/验证时才会看到 501。
- **对应 `.env` 变量**（见 `.env.example` 与 `Support/Config.php` 默认值表）：
  `EMAIL_API_URL`（本接口的开关，为空即视为未接入）、`EMAIL_API_TOKEN`、`EMAIL_API_TIMEOUT`（默认 8）、
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
  场景（scene）白名单为 `register`（注册时绑定）/ `bind`（登录后补绑）/ `reset`（找回密码，接口接入后启用），
  旧写法 `rebind` 映射为 `bind`。
- **未接入时的表现**：
  - `POST /passport/api/v1/email-code` → 501 `not_implemented`「邮箱验证码发送接口尚未接入（TODO）。请在 .env 中配置 EMAIL_API_URL 后重试。」
  - 注册流程中**只有用户填了邮箱**时才会走到邮箱验证码校验 → 501 `not_implemented`「邮箱验证码发送接口尚未接入，无法完成邮箱验证。…」；**不填邮箱则完全不受影响**。
  - 绑定邮箱（`POST /passport/api/v1/bindings`，`type=email`）→ 501；此时 `GET …/bindings` 里 `email.available` 为 `false`。

### 7.2 游戏内玩家（注册必填）

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
  - 注册流程的玩家名校验（必填校验的第一步）→ 501 `not_implemented`「玩家信息接口尚未接入，无法校验游戏内玩家名。请在 .env 中配置 PLAYER_API_BASE / PLAYER_API_PATH」
  - `GET /passport/api/v1/player`（且本地无该玩家缓存）→ 501

### 7.3 邦国信息

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
  - 注册时的邦国预热失败**不会**让注册失败（见 4.1 第 ⑧ 步）。

### 7.4 简幻通（注册必填）

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
- **未接入时的表现**：注册流程的简幻通校验（必填校验的第二步）→ 501 `not_implemented`「简幻通验证接口尚未接入，请在 .env 中配置 SIMPASS_API_URL / SIMPPASS_ACCESS_TOKEN」。

### 7.5 FanVerify（可选绑定）—— 已接入

- **定位：可选绑定**（与 7.1 的邮箱验证码同级），**且已经真实接入**，不再是 TODO。
  代码已完整覆盖 fanverify.cn openAPI 的全部 7 个接口；只有**没配 `FANVERIFY_ACCESS_TOKEN`** 时
  才会退回 `UnavailableFanVerifyVerifier` 并 501 —— 且**不影响注册与登录**。
- **接口根地址**：`https://api.fanverify.cn`（`FANVERIFY_API_BASE` 的默认值）。
  官方文档 <https://doc.fanverify.cn/llms.txt> 的 OpenAPI 里 `servers` 是空的，这个地址是**实测**得出的：
  `/openapi/*` 会返回文档中描述的 `401 {"error":"Unauthorized"}`，根路径返回 Go 风格的 `404 page not found`。
  该域名走腾讯 EdgeOne CDN。
- **鉴权统一用 `accesstoken`**：GET 类接口放 query string，POST 类接口放 JSON body。
- **已覆盖的 7 个接口**（全部挂在 `/openapi/` 下，实现见 `src/Verification/FanVerifyClient.php`）：

  | 路径 | 方法 | 用途 | 关键参数 |
  | --- | --- | --- | --- |
  | `/devinfo` | GET | 开发者令牌信息（可当连通性自检） | `accesstoken` |
  | `/otp` | GET | 申请 OTP（扫码流程第一步） | `accesstoken` |
  | `/genqrcode` | GET | OTP 二维码，返回 `image/png` | `accesstoken`、`otp` |
  | `/seeotp` | GET | 轮询 OTP 是否通过（同一 OTP 5 秒内重复查询返回 `rate_limit`） | `accesstoken`、`otp` |
  | `/user_verify` | GET | UID + 动态验证码验证（手填路径） | `accesstoken`、`uid`、`pass_code` |
  | `/getuserdata` | POST | 获取已被本开发者验证过的用户数据（403 = 该用户没被本开发者验证过） | body: `accesstoken`、`uid` |
  | `/tag` | POST | 打风险标签（扣额度、不可自助取消；代码注释写明"打一次标签需要有效认证超过 1000 次用户，并一次性扣除 1000 额度"，标签对所有开发者与用户可见。**本客户端只提供能力，绝不自动调用** —— 打不打由人决定） | body: `accesstoken`、`tuid`、`tag`、`message` |

- **统一的用户信息结构**（`user_verify` / `seeotp` / `getuserdata` 都返回它）：

  ```json
  { "status": "ok", "data": [ { "uid": 100002, "level": "3", "reg_time": "2026-07-14T08:22:38+08:00", "tag": "" } ] }
  ```

  `level` 在接口里是**字符串**（代码转成 `int`）；`tag` 空串表示没有标签（归一成 `null`）。
  `seeotp` 的非成功状态是 `{"status":"wait"}` 与 `{"status":"rate_limit"}`；
  `otp` 返回 `{"success":true,"data":{"otp":"…"}}`；`devinfo` 返回
  `{Date_of_Issue, bind_uid, mode, need_end_level, service_message, status}`；401 恒为 `{"error":"Unauthorized"}`。

- **实测结论（与官方文档不一致的地方，代码以实测为准）**：

  | 事项 | 官方文档 | 实测 | 代码怎么处理 |
  | --- | --- | --- | --- |
  | 接口根地址 | `servers: []`（空） | `https://api.fanverify.cn` | 作为 `FANVERIFY_API_BASE` 默认值 |
  | POST 类接口的 `accesstoken` | 放在 JSON body 里 | **放 body 会 401，必须放 query string** | `userData()` / `applyTag()` 都走 `url()`，令牌统一在 query |
  | `seeotp` 限流 | 「5 秒内重复查询返回 429」 | **HTTP 200 + `{"status":"rate_limit"}`** | 以 `status` 字段为准，HTTP 429 只作兜底 |
  | `user_verify` 验证码错误 | 未写 | **403 `{"error":"Forbidden"}`** | 翻成 422「动态验证码不正确」，**不**说成"权限不足" |  | `user_verify` 账号不存在 | 未写 | **404 `{"error":"Not Found"}`** | 翻成 422「账号不存在」，**不**说成"接口路径配错" |
  | `user_verify` 参数格式错 | 未写 | **400 `{"error":"Bad Request"}`** | 翻成 422「账号ID需为数字、验证码不能为空」 |
  | `getuserdata` | 403 = 未被本开发者验证过 | **任何输入都返回 `{"code":400}`**（uid 存在与否、令牌在 query 或 body、JSON 或表单都一样） | 当前**不可用**，所以 400/403 都当作"拿不到数据"返回 `null`，不影响调用方；等上游修好再收紧 |
  | `tag` | 扣 1000 额度、不可自助取消 | **未实测**（不能拿生产令牌做实验） | 只提供能力，代码里绝不自动调用 |

  这些是踩过坑才写下来的：`user_verify` 的 404 **不能**复用通用错误翻译，
  否则用户填错账号ID会看到"接口路径不存在，可能根地址配错了"这种牛头不对马嘴的提示。
  实现见 `FanVerifyClient::userVerifyError()`。

  > 关键路径（扫码绑定、手填绑定）都已实测跑通：`otp` → `genqrcode`（真实 PNG）→ `seeotp`（`wait`）→
  > 5 秒内重复轮询拿到 `rate_limit`。`getuserdata` 与 `tag` 不在绑定流程上，前者上游有问题、后者故意不测。
- **令牌的两道外部约束**（都排查过，记下来省得再踩）：
  - **来源 IP 白名单，且只允许绑定一个 IP**。服务器出口 IP 不在白名单内时，**所有**接口都返回
    `401 {"error":"Unauthorized"}` —— 看起来像"令牌无效"，实际是 IP 没放行。
    换服务器、加负载均衡、走 CDN 出站都会导致这个现象，**部署前先确认出口 IP 与白名单一致**。
  - **等级门槛 `need_end_level`**：`devinfo` 返回该令牌要求的最低 FanVerify 等级。
    已设为 `0`，即低等级账号也能验证。⚠ 如果日后调高它，"账号等级不足"会与"验证码错误"
    返回**同一个 403**、无法区分，`FanVerifyClient::userVerifyError()` 里的 403 文案必须相应放宽。
    管理员可在「接口状态 → 自检」里看到当前值。
- **对应 `.env` 变量**（见 `.env.example` 与 `Support/Config.php` 默认值表）：

  | 变量 | 默认值 | 说明 |
  | --- | --- | --- |
  | `FANVERIFY_API_BASE` | `https://api.fanverify.cn` | 接口根地址 |
  | `FANVERIFY_ACCESS_TOKEN` | 空 | **本接口的开关**：为空即视为未接入，退回 `UnavailableFanVerifyVerifier` |
  | `FANVERIFY_API_TIMEOUT` | `10` | 出站超时（秒），代码里下限是 3 |
  | `FANVERIFY_REQUIRED_LEVEL` | `0` | 本站额外要求的等级门槛，`0` = 不限（见 5.3） |
  | `FANVERIFY_OTP_TTL` | `180` | 扫码流程 OTP 的有效期（秒），代码里下限是 30 |

  > 旧的"配置驱动"通用变量（`FANVERIFY_API_` 前缀下的 URL、TOKEN、SUCCESS_CODE 与各 `*_FIELD`）
  > **已全部删除**，`.env` 里留着也不再生效；现在只认上表这五个变量
  > （`FANVERIFY_API_BASE` / `FANVERIFY_ACCESS_TOKEN` / `FANVERIFY_API_TIMEOUT` /
  > `FANVERIFY_REQUIRED_LEVEL` / `FANVERIFY_OTP_TTL`）。
- **相关文件**：
  - `src/Verification/FanVerifyClient.php` —— 覆盖上面 7 个接口的完整客户端（URL 拼装、状态映射、错误翻译）；
  - `src/Contracts/FanVerifyVerifier.php` —— 契约，除 `verify()` 外还声明了
    `requestOtp()` / `qrCodePng($otp)` / `pollOtp($otp)`；
  - `src/Verification/HttpFanVerifyVerifier.php` —— 实现，全部委托给客户端；
    `verify()` 走 `user_verify`，另外暴露 `developerInfo()` / `requiredLevel()` 供自检使用；
  - `src/Verification/FanVerifyIdentity.php` —— 值对象 `(uid, level, tag, regTime)`，
    方法 `uid()` / `level()` / `tag()` / `hasTag()` / `regTime()`；
  - `src/Verification/UnavailableFanVerifyVerifier.php` —— 令牌未配置时的 Null Object，**全部方法**抛 501；
  - `src/Identity/BindingService.php` —— `bindFanVerify()`（手填）/ `bindFanVerifyByOtp()`（扫码）/
    `bindFanVerifyWithIdentity()`（身份已拿到时直接落库，**目前没有任何端点调用它**，
    是为"服务端别处已确认身份"的场景预留的入口），
    落库 `fanverify_uid` / `fanverify_level` / `fanverify_tag` / `fanverify_verified_at`；
  - `passport/api/v1/fanverify-otp.php`、`passport/api/v1/fanverify-qr.php` —— 扫码流程的两个端点（见 5.3）。
- **为什么要代理二维码**：`/openapi/genqrcode` 要求把 `accesstoken` 放在 **query string** 上，
  让浏览器直接请求会把令牌暴露在前端 URL、浏览器历史与 `Referer` 里。
  因此二维码由服务端带令牌取回 PNG 再原样转发（`passport/api/v1/fanverify-qr.php`），
  浏览器只看到我们自己的 `/passport/api/v1/fanverify-qr?otp=…`。
- **接入自检**：`php bin/fanverify-check.php`（CLI，非 CLI 直接 404）。它会检查
  cURL 扩展 → `.env` 配置 → `GET /openapi/devinfo` 连通性 → 令牌信息（签发时间 / 绑定 UID / 模式 /
  要求等级 / 服务公告）→ `GET /openapi/otp` 能否申请 OTP，并给出门槛与 FanVerify 要求等级不一致时的提醒。
  脚本**只打印令牌的前 8 位与后 4 位**，不会输出完整令牌。
- **当前状态（务必如实理解）**：代码已完整接入，但当前配置的令牌调用 `devinfo` 返回 **401**
  （不带令牌、带伪造令牌也是同一个 401；已排除字符歧义，也确认过 5 个端点的路径与参数名都能连通，
  返回 401 而非 404）。所以问题在**令牌侧**，可能的排查方向：
  1. 令牌是否已在 FanVerify 开发者后台**启用**；
  2. 令牌是否已**过期或被重置**；
  3. FanVerify 侧是否配置了**来源 IP 白名单**，需要把本服务器出口 IP 加进去。
  排查入口就是 `php bin/fanverify-check.php`，它会打印 `curl -i` 的自查命令。
  401 在我们的端点里会被翻译成 **500 `server_error`** 并附带上述排查提示（见 `FanVerifyClient::httpError()`）。
- **未配置时的表现**（只有"没填 `FANVERIFY_ACCESS_TOKEN`"这一种情况）：
  - 注册流程中**只有用户勾选并填写了 FanVerify** 时才会走到这一步 → 501；**不勾选则完全不受影响**；
  - 绑定 FanVerify（`POST /passport/api/v1/bindings`，`type=fanverify`）→ 501 `not_implemented`；
    扫码流程的两个端点同样是 501；此时 `GET …/bindings` 里 `fanverify.available` 为 `false`。

> 管理员可在 `/passport/` 页面的「接口接入状态」卡片里一眼看到**五个接口**哪些已接入、哪些待接入
> （判定依据分别是 `playerProvider()->isConfigured()` / `simpassVerifier()->isConfigured()` /
> `emailVerifier()->isConfigured()` / `fanVerifyVerifier()->isConfigured()` /
> `countryProvider()->isConfigured()`）。
> ⚠ 卡片上的标注与代码的**实际行为**：只有"非必填"的三项（邮箱验证码、FanVerify、邦国信息）会被加上
> 「可选」角标，游戏内玩家与简幻通不加角标（**卡片并不会渲染「必填」二字**）。
> FanVerify 的接入状态取 `fanVerifyVerifier()->isConfigured()`，即 `FANVERIFY_ACCESS_TOKEN` 是否已配置；
> 卡片在"待接入"时显示的那行"配置 xxx"提示变量名**仍是已废弃的旧名**（`passport/index.php` 里的一处遗留，
> 没跟着本轮改动同步），**实际生效的开关是 `FANVERIFY_ACCESS_TOKEN`**，以本文件与 `.env.example` 为准。

---

## 八、本地开发与部署

### 8.1 准备配置

```bash
cp .env.example .env
# 必填：DB_HOST / DB_PORT / DB_NAME=bgjq8w / DB_USER=bgjq8w / DB_PASS
# 按需填：PASSPORT_BASE_URL，以及四个待接入接口的地址与令牌
#   PLAYER_API_BASE / COUNTRY_API_BASE / SIMPASS_API_URL  —— 关系到注册必填校验
#   EMAIL_API_URL                                         —— 可选绑定，不填不影响注册
# FanVerify 已接入：填 FANVERIFY_ACCESS_TOKEN 即可启用（不填则只挡"绑定 FanVerify"这一步）
#   自检：php bin/fanverify-check.php
```

`.env` 已被 `.gitignore` 排除（`.env`、`.env.*`，仅保留 `!.env.example`）。`.env.example` 里只允许出现占位值。

### 8.2 初始化数据库（`bin/init-database.ps1`）

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

**已经导入过上一版结构、库里已有数据的场景**：不要重跑 init 脚本（它会重建库与账号），
改跑增量升级脚本 `database/upgrade-email-optional-fanverify.sql`
（把 `email` 改为允许 `NULL`、补 `fanverify_uid` / `fanverify_level` / `fanverify_tag` / `fanverify_verified_at`
四列与 `uk_fanverify_uid` 索引）。
用法与自检输出见 [`docs/MIGRATION.md` 第 2.4 节](../docs/MIGRATION.md)。

### 8.3 跑起来

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
- 打开 `https://<域名>/passport/`：未登录是**登录 / 注册两个标签页**（注册页把验证邮箱与 FanVerify
  做成两个复选框开关，勾选才展开对应字段）；登录后进入**左侧导航 + 右侧内容分区**的仪表盘：
  概览、绑定管理、已授权应用、账号安全。
  管理员（默认角色 `secretary_general`，可用 `PASSPORT_ADMIN_ROLES` 配置）额外可见
  「第三方应用」与「接口状态」两块。
- `passport/assets/passport.css` 是一套完整设计系统（CSS 变量令牌、深色模式、900/640/380px 三档响应式、
  触屏 44px 点击目标、`prefers-reduced-motion` / `prefers-contrast`、打印样式），
  用户中心与 OAuth 授权确认页共用同一套样式。

### 8.4 提交前闸门

```powershell
pwsh ./bin/test.ps1
```

该脚本先对全量 `.php` 文件跑 `php -l`（跳过 `vendor` / `node_modules` / `storage`），
再运行 `passport/tests/smoke.php`，覆盖十三个部分：`Arr` 点路径取值、`Scope` 授权范围、
`Account` 可选绑定语义、未接入的可选绑定（只挡绑定不挡注册）、`Str` 随机与哈希、`Config` 配置读取、
`Directory` 权威数据值对象、`Http` 响应与异常格式、未接入接口的失败语义（关键：绝不静默放行）、
`database/8w_passport.sql` 结构自检、`Application` 依赖装配（最容易「忘了启动」的地方）、
`BindingService` 绑定规则、`FanVerifyClient`（对接 fanverify.cn openAPI —— 用假 `HttpClient`
在**无网络、无 cURL** 的环境下验证 URL 构造与响应映射，包括 401 → 500、403 → `null`、
OTP 的 `wait` / `ok` / `rate_limit` 三种状态、二维码必须是 PNG 等）。
两项全绿才允许提交。

> `HttpClient` 刻意**不加 `final`**，就是为了让测试子类覆写 `get()` / `postJson()` 返回预设响应
> （见 `passport/tests/smoke.php` 里的 `FakeHttpClient`）。

另有一个只读的接入自检脚本（需要网络与 cURL，**不参与** `bin/test.ps1` 闸门）：

```bash
php bin/fanverify-check.php
```

### 8.5 日常维护任务

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

## 九、安全要点备忘

- 密码：`password_hash(…, PASSWORD_DEFAULT)`；登录时账号不存在也走一次 `password_verify`，避免响应时间枚举账号。
- **绑定管理：绑定与解绑都要验当前密码**（见 5.1），防止被盗用的登录态把攻击者的邮箱/身份挂上来夺号。
- 会话：Cookie 只放 32 字节随机令牌（`HttpOnly`、`SameSite=Lax`、HTTPS 下 `Secure`），库里只存 SHA-256；
  令牌无效/过期时顺手清掉浏览器里的 Cookie；改密后除当前会话外全部销毁。
- 令牌与授权码：库里只存 SHA-256，明文只在签发那一刻返回一次。
- 授权端点：`client_id` / `redirect_uri` 校验失败时**绝不重定向**（防开放重定向），只渲染错误页；
  回调地址必须精确命中登记的白名单。
- 授权码：一次性消费（`UPDATE … WHERE consumed_at IS NULL`），已消费的码被再次使用时会连带吊销该码签发的令牌。
- 日志：`Logger` 按**键名**打码，名单见 `Support/Logger.php` 的 `$redactKeys`
  （`password` / `password_hash` / `client_secret` / `token` / `access_token` / `refresh_token` /
  `accesstoken` / `code` / `pass_code` / `verify_code` / `email_code` / `simpass_code` /
  `fanverify_code` / `otp` / `api_key` / `api_secret` …）。
  **新增带密钥语义的字段名时必须同步加进这个名单。**
- **URL 里的密钥也要打码**：很多第三方接口把令牌放在 query string 上（FanVerify 的 `accesstoken`），
  而 `HttpClient` 会把 URL 写进调试日志 —— 因此它统一走 `HttpClient::sanitizeUrl()`，
  把 `accesstoken` / `token` / `pass_code` / `otp` 等参数的值替换成 `***`，其余部分逐字节保留。
  注意打码名单是按**完整键名**匹配的，取名字时别绕开它（例如叫 `key` 而不是 `api_key` 就会漏掉）。
- **FanVerify 令牌绝不进前端**：`/openapi/genqrcode` 要求把 `accesstoken` 放 query string，
  因此二维码一律走服务端代理（`passport/api/v1/fanverify-qr.php`），
  不让浏览器直接请求上游 —— 否则令牌会留在前端 URL、浏览器历史与 `Referer` 里。
  该端点还要求通行证登录态，且对 `otp` 做了长度与字符白名单校验（`^[A-Za-z0-9_-]+$`、≤64），
  避免被当成公开的二维码代取服务或把任意内容拼进上游请求。
- **OTP 与二维码都不缓存**：`fanverify-otp` / `fanverify-qr` 的响应都带 `Cache-Control: no-store`
  （二维码另外带 `Pragma: no-cache`），OTP 也不落库 —— 它只在申请到落库之间的这几十秒里存在。
  注意 `Logger` 的打码名单是按**完整键名**匹配的，`accesstoken` / `otp` 这类名字**不在名单里**，
  往日志里写它们必须自己先掩码（`bin/fanverify-check.php` 就只打印令牌的前 8 位与后 4 位）。
- 数据库：`Support\Database` 禁用模拟预处理（`PDO::ATTR_EMULATE_PREPARES => false`）。

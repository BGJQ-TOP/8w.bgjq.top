# 8W通行证 开放 API 接入文档

> 面向第三方开发者。本文所有字段名、参数名、路由与错误码均取自 `passport/` 源码，
> 可照此直接写完一个 OAuth 2.0 客户端。维护者视角见 [`passport/README.md`](../passport/README.md)。

---

## 目录

1. [概述](#一概述)
2. [基础地址与路由表](#二基础地址与路由表)
3. [统一响应格式](#三统一响应格式)
4. [授权范围 scope](#四授权范围-scope)
5. [OAuth 2.0 授权码流程](#五oauth-20-授权码流程)
6. [令牌端点 POST /oauth/token](#六令牌端点-post-oauthtoken)
7. [用户信息 GET /oauth/userinfo](#七用户信息-get-oauthuserinfo)
8. [令牌内省 POST /oauth/introspect](#八令牌内省-post-oauthintrospect)
9. [令牌吊销 POST /oauth/revoke](#九令牌吊销-post-oauthrevoke)
10. [数据查询接口](#十数据查询接口)（含 [10.3 绑定管理](#103-绑定管理-passportapiv1bindings)、[10.4 FanVerify 扫码绑定的两个端点](#104-fanverify-扫码绑定的两个端点)、[10.5 FanVerify 接入自检](#105-fanverify-接入自检管理员)）
11. [错误码表](#十一错误码表)
12. [限流](#十二限流)
13. [可直接运行的示例](#十三可直接运行的示例)
---

## 一、概述

8W通行证是 8W社区的统一身份服务，对外提供：

- **OAuth 2.0 授权**（RFC 6749）：授权码模式（`authorization_code`，支持 PKCE / RFC 7636）、
  刷新令牌（`refresh_token`）、客户端凭据（`client_credentials`）。
- **令牌内省与吊销**（RFC 7662 / RFC 7009）。
- **权威数据查询**：游戏内玩家、邦国公开信息（机器对机器，无需用户授权）。

用户身份的对外唯一标识是 **通行证 UID**，也就是 OAuth 2.0 响应里的 `sub`。

接入前你需要拿到一对凭据：`client_id` 与 `client_secret`（公开客户端没有 `client_secret`，改用 PKCE）。
凭据由通行证管理员在 `/passport/` 的「第三方应用管理」里创建，创建时登记的回调地址即白名单，
**授权请求里的 `redirect_uri` 必须与之精确相等**。

---

## 二、基础地址与路由表

基础地址（`PASSPORT_BASE_URL`）：

```
https://8w.bgjq.top
```

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET / POST | `/oauth/authorize` | 授权端点（GET 展示授权确认页，POST 提交同意/拒绝） |
| POST | `/oauth/token` | 令牌端点（三种 `grant_type`） |
| GET | `/oauth/userinfo` | 用户信息（Bearer 令牌） |
| POST | `/oauth/introspect` | 令牌内省（需机密客户端认证） |
| POST | `/oauth/revoke` | 令牌吊销（需机密客户端认证） |
| GET | `/passport/api/v1/player` | 查询游戏内玩家 |
| GET | `/passport/api/v1/country` | 查询邦国 |
| POST | `/passport/api/v1/register` | 注册通行证（无需登录，成功即建立登录态） |
| POST | `/passport/api/v1/email-code` | 下发邮箱验证码 |
| GET | `/passport/api/v1/me` | 当前通行证 + 绑定全景（需通行证会话 Cookie，非第三方接口） |
| GET / POST / DELETE | `/passport/api/v1/bindings` | 绑定管理：列出 / 绑定 / 解绑（需通行证会话 Cookie） |
| POST / GET | `/passport/api/v1/fanverify-otp` | FanVerify 扫码绑定：申请 OTP / 轮询是否已被确认（需通行证会话 Cookie） |
| GET | `/passport/api/v1/fanverify-qr` | FanVerify OTP 二维码 PNG（服务端代理，需通行证会话 Cookie） |
| GET | `/passport/api/v1/fanverify-status` | FanVerify 接入自检（**需管理员**通行证会话 Cookie） |
| GET / DELETE | `/passport/api/v1/authorized-apps` | 列出 / 撤销授权（需通行证会话 Cookie） |
| GET / POST / DELETE | `/passport/api/oauth/clients` | 第三方应用管理（需管理员通行证会话 Cookie） |

> `/oauth/authorize` 等标准端点同时可通过 `/passport/api/oauth/<name>` 访问（Nginx 把两者都重写到
> `/passport/api/oauth/<name>.php`）。第三方接入请统一使用上表的 `/oauth/*` 短路径。

所有接口都返回 CORS 头，允许浏览器直连：

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization
Access-Control-Max-Age: 86400
```

`OPTIONS` 预检请求直接返回 `204`，不执行业务逻辑。

请求体解析：`Content-Type: application/json` 按 JSON 解析；
`application/x-www-form-urlencoded` 按表单解析；两者都没有时先试 JSON、再试表单。
参数取值规则是 **body 优先，其次 query**。

---

## 三、统一响应格式

由 `passport/src/Http/Response.php` 定义，除 OAuth 2.0 标准端点外一律使用：

**成功**

```json
{
  "ok": true,
  "data": { }
}
```

**失败**

```json
{
  "ok": false,
  "error": {
    "code": "invalid_request",
    "message": "邮箱格式不正确",
    "details": { "field": "email" }
  }
}
```

- `error.details` 只在有附加信息时出现，最常见的是 `{"field": "字段名"}`。
- 响应头固定包含 `Content-Type: application/json; charset=utf-8` 与 `X-Content-Type-Options: nosniff`。
- 成功状态码固定为 `200`；失败状态码见[错误码表](#十一错误码表)。

**例外**：`/oauth/token`、`/oauth/introspect`、`/oauth/revoke` 三个端点按 OAuth 2.0 规范返回**裸 JSON**
（不带 `ok` / `data` 包装）：

- 成功：令牌响应体本身（`access_token` / `token_type` / `expires_in` / `scope` / `refresh_token`）；
- 失败：`{"error":"…","error_description":"…"}`，并带 `Cache-Control: no-store`、`Pragma: no-cache`。

---

## 四、授权范围 scope

scope 由 `passport/src/OAuth/Scope.php` 的 `MAP` 常量唯一定义，逐条如下：

| scope | 含义（取自 `passport/src/OAuth/Scope.php` 的 `MAP`，`fanverify` 一行已按实际返回结构更正） |
| --- | --- |
| `basic` | 通行证UID、用户名、站内角色 |
| `email` | 验证邮箱与邮箱验证状态（未绑定时不返回该字段） |
| `player` | 游戏内玩家名、玩家ID、所属邦国ID |
| `country` | 所属邦国ID（与 player 重复，供只关心邦国的应用使用） |
| `simpass` | 简幻通ID与等级 |
| `fanverify` | FanVerify 账号信息 `{uid, level, tag}`（未绑定时不返回该字段） |
| `offline_access` | 刷新令牌到期后仍可继续换取新的刷新令牌（不申请则只能刷新一次） |
| `directory` | 查询游戏内玩家与邦国公开信息（机器对机器） |

补充规则：

- **默认 scope** 为 `basic`（`Scope::DEFAULT_SCOPE`）。请求里不传 `scope`，或传空串，都按 `basic` 处理。
- scope 字符串按**空格或逗号**分隔，自动去重。
- 应用能申请到的 scope 上限由创建应用时登记的 `allowed_scopes` 决定，请求值是二者交集，
  越权的部分会被静默裁剪；若交集为空，授权请求直接失败（`invalid_scope`）。
- **机器对机器允许的 scope 只有 `directory`**（`Scope::MACHINE_SCOPES`），
  `client_credentials` 拿不到任何用户身份数据。
- 刷新令牌时**只允许缩小 scope，不允许扩大**。
- ⚠ **`email` 与 `fanverify` 对应的是可选绑定**：用户完全可以不绑，此时对应字段在 userinfo 里
  **整块省略**（不是返回 `null`）。申请了这两个 scope 不等于一定能拿到值，客户端必须按
  "字段存在即已绑定、字段缺失即未绑定" 来判断。
- ⚠ `Scope::MAP` 里 `fanverify` 这一行的文案仍是「FanVerify 账号ID（未绑定时不返回该字段）」，
  **没跟着实际返回结构同步**；真实返回的是 `{uid, level, tag}` 三字段（见第七节），以
  `Account::toProfileArray()` 为准。

---

## 五、OAuth 2.0 授权码流程

### ① 跳转授权端点

```
GET https://8w.bgjq.top/oauth/authorize
```

查询参数（全部）：

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `client_id` | 是 | 应用标识。缺失 → 错误页 `invalid_request`；未知 → 错误页 `invalid_client`(401)；已停用 → 错误页 `unauthorized_client`(403) |
| `redirect_uri` | 否 | 回调地址。不传时使用该应用登记的唯一回调地址（登记多个则视为空，校验不通过）。必须与白名单**精确相等** |
| `response_type` | 是 | 只支持 `code`，其他值 → 回调带 `error=unsupported_response_type` |
| `scope` | 否 | 空格或逗号分隔，默认 `basic`。含未知 scope → `error=invalid_scope` |
| `state` | 否 | 原样回传，用于防 CSRF；不传则回调里不带该参数 |
| `code_challenge` | 公开客户端必填 | PKCE challenge（RFC 7636）。公开客户端缺失 → `error=invalid_request` |
| `code_challenge_method` | 否 | `S256`（默认）或 `plain`，大小写不敏感（内部转大写） |

**错误处理方式分两种，务必注意：**

- `client_id` 或 `redirect_uri` 校验失败时**绝不重定向**（避免开放重定向攻击），
  而是直接渲染「授权请求无效」错误页，并返回对应 HTTP 状态码。
- 其余参数校验失败时，会带着错误参数重定向回 `redirect_uri`：
  `?error=<code>&error_description=<描述>&state=<state>`（空值参数不会出现在查询串里）。

### ② 用户在通行证页确认

未登录时，端点会 302 到：

```
https://8w.bgjq.top/passport/?return=<原授权请求的 URL，已 URL 编码>
```

登录后自动回到授权确认页。确认页展示应用名称、主页、本次申请的每个 scope 及其含义、当前通行证与游戏内玩家名，
并提供两个提交按钮：

```
POST /oauth/authorize
Content-Type: application/x-www-form-urlencoded

client_id=…&redirect_uri=…&response_type=code&scope=…&state=…&code_challenge=…&code_challenge_method=S256&decision=allow
```

- `decision=allow` → 签发授权码并回调。
- `decision` 为其它任何值（页面上的「拒绝」按钮提交 `decision=deny`）→ 回调
  `?error=access_denied&error_description=用户拒绝了本次授权&state=…`。

> 确认页的隐藏域会原样带回 `client_id` / `redirect_uri` / `response_type` / `scope` / `state` /
> `code_challenge` / `code_challenge_method`，服务端会**重新完整校验一遍**。

### ③ 回调拿到 code

```
https://your-app.example.com/callback?code=<授权码>&state=<state>
```

- 授权码是 32 字节 URL 安全随机串（base64url 无填充），**默认 300 秒有效**（`PASSPORT_OAUTH_CODE_TTL`），
  **一次性**：已被消费的码再次使用会失败，并且会连带吊销该码签发的全部令牌（防重放）。
- 服务端只存授权码的 SHA-256，明文只在回调里出现一次。

### ④ POST /oauth/token 换令牌

见下一节。

### ⑤ 调 /oauth/userinfo

见第七节。

---

## 六、令牌端点 POST /oauth/token

```
POST https://8w.bgjq.top/oauth/token
```

- 必须使用 `POST`，否则返回 `{"error":"invalid_request","error_description":"令牌端点必须使用 POST 请求"}`。
- 请求体可以是表单或 JSON（表单最省事）。
- 响应带 `Cache-Control: no-store`、`Pragma: no-cache`。

### 客户端认证

支持两种方式，`Authorization` 头**优先于**请求体参数：

| 方式 | 写法 |
| --- | --- |
| `client_secret_basic` | `Authorization: Basic base64(urlencode(client_id):urlencode(client_secret))` |
| `client_secret_post` | 请求体里带 `client_id` 与 `client_secret` |

- 机密客户端（`is_confidential=1`）必须提供正确的 `client_secret`，否则 `invalid_client`(401)。
- 公开客户端（`is_confidential=0`）没有密钥，认证靠 PKCE；
  但它**只允许** `authorization_code` 与 `refresh_token` 两种授权类型，
  用 `client_credentials` 会得到 `unauthorized_client`(401)。

### 通用响应字段

```json
{
  "access_token": "…",
  "token_type": "Bearer",
  "expires_in": 7200,
  "scope": "basic player",
  "refresh_token": "…"
}
```

| 字段 | 说明 |
| --- | --- |
| `access_token` | 32 字节 URL 安全随机串 |
| `token_type` | 固定 `Bearer` |
| `expires_in` | 访问令牌有效期（秒），默认 7200（`PASSPORT_ACCESS_TTL`，最小 60） |
| `scope` | 本次实际授予的 scope，空格分隔 |
| `refresh_token` | **条件返回**，见下 |

`refresh_token` 的出现规则（源码 `TokenRepository::issue()`）：

- `grant_type=authorization_code` → **总是返回**；
- `grant_type=refresh_token` → 仅当本次 scope 含 `offline_access` 时返回；
- `grant_type=client_credentials` → **从不返回**。

> 也就是说：`offline_access` 决定的是「刷新之后还能不能继续拿新的刷新令牌」，
> 而不是「第一次有没有刷新令牌」。刷新令牌轮换式使用，旧令牌立即作废。

### 6.1 grant_type=authorization_code

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `grant_type` | 是 | `authorization_code` |
| `code` | 是 | 回调拿到的授权码，缺失 → `invalid_request` |
| `redirect_uri` | 否 | 若提供，必须与授权请求时使用的完全一致，否则 `invalid_grant` |
| `client_id` | 是 | 使用 Basic 认证时可省略 |
| `client_secret` | 机密客户端必填 | 使用 Basic 认证时可省略 |
| `code_verifier` | 授权时带过 `code_challenge` 则必填 | PKCE verifier，长度必须 43–128 字符 |

PKCE 校验规则：

- 授权请求里带了 `code_challenge` 时，本请求**必须**带 `code_verifier`，否则 `invalid_grant`「缺少 code_verifier」；
- verifier 长度不在 43–128 之间 → `invalid_grant`「code_verifier 长度不合法」；
- `code_challenge_method=S256`（默认）时，服务端计算
  `BASE64URL(SHA256(code_verifier))`（无填充）与 challenge 比对，不一致 → `invalid_grant`「code_verifier 校验失败」；
- `code_challenge_method=plain` 时直接明文比对。
- **公开客户端必须在授权请求里带 `code_challenge`**（`S256`），这是硬性要求。

其它失败情形：授权码无效/已过期/已被使用 → `invalid_grant`「授权码无效、已过期或已被使用」；
授权码不属于该客户端 → `invalid_grant`「授权码不属于该客户端」。

### 6.2 grant_type=refresh_token

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `grant_type` | 是 | `refresh_token` |
| `refresh_token` | 是 | 上次拿到的刷新令牌，缺失 → `invalid_request` |
| `scope` | 否 | 只允许**缩小**原授权范围；与原有 scope 无交集 → `invalid_scope` |
| `client_id` / `client_secret` | 同前 | 刷新令牌必须属于同一客户端 |

失败情形：令牌无效或已过期 → `invalid_grant`「refresh_token 无效或已过期」；
不属于该客户端 → `invalid_grant`「refresh_token 不属于该客户端」；
对应通行证已停用或不存在 → `invalid_grant`「该通行证已不可用」。

成功后**旧刷新令牌立即失效**（`revoked_at` 置位），同时签发一整套新令牌。

### 6.3 grant_type=client_credentials

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `grant_type` | 是 | `client_credentials` |
| `scope` | 否 | 默认 `directory`；只能申请 `directory` |
| `client_id` / `client_secret` | 是 | 必须是机密客户端 |

- 该令牌**不绑定任何用户**（内部 `account_id = 0`），只能调用
  `/passport/api/v1/player` 与 `/passport/api/v1/country`；
  调用 `/oauth/userinfo` 会得到 `{"error":"invalid_token","error_description":"该访问令牌不代表任何用户，无法访问 userinfo"}`（403）。
- 申请到空集合时 → `invalid_scope`「client_credentials 只能申请以下 scope：directory」。
- 响应不含 `refresh_token`。

---

## 七、用户信息 GET /oauth/userinfo

```
GET https://8w.bgjq.top/oauth/userinfo
Authorization: Bearer <access_token>
```

返回体按令牌的 scope 裁剪（源码 `Account::toProfileArray()`）：

```json
{
  "ok": true,
  "data": {
    "sub": "1001",
    "username": "louie",
    "role": "observer",
    "email": "louie@example.com",
    "email_verified": true,
    "player": {
      "player_name": "LouieMAIN",
      "player_id": 1001,
      "country_id": 7
    },
    "country_id": 7,
    "simpass": {
      "uid": 10086,
      "level": 3
    },
    "fanverify": {
      "uid": 555,
      "level": 3,
      "tag": null
    }
  }
}
```

| 字段 | 需要的 scope | 类型 | 说明 |
| --- | --- | --- | --- |
| `sub` | 无（总是返回） | string | 通行证 UID，等于 `passport_accounts.id` |
| `username` | `basic` | string | 通行证用户名 |
| `role` | `basic` | string | 站内角色：`observer` / `diplomat` / `peacekeeper` / `permanent_member` / `secretary_general` |
| `email` | `email` | string | 验证邮箱。**未绑定时整个字段省略** |
| `email_verified` | `email` | bool | 邮箱是否已验证（`email_verified_at` 非 NULL）。**未绑定时整个字段省略** |
| `player.player_name` | `player` | string | 游戏内玩家名（权威主键） |
| `player.player_id` | `player` | int \| null | 游戏内玩家 ID（权威缓存） |
| `player.country_id` | `player` | int \| null | 所属邦国 ID（权威缓存） |
| `country_id` | `country` | int \| null | 所属邦国 ID（与 `player.country_id` 同值） |
| `simpass.uid` | `simpass` | int \| null | 简幻通 ID |
| `simpass.level` | `simpass` | int \| null | 简幻通等级 |
| `fanverify.uid` | `fanverify` | int | FanVerify 账号ID。**未绑定时整个 `fanverify` 块省略** |
| `fanverify.level` | `fanverify` | int \| null | FanVerify 等级（FanVerify 接口里是字符串，通行证统一转成 `int`；权威缓存，可能为 `null`） |
| `fanverify.tag` | `fanverify` | string \| null | FanVerify 风险标签（平台侧对该账号的公开标记）。**没有标签时为 `null`**（FanVerify 返回空串即视为无标签） |

> ⚠ **「未绑定」的表示方式是"字段不存在"，而不是"字段为 `null`"。**
> `email` / `email_verified` 与整个 `fanverify` 块都遵循这条规则：
> 申请了 scope 但用户没绑，返回体里就**没有**这些键。这样客户端拿到的是明确信号
> （"该用户没有绑定"），不用去猜 `null` 到底是"没绑"还是"接口没返回"。
>
> 与之相对，`player.player_id` / `player.country_id` / `simpass.*` 是**必填绑定**或权威缓存，
> 键始终存在，值可能为 `null`。`fanverify` 块存在时同理：`uid` / `level` / `tag` 三个键都在，
> `level` 与 `tag` 可能是 `null`（分别是"FanVerify 没返回等级"与"该账号没有风险标签"）。

`offline_access` 与 `directory` 不改变 userinfo 的输出内容。

失败情形：

- 缺少 `Authorization: Bearer` → 401 `unauthorized`「缺少访问令牌」；
- 令牌无效或已过期 → 401 `unauthorized`「访问令牌无效或已过期」；
- 令牌所属应用已被停用 → 401 `invalid_client`「该应用已被停用」；
- 对应通行证已不可用 → 401 `unauthorized`「该通行证已不可用」；
- 令牌不代表任何用户（`client_credentials` 令牌）→ 403 `invalid_token`。

---

## 八、令牌内省 POST /oauth/introspect

RFC 7662。**需以机密客户端身份认证**（公开客户端调用会得到 `unauthorized_client`(401)）。

```
POST https://8w.bgjq.top/oauth/introspect
Content-Type: application/x-www-form-urlencoded

token=<要检查的令牌>&token_type_hint=access_token&client_id=…&client_secret=…
```

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `token` | 是 | 访问令牌或刷新令牌 |
| `token_type_hint` | 否 | `access_token` 或 `refresh_token`，仅用于减少查询次数 |
| `client_id` / `client_secret` | 是 | 或用 Basic 认证 |

**有效时**（HTTP 200，裸 JSON，无 `ok` 包装）：

```json
{
  "active": true,
  "client_id": "0a1b2c3d4e5f60718293a4b5c6d7e8f9",
  "sub": "1001",
  "scope": "basic player",
  "token_type": "Bearer",
  "exp": 1735689600,
  "iat": 1735682400,
  "grant_type": "authorization_code"
}
```

`exp` / `iat` 是 Unix 时间戳。`client_credentials` 令牌的 `sub` 为 `"0"`。

**无效、已过期或已吊销时**：

```json
{ "active": false }
```

---

## 九、令牌吊销 POST /oauth/revoke

RFC 7009。**需以机密客户端身份认证**。

```
POST https://8w.bgjq.top/oauth/revoke
Content-Type: application/x-www-form-urlencoded

token=<要吊销的令牌>&client_id=…&client_secret=…
```

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `token` | 是 | 访问令牌或刷新令牌，命中任一哈希即吊销整条记录 |
| `client_id` / `client_secret` | 是 | 或用 Basic 认证 |

按规范，无论令牌是否存在都返回成功。本实现的响应是 **HTTP 200，响应体为 `{}`**（空 JSON 对象，
由 `passport/src/Http/Response.php` 的 `Response::emptyBody()` 产出），并带 `Cache-Control: no-store`。
之所以不用 `[]`：`json_encode([])` 得到的是一个 JSON **数组**，容易被客户端误判成「一个列表」而按数组去解析；
`{}` 明确表达「没有内容可解析」。客户端只需判断状态码，不要解析响应体。

---

## 十、数据查询接口

这两个接口的鉴权是**二选一**：

1. `Authorization: Bearer <access_token>`，且令牌必须含 **`directory`** scope（缺失 → 403
   `forbidden`「该访问令牌没有 directory 权限」）；
2. 通行证会话 Cookie（供站内页面使用，第三方请勿依赖）。

两者都不满足 → 401 `unauthorized`「请先登录通行证，或携带有效的 Bearer 访问令牌」。

### 10.1 GET /passport/api/v1/player

```
GET https://8w.bgjq.top/passport/api/v1/player?name=LouieMAIN
Authorization: Bearer <access_token>
```

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `name` | 是 | 游戏内玩家名（也接受别名 `player`）。都为空 → 422 `invalid_request`「缺少参数 name（游戏内玩家名）」 |
| `fresh` | 否 | 传 `1` / `true` / `yes` / `on`（大小写不敏感）表示强制回源。默认 `false` |

**成功响应**：

```json
{
  "ok": true,
  "data": {
    "player": {
      "player_name": "LouieMAIN",
      "player_id": 1001,
      "country_id": 7
    },
    "source": "cache",
    "country": {
      "id": 7,
      "name": "大周",
      "declaration": "海纳百川",
      "territory_chunks": 1234,
      "population": 2,
      "players": [
        { "player_name": "Alice", "player_id": 1, "country_id": 7 },
        { "player_name": "Bob", "player_id": 2, "country_id": 7 }
      ]
    }
  }
}
```

| 字段 | 说明 |
| --- | --- |
| `data.player` | 玩家信息，字段固定为 `player_name` / `player_id` / `country_id` |
| `data.source` | 结果的来源：`cache` = 来自本地缓存（**包括**「权威接口未接入或回源失败、降级使用旧缓存」的情况）；`authoritative` = 本次确实成功调用了权威接口并写入了缓存 |
| `data.country` | 玩家所属邦国的完整信息，**仅在玩家有邦国且取数成功时出现**（第三方一次请求即可拿全） |
| `data.country_unavailable` | 邦国接口不可用时的降级说明文案；此时 `data.country` 退化为本地缓存（若有） |

**`fresh` 的真实语义**：它表示「跳过『缓存未过期就直接返回』这一步」，**不保证**拿到权威数据——
数据源未配置或回源失败时，目录层仍会返回旧缓存（可用性优先）。
相应地，`source` 描述的是**结果的实际来源**，而不是「是否尝试过回源」：
回源失败而降级返回旧缓存时，它同样是 `cache`，不会冒充权威结果。
只有本次真正调通权威接口并写入缓存才是 `authoritative`；也就是说 `source = "authoritative"`
才意味着数据来自权威接口的实时返回，`cache` 则可能是已过期的旧值（降级场景）。

**失败情形**：

- 玩家不存在 → 404 `not_found`「游戏内不存在名为「X」的玩家」；
- 玩家查询接口未接入且本地无缓存 → 501 `not_implemented`；
- 玩家查询接口异常 → 500 `server_error`。

### 10.2 GET /passport/api/v1/country

```
GET https://8w.bgjq.top/passport/api/v1/country?id=7
GET https://8w.bgjq.top/passport/api/v1/country?name=大周
Authorization: Bearer <access_token>
```

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `id` | 二选一 | 邦国 ID（也接受别名 `country_id`） |
| `name` | 二选一 | 邦国名称（也接受别名 `country`） |
| `fresh` | 否 | 同 player 接口 |

两者都不传（或 `id <= 0` 且 `name` 为空）→ 422 `invalid_request`「请提供 id（邦国ID）或 name（邦国名称）」。

**成功响应**：

```json
{
  "ok": true,
  "data": {
    "country": {
      "id": 7,
      "name": "大周",
      "declaration": "海纳百川",
      "territory_chunks": 1234,
      "population": 2,
      "players": [
        { "player_name": "Alice", "player_id": 1, "country_id": 7 },
        { "player_name": "Bob", "player_id": 2, "country_id": 7 }
      ]
    }
  }
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int | 邦国 ID（权威主键） |
| `name` | string | 邦国名称（权威缓存） |
| `declaration` | string \| null | 邦国宣言（权威缓存） |
| `territory_chunks` | int \| null | 邦国领土大小 / 领地块数（权威缓存） |
| `population` | int | 邦国人口，等于 `players` 列表长度（派生值） |
| `players` | array | 邦国玩家列表，元素为 `player_name` / `player_id` / `country_id` |

**失败情形**：

- 未找到 → 404 `not_found`「未找到对应的邦国」；
- 邦国接口未接入且本地无缓存 → 501 `not_implemented`；
- 只配了按 ID 查询的路径模板（未配 `COUNTRY_API_PATH_BY_NAME`）时用 `name` 查询 → 501
  「邦国信息接口未提供按名称查询，请使用邦国ID，或配置 COUNTRY_API_PATH_BY_NAME」。

---

### 10.3 绑定管理 `/passport/api/v1/bindings`

> ⚠ **这是第一方接口，不是第三方接入接口。**
> 鉴权走**通行证会话 Cookie**（`Authenticator::requireCurrent()`），不接受 Bearer 令牌。
> 它是 `/passport/` 页面「绑定管理」卡片的后端，第三方应用无法调用。

通行证上的绑定分两类：

| 类别 | 绑定 | `bindable` | 说明 |
| --- | --- | --- | --- |
| 必填 | 游戏内玩家名 | `false` | 权威身份主键，注册时经权威接口实时校验 |
| 必填 | 简幻通ID | `false` | 注册时校验通过，同时是默认的账号找回通道 |
| 可选 | 验证邮箱 | `true` | 用户自己决定绑不绑，随时可绑可解 |
| 可选 | FanVerify 账号 | `true` | 同上；有**扫码**与**手填**两条绑定路径（见下方「绑定」） |

#### 列出全部绑定

```http
GET /passport/api/v1/bindings
```

```json
{
  "ok": true,
  "data": {
    "bindings": {
      "player":    { "label": "游戏内玩家名", "bound": true,  "required": true,  "bindable": false, "value": "LouieMAIN", "detail": "玩家ID 1001" },
      "simpass":   { "label": "简幻通",       "bound": true,  "required": true,  "bindable": false, "value": 10086,      "detail": "等级 3" },
      "email":     { "label": "验证邮箱",     "bound": false, "required": false, "bindable": true,  "available": true,  "value": null, "detail": null },
      "fanverify": { "label": "FanVerify",    "bound": true,  "required": false, "bindable": true,  "available": true,  "value": 555, "detail": "等级 3 · 风险标签：疑似小号", "tag": "疑似小号" }
    },
    "account": { "...": "见 GET /passport/api/v1/me" }
  }
}
```

- `bound` —— 是否已绑定；
- `required` —— 是否必填（必填项 `bindable` 恒为 `false`）；
- `available` —— **仅两个可选绑定有**，表示对应的外部接口是否可用。为 `false` 时前端应禁用绑定按钮
  并说明原因，而不是让用户白点一次。判定依据分别是
  `EmailCodeService::isDeliverable()`（即 `EMAIL_API_URL` 是否配置）与
  `FanVerifyVerifier::isConfigured()`（即 `FANVERIFY_ACCESS_TOKEN` 是否配置 ——
  FanVerify 已真实接入，`false` 只表示"没填令牌"，不代表接口待开发）；
- `tag` —— **仅 `fanverify` 有**，FanVerify 风险标签（`string | null`，无标签为 `null`）。
  它是平台侧对该账号的**公开标记**，前端应在绑定项旁显眼展示；
  等级则拼进 `detail`（形如 `等级 3 · 风险标签：疑似小号`），等级与标签都为空时 `detail` 回落到「已验证」。

`GET /passport/api/v1/me` 也会返回同一份 `bindings`。

#### 绑定

```http
POST /passport/api/v1/bindings
Content-Type: application/json

# 绑定邮箱
{ "type": "email",     "email": "you@example.com", "code": "123456", "password": "当前密码" }

# 绑定 FanVerify —— 路径一：手填（账号ID + 动态验证码）
{ "type": "fanverify", "uid": 10086,               "code": "654321", "password": "当前密码" }

# 绑定 FanVerify —— 路径二：扫码（otp 来自 /passport/api/v1/fanverify-otp）
{ "type": "fanverify", "otp": "0pO6gTXmtlzwOBNc",  "password": "当前密码" }
```

- 绑定邮箱前需先调用 `POST /passport/api/v1/email-code` 并传 `scene: "bind"` 获取验证码。
- FanVerify 的两条路径由 `otp` 是否存在自动区分：**带了 `otp` 走扫码，否则走手填**（此时 `uid` 必填）。
  手填路径由服务端调 FanVerify 的 `user_verify` 接口校验 UID + 动态验证码；
  扫码路径则由服务端**重新轮询一次 OTP**（不轻信前端"用户已确认"的说法），
  未通过或已超时 → 422「扫码尚未确认或已超时，请重新扫码」（`details.status` 给出 `wait` / `rate_limit`）。
- FanVerify 落库时会一并写入 `fanverify_uid` / `fanverify_level` / `fanverify_tag` / `fanverify_verified_at`
  （未绑定时都是 `NULL`）。若本站在 `.env` 里配了 `FANVERIFY_REQUIRED_LEVEL`（默认 `0` = 不限），
  等级不足的账号会被拒绝：422「该 FanVerify 账号等级不足（当前 X，要求 N）」，`details.field = fanverify_uid`。

成功返回：

```json
{
  "ok": true,
  "data": {
    "bound": "email",
    "bindings": { "...": "最新的绑定全景" },
    "account": { "...": "最新的账号信息" }
  }
}
```

#### 解绑

```http
DELETE /passport/api/v1/bindings
Content-Type: application/json

{ "type": "email", "password": "当前密码" }
```

`type` 也可以放在查询串上（无敏感性）；**`password` 只从请求体读取** ——
放进 URL 会被 Web 服务器访问日志、浏览器历史与 `Referer` 记录下来。

成功返回 `{"ok":true,"data":{"unbound":"email","bindings":{...},"account":{...}}}`。

#### 安全约定

**绑定与解绑都要求提供当前密码**，缺失或错误一律返回 422 `invalid_request`「当前密码不正确」。

原因：绑定会改变账号的找回途径。若只凭登录态就能绑邮箱，一个被盗用的会话就能把攻击者的邮箱挂到
受害者账号上，再走找回流程彻底夺走账号；解绑同理。要求当前密码等于强制「这是本人操作」。

#### 失败情形

| 情形 | 状态码 | 错误码 |
| --- | --- | --- |
| 未登录 | 401 | `unauthorized` |
| `type` 不是 `email` / `fanverify` | 422 | `invalid_request` |
| 密码缺失或错误 | 422 | `invalid_request` |
| 邮箱格式不正确 / 邮箱验证码错误 | 422 | `invalid_request` |
| FanVerify 账号ID 非法 / 验证码为空 | 422 | `invalid_request` |
| 该邮箱 / FanVerify 已被其它通行证绑定 | 409 | `conflict` |
| 重复绑定同一个值 | 409 | `conflict` |
| 解绑一个本来就没绑定的项 | 409 | `conflict` |
| 对应外部接口不可用（邮箱接口未接入，或 FanVerify 的令牌未配置） | 501 | `not_implemented` |
| FanVerify 扫码未确认 / 已超时 / 等级不足门槛 | 422 | `invalid_request` |
| FanVerify 上游 401（令牌无效、未启用或 IP 未放行） | 500 | `server_error`（文案带排查提示） |

---

### 10.4 FanVerify 扫码绑定的两个端点

> ⚠ 同样是**第一方接口**：鉴权走通行证会话 Cookie（`Authenticator::requireCurrent()`），
> 不接受 Bearer 令牌。第三方应用用不到它们。

FanVerify 的扫码绑定由两个端点配合完成，**令牌（`accesstoken`）永远不出现在浏览器侧**：

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `POST` | `/passport/api/v1/fanverify-otp` | 申请一个 OTP（第一步） |
| `GET` | `/passport/api/v1/fanverify-otp?otp=…` | 轮询该 OTP 是否已被用户在小程序里确认 |
| `GET` | `/passport/api/v1/fanverify-qr?otp=…` | 取该 OTP 的二维码 PNG（服务端代理） |

**这三个端点背后的 FanVerify 上游接口**（根地址 `https://api.fanverify.cn`，
官方文档 <https://doc.fanverify.cn/llms.txt>，便于与官方文档对照排查）：

| 我们的端点 | FanVerify 上游 | 说明 |
| --- | --- | --- |
| `POST …/fanverify-otp` | `GET /openapi/otp` | 返回 `{"success":true,"data":{"otp":"…"}}` |
| `GET …/fanverify-qr` | `GET /openapi/genqrcode` | 返回 `image/png`；令牌只留在服务端 |
| `GET …/fanverify-otp` | `GET /openapi/seeotp` | 返回 `{"status":"wait"}` 或 `{"status":"ok","data":[…]}`；同一 OTP **5 秒内重复查询会返回 `{"status":"rate_limit"}`**（⚠ 官方文档说是 429，实测是 **HTTP 200 + status 字段**），所以前端轮询间隔定在 3 秒，撞上限流就跳过本轮 |
| `POST /passport/api/v1/bindings`（`otp` 路径） | 再调一次 `GET /openapi/seeotp` | 落库前复核，不轻信前端"已确认" |
| `POST /passport/api/v1/bindings`（`uid`+`code` 路径） | `GET /openapi/user_verify` | 参数 `uid` + `pass_code` |
| `GET …/fanverify-status` | `GET /openapi/devinfo` | 管理员自检 |

#### ① 申请 OTP

```http
POST /passport/api/v1/fanverify-otp
Content-Type: application/json

{ "password": "当前密码" }
```

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `password` | 是 | 当前通行证密码。**服务端先校验密码再申请 OTP** —— 免得用户扫完码才发现密码错了 |

成功返回：

```json
{
  "ok": true,
  "data": {
    "otp": "0pO6gTXmtlzwOBNc",
    "qr_url": "/passport/api/v1/fanverify-qr?otp=0pO6gTXmtlzwOBNc",
    "expires_in": 180,
    "poll_interval": 3
  }
}
```

| 字段 | 说明 |
| --- | --- |
| `otp` | FanVerify 签发的 OTP，后续轮询与绑定都要带上它 |
| `qr_url` | 二维码地址（**站内相对路径**，直接用 `<img src>` 即可，不要自己拼 FanVerify 的地址） |
| `expires_in` | OTP 有效期（秒），取自 `FANVERIFY_OTP_TTL`（默认 180，代码下限 30），前端据此判定超时 |
| `poll_interval` | 建议的轮询间隔（秒），固定为 `3` |

响应带 `Cache-Control: no-store`。

失败情形：未登录 → 401 `unauthorized`；`password` 缺失或错误 → 422 `invalid_request`
（「当前密码不正确」，`details.field = password`）；`FANVERIFY_ACCESS_TOKEN` 未配置 → 501 `not_implemented`；
FanVerify 未签发 OTP 或返回空 OTP → 500 `server_error`；上游 401（令牌问题）→ 500 `server_error`；
上游 403 → 403 `forbidden`。

#### ② 轮询 OTP

```http
GET /passport/api/v1/fanverify-otp?otp=0pO6gTXmtlzwOBNc
```

成功返回（HTTP 200，**注意 `rate_limit` 也是 200**，它不是错误，只是"问得太勤了"）：

```json
{ "ok": true, "data": { "status": "wait", "verified": false } }
```

| `data.status` | 含义 | 客户端该做什么 |
| --- | --- | --- |
| `wait` | 用户还没在小程序里确认 | 继续轮询 |
| `ok` | 用户已确认 | 停止轮询，接着调 `POST /passport/api/v1/bindings`（见 10.3） |
| `rate_limit` | 距上次查询不足 5 秒（FanVerify 侧的限流） | 跳过本轮，等下一次再查 |

`data.verified` 等价于 `status === "ok"`。本端点**只回"是否通过"，不回身份信息** ——
落库统一走 `/bindings`，避免出现"OTP 通过了但没人绑"的中间态被误用。

失败情形：未登录 → 401；缺少 `otp` 参数 → 422 `invalid_request`「缺少参数 otp」；
令牌未配置 → 501；上游 401（令牌问题）→ 500 `server_error`；上游 403 → 403 `forbidden`。
（注意：上游对同一 OTP 的限流**不会**变成 429 响应，而是被映射成 `status: "rate_limit"` 的 200。
实测上游限流本身就是 `HTTP 200 + {"status":"rate_limit"}`，官方文档写的 429 与事实不符。）

#### ③ 取二维码 PNG

```http
GET /passport/api/v1/fanverify-qr?otp=0pO6gTXmtlzwOBNc
```

成功时返回**图片本体**：`Content-Type: image/png`、`Content-Length` 正确，
并带 `Cache-Control: no-store, no-cache, must-revalidate` 与 `Pragma: no-cache`（二维码对应一次性 OTP，绝不能被缓存）。

**为什么要服务端代理**：FanVerify 的 `GET /openapi/genqrcode` 要求把 `accesstoken` 放在 **query string** 上。
若让浏览器直接请求上游，令牌就会出现在前端 URL、浏览器历史与 `Referer` 里；
因此由服务端带上令牌取回 PNG 再原样转发。该端点也要求登录态，避免被当成公开的二维码代取服务。

失败时返回的是 **JSON**（不是图片），格式与其他接口一致：

```json
{ "ok": false, "error": { "code": "invalid_request", "message": "otp 格式不正确" } }
```

| 情形 | 状态码 | 错误码 |
| --- | --- | --- |
| 未登录 | 401 | `unauthorized` |
| 缺少 `otp` | 422 | `invalid_request`「缺少参数 otp」 |
| `otp` 超过 64 字符或含白名单外字符（只允许 `A-Za-z0-9_-`） | 422 | `invalid_request`「otp 格式不正确」 |
| `FANVERIFY_ACCESS_TOKEN` 未配置 | 501 | `not_implemented` |
| 上游返回的不是 PNG（例如一段 HTML 错误页） | 500 | `server_error`「FanVerify 返回的二维码不是 PNG 图片」 |
| 上游 401（令牌问题） | 500 | `server_error`（带令牌排查提示） |
| 上游 403 / 429 | 403 `forbidden` / 429 `rate_limited` | 原样透传上游语义 |
| 其它未预期异常 | 500 | `server_error`「二维码获取失败」 |

#### 完整流程（前端视角）

1. 用户点「FanVerify → 绑定 → 扫码绑定」，填当前密码；
2. `POST /passport/api/v1/fanverify-otp {password}` → 拿到 `otp` / `qr_url` / `expires_in` / `poll_interval`；
3. 把 `qr_url` 作为 `<img src>` 展示（走服务端代理）；
4. 用户用 **FanVerify 微信小程序**扫码并确认；
5. 每 3 秒 `GET /passport/api/v1/fanverify-otp?otp=…` 轮询，直到 `status` 为 `ok`
   （`rate_limit` 跳过本轮，超过 `expires_in` 提示二维码过期并重新生成）；
6. `POST /passport/api/v1/bindings {type:"fanverify", otp, password}` ——
   **服务端会再轮询一次 OTP**，通过后才落库。

---

### 10.5 FanVerify 接入自检（管理员）

```http
GET /passport/api/v1/fanverify-status
```

> ⚠ 第一方接口：鉴权走通行证会话 Cookie，且**必须是管理员**
> （角色在 `PASSPORT_ADMIN_ROLES` 里，默认 `secretary_general`），
> 非管理员 → 403 `forbidden`「只有管理员通行证可以执行该操作」。

存在的意义：令牌不可用时，用户侧只会看到一句"FanVerify 拒绝了本次调用（401 未授权）"。
这个端点让管理员在后台点一下「接口接入状态 → FanVerify → 自检」就能看到
"是没配令牌、还是令牌被拒、还是网络不通"，不用登服务器翻日志
（服务器上更完整的排查仍用 `php bin/fanverify-check.php`）。

**令牌已配置且 `devinfo` 调用成功**（HTTP 200）：

```json
{
  "ok": true,
  "data": {
    "configured": true,
    "ok": true,
    "error": null,
    "base_url": "https://api.fanverify.cn",
    "developer": {
      "issued_at": "2026-07-12T15:54:56+08:00",
      "bind_uid": 100000,
      "mode": "HTTP",
      "need_end_level": 1,
      "service_message": "…",
      "status": "ok"
    },
    "level_warning": false
  }
}
```

| 字段 | 说明 |
| --- | --- |
| `configured` | `FANVERIFY_ACCESS_TOKEN` 是否已配置 |
| `ok` | 本次自检是否成功（**注意它和 HTTP 状态码是两回事**，见下） |
| `error` | 失败原因文案，成功时为 `null` |
| `base_url` | 实际使用的接口根地址（配错 `FANVERIFY_API_BASE` 时一眼可见） |
| `developer` | `devinfo` 的返回（`issued_at` / `bind_uid` / `mode` / `need_end_level` / `service_message` / `status`），失败时为 `null` |
| `level_warning` | 本站 `FANVERIFY_REQUIRED_LEVEL` 低于 FanVerify 要求的 `need_end_level` 时为 `true`（提醒本站门槛会被上游先拦下） |

**自检失败时依然返回 HTTP 200，只是 `data.ok` 为 `false`** —— 自检失败不是服务器错误，
而是"这个外部接口现在不可用"，前端据此显示一条错误提示即可：

```json
{
  "ok": true,
  "data": {
    "configured": true,
    "ok": false,
    "error": "FanVerify 拒绝了本次调用（401 未授权）。请检查 FANVERIFY_ACCESS_TOKEN 是否有效…",
    "base_url": "https://api.fanverify.cn",
    "developer": null
  }
}
```

令牌根本没配时同理：`{"configured": false, "ok": false, "error": "FANVERIFY_ACCESS_TOKEN 未配置", "developer": null}`。

响应一律带 `Cache-Control: no-store`。真正的失败只有鉴权：未登录 → 401 `unauthorized`；非管理员 → 403 `forbidden`。

> ⚠ 源码里这个文件的头部注释把返回字段写成了 `{configured, ok, token_hint, developer, error}`，
> 其中 **`token_hint` 实际并未返回**（实现返回的是 `base_url` 与 `level_warning`）——
> 以本节与 `passport/api/v1/fanverify-status.php` 的代码为准，该注释是陈旧的。

---

## 十一、错误码表

### 11.1 业务错误（统一格式 `{"ok":false,"error":{...}}`）

| `error.code` | HTTP | 触发场景 |
| --- | --- | --- |
| `invalid_request` | 422 | 参数校验失败；`error.details.field` 指出出错字段（用户名/邮箱/密码/玩家名/验证码/缺少 name 等）；FanVerify 扫码未确认或已超时、FanVerify 账号等级不足门槛也归此类 |
| `unauthorized` | 401 | 未登录；缺少或无效的 Bearer 令牌；令牌对应的通行证已不可用 |
| `forbidden` | 403 | 权限不足，例如令牌没有 `directory` scope；FanVerify 侧返回 403（权限或额度不足，或该用户未被本开发者验证过）时原样透传 |
| `not_found` | 404 | 玩家或邦国不存在 |
| `conflict` | 409 | 用户名 / 邮箱 / 游戏内玩家名 / 简幻通 ID / FanVerify 账号ID 已被占用 |
| `rate_limited` | 429 | 应用调用频率超限（**不含令牌端点**，`POST /oauth/token` 见 11.2 的 `temporarily_unavailable`）；邮箱验证码发送过于频繁；FanVerify 侧返回 429 时原样透传 |
| `not_implemented` | 501 | 对应外部接口尚未接入（邮箱验证码 / 游戏内玩家 / 邦国 / 简幻通）；或 **FanVerify 的 `FANVERIFY_ACCESS_TOKEN` 未配置**（FanVerify 本身已接入，这不是"接口待开发"） |
| `server_error` | 500 | 服务器内部错误；外部权威接口异常或返回无法解析的数据 |

> **FanVerify 的错误映射**（源码 `FanVerifyClient::httpError()` / `assertTransport()`，只影响用户主动发起
> FanVerify 绑定时的响应）：
>
> | 上游情况 | 我们返回 |
> | --- | --- |
> | `401 {"error":"Unauthorized"}` | **500 `server_error`**，文案带排查提示：检查令牌是否有效、是否已在 FanVerify 开发者后台启用、本服务器出口 IP 是否在令牌白名单内 |
> | 404 | **500 `server_error`**「FanVerify 接口路径不存在（404）：<端点>。这通常意味着 `FANVERIFY_API_BASE` 配错了，或 FanVerify 改了接口路径」—— 与 401 刻意区分开：FanVerify **先校验路径再鉴权**，所以 401 说明路径是对的、问题在令牌侧，404 才是我们的路径问题 |
> | 403 | 403 `forbidden`（带上游的 `message`，没有就用默认文案） |
> | 429 | 429 `rate_limited`「FanVerify 请求过于频繁，请稍后重试」（⚠ 实测上游限流其实是 **200 + `{"status":"rate_limit"}`**，429 只是兜底路径） |
> | 连不上 / 超时 / DNS 失败 / cURL 扩展缺失 | 500 `server_error`「FanVerify 服务暂时不可用（具体原因）」 |
> | 200 但 JSON 无法解析、或 `data` 里没有有效 `uid` | 500 `server_error` |
> | `user_verify` 的 `status` 不是 `ok` | 422 `invalid_request`「FanVerify 验证失败：账号ID或动态验证码不正确」（`details.field = fanverify_code`） |
> | `getuserdata` 的 403（该用户没被本开发者验证过） | 在客户端内部映射为 `null`，不抛错 |
>
> ⚠ **`user_verify` 的状态码语义与其它端点不同，且官方文档没写**（实测得出，见 `FanVerifyClient::userVerifyError()`）：
>
> | 上游响应 | 真实含义 | 我们翻成 |
> | --- | --- | --- |
> | `403 {"error":"Forbidden"}` | uid 有效但动态验证码不对 | 422「FanVerify 动态验证码不正确，请在微信小程序里重新获取后重试」 |
> | `404 {"error":"Not Found"}` | **uid 不存在** | 422「FanVerify 账号不存在，请检查账号ID是否填写正确」 |
> | `400 {"error":"Bad Request"}` | 参数格式错（uid 非数字、验证码为空） | 422「FanVerify 拒绝了请求参数：账号ID需为数字，动态验证码不能为空」 |
>
> ⚠ 403 还有第二种可能：如果 FanVerify 后台给令牌设了 `need_end_level > 0`，
> **账号等级不足也会返回同样的 403**，此时无法与"验证码错误"区分。
> 当前该令牌的 `need_end_level` 已设为 `0`，所以 403 只有一种含义、文案按单一原因写；
> 若日后调高门槛，需要把文案改回"同时覆盖两种可能"。
>
> 这就是为什么 `user_verify` **不能**复用通用错误翻译：通用逻辑会把它的 404 说成
> 「接口路径不存在，可能 `FANVERIFY_API_BASE` 配错了」——而实际含义是"这个 FanVerify 账号不存在"。
>
> 排错入口有两个：后台「接口接入状态」卡片上 FanVerify 那一行的「自检」按钮（`GET /passport/api/v1/fanverify-status`，见 10.5），
> 以及服务器上的 `php bin/fanverify-check.php`（见 `passport/README.md` 7.5）。

### 11.2 OAuth 2.0 错误（RFC 6749 §5.2 格式 `{"error":"…","error_description":"…"}`）

出现在 `/oauth/token`、`/oauth/introspect`、`/oauth/revoke`，以及授权端点的重定向参数中。

| `error` | HTTP / 位置 | 触发场景 |
| --- | --- | --- |
| `invalid_request` | 400 | 缺少 `client_id` / `code` / `refresh_token`；非 POST 调用令牌端点；`redirect_uri` 不在白名单；公开客户端缺 `code_challenge`；不支持的 `code_challenge_method` |
| `invalid_client` | 401 | 未知 `client_id`、客户端认证失败、应用已停用 |
| `unauthorized_client` | 401 / 403 | 公开客户端使用了不允许的 `grant_type`（401）；授权请求里应用已被停用（403） |
| `invalid_grant` | 400 | 授权码或刷新令牌无效/过期/已使用；授权码不属于该客户端；`redirect_uri` 与授权请求不一致；PKCE 校验失败 |
| `unsupported_grant_type` | 400 | `grant_type` 不是三种受支持值之一 |
| `unsupported_response_type` | 重定向 | 授权请求里 `response_type` 不是 `code` |
| `invalid_scope` | 400 / 重定向 | scope 未知、与 `allowed_scopes` 无交集、刷新时试图扩大范围、`client_credentials` 申请非 `directory` |
| `invalid_token` | 403 | `/oauth/userinfo` 收到不绑定任何用户的令牌（`client_credentials` 令牌） |
| `temporarily_unavailable` | 429 | 应用调用频率超限，且请求打的是**令牌端点** `POST /oauth/token`（协议端点必须用 RFC 6749 格式，否则第三方 SDK 无法按协议解析） |
| `access_denied` | 重定向 | 用户在授权确认页点了「拒绝」 |

> ⚠ 限流错误的格式**取决于端点**，客户端需要两种都能处理：
>
> - **令牌端点 `POST /oauth/token`** 走 RFC 6749 格式：
>   `HTTP 429` + `{"error":"temporarily_unavailable","error_description":"该应用调用频率超限（每分钟 N 次）"}`；
> - **`/oauth/introspect`、`/oauth/revoke`**，以及资源端点（`/oauth/userinfo`、`/passport/api/v1/player`、
>   `/passport/api/v1/country`）仍走统一格式：
>   `HTTP 429` + `{"ok":false,"error":{"code":"rate_limited","message":"该应用调用频率超限（每分钟 N 次）"}}`。
>
> 也就是说：判断失败时要先看响应体里是 `error`（OAuth 格式，取 `error` 字段）还是 `error.code`
> （统一格式），不能只按一种结构解析。邮箱验证码的限流（429 `rate_limited`）不受此影响，始终是统一格式。

---

## 十二、限流

- 每个应用有独立的每分钟调用上限，存在 `passport_oauth_clients.rate_limit`，**默认 600 次/分钟**。
  创建应用时可通过 `rate_limit` 参数指定（允许范围 0–100000）。
- `rate_limit <= 0` 表示**不限流**。
- 计数方式（`ClientRepository::callsInLastMinute()`）：

  ```sql
  SELECT COUNT(*) FROM `passport_api_logs`
  WHERE `client_id` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
  ```

  即统计该 `client_id` 最近 60 秒内在 `passport_api_logs` 里的调用记录数；
  当前值 `>= rate_limit` 时抛限流错误，**格式随端点而变**（源码 `OAuthServer::assertRateLimit()` 的 `$oauthStyle` 参数）：

  | 端点 | 错误格式 |
  | --- | --- |
  | `POST /oauth/token` | RFC 6749：`HTTP 429` + `{"error":"temporarily_unavailable","error_description":"该应用调用频率超限（每分钟 N 次）"}` |
  | `/oauth/introspect`、`/oauth/revoke` | 统一格式：`HTTP 429` + `{"ok":false,"error":{"code":"rate_limited","message":"该应用调用频率超限（每分钟 N 次）"}}` |
  | 资源端点（`/oauth/userinfo`、`/passport/api/v1/player`、`/passport/api/v1/country`） | 同上的统一格式 `rate_limited` |

- 计数口径的两个事实，排错时用得上：
  1. 访问日志是**在响应发出之后**写入的，因此当前这次请求本身还没被计入；
  2. 只有标记了客户端上下文的请求才会写入 `client_id`——即带 Bearer 令牌调用资源接口
     （`/passport/api/v1/player`、`/passport/api/v1/country`）和 `/oauth/userinfo` 时；
     `/oauth/token`、`/oauth/introspect`、`/oauth/revoke` 的调用记录 `client_id` 为 `NULL`，不参与限流计数。
- 邮箱验证码另有独立限流（与 scope 无关）：同一邮箱同一场景 **60 秒**内不能重发
  （超限文案「验证码已发送，请 N 秒后再试」），**每小时最多 5 次**
  （超限返回 429 `rate_limited`「该邮箱一小时内发送次数过多，请稍后再试」）。
  两者都是统一格式，与 OAuth 协议端点无关。

---

## 十三、可直接运行的示例

> 把所有占位值替换成你自己的值：
> `YOUR_CLIENT_ID`、`YOUR_CLIENT_SECRET`、`https://your-app.example.com/callback`（必须与登记的完全一致）。

### 13.1 授权码流程（机密客户端，cURL）

**第 1 步：在浏览器打开授权页**（无法用 curl 完成，因为需要用户登录并点击同意）

```
https://8w.bgjq.top/oauth/authorize?client_id=YOUR_CLIENT_ID&redirect_uri=https%3A%2F%2Fyour-app.example.com%2Fcallback&response_type=code&scope=basic%20player%20country&state=xyz123
```

**第 2 步：用户同意后，浏览器被重定向到**

```
https://your-app.example.com/callback?code=REPLACE_WITH_CODE&state=xyz123
```

**第 3 步：用授权码换令牌**

```bash
curl -sS -X POST https://8w.bgjq.top/oauth/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=authorization_code' \
  -d 'code=REPLACE_WITH_CODE' \
  -d 'redirect_uri=https://your-app.example.com/callback' \
  -d 'client_id=YOUR_CLIENT_ID' \
  -d 'client_secret=YOUR_CLIENT_SECRET'
```

也可以用 Basic 认证代替请求体里的凭据：

```bash
curl -sS -u 'YOUR_CLIENT_ID:YOUR_CLIENT_SECRET' \
  -X POST https://8w.bgjq.top/oauth/token \
  -d 'grant_type=authorization_code' \
  -d 'code=REPLACE_WITH_CODE' \
  -d 'redirect_uri=https://your-app.example.com/callback'
```

返回示例：

```json
{
  "access_token": "…",
  "token_type": "Bearer",
  "expires_in": 7200,
  "scope": "basic player country",
  "refresh_token": "…"
}
```

**第 4 步：读取用户信息**

```bash
curl -sS https://8w.bgjq.top/oauth/userinfo \
  -H 'Authorization: Bearer REPLACE_WITH_ACCESS_TOKEN'
```

**第 5 步：刷新令牌**（注意新返回的 `refresh_token` 会替换旧的）

```bash
curl -sS -X POST https://8w.bgjq.top/oauth/token \
  -d 'grant_type=refresh_token' \
  -d 'refresh_token=REPLACE_WITH_REFRESH_TOKEN' \
  -d 'client_id=YOUR_CLIENT_ID' \
  -d 'client_secret=YOUR_CLIENT_SECRET'
```

**第 6 步：吊销令牌**

```bash
curl -sS -i -X POST https://8w.bgjq.top/oauth/revoke \
  -d 'token=REPLACE_WITH_ACCESS_TOKEN' \
  -d 'client_id=YOUR_CLIENT_ID' \
  -d 'client_secret=YOUR_CLIENT_SECRET'
# HTTP/1.1 200，响应体为 {}
```

**内省令牌是否有效**

```bash
curl -sS -X POST https://8w.bgjq.top/oauth/introspect \
  -d 'token=REPLACE_WITH_ACCESS_TOKEN' \
  -d 'token_type_hint=access_token' \
  -d 'client_id=YOUR_CLIENT_ID' \
  -d 'client_secret=YOUR_CLIENT_SECRET'
```

### 13.2 授权码流程（公开客户端 + PKCE，浏览器 JavaScript）

纯前端应用没有 `client_secret`，**必须在授权请求里带 `code_challenge`**。

```html
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>PKCE 示例</title></head>
<body>
<button id="login">用 8W通行证登录</button>
<pre id="out"></pre>

<script>
// 把下面几项换成你自己的值
const CLIENT_ID    = 'YOUR_CLIENT_ID';                                  // 公开客户端（创建时不要勾选“机密客户端”）
const REDIRECT_URI = 'https://your-app.example.com/callback';           // 必须与登记的完全一致
const SCOPE        = 'basic player';
const AUTHORIZE    = 'https://8w.bgjq.top/oauth/authorize';
const TOKEN        = 'https://8w.bgjq.top/oauth/token';
const USERINFO     = 'https://8w.bgjq.top/oauth/userinfo';

const out = document.getElementById('out');
const b64url = (bytes) => btoa(String.fromCharCode(...bytes))
  .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function makeVerifier() {
  // 32 字节随机 → base64url 后正好 43 个字符，满足 43–128 的长度要求
  return b64url(crypto.getRandomValues(new Uint8Array(32)));
}

async function challengeOf(verifier) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return b64url(new Uint8Array(digest));
}

document.getElementById('login').addEventListener('click', async () => {
  const verifier = await makeVerifier();
  sessionStorage.setItem('pkce_verifier', verifier);

  const state = b64url(crypto.getRandomValues(new Uint8Array(16)));
  sessionStorage.setItem('oauth_state', state);

  const url = new URL(AUTHORIZE);
  url.searchParams.set('client_id', CLIENT_ID);
  url.searchParams.set('redirect_uri', REDIRECT_URI);
  url.searchParams.set('response_type', 'code');
  url.searchParams.set('scope', SCOPE);
  url.searchParams.set('state', state);
  url.searchParams.set('code_challenge', await challengeOf(verifier));
  url.searchParams.set('code_challenge_method', 'S256');

  window.location.href = url.toString();
});

// 回调页里执行：换取令牌并读取用户信息
(async function handleCallback() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('error')) {
    out.textContent = '授权失败：' + params.get('error') + ' / ' + (params.get('error_description') || '');
    return;
  }
  const code = params.get('code');
  if (!code) { return; }

  // 防 CSRF：state 必须与发起时一致
  if (params.get('state') !== sessionStorage.getItem('oauth_state')) {
    out.textContent = 'state 不匹配，已中止';
    return;
  }

  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    code: code,
    redirect_uri: REDIRECT_URI,
    client_id: CLIENT_ID,
    code_verifier: sessionStorage.getItem('pkce_verifier') || ''
  });

  const tokenRes = await fetch(TOKEN, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  });
  const token = await tokenRes.json();
  if (!tokenRes.ok) {
    out.textContent = '换取令牌失败：' + JSON.stringify(token);
    return;
  }

  const userRes = await fetch(USERINFO, {
    headers: { 'Authorization': 'Bearer ' + token.access_token }
  });
  const user = await userRes.json();

  out.textContent = JSON.stringify({ token, user }, null, 2);
})();
</script>
</body>
</html>
```

### 13.3 client_credentials 流程 + 查玩家 + 查邦国（cURL）

```bash
#!/usr/bin/env bash
set -euo pipefail

BASE='https://8w.bgjq.top'
CLIENT_ID='YOUR_CLIENT_ID'
CLIENT_SECRET='YOUR_CLIENT_SECRET'

# 1) 取机器令牌（只能申请 directory）
TOKEN_JSON=$(curl -sS -X POST "$BASE/oauth/token" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=client_credentials' \
  -d 'scope=directory' \
  -d "client_id=$CLIENT_ID" \
  -d "client_secret=$CLIENT_SECRET")

echo "令牌响应：$TOKEN_JSON"

ACCESS_TOKEN=$(printf '%s' "$TOKEN_JSON" | sed -n 's/.*"access_token":"\([^"]*\)".*/\1/p')
if [ -z "$ACCESS_TOKEN" ]; then
  echo '未取到 access_token，请检查上面的响应' >&2
  exit 1
fi

# 2) 查游戏内玩家（fresh=1 强制回源）
curl -sS -G "$BASE/passport/api/v1/player" \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  --data-urlencode 'name=LouieMAIN' \
  --data-urlencode 'fresh=1'

# 3) 按 ID 查邦国
curl -sS -G "$BASE/passport/api/v1/country" \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  --data-urlencode 'id=7'

# 4) 按名称查邦国（服务端需已配置 COUNTRY_API_PATH_BY_NAME）
curl -sS -G "$BASE/passport/api/v1/country" \
  -H "Authorization: Bearer $ACCESS_TOKEN" \
  --data-urlencode 'name=大周'

# 5) 内省：确认令牌仍然有效
curl -sS -X POST "$BASE/oauth/introspect" \
  -d "token=$ACCESS_TOKEN" \
  -d 'token_type_hint=access_token' \
  -d "client_id=$CLIENT_ID" \
  -d "client_secret=$CLIENT_SECRET"
```

### 13.4 服务端 JavaScript（Node.js 18+）示例

```js
// server.mjs —— 机密客户端的完整后端流程：换令牌 → 读用户信息 → 查玩家
const BASE = 'https://8w.bgjq.top';
const CLIENT_ID = 'YOUR_CLIENT_ID';
const CLIENT_SECRET = 'YOUR_CLIENT_SECRET';
const REDIRECT_URI = 'https://your-app.example.com/callback';

/** 第 1 步：生成授权链接（把它 302 给浏览器） */
export function authorizeUrl(state) {
  const url = new URL(BASE + '/oauth/authorize');
  url.searchParams.set('client_id', CLIENT_ID);
  url.searchParams.set('redirect_uri', REDIRECT_URI);
  url.searchParams.set('response_type', 'code');
  url.searchParams.set('scope', 'basic player country');
  url.searchParams.set('state', state);
  return url.toString();
}

/** 第 2 步：回调里用 code 换令牌 */
export async function exchangeCode(code) {
  const res = await fetch(BASE + '/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code,
      redirect_uri: REDIRECT_URI,
      client_id: CLIENT_ID,
      client_secret: CLIENT_SECRET
    })
  });
  const json = await res.json();
  if (!res.ok) {
    // 失败时是 RFC 6749 格式：{ error, error_description }
    throw new Error(`换取令牌失败：${json.error} / ${json.error_description}`);
  }
  return json; // { access_token, token_type, expires_in, scope, refresh_token }
}

/** 第 3 步：读用户信息（按 scope 裁剪） */
export async function userinfo(accessToken) {
  const res = await fetch(BASE + '/oauth/userinfo', {
    headers: { Authorization: 'Bearer ' + accessToken }
  });
  const json = await res.json();
  if (!res.ok) {
    // 业务接口失败时是统一格式：{ ok:false, error:{ code, message } }
    throw new Error(`读取用户信息失败：${json.error?.code} / ${json.error?.message}`);
  }
  return json.data; // { sub, username, role, email, player:{...}, country_id, ... }
}

/** 第 4 步：查游戏内玩家（需要 directory scope 的令牌） */
export async function findPlayer(accessToken, name, fresh = false) {
  const url = new URL(BASE + '/passport/api/v1/player');
  url.searchParams.set('name', name);
  if (fresh) { url.searchParams.set('fresh', '1'); }

  const res = await fetch(url, { headers: { Authorization: 'Bearer ' + accessToken } });
  const json = await res.json();
  if (!res.ok) {
    throw new Error(`查询玩家失败：${json.error?.code} / ${json.error?.message}`);
  }
  return json.data; // { player, source, country? }
}
```

> 机密客户端的 `client_secret` **绝不能出现在浏览器里**：换令牌、刷新、内省、吊销都必须在你的服务端完成，
> 浏览器只拿授权码和最终的用户信息。纯前端应用请改用 13.2 的 PKCE 方案（公开客户端）。

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
10. [数据查询接口](#十数据查询接口)
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
| GET | `/passport/api/v1/me` | 当前通行证（需通行证会话 Cookie，非第三方接口） |
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

| scope | 含义（源码原文） |
| --- | --- |
| `basic` | 通行证UID、用户名、站内角色 |
| `email` | 验证邮箱与邮箱验证状态 |
| `player` | 游戏内玩家名、玩家ID、所属邦国ID |
| `country` | 所属邦国ID（与 player 重复，供只关心邦国的应用使用） |
| `simpass` | 简幻通ID与等级 |
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
    }
  }
}
```

| 字段 | 需要的 scope | 类型 | 说明 |
| --- | --- | --- | --- |
| `sub` | 无（总是返回） | string | 通行证 UID，等于 `passport_accounts.id` |
| `username` | `basic` | string | 通行证用户名 |
| `role` | `basic` | string | 站内角色：`observer` / `diplomat` / `peacekeeper` / `permanent_member` / `secretary_general` |
| `email` | `email` | string | 验证邮箱 |
| `email_verified` | `email` | bool | 邮箱是否已验证（`email_verified_at` 非 NULL） |
| `player.player_name` | `player` | string | 游戏内玩家名（权威主键） |
| `player.player_id` | `player` | int \| null | 游戏内玩家 ID（权威缓存） |
| `player.country_id` | `player` | int \| null | 所属邦国 ID（权威缓存） |
| `country_id` | `country` | int \| null | 所属邦国 ID（与 `player.country_id` 同值） |
| `simpass.uid` | `simpass` | int \| null | 简幻通 ID |
| `simpass.level` | `simpass` | int \| null | 简幻通等级 |

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

## 十一、错误码表

### 11.1 业务错误（统一格式 `{"ok":false,"error":{...}}`）

| `error.code` | HTTP | 触发场景 |
| --- | --- | --- |
| `invalid_request` | 422 | 参数校验失败；`error.details.field` 指出出错字段（用户名/邮箱/密码/玩家名/验证码/缺少 name 等） |
| `unauthorized` | 401 | 未登录；缺少或无效的 Bearer 令牌；令牌对应的通行证已不可用 |
| `forbidden` | 403 | 权限不足，例如令牌没有 `directory` scope |
| `not_found` | 404 | 玩家或邦国不存在 |
| `conflict` | 409 | 用户名 / 邮箱 / 游戏内玩家名 / 简幻通 ID 已被占用 |
| `rate_limited` | 429 | 应用调用频率超限（**不含令牌端点**，`POST /oauth/token` 见 11.2 的 `temporarily_unavailable`）；邮箱验证码发送过于频繁 |
| `not_implemented` | 501 | 对应外部接口尚未接入（邮箱验证码 / 游戏内玩家 / 邦国 / 简幻通） |
| `server_error` | 500 | 服务器内部错误；外部权威接口异常或返回无法解析的数据 |

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

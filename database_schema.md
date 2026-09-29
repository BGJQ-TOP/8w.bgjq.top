# 8W社区 · 数据库结构说明

> **唯一真源是 `database/8w_passport.sql`**，本文档只是索引与设计意图说明。
> 结构有出入时以 SQL 文件为准。
>
> 新库名：`bgjq8w`（旧的 `bgjq` 已废弃，清理方式见 `docs/MIGRATION.md`）

---

## 设计原则

1. **身份只有一个真源**：`passport_accounts`。社区各业务表一律引用它的 `id`。
2. **权威数据只做缓存**：游戏内玩家与邦国信息由第三方权威服务提供，
   本地表存的是缓存，随时可被覆盖。
   - 权威主键：**玩家名**（`players.player_name`）、**邦国ID**（`countries.id`）
   - 除这两个键以外的字段都是缓存，不得当作真值使用
3. **country_id 上刻意不加外键**：缓存可能先于权威数据落库，
   加外键会导致合法的玩家记录写不进来。
4. **令牌只存哈希**：会话令牌、OAuth 令牌、验证码一律存 SHA-256。

---

## 一、8W通行证 · 身份域

| 表 | 说明 |
|---|---|
| `passport_accounts` | 通行证账号。第三方拿到的 `sub` 就是这里的 `id` |
| `passport_sessions` | 登录会话，只存令牌哈希 |
| `passport_email_codes` | 邮箱验证码，只存验证码哈希，含失败次数与消费标记 |

### `passport_accounts` 关键字段

账号上的绑定分两类：

- **必填且不可解绑**：游戏内玩家名（权威身份主键）、简幻通ID（默认的账号找回通道）
- **可选绑定**：验证邮箱、FanVerify 账号 —— 用户自己决定绑不绑，随时可绑可解

| 字段 | 说明 |
|---|---|
| `id` | 通行证UID（对外唯一标识） |
| `username` / `password_hash` | 登录凭据 |
| `email` | 验证邮箱，**可选绑定，NULL = 未绑定**（不是空串） |
| `email_verified_at` | 邮箱验证通过时间，NULL 表示未绑定或未验证 |
| `simpass_uid` / `simpass_level` / `simpass_verified_at` | 简幻通身份（注册必填） |
| `fanverify_uid` | FanVerify 账号ID，**可选绑定，NULL = 未绑定** |
| `fanverify_level` | FanVerify 等级（权威缓存；接口里是字符串，代码转成 `int`，NULL = 未绑定或未返回） |
| `fanverify_tag` | FanVerify 风险标签（权威缓存，`VARCHAR(64)`；空串表示无标签，代码与落库都归一成 `NULL`） |
| `fanverify_verified_at` | FanVerify 验证通过时间 |
| `player_name` | **游戏内玩家名（权威主键，注册必填）** |
| `player_id` | 玩家ID（权威缓存） |
| `country_id` | 玩家所属邦国ID（权威缓存） |
| `role` / `status` | 站内角色 / 账号状态 |

### `passport_accounts` 唯一索引

| 索引 | 字段 | 说明 |
|---|---|---|
| `uk_username` | `username` | 登录名唯一 |
| `uk_email` | `email` | 邮箱全局唯一 |
| `uk_simpass_uid` | `simpass_uid` | 简幻通ID全局唯一 |
| `uk_fanverify_uid` | `fanverify_uid` | FanVerify 账号ID全局唯一 |
| `uk_player_name` | `player_name` | 游戏内玩家名唯一 |

**唯一索引允许多个 NULL**，所以 `uk_email` 与 `uk_fanverify_uid` 上的"可选绑定"不会互相冲突：
多个未绑定邮箱（或未绑定 FanVerify）的账号可以共存，一旦绑定则必须全局唯一。
也正因如此，代码里**未绑定时一律落 `NULL` 而不是空串**——空串会占用唯一索引，第二个不填邮箱的账号就注册不了了。

---

## 二、第三方接入 / API 分发（OAuth 2.0）

| 表 | 说明 |
|---|---|
| `passport_oauth_clients` | 第三方应用：client_id、密钥哈希、回调白名单、允许的 scope、限流 |
| `passport_oauth_codes` | 授权码（一次性、短有效期，含 PKCE challenge） |
| `passport_oauth_tokens` | 访问令牌 / 刷新令牌（均只存哈希） |
| `passport_api_logs` | 第三方 API 调用日志，限流与审计依据 |

协议支持：`authorization_code`（含 PKCE）、`refresh_token`（轮换式）、`client_credentials`（仅 `directory` scope）。

---

## 三、权威数据缓存域

### `countries` —— 邦国

| 字段 | 来源 | 说明 |
|---|---|---|
| `id` | **权威** | 邦国ID |
| `name` | 缓存 | 邦国名称 |
| `declaration` | 缓存 | 邦国宣言 |
| `territory_chunks` | 缓存 | 邦国领土大小（Chunk 数） |
| `population` | 缓存 | 邦国人口（玩家列表长度） |
| `government_type` / `flag_url` / `is_active` | 站内 | 社区补充字段，同步权威数据时**不会**被覆盖 |
| `synced_at` | — | 最近同步时间，决定缓存是否过期 |

### `players` —— 游戏内玩家

**邦国玩家列表**与**玩家信息缓存**共用这一张表：

```sql
-- 玩家信息缓存
SELECT player_name, player_id, country_id FROM players WHERE player_name = ?;

-- 邦国玩家列表
SELECT player_name, player_id FROM players WHERE country_id = ?;
```

只保留规格要求的三个业务字段：玩家名、玩家ID、玩家所属邦国ID。

---

## 四、社区域

身份列一律引用 `passport_accounts(id)`，列名保持与旧库一致，
以便旧接口文件在不改动的情况下继续读数据。

| 表 | 说明 |
|---|---|
| `news` | 世界动态（`author_id`） |
| `timeline` | 历史时间轴 |
| `proposals` | 社区大会提案（`proposer_id`、`country_id`） |
| `votes` | 表决记录（`user_id`、`proposal_id`，唯一键防重复投票） |
| `conventions` | 世界公约（`enacted_by_user_id`） |
| `cases` | 国际法庭案件（`plaintiff_id`、`defendant_country_id`） |
| `case_evidence` | 案件证据 |
| `arbitration_archive` | 仲裁结果库（判例） |
| `diplomatic_relations` | 外交关系（国家对唯一） |
| `trades` | 贸易信息（`posted_by_user_id`） |
| `services` | 公共服务 |

---

## 五、遗留兼容层（迁移期使用，后续版本移除）

| 对象 | 为什么还在 | 计划 |
|---|---|---|
| `users`（**视图**） | 旧接口里大量 `LEFT JOIN users` 依赖它，视图让读路径零改动 | 待全部读路径迁移后移除 |
| `online_players` | 旧的在线缓存表，新架构以 `passport_sessions.last_seen_at` 为准 | 移除 |
| `api_keys` | 旧的静态 API Key，新架构统一走 OAuth2 | 迁移到 `passport_oauth_clients` 后移除 |
| `api_logs` | 旧调用日志，已被 `passport_api_logs` 取代 | 移除 |

`users` 视图字段映射（共 12 列）：

```
id, username, game_id(=player_name), player_id,
country_id, role, jhtuid(=simpass_uid), level(=simpass_level),
email, status, last_login_at, created_at
```

⚠ 视图**刻意不暴露密码哈希**（没有 `password(=password_hash)` 这一列）：视图一旦带上哈希，
旧代码里的 `SELECT u.*` 就会把它顺手返回给前端。需要校验密码的代码直接读 `passport_accounts.password_hash`。

⚠ 视图是**只读**兼容层。写入请走通行证 API（`/passport/api/v1/*`）。

---

## 字符集

所有表统一：

```sql
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

与 `.env` 中的 `DB_CHARSET=utf8mb4` 一致。

# 8W通行证重构 · 数据迁移与上线指南

> 面向运维与部署人员。本文说明这次重构改了什么、怎么上线、哪些是过渡期兼容层、什么时候能删。
> 字段与结构以 `database/8w_passport.sql` 为唯一真源，维护者视角见 [`passport/README.md`](../passport/README.md)，
> 第三方接入见 [`docs/PASSPORT-API.md`](PASSPORT-API.md)。

---

## 目录

1. [这次重构做了什么](#一这次重构做了什么)
2. [数据库迁移：旧库 bgjq → 新库 bgjq8w](#二数据库迁移旧库-bgjq--新库-bgjq8w)
3. [身份表迁移：users → passport_accounts](#三身份表迁移users--passport_accounts)
4. [已迁移到通行证的代码清单](#四已迁移到通行证的代码清单)
5. [前端变化](#五前端变化)
6. [遗留兼容层清单与移除计划](#六遗留兼容层清单与移除计划)
7. [上线检查清单](#七上线检查清单)

---

## 一、这次重构做了什么

| 维度 | 重构前 | 重构后 |
| --- | --- | --- |
| 数据库 | 旧库 `bgjq`，账号 `bgjq@localhost` | 新库 `bgjq8w`，账号 `bgjq8w@localhost` |
| 身份表 | `users` 表（可读写） | `passport_accounts` 表（唯一真源）+ `users` **只读视图**（兼容层） |
| 认证实现 | `php/classes/Auth.php` 自己写 SQL、自己管 `$_SESSION` | `Auth` 退化为兼容层，内部全部委托通行证；登录态以通行证 Cookie 为准 |
| 第三方接入 | 静态 API Key（`api_keys` + `api_logs` + `X-API-Key`） | OAuth 2.0（`passport_oauth_clients` / `passport_oauth_codes` / `passport_oauth_tokens` / `passport_api_logs`） |
| 在线状态 | `online_players` 表 | `passport_sessions.last_seen_at`（`expires_at > NOW()` 且 5 分钟内活跃） |
| 游戏内数据 | 各页面各自调外部接口 | `players` / `countries` 本地权威缓存，统一由 `passport/src/Directory/` 管理 |
| 注册入口 | 主站与后台各有一个注册弹窗 | 只有 `/passport/` 一处 |
| 简幻通校验 | 散落在旧接口里 | `passport/src/Verification/HttpSimpassVerifier.php`，注册时校验一次 |
| 接口风格 | `{success, message, data}` | 通行证新接口统一 `{"ok":true,"data":…}` / `{"ok":false,"error":{…}}`（旧接口保留旧格式） |

`database/8w_passport.sql` 里的全部表分四个域：

| 域 | 表 |
| --- | --- |
| 通行证身份域 | `passport_accounts`、`passport_sessions`、`passport_email_codes` |
| 第三方接入（OAuth 2.0） | `passport_oauth_clients`、`passport_oauth_codes`、`passport_oauth_tokens`、`passport_api_logs` |
| 权威数据缓存域 | `countries`、`players` |
| 社区域 | `news`、`timeline`、`proposals`、`votes`、`conventions`、`cases`、`case_evidence`、`arbitration_archive`、`diplomatic_relations`、`trades`、`services` |
| 遗留兼容层 | `online_players`、`api_keys`、`api_logs`（+ `users` 视图） |

共 23 张表 + 1 个视图。

---

## 二、数据库迁移：旧库 bgjq → 新库 bgjq8w

### 2.1 迁移前必做：完整备份

```bash
mysqldump -u root -p --databases bgjq > bgjq_backup_$(date +%F).sql
```

旧库 `bgjq` 在新库验证通过之前**一直保留**，脚本也不会自动删它。

### 2.2 标准步骤

**第 1 步：改 `.env`**

```dotenv
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bgjq8w          # 不能再用 bgjq
DB_USER=bgjq8w
DB_PASS=<新账号的真实密码>
```

`.env` 已被 `.gitignore` 排除（`.env`、`.env.*`，仅保留 `!.env.example`）。
`.env.example` 里只允许出现占位值，绝不允许出现任何真实密钥。

**第 2 步：初始化新库**

```powershell
# 推荐：交互式输入 MySQL 管理员密码（不留在命令历史里）
pwsh ./bin/init-database.ps1

# 只想看看渲染出来的 SQL：不导入，保留临时文件供人工检查
pwsh ./bin/init-database.ps1 -DryRun

# 管理员不是 root / MySQL 不在本机 / mysql 客户端不在 PATH
pwsh ./bin/init-database.ps1 -RootUser root -MysqlHost localhost -MysqlExe "C:\mysql\bin\mysql.exe"
```

脚本参数：`-RootUser`（默认 `root`）、`-RootPassword`（不传则交互式提示）、`-MysqlHost`（默认 `localhost`）、
`-MysqlExe`（默认 `mysql`）、`-DryRun`（只渲染不导入）。

**第 3 步：脚本会做什么**

1. 校验 `database/8w_passport.sql` 与项目根 `.env` 都存在；
2. 解析 `.env`，要求 `DB_NAME` / `DB_USER` / `DB_PASS` 三项非空；
3. **安全闸门**（命中直接抛错退出）：
   - `DB_NAME` 仍是旧库名 `bgjq` → 「DB_NAME 仍是已废弃的旧库名 bgjq，请先改成一个新库名。」
   - `DB_PASS` 仍是占位符 `__DB_PASSWORD__` → 「.env 里的 DB_PASS 还是占位符，请填入真实密码。」
4. 渲染 SQL 模板（模板里不含任何真实密码）：
   - `__DB_PASSWORD__` → `DB_PASS` 的值
   - `` `bgjq8w` `` → `` `<DB_NAME>` ``
   - `'bgjq8w'@'localhost'` → `'<DB_USER>'@'localhost'`
5. 渲染结果写到**系统临时目录**（UTF-8 无 BOM，避免 mysql 客户端把 BOM 当语法错误），文件名形如
   `%TEMP%\8w_passport_<32位随机>.sql`，**绝不落在仓库里**；
6. 通过 `MYSQL_PWD` 环境变量传递管理员密码（不出现在进程命令行），执行
   `mysql --host=… --user=… --default-character-set=utf8mb4 --execute="source <渲染文件>"`；
7. 无论成功失败，`finally` 块都会删除临时文件（`-DryRun` 时保留并提示手动删除）。

导入完成后脚本会提示：确认 `.env` 与导入结果一致，再访问站点验证；旧库仍保留。

**第 4 步：验证新库**

```sql
USE bgjq8w;
SHOW TABLES;                                   -- 应有 23 张表
SELECT COUNT(*) FROM passport_accounts;        -- 至少有首个管理员
SELECT id, username, role, status FROM passport_accounts;
SELECT * FROM users LIMIT 1;                   -- 视图可查
```

然后打开站点：

- `https://<域名>/passport/` → 用首个管理员通行证登录；
- 登录后管理员可见「接口接入状态」卡片，逐项确认四个外部接口的接入状态；
- 抽查主站页面（首页、世界动态、社区大会、法庭、公共服务）与 `/api/v1/users.php`（管理员）是否正常。

**第 5 步：确认无误后再清理旧库**

`database/8w_passport.sql` 文件末尾把旧库清理语句**注释掉了**，默认不执行：

```sql
-- ============================================================================
--  第 5 步：旧库清理（默认不执行，确认新库运行正常后再手动放开）
-- ============================================================================
-- ⚠ 该操作不可逆。建议先做一次完整备份：
--      mysqldump -u root -p --databases bgjq > bgjq_backup_$(date +%F).sql
--
-- DROP DATABASE IF EXISTS `bgjq`;
-- DROP USER IF EXISTS 'bgjq'@'localhost';
-- FLUSH PRIVILEGES;
```

放开注释前请确认：备份文件已生成并校验可读；新库已跑过一段时间；没有任何遗留进程或脚本仍连 `bgjq`。

**第 6 步：手工导入的等价做法（不使用脚本时）**

把 `database/8w_passport.sql` 里的 `__DB_PASSWORD__` 自己替换成真实密码，另存为一个**不要提交**的文件
（`.gitignore` 已排除 `database/*.rendered.sql`），然后：

```bash
mysql -u root -p < 8w_passport.rendered.sql
```

### 2.3 旧库数据怎么办

- **身份数据**：`users` 表的内容不自动搬运。新库 `passport_accounts` 要求每条记录都有
  `player_name`（唯一）与 `email`（唯一），且注册路径要求邮箱、玩家名、简幻通三重校验通过，
  因此批量搬运旧账号没有意义——推荐让用户用 `/passport/` 重新注册，或由管理员在后台
  （`api/v1/users.php` 的 POST，走 `Auth::provision()`）逐个开号。
- **社区业务数据**：`news` / `proposals` / `votes` / `cases` / `trades` / `services` 等表结构保持旧列名，
  可以按 `author_id` / `user_id` / `proposer_id` 等列直接迁移；**前提是 `passport_accounts.id` 与原 `users.id`
  一一对应**。若做了重新注册，需要一张映射表把旧 ID 映射到新通行证 UID 后再迁移。
- **权威数据缓存**：`players` / `countries` 不必迁移，接口接入后会自动回填（注册与查询时回源）。

---

## 三、身份表迁移：users → passport_accounts

### 3.1 `users` 视图的列映射

`database/8w_passport.sql` 里 `DROP VIEW IF EXISTS users;` 之后重建视图，映射关系如下
（左：视图列名，即旧代码用的名字；右：`passport_accounts` 的真实列）：

| `users` 视图列 | 来源 `passport_accounts` 列 |
| --- | --- |
| `id` | `id` |
| `username` | `username` |
| `password` | `password_hash` |
| `game_id` | `player_name` |
| `player_id` | `player_id` |
| `country_id` | `country_id` |
| `role` | `role` |
| `jhtuid` | `simpass_uid` |
| `level` | `simpass_level` |
| `email` | `email` |
| `status` | `status` |
| `last_login_at` | `last_login_at` |
| `created_at` | `created_at` |

### 3.2 视图的作用：为什么保留

旧接口文件里有大量 `LEFT JOIN users u ON … = u.id` 的写法（见[第六节](#六遗留兼容层清单与移除计划)），
它们只**读**用户信息用于展示（用户名、角色、所属邦国）。把这些查询一次性全部改写成
`JOIN passport_accounts` 会把一次重构铺得过大、风险过高，所以先建一个列名完全兼容的只读视图，
让旧代码**一行不改**继续工作。

### 3.3 视图的限制（重要）

- 视图**不可写**：`INSERT` / `UPDATE` / `DELETE` 到 `users` 都会失败，也不存在对应的表结构可 `ALTER`。
- 因此 `php/config.php` 里的 `ensureUsersExtraColumns()` 已废弃为空实现（旧版会尝试给 `users` 表加
  `jhtuid`、`level` 列，现在这两个字段由视图从 `simpass_uid` / `simpass_level` 映射而来），
  调用点也早已注释掉。
- **任何身份写入都必须走通行证**：新代码请直接使用 `passport/src/` 的服务，
  不要再用 `Auth` 兼容类，更不要直接写 `passport_accounts`（唯一写入入口是
  `passport/src/Identity/AccountRepository.php`）。

### 3.4 已改到通行证的写入路径

| 场景 | 入口 | 实际写入 |
| --- | --- | --- |
| 用户自助注册 | `POST /passport/api/v1/register`、`POST /api/v1/auth.php?action=register` | `RegistrationService` → `AccountRepository::create()` |
| 登录 | `POST /passport/api/v1/login`、`POST /api/v1/auth.php?action=login` | `Authenticator::login()` + `SessionStore::create()` |
| 登出 | `POST /passport/api/v1/logout`、`DELETE /api/v1/auth.php?action=logout` | `Authenticator::logout()` → `SessionStore::destroy()` |
| 改密 | `POST /passport/api/v1/password`、`POST /api/v1/auth.php?action=reset-password` | `Authenticator::changePassword()` |
| 管理员开号 | `POST /api/v1/users.php` | `Auth::provision()` → `AccountRepository::create()` |
| 管理员改号 | `PUT /api/v1/users.php?id=` | `Auth::updateAccount()` → `AccountRepository::update()` |
| 管理员删号 | `DELETE /api/v1/users.php?id=` | `Auth::deleteAccount()` → `AccountRepository::delete()` |
| 删除邦国时解绑成员 | `DELETE /api/v1/countries.php?id=` | `UPDATE passport_accounts SET country_id = NULL WHERE country_id = ?` |
| 第三方应用签发凭据 | `POST /passport/api/oauth/clients` | `ClientRepository::create()` |

---

## 四、已迁移到通行证的代码清单

### 4.1 `php/classes/Auth.php` —— 重写为兼容层

- 文件顶部 `require_once __DIR__ . '/../../passport/src/bootstrap.php';`，拿到通行证应用实例。
- **构造函数签名保留** `__construct($db = null)`：旧的 `new Auth($db)` 调用不用改，
  但参数已不再使用（通行证自带连接）。
- **公开方法签名全部保留**，内部委托通行证：
  `account()`、`accountRepository()`、`countryName()`、`isLoggedIn()`、`getCurrentUser()`、`hasRole()`、
  `login()`、`logout()`、`getUserByUsername()`、`getUserById()`、`resetPassword()`、`provision()`、
  `updateAccount()`、`deleteAccount()`，以及静态的 `roles()`、`roleLevel()`。
- **登录态真源改为通行证 Cookie**：`$_SESSION['user']` 只是给旧页面看的镜像（`syncLegacySession()`），
  不参与鉴权判定；`hasRole()` 用 `roleLevel()` 等级表比较（`observer` 0 / `diplomat` 1 /
  `peacekeeper` 2 / `permanent_member` 3 / `secretary_general` 4）。
- **`toLegacyUser()` 提供旧字段名**：`id`、`username`、`email`、`game_id`（= `player_name`）、`player_id`、
  `country_id`、`role`、`status`、`jhtuid`（= `simpass_uid`）、`level`（= `simpass_level`）、`created_at`；
  仅后台管理场景（`getUserByUsername` / `getUserById`）额外附带 `password` 哈希。
- **新增 `provision()`（管理员直接开号）**：仍需用户名/密码/邮箱/玩家名齐全，玩家名照样走权威接口校验，
  但**跳过**邮箱验证码与简幻通验证码。
- **`resetPassword()` 语义变化**：旧版要简幻通 UID + 验证码，现在只需原密码 + 新密码
  （简幻通只在注册时校验一次）。
- 数据库不可用时构造函数不会让页面白屏，只记一条 `appLog('AUTH', …)`。

### 4.2 `api/v1/auth.php` —— 认证接口改走通行证

- 保留旧 URL 与旧响应格式（`{success, message, data}`，由 `php/config.php` 的 `jsonSuccess()` / `jsonError()` 产出），
  站内既有页面无需改动。
- 行为映射（`?action=`）：

| 方法 + action | 现在委托给 |
| --- | --- |
| `POST ?action=register` | `Application::registration()->register()`，注册成功后立即 `sessions()->create()` + `accounts()->touchLogin()` 并返回 `{user_id, user}` |
| `POST ?action=login` | `Auth::login()`（内部 `Authenticator::login()`），失败返回 401 |
| `POST ?action=reset-password` | 先 `getUserByUsername()` 查 ID，再 `Auth::resetPassword()`（原密码 + 新密码） |
| `GET ?action=current` | `Auth::getCurrentUser()`，未登录 401 |
| `GET ?action=verify-player` | `Application::players()->find($name, false)`，不存在 404 |
| `DELETE ?action=logout` | `Auth::logout()` |

- 文件头的注释直接给出了新接入应使用的通行证接口：
  `POST /passport/api/v1/register`、`POST /passport/api/v1/login`、`GET /passport/api/v1/me`、
  `POST /passport/api/v1/logout`。

### 4.3 `api/v1/users.php` —— 用户管理改走通行证

- 改为 `new Auth($db)` + `Auth::accountRepository()`，不再直接对 `users` 表写 SQL。
- `GET`（列表）用 `AccountRepository::listAll()`，输出里**同时带旧字段名与新字段名**：
  `game_id` 与 `player_name`、`jhtuid` 与 `simpass_uid`、`level` 与 `simpass_level`，
  另有 `email_verified`（布尔）、`country_name`（`LEFT JOIN countries`）。**密码哈希不外泄**。
- `POST`（开号）字段改为 `username` / `password` / `email` / `player_name`（兼容旧名 `game_id`）/
  `simpass_uid` / `role`，调用 `Auth::provision()`。
- `PUT`（改号）支持 `password` / `role` / `status` / `country_id`，并兼容旧的 `country_name`
  （按 `countries.name` + `is_active = 1` 反查 ID）；改密或改为非正常状态后会
  `SessionStore::destroyAllForAccount()` 强制其它会话下线。
- `DELETE`（删号）调用 `Auth::deleteAccount()`。
- 权限仍是 `requireSecretaryGeneral()`（`hasRole('secretary_general')`），失败 403。

### 4.4 `api/v1/countries.php` —— 只改了一处写入

- 唯一的改动在 `deleteCountry()`：删除邦国前解绑成员，语句由
  `UPDATE users SET country_id = NULL WHERE country_id = ?`
  改为 **`UPDATE passport_accounts SET country_id = NULL WHERE country_id = ?`**
  （注释说明：该字段是权威接口的本地缓存，下次同步会自动回填）。
- 其余查询**保持原样**，仍通过 `users` 视图读数据：
  `getCountries()` / `getCountry()` / `getAllCountriesAdmin()` 的
  `LEFT JOIN users u ON c.id = u.country_id` 与 `SELECT u.* FROM users u WHERE u.country_id = ?`。
- `getCountry()` 返回的 `members` 数组元素来自视图，列名是 `game_id` / `jhtuid` / `level` 等旧名。

### 4.5 `api/v1/server.php` —— 在线玩家列表改读通行证

- Minecraft 服务器 Ping 逻辑不变，改动在「在线玩家 → 邦国」的查询：
  由 `SELECT u.username … FROM users u WHERE u.username IN (…)`
  改为 **`SELECT a.player_name AS username, c.name AS country_name FROM passport_accounts a
  LEFT JOIN countries c ON a.country_id = c.id WHERE a.player_name IN (…)`**。
- 原因：`users` 视图里 `username` 是通行证登录名，而游戏内玩家名对应的是 `player_name`（视图里叫 `game_id`）；
  在线列表匹配的是游戏内名字，所以必须按 `player_name` 查，输出仍叫 `username` 以保持前端不变。

### 4.6 `api/v1/public/stats.php` —— 在线人数改口径

- 唯一的改动是 `online_players` 统计：

  ```sql
  -- 旧：SELECT COUNT(*) FROM online_players WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
  -- 新：
  SELECT COUNT(DISTINCT account_id) FROM passport_sessions
  WHERE expires_at > NOW() AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
  ```

- 其余统计仍走 `users` 视图（`total_users`、`users_by_role`）与社区业务表，字段名不变。
- 注意：本文件仍使用旧的静态 API Key 鉴权（`ApiAuth` + `api_logs`），尚未迁到 OAuth 2.0。

### 4.7 `php/config.php` —— 顺带调整

- 文件头注释改为「身份与认证已迁移到独立的 8W通行证系统，见 `passport/` 目录」。
- `SIMPPASS_API_URL` / `SIMPPASS_ACCESS_TOKEN` 两个常量改为从 `.env` 读
  （旧版把接口地址硬编码在源码里），保留只为兼容尚未迁移的旧代码。
  ⚠ 变量名拼写不统一，照抄即可：接口地址是 `SIMPASS_API_URL`（**单 P**），
  调用令牌是 `SIMPPASS_ACCESS_TOKEN`（**双 P**）；通行证自己的 `Config` 用的是同样这两个键，
  所以 `.env` 里只需各配一次，旧常量与通行证两边都会生效。
- `ensureUsersExtraColumns()` 标记 `@deprecated` 并改为空实现（见 3.3）。
- `DB_NAME` 的兜底默认值仍是 `'bgjq'`，**只在 `.env` 缺失时才生效**；正常部署必须显式配置 `DB_NAME=bgjq8w`。

---

## 五、前端变化

### 5.1 注册入口统一到 `/passport/`

站内**所有**页面顶栏的「注册」都从 `<button id="showRegisterBtn">` 改为
`<a class="nes-btn" id="showRegisterBtn" href="/passport/">`，同时删除了各自的旧注册弹窗
（`#registerModal` / `#registerForm`，含用户名、密码、确认密码、游戏ID、所属邦国、简幻通UID、简幻通验证码等字段），
原位置留下一句注释：`<!-- 注册入口已统一收敛到 8W通行证系统（/passport/），站内不再保留第二套注册表单 -->`。

| 文件 | 注册入口 | 旧注册弹窗 |
| --- | --- | --- |
| `index.html` | 改为指向 `/passport/` 的链接 | 已删除 |
| `index.php` | 改为指向 `/passport/` 的链接 | 已删除 |
| `assembly.php` | 改为指向 `/passport/` 的链接 | 已删除 |
| `court.php` | 改为指向 `/passport/` 的链接 | 已删除 |
| `parliament.php` | 改为指向 `/passport/` 的链接 | 已删除 |
| `world-news.php` | 改为指向 `/passport/` 的链接 | 已删除 |
| `complaint.html` | 改为指向 `/passport/` 的链接 | 已删除 |
| `services.html` | 改为指向 `/passport/` 的链接 | 已删除 |
| `admin.html` | 无注册入口 | 已删除 |
| `js/main.js` | `initRegisterModal()` → `initRegisterEntry()`：`<a>` 形态直接跳转，`<button>` 形态由脚本接管跳转到 `/passport/` | 弹窗交互代码全部删除 |

### 5.2 其它前端/页面调整

| 文件 | 变化 |
| --- | --- |
| `admin.html` | 后台「添加用户」表单把「游戏ID + 所属邦国」改为「验证邮箱 + 游戏内玩家名」，并提示所属邦国由权威接口自动识别（对应 `Auth::provision()` 的字段） |
| `reset-password.html` | 删除「简幻通UID」「简幻通验证码」两个字段，改为提示「身份已由 8W通行证统一托管，验证原密码即可修改；忘记密码请通过邮箱找回（待邮箱接口接入后开放）」；新密码提示由「至少6个字符」改为「至少8个字符」，与通行证的密码强度下限一致 |
| `index.php`、`assembly.php`、`court.php`、`parliament.php`、`world-news.php` | 生产环境下 `ini_set('display_errors', '0')` / `ini_set('display_startup_errors', '0')`，错误只进日志、不再输出到页面 |
| 登录 | 登录弹窗保留（仍调 `/api/v1/auth.php?action=login`，走 `Auth` 兼容层） |

**用户可见的变化**：全站注册只有一个入口 —— `https://<域名>/passport/`。
注册需要同时通过邮箱验证码、游戏内玩家名权威校验、简幻通校验；登录后可在通行证中心查看
账号信息、我的邦国、已授权的第三方应用，并修改密码。改密不再需要简幻通验证码。

---

## 六、遗留兼容层清单与移除计划

| # | 对象 | 现状 | 谁还在用 | 移除前置条件 | 移除动作 |
| --- | --- | --- | --- | --- | --- |
| 1 | `online_players` 表 | 保留结构，**新代码不再写入**；在线口径已改为 `passport_sessions.last_seen_at` | 无（`api/v1/public/stats.php` 已改口径） | 确认全仓库无 `INSERT/UPDATE online_players`（现有引用只剩 `MCServerPing.php` / `server.php` 里同名的**响应字段**，与表无关） | 从 `database/8w_passport.sql` 删除建表段 → `DROP TABLE online_players;` |
| 2 | `api_keys` 表 | 保留，静态 API Key 仍在生效 | `php/classes/ApiAuth.php`、`api-manager.php`、`api/v1/admin/api-keys.php`、`api/v1/public/*.php`（`X-API-Key` 鉴权） | 把 `api/v1/public/*` 迁到 OAuth 2.0（`client_credentials` + `directory` scope），并通知所有存量 Key 持有者换用 `client_id` / `client_secret` | 停用 `api-manager.php` 与 `api/v1/admin/api-keys.php` → 删除 `ApiAuth` → `DROP TABLE api_keys;` |
| 3 | `api_logs` 表 | 保留，旧 API 仍在写 | `php/classes/ApiLogger.php`（`INSERT INTO api_logs …`）、`php/classes/ApiAuth.php`（按 `api_logs` 做限流计数） | 同第 2 项（旧 Key 体系整体下线）；新的调用日志已在 `passport_api_logs` | 删除 `ApiLogger` → `DROP TABLE api_logs;` |
| 4 | `users` 视图 | 保留，只读兼容层 | 读：`api/v1/cases.php`、`api/v1/conventions.php`、`api/v1/countries.php`、`api/v1/news.php`、`api/v1/proposals.php`、`api/v1/trades.php`、`api/v1/public/countries.php`、`api/v1/public/users.php`、`api/v1/public/stats.php`、`php/render_functions.php` | 把上述文件的 `JOIN users` 全部改写为 `JOIN passport_accounts`，并把取出的列名从 `game_id` / `jhtuid` / `level` / `password` 改为 `player_name` / `simpass_uid` / `simpass_level` / `password_hash` | 从 `database/8w_passport.sql` 删除 `DROP VIEW` + `CREATE VIEW` 段 → `DROP VIEW users;` |
| 5 | `Auth` 兼容类（`php/classes/Auth.php`） | 保留，旧方法签名齐备 | `api/v1/auth.php`、`api/v1/users.php`、`api/v1/countries.php`（`new Auth($db)`）、`php/config.php` 的自动加载器 | 上述接口全部改为直接使用 `passport/src/` 的服务或通行证 HTTP 接口 | 删除 `php/classes/Auth.php`，并清理 `$_SESSION['user']` 相关读取（`php/config.php` 的 `hasPermission()` 等） |

**移除顺序建议**：4 → 5 → 2 → 3 → 1（先做纯读改写，风险最低；再动鉴权体系；最后清理无引用的表）。

---

## 七、上线检查清单

### 迁移前

- [ ] 已用 `mysqldump -u root -p --databases bgjq > bgjq_backup_<日期>.sql` 完整备份旧库，并验证备份可读
- [ ] 已确认旧库无其它应用/脚本仍在写入（如有，先评估影响）
- [ ] `.env` 已按 `.env.example` 补齐：`DB_NAME=bgjq8w`、`DB_USER=bgjq8w`、`DB_PASS=<真实密码>`
- [ ] 已确认 `.env` 未被 git 跟踪：`git status --porcelain | Select-String '\.env$'` 应无输出
- [ ] `.env.example` 中没有任何真实密钥（只有占位值）

### 初始化与配置

- [ ] `pwsh ./bin/init-database.ps1` 执行成功（或 `-DryRun` 检查过渲染结果后手工导入）
- [ ] `SHOW TABLES` 显示 23 张表，`SELECT * FROM users LIMIT 1;` 可查（视图存在）
- [ ] `passport/storage/logs/` 目录对 PHP-FPM 用户可写（否则日志会退回 `error_log`）
- [ ] Nginx 已按 `nginx-8w.bgjq.top.conf` 配置：`/passport/src/`、`/passport/storage/` 为 `deny all`；
      `/passport/api/v1/<name>`、`/passport/api/oauth/<name>`、`/oauth/{authorize,token,userinfo,introspect,revoke}` 重写生效
- [ ] `PASSPORT_BASE_URL` 已设为线上域名（`https://8w.bgjq.top`）
- [ ] `PASSPORT_DEBUG=0` 且 `PASSPORT_DEV_BYPASS_VERIFICATION=0`（生产环境必须都关闭）
- [ ] `PASSPORT_ADMIN_ROLES` 已按实际管理员角色配置（默认 `secretary_general`）
- [ ] `PASSPORT_COOKIE_DOMAIN` 按需配置（留空表示当前域），站点已启用 HTTPS（Cookie 的 `Secure` 由请求协议自动判定）

### 外部接口（四个 TODO 项）

- [ ] 邮箱验证码：`.env` 的 `EMAIL_API_URL`（+ `EMAIL_API_TOKEN` / `EMAIL_API_BODY_TEMPLATE` 等）已配置，
      且 `/passport/` 的「接口接入状态」显示**已接入**
- [ ] 游戏内玩家：`PLAYER_API_BASE` / `PLAYER_API_PATH`（+ 字段路径）已配置，状态显示已接入
- [ ] 邦国信息：`COUNTRY_API_BASE` / `COUNTRY_API_PATH` 已配置；若需要按名称查询，
      必须同时配置 `COUNTRY_API_PATH_BY_NAME`，否则按名称查询会明确报 501
- [ ] 简幻通：`SIMPASS_API_URL` / `SIMPPASS_ACCESS_TOKEN`（注意变量名是**双 P**）已配置，状态显示已接入
- [ ] 已知悉：任何一个未接入时，对应功能会返回 **HTTP 501 `not_implemented`** 并给出明确原因，
      **不会静默放行**；注册链路的校验顺序为 玩家名 → 简幻通 → 邮箱验证码

### 功能验收

- [ ] 用首个管理员通行证（`LouieMAIN`）登录 `/passport/`，**立即修改密码与邮箱**
- [ ] 完整走一遍注册：邮箱验证码 → 玩家名 → 简幻通 → 自动登录，且邦国信息自动带出
- [ ] 修改密码后，其它设备上的登录态被踢下线
- [ ] 创建测试第三方应用，跑通授权码流程（`/oauth/authorize` → `/oauth/token` → `/oauth/userinfo`）
- [ ] `client_credentials` 令牌可查 `/passport/api/v1/player` 与 `/passport/api/v1/country`，
      调 `/oauth/userinfo` 返回 403 `invalid_token`
- [ ] `/oauth/introspect` 对有效/已吊销令牌分别返回 `active: true` / `active: false`
- [ ] 主站页面正常：首页、世界动态、社区大会、法庭、公共服务
- [ ] 主站旧接口正常：`/api/v1/auth.php?action=current`、`/api/v1/users.php`（管理员）、
      `/api/v1/countries.php`、`/api/v1/server.php`、`/api/v1/public/stats.php`（`online_players` 有值）
- [ ] 首页与后台的「注册」入口都跳转到 `/passport/`，站内不再有第二套注册表单

### 收尾

- [ ] 观察 `passport/storage/logs/passport-<日期>.log` 与 `php/logs/app.log`，确认无异常刷屏
- [ ] 观察 `passport_api_logs` 是否有第三方调用（确认 OAuth 接入生效）
- [ ] 新库稳定运行一段时间后，再放开 `database/8w_passport.sql` 末尾注释掉的旧库 `DROP DATABASE` / `DROP USER`
- [ ] 按[第六节](#六遗留兼容层清单与移除计划)的顺序排期移除遗留兼容层

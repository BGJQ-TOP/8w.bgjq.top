# 8W社区网站 - 部署说明

## 目录结构

```
8w.bgjq.top/
├── index.html              # 主页面
├── database.sql            # 数据库初始化脚本
├── DEPLOY.md              # 本文档
├── css/
│   ├── nes.min.css        # NES.css框架
│   └── style.css          # 自定义样式
├── js/
│   └── main.js            # 前端JavaScript
├── php/
│   ├── config.php         # 配置文件
│   └── classes/
│       └── Auth.php       # 用户认证类
├── api/
│   └── v1/
│       ├── auth.php       # 认证API
│       ├── news.php       # 新闻API
│       ├── proposals.php  # 提案API
│       └── (更多API文件)
├── fonts/
│   ├── zpix.woff2
│   └── consola.woff2
└── images/
    └── (logo.webp)
```

## 部署步骤

### 1. 数据库配置

> 身份与认证已迁移到独立的「8W通行证系统」。
> 数据库结构、账号授权的唯一真源是 `database/8w_passport.sql`。
> 新库为 `bgjq8w`，旧的 `bgjq` 已废弃（清理方式见 `docs/MIGRATION.md`）。

#### 1.1 准备 .env

```bash
cp .env.example .env
```

填写 `DB_NAME` / `DB_USER` / `DB_PASS` 三项（新库名不得与旧的 `bgjq` 相同）。

#### 1.2 一键建库 + 建账号 + 授权 + 建表

`database/8w_passport.sql` 里的密码位置是占位符 `__DB_PASSWORD__`，
由脚本从 `.env` 读取真实值渲染后执行，执行完立即删除临时文件，
**保证真实密码不会落进版本库**：

```bash
pwsh ./bin/init-database.ps1
```

只想先看看会执行什么：

```bash
pwsh ./bin/init-database.ps1 -DryRun
```

也可以手工导入（需先把 `__DB_PASSWORD__` 换成真实密码，且不要把替换后的文件提交）：

```bash
mysql -u root -p < 8w_passport.rendered.sql
```

### 2. 网站配置

#### 2.1 配置文件

**所有敏感配置都放在项目根目录的 `.env`**（已被 `.gitignore` 排除）。
变量清单见 `.env.example`，其中关键几项：

```ini
# 数据库
DB_HOST=localhost
DB_NAME=bgjq8w
DB_USER=bgjq8w
DB_PASS=******

# 通行证
PASSPORT_BASE_URL=https://8w.bgjq.top
PASSPORT_SESSION_TTL=86400

# 权威数据接口（待接入，见 passport/README.md 的 TODO 清单）
PLAYER_API_BASE=
COUNTRY_API_BASE=
SIMPASS_API_URL=

# 可选绑定接口：不接入也能注册与登录，只是对应绑定功能不可用
EMAIL_API_URL=
FANVERIFY_API_URL=
```

> `EMAIL_API_URL` 与 `FANVERIFY_API_URL` 是**可选绑定**（验证邮箱 / FanVerify 账号）对应的接口，
> 未配置时不影响注册与登录；`PLAYER_API_BASE` / `COUNTRY_API_BASE` / `SIMPASS_API_URL`
> 则关系到注册必填校验，未配置时注册会明确返回 501。

`php/config.php` 只负责社区站点自身的数据库连接，通过 `env()` 读取上述变量，
不再硬编码任何凭据。

### 3. Web服务器配置

#### 3.1 Apache配置

创建虚拟主机配置文件 `/etc/apache2/sites-available/bgjq.conf`：

```apache
<VirtualHost *:80>
    ServerName 8w.bgjq.top
    DocumentRoot /var/www/8w.bgjq.top

    <Directory /var/www/8w.bgjq.top>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/bgjq-error.log
    CustomLog ${APACHE_LOG_DIR}/bgjq-access.log combined
</VirtualHost>
```

启用站点：

```bash
sudo a2ensite bgjq.conf
sudo systemctl reload apache2
```

#### 3.2 Nginx配置

**直接使用仓库根目录的 `nginx-8w.bgjq.top.conf`**，它已经包含社区站点与
8W通行证系统（含 `/oauth/*` 与 `/passport/api/*`）的全部路由、静态缓存与安全头：

```bash
sudo cp nginx-8w.bgjq.top.conf /etc/nginx/sites-available/8w.bgjq.top
sudo ln -sf /etc/nginx/sites-available/8w.bgjq.top /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

其中与通行证相关的关键规则：

| 规则 | 作用 |
|---|---|
| `location ^~ /passport/src/`、`/passport/storage/` | `deny all`，源码与日志禁止直接访问 |
| `location ^~ /passport/assets/` | 通行证静态资源，缓存 7 天 |
| `location ~ ^/passport/api/(v1\|oauth)/([a-z0-9_-]+)$` | 美化 URL → 对应 `.php` |
| `location ~ ^/oauth/(authorize\|token\|userinfo\|introspect\|revoke)$` | OAuth 2.0 标准端点 |

`/passport/` 由 server 段的 `index index.php` 落到 `passport/index.php`，无需额外规则。

### 4. 文件权限

设置正确的文件权限：

```bash
# 设置所有者
sudo chown -R www-data:www-data /var/www/8w.bgjq.top

# 设置目录权限
sudo find /var/www/8w.bgjq.top -type d -exec chmod 755 {} \;

# 设置文件权限
sudo find /var/www/8w.bgjq.top -type f -exec chmod 644 {} \;

# 如果有上传目录，设置写权限
# sudo chmod 775 /var/www/8w.bgjq.top/uploads
```

### 5. PHP配置

确保PHP已安装必要的扩展：

```bash
# Ubuntu/Debian
sudo apt install php php-mysql php-curl php-json php-mbstring

# CentOS/RHEL
sudo yum install php php-mysqlnd php-curl php-json php-mbstring
```

8W通行证系统额外依赖（缺一不可）：

| 扩展 | 用途 |
|---|---|
| `pdo_mysql` | 数据库访问（通行证与社区站点共用） |
| `curl` | 调用游戏内玩家 / 邦国 / 简幻通 / 邮箱验证码 / FanVerify 五个外部接口 |
| `openssl` | 生成密码学安全随机数（会话令牌、OAuth 令牌、验证码） |
| `mbstring` | 中文与多字节字符串处理 |

缺失时不会静默降级：`HttpClient` 会在 cURL 不可用时明确报错，
`Str` 会在 `random_bytes` 不可用时回退到 `openssl_random_pseudo_bytes`。

检查 `php.ini` 配置：

```ini
file_uploads = On
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 300
memory_limit = 256M
date.timezone = Asia/Shanghai
```

### 6. 初始管理员账户

数据库初始化脚本已经写入首个管理员通行证：

```
用户名：LouieMAIN
邮箱：  admin@bgjq.top
密码：  Lyizai211
```

**登录后请立即修改密码与邮箱。** 登录入口：`https://8w.bgjq.top/passport/`

新增管理员可通过两种方式：

1. 用已有管理员登录 `/passport/`，在「第三方应用管理」下方的账号体系里维护（或走 `/api/v1/users.php`）
2. 直接改写 `database/8w_passport.sql` 末尾的初始数据段后重新导入

管理员角色为 `secretary_general`，可用 `.env` 的 `PASSPORT_ADMIN_ROLES` 调整。

### 7. 定时维护（建议配置）

通行证会产生会话、授权码、令牌与调用日志，建议每天清理一次过期数据：

```bash
crontab -e
```

```
0 3 * * * /usr/bin/php /var/www/8w.bgjq.top/bin/maintenance.php >> /var/log/8w-passport-cron.log 2>&1
```

`bin/maintenance.php` 会清理过期的登录会话、授权码、令牌、邮箱验证码，
以及超过 30 天的调用日志（可用 `--log-days=N` 调整）。

### 8. 测试

提交前先跑一遍闸门（全量 PHP 语法检查 + 通行证核心逻辑测试）：

```bash
pwsh ./bin/test.ps1
```

再访问网站验证：

访问网站：https://8w.bgjq.top

测试以下功能：
- [ ] 页面加载正常
- [ ] 数据库连接正常
- [ ] 通行证注册页可打开（`/passport/`）
- [ ] 新闻查看
- [ ] 提案查看
- [ ] 投票功能（如已登录）

## API文档

### 认证API

#### 注册

> 这是站内**兼容入口**，响应仍是旧格式 `{success, message, data}`。
> 新代码请直接用 `POST /passport/api/v1/register`（响应为 `{"ok":true,"data":{…}}`）。

```
POST /api/v1/auth.php?action=register
Content-Type: application/json

{
    "username": "testuser",
    "password": "password123",
    "player_name": "Player123",
    "simpass_uid": 10086,
    "simpass_code": "654321",

    "email": "testuser@example.com",
    "email_code": "123456"
}
```

- **必填**：`username`、`password`、`player_name`（提交时会调用权威接口实时校验）、`simpass_uid`、`simpass_code`。
- **可选**：`email` + `email_code`（填了邮箱则验证码必填）、`fanverify_uid` + `fanverify_code`。
- 所属邦国由权威接口自动识别，**不接受**前端传入的 `country_id`（旧示例里的 `game_id` 也已改名 `player_name`）。

#### 登录
```
POST /api/v1/auth.php?action=login
Content-Type: application/json

{
    "username": "testuser",
    "password": "password123",
    "remember": true
}
```

#### 检查登录状态
```
GET /api/v1/auth.php?action=check
```

#### 登出
```
POST /api/v1/auth.php?action=logout
```

### 新闻API

#### 获取新闻
```
GET /api/v1/news.php?headline=1&limit=10
```

#### 创建新闻（需要权限）
```
POST /api/v1/news.php
Content-Type: application/json

{
    "title": "新闻标题",
    "content": "新闻内容",
    "is_headline": true
}
```

### 提案API

#### 获取提案列表
```
GET /api/v1/proposals.php?status=voting
```

#### 获取单个提案
```
GET /api/v1/proposals.php?id=1
```

#### 创建提案（需要权限）
```
POST /api/v1/proposals.php
Content-Type: application/json

{
    "title": "提案标题",
    "description": "提案描述",
    "type": "territory"
}
```

#### 投票（需要登录）
```
PUT /api/v1/proposals.php?id=1&action=vote
Content-Type: application/json

{
    "vote": "for"
}
```

## 安全建议

1. **修改默认密码**：立即修改数据库用户密码和管理员账户密码
2. **启用HTTPS**：使用Let's Encrypt免费证书
3. **定期备份**：设置数据库自动备份
4. **限制访问**：敏感目录（如php/）设置.htaccess或nginx规则
5. **更新依赖**：定期更新PHP和MySQL版本
6. **日志监控**：监控访问日志和错误日志

## 故障排除

### 数据库连接失败
- 检查数据库服务是否运行
- 验证用户名和密码
- 检查用户权限

### 页面404错误
- 检查Web服务器根目录配置
- 确认文件存在且权限正确
- 检查重写规则

### API返回错误
- 查看PHP错误日志
- 检查请求格式是否正确
- 验证用户权限

## 联系方式

如有问题，请联系服务器管理员。

---

**祝部署顺利！**




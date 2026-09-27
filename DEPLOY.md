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
EMAIL_API_URL=
```

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

创建配置文件 `/etc/nginx/sites-available/bgjq`：

```nginx
server {
    listen 80;
    server_name 8w.bgjq.top;
    root /var/www/8w.bgjq.top;
    index index.html index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

启用站点：

```bash
sudo ln -s /etc/nginx/sites-available/bgjq /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

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

### 7. 测试

访问网站：https://8w.bgjq.top

测试以下功能：
- [ ] 页面加载正常
- [ ] 数据库连接正常
- [ ] 用户注册/登录
- [ ] 新闻查看
- [ ] 提案查看
- [ ] 投票功能（如已登录）

## API文档

### 认证API

#### 注册
```
POST /api/v1/auth.php?action=register
Content-Type: application/json

{
    "username": "testuser",
    "password": "password123",
    "game_id": "Player123",
    "country_id": 1
}
```

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




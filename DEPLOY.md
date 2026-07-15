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

#### 1.1 创建数据库和用户

首先登录MySQL/MariaDB：

```bash
mysql -u root -p
```

然后执行以下命令：

```sql
-- 创建数据库
CREATE DATABASE IF NOT EXISTS bgjq DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 创建用户
CREATE USER IF NOT EXISTS 'bgjq'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD';

-- 授予权限
GRANT ALL PRIVILEGES ON bgjq.* TO 'bgjq'@'localhost';
FLUSH PRIVILEGES;
```

#### 1.2 导入数据库结构

```bash
mysql -u bgjq -p bgjq < database.sql
```

或者在MySQL客户端中：

```sql
USE bgjq;
SOURCE /path/to/database.sql;
```

### 2. 网站配置

#### 2.1 修改配置文件

编辑 `php/config.php`，根据需要修改以下配置：

```php
// 数据库配置（如果不同）
define('DB_HOST', 'localhost');
define('DB_NAME', 'bgjq');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// 网站配置
define('SITE_NAME', '8W社区');
define('SITE_URL', 'https://8w.bgjq.top');

// 会话配置
define('SESSION_LIFETIME', 86400); // 24小时
define('COOKIE_DOMAIN', '');
define('COOKIE_SECURE', false); // 如果使用HTTPS，设置为true
```

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

### 6. 初始化管理员账户

创建一个初始化脚本 `init_admin.php`：

```php
<?php
require_once 'php/config.php';

$db = getDBConnection();
$auth = new Auth($db);

try {
    $userId = $auth->register('admin', '你的密码', 'admin_game_id', null);
    
    $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->execute([ROLE_SECRETARY_GENERAL, $userId]);
    
    echo "管理员账户创建成功！\n";
    echo "用户名: admin\n";
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
```

在命令行执行：

```bash
php init_admin.php
```

执行后记得删除这个文件！

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




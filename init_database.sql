-- ============================================================
-- 8W社区 数据库完整初始化脚本
-- 适用：MySQL 8.0+ / MariaDB 10.3+
-- 使用方式：mysql -u root -p < init_database.sql
-- ============================================================

-- ============================================================
-- 第一步：创建数据库
-- ============================================================
CREATE DATABASE IF NOT EXISTS bgjq
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- ============================================================
-- 第二步：创建用户并授权
-- ============================================================
-- 如果用户已存在则跳过（MariaDB 10.3+ 支持 IF NOT EXISTS）
CREATE USER IF NOT EXISTS 'bgjq'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD';

-- 授予 bgjq 数据库全部权限
GRANT ALL PRIVILEGES ON bgjq.* TO 'bgjq'@'localhost';
FLUSH PRIVILEGES;

-- ============================================================
-- 第三步：切换到目标数据库
-- ============================================================
USE bgjq;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================
-- 第四步：创建所有表（按依赖顺序）
-- ============================================================

-- ---------------------------------------------------------
-- 1. users（用户）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL COMMENT 'bcrypt 哈希',
    `game_id` VARCHAR(255) NOT NULL COMMENT 'Minecraft 游戏ID',
    `country_id` INT NULL COMMENT '所属邦国',
    `role` VARCHAR(50) DEFAULT 'observer' COMMENT 'observer / diplomat / peacekeeper / permanent_member / secretary_general',
    `jhtuid` TEXT NULL COMMENT '简幻通用户UID',
    `level` TEXT NULL COMMENT '简幻通等级',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2. countries（邦国）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `countries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `declaration` TEXT NULL COMMENT '国家宣言',
    `government_type` VARCHAR(50) DEFAULT 'other' COMMENT 'monarchy / democracy / guild / other',
    `population` INT NULL COMMENT '人口',
    `territory_chunks` INT NULL COMMENT '领地块数',
    `flag_url` VARCHAR(255) NULL COMMENT '旗帜URL',
    `is_active` BOOLEAN DEFAULT TRUE COMMENT '是否活跃',
    `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. sessions（会话 / 登录态）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `ip_address` VARCHAR(50) NOT NULL,
    `user_agent` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4. online_players（在线玩家缓存）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `online_players` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `game_id` VARCHAR(255) NOT NULL,
    `country_id` INT NULL,
    `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL,
    UNIQUE KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 5. news（新闻）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `author_id` INT NOT NULL,
    `is_headline` BOOLEAN DEFAULT FALSE,
    `published_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 6. timeline（历史时间轴）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `timeline` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `date` DATE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `event_type` VARCHAR(50) DEFAULT 'other' COMMENT 'war / peace / construction / diplomatic / other',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 7. proposals（提案）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `proposals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `type` VARCHAR(50) DEFAULT 'other' COMMENT 'territory / defense / trade / embargo / event / other',
    `proposer_id` INT NOT NULL,
    `country_id` INT NULL,
    `status` VARCHAR(50) DEFAULT 'draft' COMMENT 'draft / voting / passed / rejected',
    `voting_start` DATETIME NULL,
    `voting_end` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`proposer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 8. votes（投票记录）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `votes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `proposal_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `country_id` INT NULL,
    `vote` VARCHAR(20) NOT NULL COMMENT 'for / against / abstain',
    `has_veto` BOOLEAN DEFAULT FALSE COMMENT '是否一票否决',
    FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 9. conventions（世界公约）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conventions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `proposal_id` INT NULL,
    `enacted_by_user_id` INT NULL,
    `enacted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`enacted_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 10. cases（国际法庭案件）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `case_number` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `plaintiff_id` INT NOT NULL,
    `defendant_country_id` INT NULL,
    `status` VARCHAR(50) DEFAULT 'filed' COMMENT 'filed / hearing / judged / closed',
    `judgment` TEXT NULL,
    `filed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `judged_at` DATETIME NULL,
    FOREIGN KEY (`plaintiff_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`defendant_country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 11. case_evidence（案件证据）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `case_evidence` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `case_id` INT NOT NULL,
    `uploaded_by_user_id` INT NULL,
    `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `file_url` VARCHAR(255) NULL,
    FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 12. arbitration_archive（仲裁结果库）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `arbitration_archive` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `case_id` INT NOT NULL,
    `case_number` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `judgment` TEXT NOT NULL,
    FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 13. diplomatic_relations（外交关系）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `diplomatic_relations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `country1_id` INT NOT NULL,
    `country2_id` INT NOT NULL,
    `relation` VARCHAR(50) DEFAULT 'neutral' COMMENT 'friendly / hostile / neutral / ceasefire',
    `set_by_user_id` INT NOT NULL,
    FOREIGN KEY (`country1_id`) REFERENCES `countries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country2_id`) REFERENCES `countries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`set_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 14. trades（贸易信息）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(20) NOT NULL COMMENT 'buy / sell',
    `item_name` VARCHAR(255) NOT NULL,
    `quantity` VARCHAR(100) NULL,
    `exchange_method` VARCHAR(255) NULL,
    `country_id` INT NULL,
    `posted_by_user_id` INT NOT NULL,
    `status` VARCHAR(20) DEFAULT 'active' COMMENT 'active / completed / cancelled',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`posted_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 15. services（公共服务）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 16. api_keys（API 密钥管理）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `key_name` VARCHAR(255) NOT NULL COMMENT '密钥名称',
    `api_key` VARCHAR(255) NOT NULL COMMENT 'API Key（32位hex）',
    `api_secret` VARCHAR(255) NOT NULL COMMENT 'API Secret（48位hex）',
    `allowed_ips` VARCHAR(500) DEFAULT '' COMMENT 'IP白名单，逗号分隔',
    `rate_limit` INT DEFAULT 60 COMMENT '每分钟调用次数限制',
    `is_active` BOOLEAN DEFAULT TRUE COMMENT '是否启用',
    `permissions` VARCHAR(500) DEFAULT 'users,countries,stats' COMMENT '权限范围，逗号分隔',
    `last_used_at` DATETIME NULL COMMENT '最后使用时间',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NULL COMMENT '过期时间，NULL=永不过期'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 17. api_logs（API 调用日志）
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `api_key_id` INT NULL COMMENT '关联的 api_keys.id',
    `api_key` VARCHAR(255) NOT NULL COMMENT '使用的 API Key',
    `endpoint` VARCHAR(255) NOT NULL COMMENT '请求端点',
    `method` VARCHAR(10) NOT NULL COMMENT 'HTTP 方法',
    `query_params` TEXT NULL COMMENT '请求参数（JSON）',
    `response_status` INT NOT NULL COMMENT 'HTTP 响应状态码',
    `response_time` INT NOT NULL COMMENT '响应耗时（毫秒）',
    `ip_address` VARCHAR(45) NOT NULL COMMENT '客户端IP',
    `user_agent` TEXT NULL COMMENT 'User-Agent',
    `error_message` TEXT NULL COMMENT '错误信息',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 第五步：插入初始数据
-- ============================================================

-- 默认管理员用户（用户名：LouieMAIN，密码：Lyizai211）
INSERT IGNORE INTO `users` (`username`, `password`, `game_id`, `role`) VALUES
('LouieMAIN', '$2y$10$CBiU1EOLZMOxjJAryMUW9.OShfztcH4c94HT9Q1WKUw1/I0JIRGpm', 'LouieMAIN', 'secretary_general');

-- 默认公共服务
INSERT IGNORE INTO `services` (`name`, `url`) VALUES
('在线地图', 'http://bgjq.simpfun.cn'),
('官方QQ群', 'https://qm.qq.com/q/hELXutcWZy'),
('QQ频道', 'https://pd.qq.com/s/61ds8hzgr');

COMMIT;

-- ============================================================
-- 初始化完成
-- ============================================================
-- 默认管理员账号：LouieMAIN / Lyizai211
-- 请登录后立即修改密码！
-- ============================================================

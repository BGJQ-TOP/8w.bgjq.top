-- 8W社区数据库初始化脚本
-- 创建所有必要的表结构

-- 设置字符集
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. users（用户）
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `game_id` VARCHAR(255) NOT NULL,
    `country_id` INT NULL,
    `role` VARCHAR(50) DEFAULT 'observer',
    `jhtuid` TEXT NULL,
    `level` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. countries（邦国）
CREATE TABLE IF NOT EXISTS `countries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `declaration` TEXT NULL,
    `government_type` VARCHAR(50) DEFAULT 'other',
    `population` INT NULL,
    `territory_chunks` INT NULL,
    `flag_url` VARCHAR(255) NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. sessions（会话 / 登录态）
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `ip_address` VARCHAR(50) NOT NULL,
    `user_agent` VARCHAR(255) NOT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. online_players（在线玩家缓存）
CREATE TABLE IF NOT EXISTS `online_players` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `game_id` VARCHAR(255) NOT NULL,
    `country_id` INT NULL,
    `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. news（新闻）
CREATE TABLE IF NOT EXISTS `news` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `author_id` INT NOT NULL,
    `is_headline` BOOLEAN DEFAULT FALSE,
    `published_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. timeline（历史时间轴）
CREATE TABLE IF NOT EXISTS `timeline` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `date` DATE NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `event_type` VARCHAR(50) DEFAULT 'other',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. proposals（提案）
CREATE TABLE IF NOT EXISTS `proposals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `type` VARCHAR(50) DEFAULT 'other',
    `proposer_id` INT NOT NULL,
    `country_id` INT NULL,
    `status` VARCHAR(50) DEFAULT 'draft',
    `voting_start` DATETIME NULL,
    `voting_end` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`proposer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. votes（投票记录）
CREATE TABLE IF NOT EXISTS `votes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `proposal_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `country_id` INT NULL,
    `vote` VARCHAR(20) NOT NULL,
    `has_veto` BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. conventions（世界公约）
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

-- 10. cases（国际法庭案件）
CREATE TABLE IF NOT EXISTS `cases` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `case_number` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `plaintiff_id` INT NOT NULL,
    `defendant_country_id` INT NULL,
    `status` VARCHAR(50) DEFAULT 'filed',
    `judgment` TEXT NULL,
    `filed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `judged_at` DATETIME NULL,
    FOREIGN KEY (`plaintiff_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`defendant_country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. case_evidence（案件证据）
CREATE TABLE IF NOT EXISTS `case_evidence` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `case_id` INT NOT NULL,
    `uploaded_by_user_id` INT NULL,
    `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `file_url` VARCHAR(255) NULL,
    FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. arbitration_archive（仲裁结果库）
CREATE TABLE IF NOT EXISTS `arbitration_archive` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `case_id` INT NOT NULL,
    `case_number` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `judgment` TEXT NOT NULL,
    FOREIGN KEY (`case_id`) REFERENCES `cases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. diplomatic_relations（外交关系）
CREATE TABLE IF NOT EXISTS `diplomatic_relations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `country1_id` INT NOT NULL,
    `country2_id` INT NOT NULL,
    `relation` VARCHAR(50) DEFAULT 'neutral',
    `set_by_user_id` INT NOT NULL,
    FOREIGN KEY (`country1_id`) REFERENCES `countries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`country2_id`) REFERENCES `countries`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`set_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. trades（贸易信息）
CREATE TABLE IF NOT EXISTS `trades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(20) NOT NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `quantity` VARCHAR(100) NULL,
    `exchange_method` VARCHAR(255) NULL,
    `country_id` INT NULL,
    `posted_by_user_id` INT NOT NULL,
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`posted_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. services（公共服务）
CREATE TABLE IF NOT EXISTS `services` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 插入一些初始数据
-- 1. 插入默认服务
INSERT IGNORE INTO `services` (`name`, `url`) VALUES
('在线地图', 'http://bgjq.simpfun.cn'),
('官方QQ群', 'https://qm.qq.com/q/hELXutcWZy'),
('QQ频道', 'https://pd.qq.com/s/61ds8hzgr');

-- 2. 插入一些示例新闻
INSERT IGNORE INTO `news` (`title`, `content`, `author_id`, `is_headline`) VALUES
('服务器开服公告', '邦国崛起服务器正式开服，欢迎各位玩家加入！', 1, TRUE),
('社区成立', '8W社区正式成立，致力于维护服务器和平与发展。', 1, TRUE);

-- 3. 插入一些示例时间轴事件
INSERT IGNORE INTO `timeline` (`date`, `title`, `description`, `event_type`) VALUES
(CURDATE(), '服务器开服', '邦国崛起服务器正式开服', 'construction'),
(CURDATE(), '社区成立', '8W社区正式成立', 'diplomatic');

-- 4. 插入一些示例公约
INSERT IGNORE INTO `conventions` (`title`, `content`) VALUES
('和平共处五项原则', '互相尊重主权和领土完整、互不侵犯、互不干涉内政、平等互利、和平共处。'),
('核不扩散条约', '禁止发展、生产、储存核武器，促进核裁军。');

-- 5. 插入一些示例邦国
INSERT IGNORE INTO `countries` (`name`, `declaration`, `government_type`) VALUES
('中华人民共和国', '中华人民共和国是工人阶级领导的、以工农联盟为基础的人民民主专政的社会主义国家。', 'democracy'),
('美利坚合众国', '美利坚合众国是一个由五十个州和一个联邦直辖特区组成的宪政联邦共和制国家。', 'democracy'),
('俄罗斯联邦', '俄罗斯联邦是由22个自治共和国、46个州、9个边疆区、4个自治区、1个自治州、3个联邦直辖市组成的联邦共和立宪制国家。', 'democracy');

-- 6. 插入一个默认用户（密码：password）
INSERT IGNORE INTO `users` (`username`, `password`, `game_id`, `role`) VALUES
('admin', '$2y$10$EixZaYVK1fsbw1ZfbX3OXePaWxn96p36WQoeG6Lruj3vjPGga31lW', 'Admin', 'secretary_general');

-- 7. 插入一些示例案件
INSERT IGNORE INTO `cases` (`case_number`, `title`, `description`, `plaintiff_id`, `status`) VALUES
('案字第001号', '领土争端案', '两个邦国之间的领土争端', 1, 'closed'),
('案字第002号', '贸易纠纷案', '贸易协议违约纠纷', 1, 'closed');

-- 8. 插入一些示例提案
INSERT IGNORE INTO `proposals` (`title`, `description`, `type`, `proposer_id`, `status`) VALUES
('建立国际贸易组织', '建立一个专门的国际贸易组织，促进邦国之间的贸易往来。', 'trade', 1, 'passed'),
('设立维和部队', '设立社区维和部队，维护服务器和平与稳定。', 'defense', 1, 'passed');

-- 9. 插入一些示例仲裁记录
INSERT IGNORE INTO `arbitration_archive` (`case_id`, `case_number`, `title`, `judgment`) VALUES
(1, '案字第001号', '领土争端案', '根据相关公约，争议领土归属于原告邦国。'),
(2, '案字第002号', '贸易纠纷案', '被告邦国需向原告邦国赔偿损失。');

-- 10. 插入一些示例外交关系
INSERT IGNORE INTO `diplomatic_relations` (`country1_id`, `country2_id`, `relation`, `set_by_user_id`) VALUES
(1, 2, 'friendly', 1),
(1, 3, 'neutral', 1),
(2, 3, 'friendly', 1);

-- 11. 插入一些示例贸易信息
INSERT IGNORE INTO `trades` (`type`, `item_name`, `quantity`, `exchange_method`, `country_id`, `posted_by_user_id`) VALUES
('sell', '钻石', '10个', '以物易物或货币', 1, 1),
('buy', '铁锭', '50个', '货币交易', 2, 1);

-- 12. 插入一些示例投票
INSERT IGNORE INTO `votes` (`proposal_id`, `user_id`, `country_id`, `vote`, `has_veto`) VALUES
(1, 1, 1, 'for', TRUE),
(2, 1, 1, 'for', TRUE);

-- 13. 插入一些示例案件证据
INSERT IGNORE INTO `case_evidence` (`case_id`, `uploaded_by_user_id`) VALUES
(1, 1),
(2, 1);

-- 14. 插入一些示例在线玩家
INSERT IGNORE INTO `online_players` (`user_id`, `game_id`, `country_id`) VALUES
(1, 'Admin', 1);

-- 15. 插入一个示例会话
INSERT IGNORE INTO `sessions` (`user_id`, `token`, `expires_at`, `ip_address`, `user_agent`) VALUES
(1, 'test_token', DATE_ADD(NOW(), INTERVAL 7 DAY), '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

-- 提交所有更改
COMMIT;



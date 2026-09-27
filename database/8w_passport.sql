-- ============================================================================
--  8W社区 · 8W通行证系统 —— 数据库完整初始化脚本
-- ----------------------------------------------------------------------------
--  适用：MySQL 8.0+ / MariaDB 10.3+
--  新库名：bgjq8w          （旧库 bgjq 已废弃，见文件末尾的清理说明）
--  新账号：bgjq8w@localhost
--
--  ⚠ 密钥纪律
--  本文件会被提交到公开仓库，因此**不允许**出现任何真实密码。
--  密码位置统一使用占位符 __DB_PASSWORD__，由脚本从 .env 渲染后执行：
--
--      pwsh ./bin/init-database.ps1            # 渲染到临时文件并导入
--
--  若想手工执行，请先自行把 __DB_PASSWORD__ 替换为 .env 中的 DB_PASS 值，
--  并且**不要把替换后的文件提交到仓库**。
--
--  导入方式（推荐）：
--      pwsh ./bin/init-database.ps1
--  导入方式（手工）：
--      mysql -u root -p < 8w_passport.rendered.sql
-- ============================================================================

-- ============================================================================
--  第 1 步：创建新数据库
-- ============================================================================
CREATE DATABASE IF NOT EXISTS `bgjq8w`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- ============================================================================
--  第 2 步：创建数据库账号并授权
--  __DB_PASSWORD__ 由 bin/init-database.ps1 从 .env 的 DB_PASS 注入
-- ============================================================================
CREATE USER IF NOT EXISTS 'bgjq8w'@'localhost' IDENTIFIED BY '__DB_PASSWORD__';

-- 若账号已存在，上面不会改密码；这里强制同步为 .env 中的密码，保证幂等
ALTER USER 'bgjq8w'@'localhost' IDENTIFIED BY '__DB_PASSWORD__';

GRANT ALL PRIVILEGES ON `bgjq8w`.* TO 'bgjq8w'@'localhost';
FLUSH PRIVILEGES;

-- ============================================================================
--  第 3 步：切换到新库
-- ============================================================================
USE `bgjq8w`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================================
--  一、8W通行证 · 身份域
--  通行证是独立系统，第三方应用通过 OAuth2 消费它，不直接碰这些表。
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1.1 passport_accounts —— 通行证账号（全局唯一身份主体）
--      第三方拿到的 user_id 就是这里的 id，对应 OAuth2 的 sub。
--
--      绑定策略：
--        · 必填且不可解绑：游戏内玩家名、简幻通ID —— 简幻通是默认的找回通道
--        · 可选绑定：验证邮箱、FanVerify 账号 —— 用户自行决定绑不绑
--      因此 email / fanverify_* 全部允许为 NULL，且唯一索引允许多个 NULL。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_accounts` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '通行证UID（对外唯一标识 / OAuth2 sub）',
    `username`            VARCHAR(32)     NOT NULL                COMMENT '通行证用户名（登录用）',
    `password_hash`       VARCHAR(255)    NOT NULL                COMMENT '密码哈希（password_hash / PASSWORD_DEFAULT）',

    -- 可选绑定：验证邮箱
    `email`               VARCHAR(191)    NULL                    COMMENT '验证邮箱（可选绑定；NULL = 未绑定）',
    `email_verified_at`   DATETIME        NULL                    COMMENT '邮箱验证通过时间，NULL 表示未绑定或未验证',

    -- 必填绑定：简幻通身份（TODO：接口待对接，见 passport/src/Verification/）
    `simpass_uid`         BIGINT UNSIGNED NULL                    COMMENT '简幻通ID（注册必填）',
    `simpass_level`       TINYINT UNSIGNED NULL                   COMMENT '简幻通等级',
    `simpass_verified_at` DATETIME        NULL                    COMMENT '简幻通验证通过时间',

    -- 可选绑定：FanVerify 账号（TODO：接口待对接）
    `fanverify_uid`         BIGINT UNSIGNED NULL                  COMMENT 'FanVerify 账号ID（可选绑定；NULL = 未绑定）',
    `fanverify_verified_at` DATETIME        NULL                  COMMENT 'FanVerify 验证通过时间',

    -- 必填绑定：游戏内玩家身份（权威第三方提供；玩家名为权威主键，其余为缓存）
    `player_name`         VARCHAR(32)     NOT NULL                COMMENT '游戏内玩家名（权威主键）',
    `player_id`           BIGINT UNSIGNED NULL                    COMMENT '游戏内玩家ID（权威缓存）',
    `country_id`          BIGINT UNSIGNED NULL                    COMMENT '玩家所属邦国ID（权威缓存）',
    `player_synced_at`    DATETIME        NULL                    COMMENT '玩家信息最近同步时间',

    `role`                VARCHAR(32)     NOT NULL DEFAULT 'observer' COMMENT '站内角色 observer/diplomat/peacekeeper/permanent_member/secretary_general',
    `status`              TINYINT         NOT NULL DEFAULT 1      COMMENT '1=正常 2=禁用 3=已注销',

    `last_login_at`       DATETIME        NULL                    COMMENT '最近登录时间',
    `last_login_ip`       VARCHAR(45)     NULL                    COMMENT '最近登录IP',
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username`     (`username`),
    -- 唯一索引允许多个 NULL，所以"可选绑定"不会互相冲突
    UNIQUE KEY `uk_email`        (`email`),
    UNIQUE KEY `uk_simpass_uid`  (`simpass_uid`),
    UNIQUE KEY `uk_fanverify_uid`(`fanverify_uid`),
    UNIQUE KEY `uk_player_name`  (`player_name`),
    KEY `idx_country`   (`country_id`),
    KEY `idx_status`    (`status`),
    KEY `idx_role`      (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='8W通行证账号';

-- ----------------------------------------------------------------------------
--  1.2 passport_sessions —— 通行证登录会话
--      只存 token 的 SHA-256，泄库也无法直接冒用。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_sessions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id`    BIGINT UNSIGNED NOT NULL                COMMENT 'passport_accounts.id',
    `token_hash`    CHAR(64)        NOT NULL                COMMENT '会话令牌 SHA-256',
    `ip_address`    VARCHAR(45)     NULL,
    `user_agent`    VARCHAR(255)    NULL,
    `expires_at`    DATETIME        NOT NULL,
    `last_seen_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token_hash` (`token_hash`),
    KEY `idx_account_expiry` (`account_id`, `expires_at`),
    KEY `idx_last_seen` (`last_seen_at`),
    CONSTRAINT `fk_session_account` FOREIGN KEY (`account_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='8W通行证登录会话';

-- ----------------------------------------------------------------------------
--  1.3 passport_email_codes —— 邮箱验证码
--      TODO：邮件发送接口待对接（passport/src/Verification/HttpEmailVerifier.php）
--      表结构先落地，接口一到位即可直接启用。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_email_codes` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`       VARCHAR(191)    NOT NULL                COMMENT '目标邮箱',
    `scene`       VARCHAR(20)     NOT NULL DEFAULT 'register' COMMENT 'register/rebind/reset',
    `code_hash`   CHAR(64)        NOT NULL                COMMENT '验证码 SHA-256（不落明文）',
    `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0     COMMENT '校验失败次数，超过阈值作废',
    `expires_at`  DATETIME        NOT NULL,
    `consumed_at` DATETIME        NULL                    COMMENT '使用时间，NULL 表示未使用',
    `ip_address`  VARCHAR(45)     NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_email_scene` (`email`, `scene`, `created_at`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='通行证邮箱验证码';


-- ============================================================================
--  二、第三方接入 / API 分发（OAuth 2.0）
-- ============================================================================

-- ----------------------------------------------------------------------------
--  2.1 passport_oauth_clients —— 第三方应用
--      confidential=1 走 client_secret；=0 为公开客户端，必须用 PKCE。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_oauth_clients` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           CHAR(32)        NOT NULL                COMMENT '公开客户端标识',
    `client_secret_hash`  VARCHAR(255)    NULL                    COMMENT 'client_secret 的哈希；公开客户端为 NULL',
    `name`                VARCHAR(64)     NOT NULL                COMMENT '应用名称',
    `description`         VARCHAR(255)    NULL                    COMMENT '应用简介',
    `homepage_url`        VARCHAR(255)    NULL,
    `logo_url`            VARCHAR(255)    NULL,
    `redirect_uris`       TEXT            NULL                    COMMENT '回调地址白名单，每行一个',
    `allowed_scopes`      VARCHAR(255)    NOT NULL DEFAULT 'basic' COMMENT '允许申请的 scope，逗号分隔',
    `is_confidential`     TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '1=机密客户端 0=公开客户端(PKCE)',
    `rate_limit`          INT UNSIGNED    NOT NULL DEFAULT 600    COMMENT '每分钟调用上限',
    `status`              TINYINT         NOT NULL DEFAULT 1      COMMENT '1=启用 0=停用',
    `owner_account_id`    BIGINT UNSIGNED NULL                    COMMENT '申请方通行证UID',
    `last_used_at`        DATETIME        NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_client_id` (`client_id`),
    KEY `idx_status` (`status`),
    KEY `idx_owner` (`owner_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='通行证第三方应用';

-- ----------------------------------------------------------------------------
--  2.2 passport_oauth_codes —— 授权码（一次性，短有效期）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_oauth_codes` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code_hash`             CHAR(64)        NOT NULL                COMMENT '授权码 SHA-256',
    `client_id`             CHAR(32)        NOT NULL,
    `account_id`            BIGINT UNSIGNED NOT NULL,
    `redirect_uri`          VARCHAR(255)    NOT NULL,
    `scopes`                VARCHAR(255)    NOT NULL DEFAULT 'basic',
    `code_challenge`        VARCHAR(128)    NULL                    COMMENT 'PKCE challenge',
    `code_challenge_method` VARCHAR(10)     NULL                    COMMENT 'S256 / plain',
    `expires_at`            DATETIME        NOT NULL,
    `consumed_at`           DATETIME        NULL,
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code_hash` (`code_hash`),
    KEY `idx_client` (`client_id`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `fk_code_account` FOREIGN KEY (`account_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='通行证OAuth授权码';

-- ----------------------------------------------------------------------------
--  2.3 passport_oauth_tokens —— 访问令牌 / 刷新令牌
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_oauth_tokens` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           CHAR(32)        NOT NULL,
    `account_id`          BIGINT UNSIGNED NOT NULL,
    `access_token_hash`   CHAR(64)        NOT NULL                COMMENT '访问令牌 SHA-256',
    `refresh_token_hash`  CHAR(64)        NULL                    COMMENT '刷新令牌 SHA-256',
    `scopes`              VARCHAR(255)    NOT NULL DEFAULT 'basic',
    `grant_type`          VARCHAR(32)     NOT NULL DEFAULT 'authorization_code',
    `access_expires_at`   DATETIME        NOT NULL,
    `refresh_expires_at`  DATETIME        NULL,
    `revoked_at`          DATETIME        NULL                    COMMENT '吊销时间，非 NULL 即失效',
    `last_used_at`        DATETIME        NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_access_hash`  (`access_token_hash`),
    UNIQUE KEY `uk_refresh_hash` (`refresh_token_hash`),
    KEY `idx_client_account` (`client_id`, `account_id`),
    KEY `idx_access_expiry` (`access_expires_at`),
    CONSTRAINT `fk_token_account` FOREIGN KEY (`account_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='通行证OAuth令牌';

-- ----------------------------------------------------------------------------
--  2.4 passport_api_logs —— 第三方 API 调用日志（计费/限流/审计）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passport_api_logs` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`        CHAR(32)        NULL,
    `account_id`       BIGINT UNSIGNED NULL                    COMMENT '用户态调用时记录',
    `endpoint`         VARCHAR(160)    NOT NULL,
    `method`           VARCHAR(10)     NOT NULL,
    `response_status`  SMALLINT        NOT NULL,
    `response_time_ms` INT UNSIGNED    NOT NULL DEFAULT 0,
    `ip_address`       VARCHAR(45)     NULL,
    `user_agent`       VARCHAR(255)    NULL,
    `error_message`    VARCHAR(255)    NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_client_time` (`client_id`, `created_at`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='通行证第三方API调用日志';


-- ============================================================================
--  三、权威数据缓存域
--  数据权威方是第三方游戏服务，本地只做缓存。
--  权威主键：玩家名（players.player_name）、邦国ID（countries.id）。
--  除这两个键以外的一切字段都是缓存，随时可被权威接口覆盖。
--  注意：country_id 上刻意不加外键 —— 缓存可能先于权威数据落库，
--        加外键会导致合法的玩家记录写不进来。
-- ============================================================================

-- ----------------------------------------------------------------------------
--  3.1 countries —— 邦国（权威缓存 + 站内补充字段）
--      id / name / declaration / territory_chunks / population 均来自权威接口
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `countries` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '邦国ID（权威第三方提供）',
    `name`             VARCHAR(64)     NOT NULL                COMMENT '邦国名称（权威缓存）',
    `declaration`      TEXT            NULL                    COMMENT '邦国宣言（权威缓存）',
    `territory_chunks` INT UNSIGNED    NULL                    COMMENT '邦国领土大小 / 领地块数（权威缓存）',
    `population`       INT UNSIGNED    NULL                    COMMENT '邦国人口 = 玩家列表长度（权威缓存）',

    `government_type`  VARCHAR(32)     NOT NULL DEFAULT 'other' COMMENT '站内补充：monarchy/democracy/guild/other',
    `flag_url`         VARCHAR(255)    NULL                    COMMENT '站内补充：旗帜URL',
    `is_active`        TINYINT(1)      NOT NULL DEFAULT 1      COMMENT '站内补充：是否启用',

    `synced_at`        DATETIME        NULL                    COMMENT '权威数据最近同步时间',
    `joined_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '纳入社区时间',
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='邦国（权威数据缓存）';

-- ----------------------------------------------------------------------------
--  3.2 players —— 游戏内玩家（权威缓存）
--      这张表同时承载两个用途：
--        · 玩家信息缓存：按 player_name 查 player_id / country_id
--        · 邦国玩家列表：SELECT player_name, player_id FROM players WHERE country_id = ?
--      只保留规格要求的三个字段：玩家名、玩家ID、玩家所属邦国ID。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `players` (
    `player_name` VARCHAR(32)     NOT NULL                COMMENT '游戏内玩家名（权威主键）',
    `player_id`   BIGINT UNSIGNED NULL                    COMMENT '玩家ID（权威缓存）',
    `country_id`  BIGINT UNSIGNED NULL                    COMMENT '所属邦国ID（权威缓存）',
    `synced_at`   DATETIME        NULL                    COMMENT '权威数据最近同步时间',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`player_name`),
    KEY `idx_country` (`country_id`),
    KEY `idx_player_id` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='游戏内玩家（权威数据缓存）';


-- ============================================================================
--  四、社区域
--  这些表属于 8W社区 自身业务，身份一律引用 passport_accounts.id。
--  列名保持与旧库一致（author_id / user_id / proposer_id ...），
--  以便旧接口文件在不改动的情况下继续读数据。
-- ============================================================================

-- ----------------------------------------------------------------------------
--  4.1 news —— 世界动态
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255)    NOT NULL,
    `content`      TEXT            NOT NULL,
    `author_id`    BIGINT UNSIGNED NOT NULL                COMMENT 'passport_accounts.id',
    `is_headline`  TINYINT(1)      NOT NULL DEFAULT 0,
    `published_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_headline_time` (`is_headline`, `published_at`),
    CONSTRAINT `fk_news_author` FOREIGN KEY (`author_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='世界动态/新闻';

-- ----------------------------------------------------------------------------
--  4.2 timeline —— 历史时间轴
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `timeline` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `date`        DATE            NOT NULL,
    `title`       VARCHAR(255)    NOT NULL,
    `description` TEXT            NULL,
    `event_type`  VARCHAR(50)     NOT NULL DEFAULT 'other' COMMENT 'war/peace/construction/diplomatic/other',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='历史时间轴';

-- ----------------------------------------------------------------------------
--  4.3 proposals —— 社区大会提案
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `proposals` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255)    NOT NULL,
    `description`  TEXT            NOT NULL,
    `type`         VARCHAR(50)     NOT NULL DEFAULT 'other' COMMENT 'territory/defense/trade/embargo/event/other',
    `proposer_id`  BIGINT UNSIGNED NOT NULL                COMMENT 'passport_accounts.id',
    `country_id`   BIGINT UNSIGNED NULL                    COMMENT 'countries.id',
    `status`       VARCHAR(50)     NOT NULL DEFAULT 'draft' COMMENT 'draft/voting/passed/rejected',
    `voting_start` DATETIME        NULL,
    `voting_end`   DATETIME        NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_country` (`country_id`),
    CONSTRAINT `fk_proposal_proposer` FOREIGN KEY (`proposer_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_proposal_country` FOREIGN KEY (`country_id`)
        REFERENCES `countries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='社区大会提案';

-- ----------------------------------------------------------------------------
--  4.4 votes —— 表决记录
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `votes` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `proposal_id` BIGINT UNSIGNED NOT NULL,
    `user_id`     BIGINT UNSIGNED NOT NULL                COMMENT 'passport_accounts.id',
    `country_id`  BIGINT UNSIGNED NULL,
    `vote`        VARCHAR(20)     NOT NULL                COMMENT 'for/against/abstain',
    `has_veto`    TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '常任理事国一票否决',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_proposal_user` (`proposal_id`, `user_id`),
    KEY `idx_country` (`country_id`),
    CONSTRAINT `fk_vote_proposal` FOREIGN KEY (`proposal_id`)
        REFERENCES `proposals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vote_user` FOREIGN KEY (`user_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vote_country` FOREIGN KEY (`country_id`)
        REFERENCES `countries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='提案表决记录';

-- ----------------------------------------------------------------------------
--  4.5 conventions —— 世界公约
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conventions` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`              VARCHAR(255)    NOT NULL,
    `content`            TEXT            NOT NULL,
    `proposal_id`        BIGINT UNSIGNED NULL,
    `enacted_by_user_id` BIGINT UNSIGNED NULL,
    `enacted_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_proposal` (`proposal_id`),
    CONSTRAINT `fk_convention_proposal` FOREIGN KEY (`proposal_id`)
        REFERENCES `proposals` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_convention_actor` FOREIGN KEY (`enacted_by_user_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='世界公约';

-- ----------------------------------------------------------------------------
--  4.6 cases —— 国际法庭案件
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cases` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_number`          VARCHAR(50)     NOT NULL,
    `title`                VARCHAR(255)    NOT NULL,
    `description`          TEXT            NOT NULL,
    `plaintiff_id`         BIGINT UNSIGNED NOT NULL              COMMENT 'passport_accounts.id',
    `defendant_country_id` BIGINT UNSIGNED NULL,
    `status`               VARCHAR(50)     NOT NULL DEFAULT 'filed' COMMENT 'filed/hearing/judged/closed',
    `judgment`             TEXT            NULL,
    `filed_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `judged_at`            DATETIME        NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_case_number` (`case_number`),
    KEY `idx_status` (`status`),
    KEY `idx_defendant` (`defendant_country_id`),
    CONSTRAINT `fk_case_plaintiff` FOREIGN KEY (`plaintiff_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_case_defendant` FOREIGN KEY (`defendant_country_id`)
        REFERENCES `countries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='国际法庭案件';

-- ----------------------------------------------------------------------------
--  4.7 case_evidence —— 案件证据
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `case_evidence` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id`             BIGINT UNSIGNED NOT NULL,
    `uploaded_by_user_id` BIGINT UNSIGNED NULL,
    `file_url`            VARCHAR(255)    NULL,
    `uploaded_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_case` (`case_id`),
    CONSTRAINT `fk_evidence_case` FOREIGN KEY (`case_id`)
        REFERENCES `cases` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_evidence_uploader` FOREIGN KEY (`uploaded_by_user_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='案件证据';

-- ----------------------------------------------------------------------------
--  4.8 arbitration_archive —— 仲裁结果库（判例）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `arbitration_archive` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id`     BIGINT UNSIGNED NOT NULL,
    `case_number` VARCHAR(50)     NOT NULL,
    `title`       VARCHAR(255)    NOT NULL,
    `judgment`    TEXT            NOT NULL,
    `archived_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_case` (`case_id`),
    CONSTRAINT `fk_archive_case` FOREIGN KEY (`case_id`)
        REFERENCES `cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='仲裁结果库';

-- ----------------------------------------------------------------------------
--  4.9 diplomatic_relations —— 外交关系
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `diplomatic_relations` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `country1_id`     BIGINT UNSIGNED NOT NULL,
    `country2_id`     BIGINT UNSIGNED NOT NULL,
    `relation`        VARCHAR(50)     NOT NULL DEFAULT 'neutral' COMMENT 'friendly/hostile/neutral/ceasefire',
    `set_by_user_id`  BIGINT UNSIGNED NOT NULL                   COMMENT 'passport_accounts.id',
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pair` (`country1_id`, `country2_id`),
    CONSTRAINT `fk_relation_c1` FOREIGN KEY (`country1_id`)
        REFERENCES `countries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_relation_c2` FOREIGN KEY (`country2_id`)
        REFERENCES `countries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_relation_actor` FOREIGN KEY (`set_by_user_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='外交关系';

-- ----------------------------------------------------------------------------
--  4.10 trades —— 全球经济与贸易
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trades` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type`               VARCHAR(20)     NOT NULL                COMMENT 'buy/sell',
    `item_name`          VARCHAR(255)    NOT NULL,
    `quantity`           VARCHAR(100)    NULL,
    `exchange_method`    VARCHAR(255)    NULL,
    `country_id`         BIGINT UNSIGNED NULL,
    `posted_by_user_id`  BIGINT UNSIGNED NOT NULL                COMMENT 'passport_accounts.id',
    `status`             VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active/completed/cancelled',
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_country` (`country_id`),
    CONSTRAINT `fk_trade_country` FOREIGN KEY (`country_id`)
        REFERENCES `countries` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_trade_poster` FOREIGN KEY (`posted_by_user_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='贸易信息';

-- ----------------------------------------------------------------------------
--  4.11 services —— 公共服务
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `services` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255)    NOT NULL,
    `url`        VARCHAR(255)    NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='公共服务';


-- ============================================================================
--  五、遗留兼容层（迁移期使用，后续版本移除）
--  见 docs/MIGRATION.md 的分阶段计划。
-- ============================================================================

-- ----------------------------------------------------------------------------
--  5.1 online_players —— 旧的"在线玩家"缓存
--      新架构下"在线"以 passport_sessions.last_seen_at 为准。
--      保留此表只为让旧代码不报错，新代码不再写入。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `online_players` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `game_id`    VARCHAR(255)    NOT NULL,
    `country_id` BIGINT UNSIGNED NULL,
    `last_seen`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user` (`user_id`),
    KEY `idx_last_seen` (`last_seen`),
    CONSTRAINT `fk_online_account` FOREIGN KEY (`user_id`)
        REFERENCES `passport_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='[遗留] 在线玩家缓存，待移除';

-- ----------------------------------------------------------------------------
--  5.2 api_keys —— 旧的静态 API Key
--      新架构统一走 passport_oauth_clients（OAuth2），待迁移完成后移除。
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key_name`     VARCHAR(255)    NOT NULL,
    `api_key`      VARCHAR(255)    NOT NULL,
    `api_secret`   VARCHAR(255)    NOT NULL,
    `allowed_ips`  VARCHAR(500)    NOT NULL DEFAULT '',
    `rate_limit`   INT UNSIGNED    NOT NULL DEFAULT 60,
    `permissions`  VARCHAR(500)    NOT NULL DEFAULT 'users,countries,stats',
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    `last_used_at` DATETIME        NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME        NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='[遗留] 静态API密钥，待迁移到OAuth2';

-- ----------------------------------------------------------------------------
--  5.3 api_logs —— 旧的 API 调用日志
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_logs` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `api_key_id`      BIGINT UNSIGNED NULL,
    `api_key`         VARCHAR(255)    NOT NULL,
    `endpoint`        VARCHAR(255)    NOT NULL,
    `method`          VARCHAR(10)     NOT NULL,
    `query_params`    TEXT            NULL,
    `response_status` INT             NOT NULL,
    `response_time`   INT             NOT NULL,
    `ip_address`      VARCHAR(45)     NOT NULL,
    `user_agent`      TEXT            NULL,
    `error_message`   TEXT            NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_key_time` (`api_key_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='[遗留] API调用日志，待迁移到 passport_api_logs';

-- ----------------------------------------------------------------------------
--  5.4 users 视图 —— 旧代码的只读兼容层
--      旧接口文件里大量 `LEFT JOIN users u ON ... = u.id` 保持可用。
--      身份的唯一真源是 passport_accounts；写入请走通行证 API。
--
--      ⚠ 刻意**不**暴露 password_hash：视图一旦带上密码哈希，
--        任何 `SELECT u.*` 的旧查询都会把它顺手返回给前端。
--        需要校验密码的代码一律走 passport/src（读 passport_accounts 本体）。
-- ----------------------------------------------------------------------------
DROP VIEW IF EXISTS `users`;
CREATE VIEW `users` AS
SELECT
    `id`                AS `id`,
    `username`          AS `username`,
    `player_name`       AS `game_id`,
    `player_id`         AS `player_id`,
    `country_id`        AS `country_id`,
    `role`              AS `role`,
    `simpass_uid`       AS `jhtuid`,
    `simpass_level`     AS `level`,
    `email`             AS `email`,
    `status`            AS `status`,
    `last_login_at`     AS `last_login_at`,
    `created_at`        AS `created_at`
FROM `passport_accounts`;


-- ============================================================================
--  第 4 步：初始数据
-- ============================================================================

-- 默认公共服务
INSERT IGNORE INTO `services` (`name`, `url`) VALUES
('在线地图', 'http://bgjq.simpfun.cn'),
('官方QQ群', 'https://qm.qq.com/q/hELXutcWZy'),
('QQ频道', 'https://pd.qq.com/s/61ds8hzgr');

-- 首个管理员通行证
-- 用户名：LouieMAIN  邮箱：admin@bgjq.top  密码：Lyizai211
-- ⚠ 首次登录后请立刻修改密码与邮箱
INSERT IGNORE INTO `passport_accounts`
    (`username`, `email`, `email_verified_at`, `password_hash`, `player_name`, `role`, `status`)
VALUES (
    'LouieMAIN',
    'admin@bgjq.top',
    NOW(),
    '$2y$10$CBiU1EOLZMOxjJAryMUW9.OShfztcH4c94HT9Q1WKUw1/I0JIRGpm',
    'LouieMAIN',
    'secretary_general',
    1
);


-- ============================================================================
--  第 5 步：旧库清理（默认不执行，确认新库运行正常后再手动放开）
-- ============================================================================
-- ⚠ 该操作不可逆。建议先做一次完整备份：
--      mysqldump -u root -p --databases bgjq > bgjq_backup_$(date +%F).sql
--
-- DROP DATABASE IF EXISTS `bgjq`;
-- DROP USER IF EXISTS 'bgjq'@'localhost';
-- FLUSH PRIVILEGES;

-- ============================================================================
--  初始化完成
--  默认管理员：LouieMAIN / Lyizai211（登录后请立即修改）
-- ============================================================================

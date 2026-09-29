-- ============================================================================
--  升级脚本：邮箱改为可选绑定 + 新增 FanVerify 可选绑定
-- ----------------------------------------------------------------------------
--  适用场景：**已经导入过上一版 8w_passport.sql** 的库。
--  全新部署不需要执行本文件 —— 新库直接用 database/8w_passport.sql 即可，
--  那份脚本里已经是最终结构。
--
--  执行方式：
--      mysql -u root -p bgjq8w < database/upgrade-email-optional-fanverify.sql
--
--  本脚本可重复执行：每一步执行前都会先判断当前状态，已完成的步骤自动跳过。
-- ============================================================================

USE `bgjq8w`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  1. email 由 NOT NULL 改为允许 NULL（改为"可选绑定"）
-- ----------------------------------------------------------------------------
SET @nullable := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'passport_accounts'
      AND COLUMN_NAME = 'email'
      AND IS_NULLABLE = 'YES'
);

SET @sql := IF(@nullable = 0,
    'ALTER TABLE `passport_accounts` MODIFY COLUMN `email` VARCHAR(191) NULL COMMENT ''验证邮箱（可选绑定；NULL = 未绑定）''',
    'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
--  2. 新增 FanVerify 可选绑定字段
--     唯一索引允许多个 NULL，所以未绑定的账号不会互相冲突。
-- ----------------------------------------------------------------------------
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'passport_accounts'
      AND COLUMN_NAME = 'fanverify_uid'
);

SET @sql := IF(@has_col = 0,
    'ALTER TABLE `passport_accounts`
        ADD COLUMN `fanverify_uid` BIGINT UNSIGNED NULL COMMENT ''FanVerify 账号ID（可选绑定；NULL = 未绑定）'' AFTER `simpass_verified_at`,
        ADD COLUMN `fanverify_level` TINYINT UNSIGNED NULL COMMENT ''FanVerify 等级（权威缓存）'' AFTER `fanverify_uid`,
        ADD COLUMN `fanverify_tag` VARCHAR(64) NULL COMMENT ''FanVerify 风险标签（权威缓存）'' AFTER `fanverify_level`,
        ADD COLUMN `fanverify_verified_at` DATETIME NULL COMMENT ''FanVerify 验证通过时间'' AFTER `fanverify_tag`',
    'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
--  2b. 补 fanverify_level / fanverify_tag（针对只加过 fanverify_uid 的中间版本）
-- ----------------------------------------------------------------------------
SET @has_level := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'passport_accounts'
      AND COLUMN_NAME = 'fanverify_level'
);

SET @sql := IF(@has_level = 0,
    'ALTER TABLE `passport_accounts`
        ADD COLUMN `fanverify_level` TINYINT UNSIGNED NULL COMMENT ''FanVerify 等级（权威缓存）'' AFTER `fanverify_uid`,
        ADD COLUMN `fanverify_tag` VARCHAR(64) NULL COMMENT ''FanVerify 风险标签（权威缓存）'' AFTER `fanverify_level`',
    'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
--  3. 新增 fanverify_uid 唯一索引
-- ----------------------------------------------------------------------------
SET @has_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'passport_accounts'
      AND INDEX_NAME = 'uk_fanverify_uid'
);

SET @sql := IF(@has_index = 0,
    'ALTER TABLE `passport_accounts` ADD UNIQUE KEY `uk_fanverify_uid` (`fanverify_uid`)',
    'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
--  4. email 唯一索引确认存在（可选绑定同样需要唯一，只是允许 NULL）
-- ----------------------------------------------------------------------------
SET @has_email_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'passport_accounts'
      AND INDEX_NAME = 'uk_email'
);

SET @sql := IF(@has_email_index = 0,
    'ALTER TABLE `passport_accounts` ADD UNIQUE KEY `uk_email` (`email`)',
    'DO 0');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
--  5. 自检
-- ----------------------------------------------------------------------------
SELECT
    COLUMN_NAME     AS `字段`,
    COLUMN_TYPE     AS `类型`,
    IS_NULLABLE     AS `允许NULL`,
    COLUMN_COMMENT  AS `说明`
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'passport_accounts'
  AND COLUMN_NAME IN ('email', 'email_verified_at', 'simpass_uid', 'fanverify_uid', 'fanverify_verified_at')
ORDER BY ORDINAL_POSITION;

SELECT
    INDEX_NAME  AS `索引`,
    COLUMN_NAME AS `字段`,
    NON_UNIQUE  AS `非唯一`
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'passport_accounts'
  AND INDEX_NAME IN ('uk_email', 'uk_simpass_uid', 'uk_fanverify_uid')
ORDER BY INDEX_NAME;

-- ============================================================================
--  升级完成
--  预期：email 允许 NULL；fanverify_uid / fanverify_verified_at 已存在；
--        uk_email、uk_simpass_uid、uk_fanverify_uid 三个唯一索引齐全。
-- ============================================================================

-- ============================================================
-- 食光菜谱 · 数据库结构（安装程序会自动导入，前缀可自定义）
-- 共 24 张表（含用户评分、同步映射、账号安全、多设备登录与 App 下载）
-- ============================================================

SET NAMES utf8mb4;

-- 1. 站点设置
CREATE TABLE IF NOT EXISTS `sg_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skey` VARCHAR(64) NOT NULL,
  `svalue` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 管理员
CREATE TABLE IF NOT EXISTS `sg_admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(64) NOT NULL DEFAULT '',
  `role` VARCHAR(16) NOT NULL DEFAULT 'super',
  `status` TINYINT NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 前台用户
CREATE TABLE IF NOT EXISTS `sg_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(64) NOT NULL DEFAULT '',
  `avatar` VARCHAR(255) NOT NULL DEFAULT '',
  `role` VARCHAR(16) NOT NULL DEFAULT 'user',
  `status` TINYINT NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 分类
CREATE TABLE IF NOT EXISTS `sg_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `slug` VARCHAR(64) NOT NULL,
  `icon` VARCHAR(32) NOT NULL DEFAULT '',
  `bg` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4b. 自定义分类图标
CREATE TABLE IF NOT EXISTS `sg_icons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(32) NOT NULL,
  `note` VARCHAR(80) NOT NULL DEFAULT '',
  `file` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 菜谱
CREATE TABLE IF NOT EXISTS `sg_recipes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(128) NOT NULL,
  `slug` VARCHAR(128) NOT NULL DEFAULT '',
  `summary` VARCHAR(255) NOT NULL DEFAULT '',
  `category_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `difficulty` VARCHAR(16) NOT NULL DEFAULT '初级',
  `prep_minutes` INT NOT NULL DEFAULT 0,
  `cook_minutes` INT NOT NULL DEFAULT 0,
  `servings` VARCHAR(32) NOT NULL DEFAULT '2 人份',
  `cover` VARCHAR(255) NOT NULL DEFAULT '',
  `author_name` VARCHAR(64) NOT NULL DEFAULT '',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `tips` TEXT NULL,
  `nutrition` VARCHAR(255) NOT NULL DEFAULT '',
  `views` INT NOT NULL DEFAULT 0,
  `fav_count` INT NOT NULL DEFAULT 0,
  `rating` DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  `rating_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
  `is_featured` TINYINT NOT NULL DEFAULT 0,
  `published_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipe_cat` (`category_id`),
  KEY `idx_recipe_status` (`status`, `published_at`),
  KEY `idx_recipe_featured` (`is_featured`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 食材
CREATE TABLE IF NOT EXISTS `sg_recipe_ingredients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `quantity` VARCHAR(64) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ing_recipe` (`recipe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6.1 食材图库（全局字典：按食材名匹配配图）
CREATE TABLE IF NOT EXISTS `sg_ingredients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `image` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ing_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 步骤
CREATE TABLE IF NOT EXISTS `sg_recipe_steps` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id` INT UNSIGNED NOT NULL,
  `step_no` INT NOT NULL DEFAULT 1,
  `title` VARCHAR(128) NOT NULL DEFAULT '',
  `content` TEXT NULL,
  `duration` VARCHAR(64) NOT NULL DEFAULT '',
  `image` VARCHAR(255) NOT NULL DEFAULT '',
  `images` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_step_recipe` (`recipe_id`, `step_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 标签
CREATE TABLE IF NOT EXISTS `sg_tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(32) NOT NULL,
  `slug` VARCHAR(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 菜谱-标签
CREATE TABLE IF NOT EXISTS `sg_recipe_tags` (
  `recipe_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`recipe_id`, `tag_id`),
  KEY `idx_rt_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. 评论
CREATE TABLE IF NOT EXISTS `sg_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `nickname` VARCHAR(64) NOT NULL DEFAULT '',
  `content` TEXT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cmt_recipe` (`recipe_id`, `status`),
  KEY `idx_cmt_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10.1 评论点赞
CREATE TABLE IF NOT EXISTS `sg_comment_likes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cmt_like` (`comment_id`, `user_id`),
  KEY `idx_cmt_like_comment` (`comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. 收藏
CREATE TABLE IF NOT EXISTS `sg_favorites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `recipe_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fav` (`user_id`, `recipe_id`),
  KEY `idx_fav_recipe` (`recipe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. 用户评分
CREATE TABLE IF NOT EXISTS `sg_ratings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipe_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rating_user` (`recipe_id`, `user_id`),
  KEY `idx_rating_recipe` (`recipe_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. 本地 → 云端同步映射（由 api/sync.php 自动创建，也可预先建好）
CREATE TABLE IF NOT EXISTS `sg_sync_map` (
  `skey` VARCHAR(64) NOT NULL,
  `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. 用户 TOTP（可选的两步验证；secret 为 Base32）
CREATE TABLE IF NOT EXISTS `sg_user_totp` (
  `user_id` INT UNSIGNED NOT NULL,
  `secret` VARCHAR(64) NOT NULL,
  `enabled` TINYINT NOT NULL DEFAULT 0,
  `last_step` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. 一次性恢复码 / 管理员发放的重置码（只存哈希）
CREATE TABLE IF NOT EXISTS `sg_recovery_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'recovery',
  `expires_at` DATETIME NULL,
  `used_at` DATETIME NULL,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recovery_user` (`user_id`, `kind`, `used_at`),
  KEY `idx_recovery_expire` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. 前台客服工单
CREATE TABLE IF NOT EXISTS `sg_support_tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_no` VARCHAR(32) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `contact` VARCHAR(255) NOT NULL DEFAULT '',
  `subject` VARCHAR(120) NOT NULL DEFAULT '',
  `body` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `reply` TEXT NULL,
  `admin_note` TEXT NULL,
  `handled_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket_no` (`ticket_no`),
  KEY `idx_ticket_status` (`status`, `created_at`),
  KEY `idx_ticket_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. 管理员操作审计
CREATE TABLE IF NOT EXISTS `sg_admin_audit` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(40) NOT NULL,
  `target` VARCHAR(80) NOT NULL DEFAULT '',
  `detail` TEXT NULL,
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_time` (`created_at`),
  KEY `idx_audit_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. 登录/找回密码频率限制
CREATE TABLE IF NOT EXISTS `sg_auth_throttle` (
  `bucket` CHAR(64) NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_at` DATETIME NOT NULL,
  `blocked_until` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. 用户多设备登录会话
CREATE TABLE IF NOT EXISTS `sg_user_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `device_name` VARCHAR(120) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_token` (`token_hash`),
  KEY `idx_session_user` (`user_id`, `revoked_at`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. 手机扫码登录挑战
CREATE TABLE IF NOT EXISTS `sg_qr_logins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `requester_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `requester_ua` VARCHAR(255) NOT NULL DEFAULT '',
  `device_name` VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `approved_at` DATETIME NULL,
  `consumed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_qr_token` (`token_hash`),
  KEY `idx_qr_status` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 24. App 下载卡片（独立下载页）
CREATE TABLE IF NOT EXISTS `sg_app_downloads` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform` VARCHAR(16) NOT NULL DEFAULT 'other',
  `title` VARCHAR(80) NOT NULL,
  `ver` VARCHAR(32) NOT NULL DEFAULT '',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `url` TEXT NOT NULL,
  `app_link` VARCHAR(255) NOT NULL DEFAULT '',
  `size` VARCHAR(32) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dl_order` (`status`, `sort`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

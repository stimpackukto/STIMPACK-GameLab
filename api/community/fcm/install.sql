CREATE DATABASE IF NOT EXISTS `lightguardian_community`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lightguardian_community`.`fcm_notification_category` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `default_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lightguardian_community`.`fcm_api_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(120) NOT NULL,
  `auth_token` VARCHAR(128) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_device` (`account_id`, `device_id`),
  KEY `idx_token` (`auth_token`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lightguardian_community`.`fcm_device_token` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(120) NOT NULL,
  `fcm_token` VARCHAR(255) NOT NULL,
  `app_version` VARCHAR(50) NOT NULL DEFAULT '',
  `platform` VARCHAR(20) NOT NULL DEFAULT 'android',
  `device_info` VARCHAR(255) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device` (`device_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_token` (`fcm_token`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lightguardian_community`.`fcm_user_preference` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT UNSIGNED NOT NULL,
  `category_code` VARCHAR(50) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_category` (`account_id`, `category_code`),
  KEY `idx_category_enabled` (`category_code`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `lightguardian_community`.`fcm_notification_category`
  (`code`, `name`, `description`, `sort_order`, `default_enabled`, `is_active`)
VALUES
  ('notice', '서버 공지', '중요 공지와 안내 알림', 10, 1, 1),
  ('event', '이벤트 알림', '이벤트 시작 및 보상 안내', 20, 1, 1),
  ('outdoor_pvp', '야외전장 알림', '지옥불, 실리더스, 나그란드 전장 알림', 30, 1, 1),
  ('raid', '공격대 알림', '주간 공격대 및 레이드 관련 알림', 40, 1, 1),
  ('auction', '경매 알림', '입찰, 낙찰, 환불 관련 알림', 50, 1, 1),
  ('mail', '우편 알림', '게임 우편 관련 알림', 60, 1, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`),
  `is_active` = VALUES(`is_active`);

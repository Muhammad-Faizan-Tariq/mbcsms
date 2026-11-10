-- MBC SMS Database Schema
-- MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Create Database
CREATE DATABASE IF NOT EXISTS `mbc_sms` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `mbc_sms`;

-- =====================================================
-- USERS TABLE (Manager, Agents, Clients)
-- =====================================================
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `role` ENUM('manager', 'agent', 'client') NOT NULL DEFAULT 'client',
    `parent_id` INT UNSIGNED DEFAULT NULL COMMENT 'For clients: agent_id, For agents: NULL',
    `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    `api_token` VARCHAR(64) DEFAULT NULL,
    `last_login` DATETIME DEFAULT NULL,
    `login_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_api_token` (`api_token`),
    KEY `idx_parent` (`parent_id`),
    KEY `idx_role` (`role`),
    CONSTRAINT `fk_user_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- USER PROFILES TABLE
-- =====================================================
CREATE TABLE `user_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `full_name` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `company` VARCHAR(255) DEFAULT NULL,
    `timezone` VARCHAR(50) DEFAULT 'UTC',
    `avatar` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user` (`user_id`),
    CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- USER BALANCES TABLE
-- =====================================================
CREATE TABLE `user_balances` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `currency` ENUM('USD', 'EUR', 'GBP') NOT NULL DEFAULT 'USD',
    `balance` DECIMAL(15, 6) DEFAULT 0.000000,
    `held_balance` DECIMAL(15, 6) DEFAULT 0.000000 COMMENT 'Balance on hold',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_currency` (`user_id`, `currency`),
    CONSTRAINT `fk_balance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SMS RANGES TABLE
-- =====================================================
CREATE TABLE `sms_ranges` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `manager_id` INT UNSIGNED NOT NULL,
    `range_name` VARCHAR(255) NOT NULL,
    `prefix` VARCHAR(20) NOT NULL,
    `currency` ENUM('USD', 'EUR', 'GBP') NOT NULL DEFAULT 'USD',
    `payout_1_1` DECIMAL(10, 6) DEFAULT NULL,
    `payout_7_1` DECIMAL(10, 6) DEFAULT NULL,
    `payout_7_7` DECIMAL(10, 6) DEFAULT NULL,
    `payout_30_45` DECIMAL(10, 6) DEFAULT NULL,
    `test_number` VARCHAR(50) DEFAULT NULL,
    `total_numbers` INT UNSIGNED DEFAULT 0,
    `available_numbers` INT UNSIGNED DEFAULT 0,
    `memo` TEXT DEFAULT NULL,
    `status` ENUM('active', 'inactive', 'exhausted') DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_manager` (`manager_id`),
    KEY `idx_prefix` (`prefix`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_range_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SMS NUMBERS TABLE
-- =====================================================
CREATE TABLE `sms_numbers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `range_id` INT UNSIGNED NOT NULL,
    `number` VARCHAR(50) NOT NULL,
    `is_test` TINYINT(1) DEFAULT 0,
    `assigned_to` INT UNSIGNED DEFAULT NULL COMMENT 'User ID (agent or client)',
    `assigned_at` DATETIME DEFAULT NULL,
    `status` ENUM('available', 'assigned', 'reserved') DEFAULT 'available',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_number` (`number`),
    KEY `idx_range` (`range_id`),
    KEY `idx_assigned` (`assigned_to`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_number_range` FOREIGN KEY (`range_id`) REFERENCES `sms_ranges` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_number_user` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SMS CDR TABLE (Call Detail Records)
-- =====================================================
CREATE TABLE `sms_cdr` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `date_time` DATETIME NOT NULL,
    `range_id` INT UNSIGNED DEFAULT NULL,
    `number` VARCHAR(50) NOT NULL,
    `cli` VARCHAR(50) DEFAULT NULL COMMENT 'Caller ID',
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Agent or Client',
    `sms_count` INT UNSIGNED DEFAULT 1,
    `sms_type` ENUM('general', 'test') DEFAULT 'general',
    `currency` ENUM('USD', 'EUR', 'GBP') DEFAULT 'USD',
    `my_payout` DECIMAL(10, 6) DEFAULT 0.000000 COMMENT 'Manager payout',
    `user_payout` DECIMAL(10, 6) DEFAULT 0.000000 COMMENT 'Agent/Client payout',
    `profit` DECIMAL(10, 6) DEFAULT 0.000000,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_date` (`date_time`),
    KEY `idx_range` (`range_id`),
    KEY `idx_number` (`number`),
    KEY `idx_user` (`user_id`),
    KEY `idx_type` (`sms_type`),
    CONSTRAINT `fk_cdr_range` FOREIGN KEY (`range_id`) REFERENCES `sms_ranges` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cdr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- NUMBER REQUESTS TABLE
-- =====================================================
CREATE TABLE `number_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `range_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    `pay_term` VARCHAR(20) DEFAULT '1/1',
    `status` ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    `notes` TEXT DEFAULT NULL,
    `processed_by` INT UNSIGNED DEFAULT NULL,
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_range` (`range_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_request_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_request_range` FOREIGN KEY (`range_id`) REFERENCES `sms_ranges` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_request_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- NEWS TABLE
-- =====================================================
CREATE TABLE `news` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `author_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `target_role` ENUM('all', 'agent', 'client') DEFAULT 'all',
    `status` ENUM('draft', 'published', 'archived') DEFAULT 'published',
    `published_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_author` (`author_id`),
    KEY `idx_status` (`status`),
    KEY `idx_target` (`target_role`),
    CONSTRAINT `fk_news_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PAYMENT REQUESTS TABLE
-- =====================================================
CREATE TABLE `payment_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `currency` ENUM('USD', 'EUR', 'GBP') NOT NULL,
    `amount` DECIMAL(15, 6) NOT NULL,
    `method` VARCHAR(50) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    `processed_by` INT UNSIGNED DEFAULT NULL,
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_payment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payment_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CREDIT NOTES TABLE
-- =====================================================
CREATE TABLE `credit_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `currency` ENUM('USD', 'EUR', 'GBP') NOT NULL,
    `amount` DECIMAL(15, 6) NOT NULL,
    `type` ENUM('credit', 'debit') NOT NULL,
    `reference` VARCHAR(255) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_credit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_credit_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- USER ACTIVITY LOG TABLE
-- =====================================================
CREATE TABLE `user_activity` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_date` (`created_at`),
    CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- NOTIFICATIONS TABLE
-- =====================================================
CREATE TABLE `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_read` (`is_read`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- API CONFIGURATION TABLE (Jasmin Gateway)
-- =====================================================
CREATE TABLE `api_config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `manager_id` INT UNSIGNED NOT NULL,
    `config_key` VARCHAR(100) NOT NULL,
    `config_value` TEXT DEFAULT NULL,
    `is_encrypted` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_manager_key` (`manager_id`, `config_key`),
    CONSTRAINT `fk_config_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- LOGIN ATTEMPTS TABLE (Rate Limiting)
-- =====================================================
CREATE TABLE `login_attempts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempts` INT DEFAULT 1,
    `locked_until` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_username` (`username`),
    KEY `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CAPTCHA SESSION TABLE
-- =====================================================
CREATE TABLE `captcha_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` VARCHAR(64) NOT NULL,
    `captcha_answer` INT NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session` (`session_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERT DEFAULT MANAGER ACCOUNT
-- =====================================================
INSERT INTO `users` (`username`, `password`, `email`, `role`, `status`, `api_token`) VALUES
('MBCSMS', SHA2(CONCAT('mbc_sms_secure_salt_2024', 'MBCSMS'), 256), 'manager@mbc-sms.com', 'manager', 'active', SHA2(CONCAT('mbc_sms_secure_salt_2024', 'manager_token_', NOW()), 256));

INSERT INTO `user_profiles` (`user_id`, `full_name`, `phone`, `timezone`) VALUES
(1, 'MBC SMS Manager', '+1234567890', 'UTC');

INSERT INTO `user_balances` (`user_id`, `currency`, `balance`) VALUES
(1, 'USD', 1000.000000),
(1, 'EUR', 500.000000),
(1, 'GBP', 250.000000);

-- =====================================================
-- SAMPLE DATA FOR TESTING
-- =====================================================

-- Sample Agent
INSERT INTO `users` (`username`, `password`, `email`, `role`, `parent_id`, `status`, `api_token`) VALUES
('agent1', SHA2(CONCAT('mbc_sms_secure_salt_2024', 'agent1'), 256), 'agent1@mbc-sms.com', 'agent', 1, 'active', SHA2(CONCAT('mbc_sms_secure_salt_2024', 'agent_token_', NOW()), 256));

INSERT INTO `user_profiles` (`user_id`, `full_name`, `phone`, `timezone`) VALUES
(2, 'Test Agent', '+1987654321', 'UTC');

INSERT INTO `user_balances` (`user_id`, `currency`, `balance`) VALUES
(2, 'USD', 100.000000);

-- Sample Client
INSERT INTO `users` (`username`, `password`, `email`, `role`, `parent_id`, `status`, `api_token`) VALUES
('client1', SHA2(CONCAT('mbc_sms_secure_salt_2024', 'client1'), 256), 'client1@mbc-sms.com', 'client', 2, 'active', SHA2(CONCAT('mbc_sms_secure_salt_2024', 'client_token_', NOW()), 256));

INSERT INTO `user_profiles` (`user_id`, `full_name`, `phone`, `timezone`) VALUES
(3, 'Test Client', '+1122334455', 'UTC');

INSERT INTO `user_balances` (`user_id`, `currency`, `balance`) VALUES
(3, 'USD', 50.000000);

-- Sample SMS Range
INSERT INTO `sms_ranges` (`manager_id`, `range_name`, `prefix`, `currency`, `payout_1_1`, `payout_7_1`, `payout_7_7`, `payout_30_45`, `test_number`, `total_numbers`, `available_numbers`, `memo`, `status`) VALUES
(1, 'UK Premium', '447', 'USD', 0.0500, 0.0400, NULL, NULL, '4471234567', 100, 95, 'UK Mobile Numbers', 'active');

-- Sample Numbers
INSERT INTO `sms_numbers` (`range_id`, `number`, `is_test`, `status`) VALUES
(1, '4471234567', 1, 'available'),
(1, '4471234568', 0, 'available'),
(1, '4471234569', 0, 'available');

-- Sample News
INSERT INTO `news` (`author_id`, `title`, `content`, `target_role`, `status`) VALUES
(1, 'Welcome to MBC SMS', 'Welcome to the MBC SMS platform. We are excited to have you on board!', 'all', 'published');

SET FOREIGN_KEY_CHECKS = 1;

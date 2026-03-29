-- ============================================================
-- OAuth Authentication Tables Migration
-- Simplified Version (Tables Only - No Initial Data)
-- ============================================================

-- ============================================================
-- TABLE: app_user
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_user` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider_id` VARCHAR(100) NOT NULL UNIQUE COMMENT 'ค่าอ่านได้จาก Provider ID token',
  `health_account_id` VARCHAR(100) COMMENT 'Account ID จาก Health ID server',
  
  -- Profile Data from Provider
  `name_th` VARCHAR(255) COMMENT 'ชื่อ-นามสกุล ภาษาไทย',
  `name_eng` VARCHAR(255) COMMENT 'First Middle Last Name',
  `position_name` VARCHAR(255) COMMENT 'ตำแหน่ง',
  
  -- Status
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  
  -- Tracking
  `first_login_at` DATETIME COMMENT 'ครั้งแรกที่ login',
  `last_login_at` DATETIME COMMENT 'ครั้งล่าสุด',
  `last_login_ip` VARCHAR(45) COMMENT 'IPv4 or IPv6',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_id` (`provider_id`),
  KEY `idx_health_account_id` (`health_account_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_last_login_at` (`last_login_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ผู้ใช้ระบบ - ลง Register จาก Provider ID OAuth';

-- ============================================================
-- TABLE: app_user_role
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_user_role` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `app_user_id` bigint(20) NOT NULL,
  `role` VARCHAR(50) NOT NULL COMMENT 'admin, manager, viewer, user',
  
  -- Assignment Info
  `assigned_by` VARCHAR(100) COMMENT 'ใครกำหนด - default: system',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`app_user_id`, `role`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `app_user_role_ibfk_1` FOREIGN KEY (`app_user_id`) 
    REFERENCES `app_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='บทบาท/สิทธิของผู้ใช้ - 1 user สามารถมีหลาย role';

-- ============================================================
-- TABLE: app_user_org
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_user_org` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `app_user_id` bigint(20) NOT NULL,
  `hcode` VARCHAR(20) NOT NULL COMMENT 'รหัสสถานพยาบาล (moph standard)',
  `hname_th` VARCHAR(255) COMMENT 'ชื่อหน่วยงาน ไทย',
  `hname_eng` VARCHAR(255) COMMENT 'ชื่อหน่วยงาน Eng',
  `zone_id` INT COMMENT 'Zone/Region ID',
  
  -- Status
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'หน่วยงานเริ่มต้นของผู้ใช้',
  `synced_from_provider` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'ข้อมูล sync จาก Provider',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  
  -- Tracking
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_org` (`app_user_id`, `hcode`),
  KEY `idx_hcode` (`hcode`),
  KEY `idx_zone_id` (`zone_id`),
  KEY `idx_is_default` (`is_default`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `app_user_org_ibfk_1` FOREIGN KEY (`app_user_id`) 
    REFERENCES `app_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='หน่วยงาน/องค์กรของผู้ใช้ - 1 user สามารถประจำหลายหน่วยงาน';

-- ============================================================
-- TABLE: app_login_audit
-- ============================================================
CREATE TABLE IF NOT EXISTS `app_login_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `app_user_id` bigint(20) COMMENT 'NULL ถ้า login ล้มเหลว',
  `provider_id` VARCHAR(100) COMMENT 'Provider ID พยายาม login',
  
  -- Event
  `event_type` ENUM('login_success', 'login_failed', 'logout', 'access_denied', 'provider_error') NOT NULL,
  `outcome_code` VARCHAR(50) COMMENT 'error code จาก provider หรือ system',
  `error_message` TEXT COMMENT 'ข้อความ error (token ต้องมาสก แบบ last 8 chars เท่านั้น)',
  
  -- Request Info
  `ip_address` VARCHAR(45) COMMENT 'IPv4 or IPv6 address',
  `user_agent` TEXT COMMENT 'Browser user agent',
  `response_time_ms` INT COMMENT 'duration of auth process',
  
  -- Tracking
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_app_user_id` (`app_user_id`),
  KEY `idx_provider_id` (`provider_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `app_login_audit_ibfk_1` FOREIGN KEY (`app_user_id`) 
    REFERENCES `app_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log สำหรับการ login / logout / failed attempts';

-- ============================================================
-- SUCCESS MESSAGE
-- ============================================================
-- Tables created successfully!
-- Ready for OAuth authentication system

-- ================================================================
-- Migration: ระบบ OAuth Login ผ่าน Health ID + Provider ID
-- Version: 1.0
-- Database: hospital_kpi
-- Compatibility: MySQL 5.7 + / MariaDB 10.1+
-- ================================================================
-- หมายเหตุ: 
-- - ใช้ provider_id เป็น unique key หลัก
-- - รองรับหลาย organization ต่อ 1 user
-- - รองรับ role หลายตัวต่อ user
-- - Audit login success / fail / logout
-- - ตาราง tb_users เดิมจะถูกปิดใช้งาน ไม่ใช้ลบ

-- ============================================================
-- TABLE: app_user
-- ============================================================
-- เก็บข้อมูลผู้ใช้ที่ลงทะเบียนผ่าน Health ID และมี Provider ID
-- primary key: provider_id (unique identifier จาก Provider)
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_user` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'Local auto-increment ID for internal reference',
  `provider_id` varchar(100) NOT NULL UNIQUE COMMENT 'Unique ID from Provider - PRIMARY IDENTIFIER',
  `health_account_id` varchar(100) COMMENT 'Health ID account identifier',
  `provider_account_id` varchar(100) COMMENT 'Provider account ID',
  `name_th` varchar(255) COMMENT 'ชื่อ-นามสกุล ภาษาไทย',
  `name_eng` varchar(255) COMMENT 'Name in English',
  `hcode` varchar(20) COMMENT 'รหัสสถานที่บริการ (hospital code)',
  `hname_th` varchar(255) COMMENT 'ชื่อสถานที่บริการ ภาษาไทย',
  `position_name` varchar(255) COMMENT 'ตำแหน่ง/ยศ',
  `position_type` varchar(50) COMMENT 'ประเภทตำแหน่ง เช่น professional, support',
  `license_id_verify` tinyint(1) DEFAULT 0 COMMENT 'สถานะตรวจสอบใบอนุญาต (0=ยังไม่ตรวจสอบ, 1=ตรวจสอบสำเร็จ)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'สถานะการใช้งาน (0=ปิด, 1=เปิด)',
  `first_login_at` datetime COMMENT 'วันเวลา login ครั้งแรก',
  `last_login_at` datetime COMMENT 'วันเวลา login ล่าสุด',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_id` (`provider_id`),
  KEY `idx_health_account_id` (`health_account_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_last_login_at` (`last_login_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='ข้อมูลผู้ใช้ของระบบ - ลงทะเบียนผ่าน Health ID + Provider ID';

-- ============================================================
-- TABLE: app_user_role
-- ============================================================
-- เก็บ role ของแต่ละ user
-- role: user, admin, manager, report_viewer, finance, auditor, etc.
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_user_role` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `app_user_id` bigint(20) NOT NULL COMMENT 'Reference to app_user.id',
  `role` varchar(50) NOT NULL COMMENT 'Role name: user, admin, manager, report_viewer, finance, auditor',
  `assigned_by` varchar(100) COMMENT 'ผู้ที่มอบหมาย (admin user provider_id)',
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`app_user_id`, `role`),
  KEY `idx_app_user_id` (`app_user_id`),
  KEY `idx_role` (`role`),
  FOREIGN KEY (`app_user_id`) REFERENCES `app_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Role assignment สำหรับแต่ละ user';

-- ============================================================
-- TABLE: app_user_org
-- ============================================================
-- เก็บ organization ที่เกี่ยวข้อง (จาก Provider profile หรือ manual)
-- รองรับหลาย org ต่อ 1 user
-- is_default = 1 => default organization
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_user_org` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `app_user_id` bigint(20) NOT NULL,
  `hcode` varchar(20) NOT NULL COMMENT 'Hospital code จาก Provider',
  `hname_th` varchar(255) COMMENT 'ชื่อสถานที่บริการ ภาษาไทย',
  `hname_eng` varchar(255) COMMENT 'Hospital name in English',
  `zone_id` varchar(50) COMMENT 'Zone/Region ID',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'องค์กรเริ่มต้น',
  `synced_from_provider` tinyint(1) DEFAULT 1 COMMENT 'ดึงจาก Provider profile (1=ใช่, 0=เพิ่มเอง)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_org` (`app_user_id`, `hcode`),
  KEY `idx_app_user_id` (`app_user_id`),
  KEY `idx_hcode` (`hcode`),
  KEY `idx_is_default` (`app_user_id`, `is_default`),
  FOREIGN KEY (`app_user_id`) REFERENCES `app_user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Organization/Hospital ที่เกี่ยวข้องกับ user';

-- ============================================================
-- TABLE: app_login_audit
-- ============================================================
-- Audit log สำหรับการ login / logout / failed attempt
-- ช่วยในการตรวจสอบความปลอดภัย
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_login_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `app_user_id` bigint(20) COMMENT 'Reference to app_user.id (NULL if login failed before identifying user)',
  `provider_id` varchar(100) COMMENT 'Provider ID (captured from auth token)',
  `event_type` varchar(50) NOT NULL COMMENT 'login_success, login_failed, logout, access_denied, provider_error',
  `outcome_code` varchar(50) COMMENT 'OK, NO_PROVIDER_ID, PROVIDER_ERROR, INVALID_STATE, CONFIG_MISSING, etc.',
  `ip_address` varchar(50) COMMENT 'IP address ของผู้ใช้',
  `user_agent` varchar(500) COMMENT 'User-Agent header',
  `error_message` text COMMENT 'ข้อความ error (ห้าม log token เต็ม)',
  `response_time_ms` int COMMENT 'ระยะเวลาการ process (milliseconds)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_app_user_id` (`app_user_id`),
  KEY `idx_provider_id` (`provider_id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`app_user_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='Audit log สำหรับการ login / logout / failed attempts';

-- ============================================================
-- INITIAL DATA: Default Roles
-- ============================================================
-- Role สำหรับระบบ
INSERT IGNORE INTO `app_user_role` (`id`, `app_user_id`, `role`, `assigned_by`, `assigned_at`, `is_active`) 
VALUES 
  (1, 1, 'user', 'system', NOW(), 1),
  (2, 1, 'admin', 'system', NOW(), 1);

-- ============================================================
-- NOTES
-- ============================================================
-- 1. ตาราง tb_users เดิมจะถูกปิดใช้งาน ห้ามลบ (อาจใช้ migrate ข้อมูลเดิมถ้าต้องการ)
-- 2. ต้องสร้าง index ให้ดี เพราะพบ user ด้วย provider_id บ่อยครั้ง
-- 3. app_login_audit ต้องเก็บประวัติให้ยาว ๆ (อย่างนอย 3-6 เดือน)
-- 4. ให้ schedule job ลบข้อมูลเก่าออก weekly
-- 5. นะห้าม log token แบบเต็ม ใน error_message - ชิดท้าย last 8 chars เท่านั้น


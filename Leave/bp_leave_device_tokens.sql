CREATE TABLE IF NOT EXISTS `bp_device_tokens` (
  `unique_id` varchar(50) NOT NULL,
  `staff_id` varchar(50) NOT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'android',
  `fcm_token` text NOT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `updated` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`unique_id`),
  UNIQUE KEY `uniq_bp_device_tokens_token` (`fcm_token`(191)),
  KEY `idx_bp_device_tokens_staff` (`staff_id`),
  KEY `idx_bp_device_tokens_active` (`staff_id`, `is_active`, `is_delete`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

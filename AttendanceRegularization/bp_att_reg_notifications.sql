-- In-app notification store for the Attendance Regularization module.
--
-- att_reg_helpers.php creates this table on first use if it is missing, so the
-- module is self-installing. Running this script on a clean deploy is still
-- preferred: it avoids the first request paying for the DDL, and it makes the
-- schema reviewable alongside the Leave and WFH equivalents.
--
-- Mirrors bp_leave_notifications / bp_wfh_notifications, with
-- att_reg_unique_id as the foreign key into attendance_regularization.

CREATE TABLE IF NOT EXISTS `bp_att_reg_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `unique_id` varchar(64) NOT NULL,
  `to_staff_id` varchar(64) NOT NULL,
  `from_staff_id` varchar(64) DEFAULT NULL,
  `att_reg_unique_id` varchar(64) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `deep_link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bp_att_reg_notifications_uid` (`unique_id`),
  KEY `idx_bp_att_reg_notifications_to_staff` (`to_staff_id`),
  KEY `idx_bp_att_reg_notifications_reg` (`att_reg_unique_id`),
  KEY `idx_bp_att_reg_notifications_unread` (`to_staff_id`, `is_read`, `is_delete`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

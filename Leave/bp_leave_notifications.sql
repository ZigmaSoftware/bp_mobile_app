CREATE TABLE IF NOT EXISTS `bp_leave_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unique_id` varchar(50) NOT NULL,
  `to_staff_id` varchar(50) NOT NULL,
  `from_staff_id` varchar(50) DEFAULT NULL,
  `leave_unique_id` varchar(50) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `deep_link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created` datetime DEFAULT NULL,
  `updated` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_unique_id` (`unique_id`),
  KEY `idx_to_staff_id` (`to_staff_id`),
  KEY `idx_leave_unique_id` (`leave_unique_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_is_delete` (`is_delete`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


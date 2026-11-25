CREATE TABLE IF NOT EXISTS `la_wenan_seed_card_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `store_id` int unsigned NOT NULL,
  `mode` varchar(8) NOT NULL,
  `group_id` int unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `processed` int unsigned NOT NULL DEFAULT 0,
  `context` text NOT NULL,
  `items` text NOT NULL,
  `error` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `lease_until` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `owner` (`tenant_id`,`user_id`,`store_id`,`mode`,`id`),
  KEY `queue` (`status`,`lease_until`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

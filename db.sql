CREATE TABLE IF NOT EXISTS `ips` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) DEFAULT NULL,
  `mac` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `important` tinyint(4) DEFAULT NULL,
  `repeater` tinyint(4) DEFAULT NULL,
  `web` tinyint(4) DEFAULT NULL,
  `router` tinyint(4) unsigned DEFAULT NULL,
  `dns` varchar(50) DEFAULT NULL,
  `netboot_image_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `mac` (`mac`),
  UNIQUE KEY `ip` (`ip`),
  KEY `ips_netboot_image_id` (`netboot_image_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `leases` (
  `ip` varchar(50) DEFAULT NULL,
  `starts` varchar(50) DEFAULT NULL,
  `ends` varchar(50) DEFAULT NULL,
  `tstp` varchar(50) DEFAULT NULL,
  `cltt` varchar(50) DEFAULT NULL,
  `hardware-ethernet` varchar(50) DEFAULT NULL,
  `client-hostname` varchar(50) DEFAULT NULL,
  `vendor-class-identifier` varchar(50) DEFAULT NULL,
  KEY `leases_ip` (`ip`),
  KEY `leases_hardware_ethernet` (`hardware-ethernet`),
  KEY `leases_ends` (`ends`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `ping` (
  `ip` varchar(50) NOT NULL,
  `mac` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`),
  KEY `ping_mac` (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `range` (
  `ip_begin` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `type` varchar(50) COLLATE utf8_bin DEFAULT NULL,
  KEY `range_ip_begin` (`ip_begin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE IF NOT EXISTS `stats` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date_begin` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_end` datetime DEFAULT CURRENT_TIMESTAMP,
  `nb_scan` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`),
  KEY `stats_ip_date_begin` (`ip`, `date_begin`),
  KEY `stats_date_begin` (`date_begin`) USING BTREE,
  KEY `stats_date_end` (`date_end`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `stats_old` (
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `scans` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `state` varchar(20) NOT NULL DEFAULT 'running',
  `status` varchar(50) DEFAULT NULL,
  `date_begin` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_end` datetime DEFAULT NULL,
  `duration` int(11) unsigned DEFAULT NULL,
  `ports_count` int(11) unsigned NOT NULL DEFAULT '0',
  `snapshot_id` int(11) unsigned DEFAULT NULL,
  `result_changed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scans_ip_date` (`ip`, `date_begin`),
  KEY `scans_ip_id` (`ip`, `id`),
  KEY `scans_snapshot_id` (`snapshot_id`),
  KEY `scans_state` (`state`),
  KEY `scans_queue` (`state`, `mode`, `id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `scan_snapshots` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `result_hash` char(64) NOT NULL,
  `xml` mediumblob NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshots_result` (`ip`, `mode`, `result_hash`),
  KEY `scan_snapshots_ip_mode_id` (`ip`, `mode`, `id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `users` (
  `login` varchar(50) DEFAULT NULL,
  `pass` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `vendors` (
  `mac` varchar(50) NOT NULL,
  `vendors` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `netboot_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `netboot_images_filename` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE INDEX IF NOT EXISTS `leases_ip` ON `leases` (`ip`);
CREATE INDEX IF NOT EXISTS `leases_hardware_ethernet` ON `leases` (`hardware-ethernet`);
CREATE INDEX IF NOT EXISTS `leases_ends` ON `leases` (`ends`);
CREATE INDEX IF NOT EXISTS `ping_mac` ON `ping` (`mac`);
CREATE INDEX IF NOT EXISTS `range_ip_begin` ON `range` (`ip_begin`);
CREATE INDEX IF NOT EXISTS `stats_ip_date_begin` ON `stats` (`ip`, `date_begin`);
ALTER TABLE `ips` ADD COLUMN IF NOT EXISTS `netboot_image_id` int(10) unsigned DEFAULT NULL AFTER `dns`;
CREATE INDEX IF NOT EXISTS `ips_netboot_image_id` ON `ips` (`netboot_image_id`);
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `snapshot_id` int(11) unsigned DEFAULT NULL AFTER `ports_count`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `result_changed` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `snapshot_id`;
CREATE INDEX IF NOT EXISTS `scans_ip_id` ON `scans` (`ip`, `id`);
CREATE INDEX IF NOT EXISTS `scans_snapshot_id` ON `scans` (`snapshot_id`);
CREATE INDEX IF NOT EXISTS `scans_queue` ON `scans` (`state`, `mode`, `id`);
DROP INDEX IF EXISTS `scans_ip_xml_hash_id` ON `scans`;
ALTER TABLE `scans` DROP COLUMN IF EXISTS `xml_hash`;
ALTER TABLE `scans` DROP COLUMN IF EXISTS `xml`;

UPDATE scans
SET state=IF(
      date_begin IS NOT NULL AND date_begin <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 HOUR),
      'timeout',
      'cancelled'
    ),
    date_end=COALESCE(date_end, CURRENT_TIMESTAMP),
    duration=COALESCE(duration, IF(date_begin IS NULL, 0, GREATEST(0, TIMESTAMPDIFF(SECOND, date_begin, CURRENT_TIMESTAMP)))),
    error=COALESCE(NULLIF(error, ''), IF(
      date_begin IS NOT NULL AND date_begin <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 HOUR),
      'nmap timed out after 2 hours',
      'cancelled at boot'
    ))
WHERE state='running';

UPDATE ping SET status='Down' WHERE status IS NULL OR status='';
UPDATE stats SET status='Down' WHERE status IS NULL OR status='';

DROP PROCEDURE IF EXISTS `update_status`;
DELIMITER ;;
CREATE PROCEDURE `update_status`(IN p_ip VARCHAR(50), IN p_mac VARCHAR(50), IN p_status VARCHAR(50))
BEGIN
DECLARE var_id INT;
DECLARE var_status VARCHAR(50);
DECLARE var_ip VARCHAR(50);
DECLARE var_mac VARCHAR(50);
DECLARE var_date_begin DATETIME;
DECLARE var_next_status VARCHAR(50);
SET var_next_status = IFNULL(NULLIF(TRIM(p_status), ''), 'Down');
SELECT id,mac,ip,status,date_begin INTO var_id,var_mac,var_ip,var_status,var_date_begin FROM stats WHERE ip=p_ip ORDER BY id DESC LIMIT 1;
IF var_status = var_next_status AND (var_ip=p_ip OR (var_ip IS NULL AND p_ip IS NULL)) AND (var_mac=p_mac OR (var_mac IS NULL AND p_mac IS NULL)) THEN
  UPDATE stats SET date_end=CURRENT_TIMESTAMP, nb_scan=nb_scan+1 WHERE id=var_id;
ELSE
  INSERT INTO stats (ip,mac,status) VALUES (p_ip, p_mac, var_next_status);
END IF;
END;;
DELIMITER ;

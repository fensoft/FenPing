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
  `scan_profile` varchar(20) NOT NULL DEFAULT 'deep',
  `scan_interval_hours` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `mac` (`mac`),
  UNIQUE KEY `ip` (`ip`),
  KEY `ips_netboot_image_id` (`netboot_image_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `leases` (
  `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hardware-ethernet` char(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client-hostname` varchar(255) DEFAULT NULL,
  `ends` datetime NOT NULL,
  `first_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`hardware-ethernet`, `ip`),
  KEY `leases_ip` (`ip`),
  KEY `leases_ends` (`ends`),
  KEY `leases_active_last_seen` (`active`, `last_seen`),
  KEY `leases_mac_last_seen` (`hardware-ethernet`, `last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_approvals` (
  `mac` char(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `approved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`mac`),
  KEY `device_approvals_approved_at` (`approved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS `migrate_leases_v2`;
DELIMITER ;;
CREATE PROCEDURE `migrate_leases_v2`()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='leases' AND column_name='starts'
  ) THEN
    DROP TABLE IF EXISTS `leases_v2`;
    CREATE TABLE `leases_v2` (
      `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `hardware-ethernet` char(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      `client-hostname` varchar(255) DEFAULT NULL,
      `ends` datetime NOT NULL,
      `first_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `last_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
      PRIMARY KEY (`hardware-ethernet`, `ip`),
      KEY `leases_ip` (`ip`),
      KEY `leases_ends` (`ends`),
      KEY `leases_active_last_seen` (`active`, `last_seen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    INSERT INTO `leases_v2`
      (`ip`, `hardware-ethernet`, `client-hostname`, `ends`, `first_seen`, `last_seen`, `active`)
    SELECT
      TRIM(`ip`),
      LOWER(TRIM(`hardware-ethernet`)),
      MAX(NULLIF(TRIM(`client-hostname`), '')),
      MAX(COALESCE(STR_TO_DATE(NULLIF(`ends`, ''), '%Y-%m-%d %H:%i:%s'), CURRENT_TIMESTAMP)),
      MIN(COALESCE(STR_TO_DATE(NULLIF(`starts`, ''), '%Y-%m-%d %H:%i:%s'), CURRENT_TIMESTAMP)),
      MAX(COALESCE(
        STR_TO_DATE(NULLIF(`cltt`, ''), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(NULLIF(`starts`, ''), '%Y-%m-%d %H:%i:%s'),
        CURRENT_TIMESTAMP
      )),
      1
    FROM `leases`
    WHERE INET_ATON(TRIM(`ip`)) IS NOT NULL
      AND LOWER(TRIM(`hardware-ethernet`)) REGEXP '^([0-9a-f]{2}:){5}[0-9a-f]{2}$'
    GROUP BY TRIM(`ip`), LOWER(TRIM(`hardware-ethernet`));

    DROP TABLE IF EXISTS `leases_legacy`;
    RENAME TABLE `leases` TO `leases_legacy`, `leases_v2` TO `leases`;
    DROP TABLE `leases_legacy`;
  END IF;
END;;
DELIMITER ;
CALL `migrate_leases_v2`();
DROP PROCEDURE IF EXISTS `migrate_leases_v2`;
DROP TABLE IF EXISTS `leases_legacy`;
DROP TABLE IF EXISTS `leases_v2`;

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
  `port_changes_processed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `scanner` varchar(50) DEFAULT NULL,
  `scanner_version` varchar(50) DEFAULT NULL,
  `scan_args` text DEFAULT NULL,
  `host_reason` varchar(100) DEFAULT NULL,
  `host_reason_ttl` smallint(5) unsigned DEFAULT NULL,
  `last_boot` datetime DEFAULT NULL,
  `uptime_seconds` bigint(20) unsigned DEFAULT NULL,
  `distance` smallint(5) unsigned DEFAULT NULL,
  `error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scans_ip_date` (`ip`, `date_begin`),
  KEY `scans_ip_id` (`ip`, `id`),
  KEY `scans_snapshot_id` (`snapshot_id`),
  KEY `scans_state` (`state`),
  KEY `scans_queue` (`state`, `mode`, `id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `scan_port_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scan_id` int(11) unsigned NOT NULL,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `change_type` varchar(20) NOT NULL,
  `protocol` varchar(10) NOT NULL,
  `port` int(11) unsigned NOT NULL,
  `previous_service` varchar(255) DEFAULT NULL,
  `previous_version` text DEFAULT NULL,
  `current_service` varchar(255) DEFAULT NULL,
  `current_version` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_port_changes_scan_port` (`scan_id`, `protocol`, `port`),
  KEY `scan_port_changes_created` (`created_at`),
  KEY `scan_port_changes_ip_created` (`ip`, `created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS `discard_legacy_scan_xml`;
DELIMITER ;;
CREATE PROCEDURE `discard_legacy_scan_xml`()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='scan_snapshots' AND column_name='xml'
  ) THEN
    DELETE FROM `scan_port_changes`;
    DELETE FROM `scans`;
    DROP TABLE IF EXISTS `scan_snapshot_script_nodes`;
    DROP TABLE IF EXISTS `scan_snapshot_scripts`;
    DROP TABLE IF EXISTS `scan_snapshot_trace_hops`;
    DROP TABLE IF EXISTS `scan_snapshot_os_cpes`;
    DROP TABLE IF EXISTS `scan_snapshot_os_classes`;
    DROP TABLE IF EXISTS `scan_snapshot_os_matches`;
    DROP TABLE IF EXISTS `scan_snapshot_extra_reasons`;
    DROP TABLE IF EXISTS `scan_snapshot_extra_ports`;
    DROP TABLE IF EXISTS `scan_snapshot_port_cpes`;
    DROP TABLE IF EXISTS `scan_snapshot_ports`;
    DROP TABLE IF EXISTS `scan_snapshot_hostnames`;
    DROP TABLE IF EXISTS `scan_snapshot_addresses`;
    DROP TABLE IF EXISTS `scan_snapshot_scopes`;
    DROP TABLE `scan_snapshots`;
  END IF;
END;;
DELIMITER ;
CALL `discard_legacy_scan_xml`();
DROP PROCEDURE IF EXISTS `discard_legacy_scan_xml`;

CREATE TABLE IF NOT EXISTS `scan_snapshots` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `result_hash` char(64) NOT NULL,
  `content_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshots_content` (`ip`, `mode`, `content_hash`),
  KEY `scan_snapshots_result` (`ip`, `mode`, `result_hash`),
  KEY `scan_snapshots_ip_mode_id` (`ip`, `mode`, `id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_scopes` (
  `snapshot_id` int(11) unsigned NOT NULL,
  `protocol` varchar(10) NOT NULL,
  `port_begin` int(11) unsigned NOT NULL,
  `port_end` int(11) unsigned NOT NULL,
  PRIMARY KEY (`snapshot_id`, `protocol`, `port_begin`, `port_end`),
  CONSTRAINT `scan_snapshot_scopes_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `address` varchar(255) NOT NULL,
  `address_type` varchar(20) NOT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshot_addresses_item` (`snapshot_id`, `address_type`, `address`),
  CONSTRAINT `scan_snapshot_addresses_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_hostnames` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `hostname_type` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshot_hostnames_item` (`snapshot_id`, `hostname_type`, `hostname`),
  CONSTRAINT `scan_snapshot_hostnames_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_ports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `protocol` varchar(10) NOT NULL,
  `port` int(11) unsigned NOT NULL,
  `state` varchar(30) NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `reason_ttl` smallint(5) unsigned DEFAULT NULL,
  `service` varchar(255) DEFAULT NULL,
  `product` varchar(255) DEFAULT NULL,
  `version` varchar(255) DEFAULT NULL,
  `extra_info` text DEFAULT NULL,
  `tunnel` varchar(50) DEFAULT NULL,
  `method` varchar(50) DEFAULT NULL,
  `confidence` tinyint(3) unsigned DEFAULT NULL,
  `os_type` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshot_ports_item` (`snapshot_id`, `protocol`, `port`),
  KEY `scan_snapshot_ports_service` (`service`, `port`),
  CONSTRAINT `scan_snapshot_ports_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_port_cpes` (
  `port_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `cpe` varchar(1024) NOT NULL,
  PRIMARY KEY (`port_id`, `position`),
  CONSTRAINT `scan_snapshot_port_cpes_port_fk` FOREIGN KEY (`port_id`) REFERENCES `scan_snapshot_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_extra_ports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `state` varchar(30) NOT NULL,
  `count` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `scan_snapshot_extra_ports_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_extra_reasons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `extra_port_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `reason` varchar(100) NOT NULL,
  `count` int(11) unsigned NOT NULL,
  `protocol` varchar(10) DEFAULT NULL,
  `ports` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `scan_snapshot_extra_reasons_port_fk` FOREIGN KEY (`extra_port_id`) REFERENCES `scan_snapshot_extra_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_os_matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `name` varchar(1024) NOT NULL,
  `accuracy` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `scan_snapshot_os_matches_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_os_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `os_match_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  `os_family` varchar(255) DEFAULT NULL,
  `os_generation` varchar(255) DEFAULT NULL,
  `device_type` varchar(255) DEFAULT NULL,
  `accuracy` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `scan_snapshot_os_classes_match_fk` FOREIGN KEY (`os_match_id`) REFERENCES `scan_snapshot_os_matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_os_cpes` (
  `os_class_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `cpe` varchar(1024) NOT NULL,
  PRIMARY KEY (`os_class_id`, `position`),
  CONSTRAINT `scan_snapshot_os_cpes_class_fk` FOREIGN KEY (`os_class_id`) REFERENCES `scan_snapshot_os_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_scripts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `port_id` bigint(20) unsigned DEFAULT NULL,
  `position` int(11) unsigned NOT NULL,
  `script_id` varchar(255) NOT NULL,
  `output` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_scripts_snapshot` (`snapshot_id`, `port_id`),
  CONSTRAINT `scan_snapshot_scripts_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scan_snapshot_scripts_port_fk` FOREIGN KEY (`port_id`) REFERENCES `scan_snapshot_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_script_nodes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `script_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `position` int(11) unsigned NOT NULL,
  `node_type` varchar(10) NOT NULL,
  `node_key` varchar(1024) DEFAULT NULL,
  `value` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_script_nodes_parent` (`script_id`, `parent_id`, `position`),
  CONSTRAINT `scan_snapshot_script_nodes_script_fk` FOREIGN KEY (`script_id`) REFERENCES `scan_snapshot_scripts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scan_snapshot_script_nodes_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `scan_snapshot_script_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `scan_snapshot_trace_hops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `protocol` varchar(10) DEFAULT NULL,
  `port` int(11) unsigned DEFAULT NULL,
  `ttl` smallint(5) unsigned NOT NULL,
  `ip` varchar(255) NOT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `rtt` decimal(12,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `scan_snapshot_trace_hops_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `login` varchar(50) DEFAULT NULL,
  `pass` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `oui_vendors` (
  `prefix_length` tinyint(3) unsigned NOT NULL,
  `prefix` varchar(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `vendor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`prefix_length`, `prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
CREATE INDEX IF NOT EXISTS `leases_ends` ON `leases` (`ends`);
CREATE INDEX IF NOT EXISTS `leases_active_last_seen` ON `leases` (`active`, `last_seen`);
CREATE INDEX IF NOT EXISTS `leases_mac_last_seen` ON `leases` (`hardware-ethernet`, `last_seen`);
CREATE INDEX IF NOT EXISTS `device_approvals_approved_at` ON `device_approvals` (`approved_at`);
CREATE INDEX IF NOT EXISTS `ping_mac` ON `ping` (`mac`);
CREATE INDEX IF NOT EXISTS `range_ip_begin` ON `range` (`ip_begin`);
CREATE INDEX IF NOT EXISTS `stats_ip_date_begin` ON `stats` (`ip`, `date_begin`);
DROP TABLE IF EXISTS `vendors`;
ALTER TABLE `ips` ADD COLUMN IF NOT EXISTS `netboot_image_id` int(10) unsigned DEFAULT NULL AFTER `dns`;
ALTER TABLE `ips` ADD COLUMN IF NOT EXISTS `scan_profile` varchar(20) NOT NULL DEFAULT 'deep' AFTER `netboot_image_id`;
ALTER TABLE `ips` ADD COLUMN IF NOT EXISTS `scan_interval_hours` int(10) unsigned NOT NULL DEFAULT 1 AFTER `scan_profile`;
CREATE INDEX IF NOT EXISTS `ips_netboot_image_id` ON `ips` (`netboot_image_id`);
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `snapshot_id` int(11) unsigned DEFAULT NULL AFTER `ports_count`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `result_changed` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `snapshot_id`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `port_changes_processed` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `result_changed`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `scanner` varchar(50) DEFAULT NULL AFTER `port_changes_processed`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `scanner_version` varchar(50) DEFAULT NULL AFTER `scanner`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `scan_args` text DEFAULT NULL AFTER `scanner_version`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `host_reason` varchar(100) DEFAULT NULL AFTER `scan_args`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `host_reason_ttl` smallint(5) unsigned DEFAULT NULL AFTER `host_reason`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `last_boot` datetime DEFAULT NULL AFTER `host_reason_ttl`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `uptime_seconds` bigint(20) unsigned DEFAULT NULL AFTER `last_boot`;
ALTER TABLE `scans` ADD COLUMN IF NOT EXISTS `distance` smallint(5) unsigned DEFAULT NULL AFTER `uptime_seconds`;
CREATE INDEX IF NOT EXISTS `scans_ip_id` ON `scans` (`ip`, `id`);
CREATE INDEX IF NOT EXISTS `scans_snapshot_id` ON `scans` (`snapshot_id`);
CREATE INDEX IF NOT EXISTS `scans_queue` ON `scans` (`state`, `mode`, `id`);
CREATE INDEX IF NOT EXISTS `scan_port_changes_created` ON `scan_port_changes` (`created_at`);
CREATE INDEX IF NOT EXISTS `scan_port_changes_ip_created` ON `scan_port_changes` (`ip`, `created_at`);
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
  UPDATE stats
  SET date_end=CURRENT_TIMESTAMP, nb_scan=nb_scan+1
  WHERE id=var_id
    AND (date_end IS NULL OR date_end <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY));
ELSE
  INSERT INTO stats (ip,mac,status) VALUES (p_ip, p_mac, var_next_status);
END IF;
END;;
DELIMITER ;

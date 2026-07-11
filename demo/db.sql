/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ping` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci */;

USE `ping`;
DROP TABLE IF EXISTS `device_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_approvals` (
  `mac` char(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`mac`),
  KEY `device_approvals_approved_at` (`approved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `device_approvals` WRITE;
/*!40000 ALTER TABLE `device_approvals` DISABLE KEYS */;
INSERT INTO `device_approvals` VALUES
('3c:22:fb:95:02:05','2026-06-06 14:44:51'),
('8c:f5:a3:92:02:02','2026-06-21 14:44:51');
/*!40000 ALTER TABLE `device_approvals` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ips` (
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
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `ips` WRITE;
/*!40000 ALTER TABLE `ips` DISABLE KEYS */;
INSERT INTO `ips` VALUES
(1,'gateway','74:83:c2:10:00:01','192.168.1.1',1,NULL,1,1,'1.1.1.1 9.9.9.9',NULL,'deep',24),
(2,'nas','00:11:32:20:00:10','192.168.1.10',1,NULL,1,NULL,NULL,NULL,'deep',24),
(3,'backup-node','b8:27:eb:30:00:15','192.168.1.15',1,NULL,1,NULL,NULL,NULL,'standard',12),
(4,'office-pc','f8:db:88:40:00:42','192.168.1.42',NULL,NULL,NULL,NULL,NULL,NULL,'standard',24),
(5,'living-room-tv','f4:f5:d8:50:00:90','192.168.1.90',NULL,NULL,1,NULL,NULL,NULL,'lightweight',24),
(6,'netboot-client','b8:27:eb:60:01:20','192.168.1.120',NULL,NULL,NULL,NULL,NULL,1,'lightweight',0),
(7,'laser-printer','3c:52:a1:70:02:10','192.168.1.210',NULL,NULL,1,NULL,NULL,NULL,'deep',168),
(8,'front-camera','00:40:8c:80:02:20','192.168.1.220',1,NULL,1,NULL,NULL,NULL,'standard',12);
/*!40000 ALTER TABLE `ips` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `leases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leases` (
  `ip` varchar(45) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `hardware-ethernet` char(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `client-hostname` varchar(255) DEFAULT NULL,
  `ends` datetime NOT NULL,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`hardware-ethernet`,`ip`),
  KEY `leases_ip` (`ip`),
  KEY `leases_ends` (`ends`),
  KEY `leases_active_last_seen` (`active`,`last_seen`),
  KEY `leases_mac_last_seen` (`hardware-ethernet`,`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `leases` WRITE;
/*!40000 ALTER TABLE `leases` DISABLE KEYS */;
INSERT INTO `leases` VALUES
('192.168.1.201','3c:22:fb:91:02:01','alex-iphone','2026-07-11 22:44:51','2026-06-25 14:44:51','2026-07-11 14:42:51',1),
('192.168.1.205','3c:22:fb:95:02:05','old-phone','2026-06-29 14:44:51','2026-06-01 14:44:51','2026-06-29 14:44:51',0),
('192.168.1.210','3c:52:a1:70:02:10','laser-printer','2026-07-11 23:44:51','2026-05-12 14:44:51','2026-07-11 14:39:51',1),
('192.168.1.202','8c:f5:a3:92:02:02','meeting-tablet','2026-07-12 00:44:51','2026-06-10 14:44:51','2026-07-11 14:41:51',1),
('192.168.1.204','d8:f1:5b:94:02:04','air-quality','2026-07-12 01:44:51','2026-07-09 14:44:51','2026-07-11 14:40:51',1),
('192.168.1.203','f8:db:88:93:02:03','guest-laptop','2026-07-11 16:44:51','2026-07-10 14:44:51','2026-07-11 14:07:51',1);
/*!40000 ALTER TABLE `leases` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `netboot_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `netboot_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `netboot_images_filename` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `netboot_images` WRITE;
/*!40000 ALTER TABLE `netboot_images` DISABLE KEYS */;
INSERT INTO `netboot_images` VALUES
(1,'Linux Rescue','demo-linux-rescue.ipxe','linux-rescue.ipxe',223,'2026-06-20 14:44:51'),
(2,'Hardware Diagnostics','demo-diagnostics.ipxe','diagnostics.ipxe',201,'2026-06-27 14:44:51');
/*!40000 ALTER TABLE `netboot_images` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `oui_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oui_vendors` (
  `prefix_length` tinyint(3) unsigned NOT NULL,
  `prefix` varchar(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `vendor` varchar(255) NOT NULL,
  PRIMARY KEY (`prefix_length`,`prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `oui_vendors` WRITE;
/*!40000 ALTER TABLE `oui_vendors` DISABLE KEYS */;
INSERT INTO `oui_vendors` VALUES
(6,'001132','Synology Incorporated'),
(6,'00408C','Axis Communications AB'),
(6,'3C22FB','Apple, Inc.'),
(6,'3C52A1','HP Inc.'),
(6,'7483C2','Ubiquiti Inc.'),
(6,'8CF5A3','Samsung Electronics'),
(6,'B827EB','Raspberry Pi Foundation'),
(6,'D8F15B','Espressif Inc.'),
(6,'F4F5D8','Google, Inc.'),
(6,'F8DB88','Dell Technologies');
/*!40000 ALTER TABLE `oui_vendors` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `ping`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ping` (
  `ip` varchar(50) NOT NULL,
  `mac` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ip`),
  KEY `ping_mac` (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `ping` WRITE;
/*!40000 ALTER TABLE `ping` DISABLE KEYS */;
INSERT INTO `ping` VALUES
('192.168.1.1','74:83:c2:10:00:01','Up','2026-07-11 14:42:51'),
('192.168.1.10','00:11:32:20:00:10','Up','2026-07-11 14:42:51'),
('192.168.1.120','b8:27:eb:60:01:20','Down','2026-07-11 14:42:51'),
('192.168.1.15','b8:27:eb:30:00:15','Down','2026-07-11 14:42:51'),
('192.168.1.201','3c:22:fb:91:02:01','Up','2026-07-11 14:42:51'),
('192.168.1.202','8c:f5:a3:92:02:02','Up','2026-07-11 14:42:51'),
('192.168.1.203','f8:db:88:93:02:03','Down','2026-07-11 14:42:51'),
('192.168.1.204','d8:f1:5b:94:02:04','Up','2026-07-11 14:42:51'),
('192.168.1.210','3c:52:a1:70:02:10','Up','2026-07-11 14:42:51'),
('192.168.1.220','00:40:8c:80:02:20','Down','2026-07-11 14:42:51'),
('192.168.1.42','f8:db:88:40:00:42','Up','2026-07-11 14:42:51'),
('192.168.1.90','f4:f5:d8:50:00:90','Up','2026-07-11 14:42:51');
/*!40000 ALTER TABLE `ping` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `range`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `range` (
  `ip_begin` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_ai_ci DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  KEY `range_ip_begin` (`ip_begin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `range` WRITE;
/*!40000 ALTER TABLE `range` DISABLE KEYS */;
INSERT INTO `range` VALUES
('192.168.1.1','Infrastructure'),
('192.168.1.40','Computers'),
('192.168.1.60','Entertainment & IoT'),
('192.168.1.120','Lab'),
('192.168.1.200','DHCP & Reserved');
/*!40000 ALTER TABLE `range` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_port_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_port_changes` (
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_port_changes_scan_port` (`scan_id`,`protocol`,`port`),
  KEY `scan_port_changes_created` (`created_at`),
  KEY `scan_port_changes_ip_created` (`ip`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_port_changes` WRITE;
/*!40000 ALTER TABLE `scan_port_changes` DISABLE KEYS */;
INSERT INTO `scan_port_changes` VALUES
(1,2,'192.168.1.10','deep','appeared','tcp',5001,NULL,NULL,'https','Synology DSM 7.2','2026-07-11 09:44:51'),
(2,5,'192.168.1.210','deep','changed','tcp',80,'http','HP Embedded Web Server 4.2','http','HP Embedded Web Server 5.1','2026-07-11 02:44:51'),
(3,6,'192.168.1.220','standard','disappeared','tcp',22,'ssh','Dropbear sshd 2020.81',NULL,NULL,'2026-07-11 10:44:51');
/*!40000 ALTER TABLE `scan_port_changes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `address` varchar(255) NOT NULL,
  `address_type` varchar(20) NOT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshot_addresses_item` (`snapshot_id`,`address_type`,`address`),
  CONSTRAINT `scan_snapshot_addresses_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_addresses` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_addresses` DISABLE KEYS */;
INSERT INTO `scan_snapshot_addresses` VALUES
(1,1,0,'192.168.1.1','ipv4',NULL),
(2,1,1,'74:83:C2:10:00:01','mac','Ubiquiti Inc.'),
(3,2,0,'192.168.1.10','ipv4',NULL),
(4,2,1,'00:11:32:20:00:10','mac','Synology Incorporated'),
(5,3,0,'192.168.1.42','ipv4',NULL),
(6,3,1,'F8:DB:88:40:00:42','mac','Dell Technologies'),
(7,4,0,'192.168.1.90','ipv4',NULL),
(8,4,1,'F4:F5:D8:50:00:90','mac','Google, Inc.'),
(9,5,0,'192.168.1.210','ipv4',NULL),
(10,5,1,'3C:52:A1:70:02:10','mac','HP Inc.'),
(11,6,0,'192.168.1.220','ipv4',NULL),
(12,6,1,'00:40:8C:80:02:20','mac','Axis Communications AB');
/*!40000 ALTER TABLE `scan_snapshot_addresses` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_extra_ports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_extra_ports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `state` varchar(30) NOT NULL,
  `count` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_extra_ports_snapshot_fk` (`snapshot_id`),
  CONSTRAINT `scan_snapshot_extra_ports_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_extra_ports` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_extra_ports` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_extra_ports` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_extra_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_extra_reasons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `extra_port_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `reason` varchar(100) NOT NULL,
  `count` int(11) unsigned NOT NULL,
  `protocol` varchar(10) DEFAULT NULL,
  `ports` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_extra_reasons_port_fk` (`extra_port_id`),
  CONSTRAINT `scan_snapshot_extra_reasons_port_fk` FOREIGN KEY (`extra_port_id`) REFERENCES `scan_snapshot_extra_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_extra_reasons` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_extra_reasons` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_extra_reasons` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_hostnames`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_hostnames` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `hostname` varchar(255) NOT NULL,
  `hostname_type` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshot_hostnames_item` (`snapshot_id`,`hostname_type`,`hostname`),
  CONSTRAINT `scan_snapshot_hostnames_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_hostnames` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_hostnames` DISABLE KEYS */;
INSERT INTO `scan_snapshot_hostnames` VALUES
(1,1,0,'gateway','PTR'),
(2,2,0,'nas','PTR'),
(3,3,0,'office-pc','PTR'),
(4,4,0,'living-room-tv','PTR'),
(5,5,0,'laser-printer','PTR'),
(6,6,0,'front-camera','PTR');
/*!40000 ALTER TABLE `scan_snapshot_hostnames` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_os_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_os_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `os_match_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `vendor` varchar(255) DEFAULT NULL,
  `os_family` varchar(255) DEFAULT NULL,
  `os_generation` varchar(255) DEFAULT NULL,
  `device_type` varchar(255) DEFAULT NULL,
  `accuracy` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_os_classes_match_fk` (`os_match_id`),
  CONSTRAINT `scan_snapshot_os_classes_match_fk` FOREIGN KEY (`os_match_id`) REFERENCES `scan_snapshot_os_matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_os_classes` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_os_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_os_classes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_os_cpes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_os_cpes` (
  `os_class_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `cpe` varchar(1024) NOT NULL,
  PRIMARY KEY (`os_class_id`,`position`),
  CONSTRAINT `scan_snapshot_os_cpes_class_fk` FOREIGN KEY (`os_class_id`) REFERENCES `scan_snapshot_os_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_os_cpes` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_os_cpes` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_os_cpes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_os_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_os_matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `name` varchar(1024) NOT NULL,
  `accuracy` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_os_matches_snapshot_fk` (`snapshot_id`),
  CONSTRAINT `scan_snapshot_os_matches_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_os_matches` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_os_matches` DISABLE KEYS */;
INSERT INTO `scan_snapshot_os_matches` VALUES
(1,1,0,'Linux 5.15 - 6.8',100),
(2,2,0,'Linux 5.10',100),
(3,3,0,'Microsoft Windows 11',100),
(4,4,0,'Linux 4.9',96),
(5,5,0,'HP printer embedded Linux',100),
(6,6,0,'Linux 4.14',100);
/*!40000 ALTER TABLE `scan_snapshot_os_matches` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_port_cpes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_port_cpes` (
  `port_id` bigint(20) unsigned NOT NULL,
  `position` int(11) unsigned NOT NULL,
  `cpe` varchar(1024) NOT NULL,
  PRIMARY KEY (`port_id`,`position`),
  CONSTRAINT `scan_snapshot_port_cpes_port_fk` FOREIGN KEY (`port_id`) REFERENCES `scan_snapshot_ports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_port_cpes` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_port_cpes` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_port_cpes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_ports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_ports` (
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
  UNIQUE KEY `scan_snapshot_ports_item` (`snapshot_id`,`protocol`,`port`),
  KEY `scan_snapshot_ports_service` (`service`,`port`),
  CONSTRAINT `scan_snapshot_ports_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_ports` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_ports` DISABLE KEYS */;
INSERT INTO `scan_snapshot_ports` VALUES
(1,1,'tcp',22,'open',NULL,NULL,'ssh','OpenSSH','9.6',NULL,NULL,NULL,NULL,NULL),
(2,1,'tcp',53,'open',NULL,NULL,'domain','dnsmasq','2.90',NULL,NULL,NULL,NULL,NULL),
(3,1,'tcp',80,'open',NULL,NULL,'http','nginx','1.26.1',NULL,NULL,NULL,NULL,NULL),
(4,1,'tcp',443,'open',NULL,NULL,'https','nginx','1.26.1',NULL,'ssl',NULL,NULL,NULL),
(5,2,'tcp',22,'open',NULL,NULL,'ssh','OpenSSH','9.2',NULL,NULL,NULL,NULL,NULL),
(6,2,'tcp',80,'open',NULL,NULL,'http','nginx','1.24.0',NULL,NULL,NULL,NULL,NULL),
(7,2,'tcp',443,'open',NULL,NULL,'https','nginx','1.24.0',NULL,'ssl',NULL,NULL,NULL),
(8,2,'tcp',445,'open',NULL,NULL,'microsoft-ds','Samba smbd','4.18.6',NULL,NULL,NULL,NULL,NULL),
(9,2,'tcp',5001,'open',NULL,NULL,'https','Synology DSM','7.2',NULL,'ssl',NULL,NULL,NULL),
(10,3,'tcp',135,'open',NULL,NULL,'msrpc','Microsoft Windows RPC',NULL,NULL,NULL,NULL,NULL,NULL),
(11,3,'tcp',445,'open',NULL,NULL,'microsoft-ds','Microsoft Windows 11',NULL,NULL,NULL,NULL,NULL,NULL),
(12,3,'tcp',3389,'open',NULL,NULL,'ms-wbt-server','Microsoft Terminal Services',NULL,NULL,NULL,NULL,NULL,NULL),
(13,4,'tcp',8008,'open',NULL,NULL,'http','Google Cast',NULL,NULL,NULL,NULL,NULL,NULL),
(14,4,'tcp',8009,'open',NULL,NULL,'ajp13','Google Cast',NULL,NULL,NULL,NULL,NULL,NULL),
(15,5,'tcp',80,'open',NULL,NULL,'http','HP Embedded Web Server',NULL,NULL,NULL,NULL,NULL,NULL),
(16,5,'tcp',443,'open',NULL,NULL,'https','HP Embedded Web Server',NULL,NULL,'ssl',NULL,NULL,NULL),
(17,5,'tcp',631,'open',NULL,NULL,'ipp','CUPS','2.4',NULL,NULL,NULL,NULL,NULL),
(18,5,'tcp',9100,'open',NULL,NULL,'jetdirect','HP JetDirect',NULL,NULL,NULL,NULL,NULL,NULL),
(19,6,'tcp',80,'open',NULL,NULL,'http','AXIS Camera Station','5.56',NULL,NULL,NULL,NULL,NULL),
(20,6,'tcp',443,'open',NULL,NULL,'https','AXIS Camera Station','5.56',NULL,'ssl',NULL,NULL,NULL),
(21,6,'tcp',554,'open',NULL,NULL,'rtsp','AXIS Media Control',NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `scan_snapshot_ports` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_scopes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_scopes` (
  `snapshot_id` int(11) unsigned NOT NULL,
  `protocol` varchar(10) NOT NULL,
  `port_begin` int(11) unsigned NOT NULL,
  `port_end` int(11) unsigned NOT NULL,
  PRIMARY KEY (`snapshot_id`,`protocol`,`port_begin`,`port_end`),
  CONSTRAINT `scan_snapshot_scopes_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_scopes` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_scopes` DISABLE KEYS */;
INSERT INTO `scan_snapshot_scopes` VALUES
(1,'tcp',1,65535),
(2,'tcp',1,65535),
(5,'tcp',1,65535);
/*!40000 ALTER TABLE `scan_snapshot_scopes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_script_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_script_nodes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `script_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `position` int(11) unsigned NOT NULL,
  `node_type` varchar(10) NOT NULL,
  `node_key` varchar(1024) DEFAULT NULL,
  `value` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_script_nodes_parent` (`script_id`,`parent_id`,`position`),
  KEY `scan_snapshot_script_nodes_parent_fk` (`parent_id`),
  CONSTRAINT `scan_snapshot_script_nodes_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `scan_snapshot_script_nodes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scan_snapshot_script_nodes_script_fk` FOREIGN KEY (`script_id`) REFERENCES `scan_snapshot_scripts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_script_nodes` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_script_nodes` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_script_nodes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_scripts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_scripts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_id` int(11) unsigned NOT NULL,
  `port_id` bigint(20) unsigned DEFAULT NULL,
  `position` int(11) unsigned NOT NULL,
  `script_id` varchar(255) NOT NULL,
  `output` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scan_snapshot_scripts_snapshot` (`snapshot_id`,`port_id`),
  KEY `scan_snapshot_scripts_port_fk` (`port_id`),
  CONSTRAINT `scan_snapshot_scripts_port_fk` FOREIGN KEY (`port_id`) REFERENCES `scan_snapshot_ports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scan_snapshot_scripts_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_scripts` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_scripts` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_scripts` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshot_trace_hops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshot_trace_hops` (
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
  KEY `scan_snapshot_trace_hops_snapshot_fk` (`snapshot_id`),
  CONSTRAINT `scan_snapshot_trace_hops_snapshot_fk` FOREIGN KEY (`snapshot_id`) REFERENCES `scan_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshot_trace_hops` WRITE;
/*!40000 ALTER TABLE `scan_snapshot_trace_hops` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_snapshot_trace_hops` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scan_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshots` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `result_hash` char(64) NOT NULL,
  `content_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshots_content` (`ip`,`mode`,`content_hash`),
  KEY `scan_snapshots_result` (`ip`,`mode`,`result_hash`),
  KEY `scan_snapshots_ip_mode_id` (`ip`,`mode`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshots` WRITE;
/*!40000 ALTER TABLE `scan_snapshots` DISABLE KEYS */;
INSERT INTO `scan_snapshots` VALUES
(1,'192.168.1.1','deep','df0cd762e09b9dab854d185ba95dc8b4513d827476b5c0955589ef67a0c276c8','de0ec19b3aa3e485579758888b61c0fa660a5a50aebe988781dbc226c3dd503d','2026-07-11 14:44:58'),
(2,'192.168.1.10','deep','0df5a7c47344ee8e0ea303b53387416b13d212087de5d1bd9053814f7b0ec497','9868eeafe1425c5383329ece2f2bb39b0e4e2cd18a3ddd4488928d878f080c7a','2026-07-11 14:44:58'),
(3,'192.168.1.42','standard','d02b8cc094f532f7a9dce54dad72939a0ee3ec92407cb0676186175ef743d170','fd4c10e74d1a50357a66ac082d709cda4505199880768abef83c9b0b6ab57b45','2026-07-11 14:44:58'),
(4,'192.168.1.90','lightweight','3309e334b2c2de09cb386be61488cd6071bc5138b65792df4d1505e0acbdab38','01872f9567a5dd7783c4d47bb267ddc3bf122b77dd3b55141f3d5e1d0e1a6750','2026-07-11 14:44:58'),
(5,'192.168.1.210','deep','74db1545280ca0ba0ea3bcd9288d71d1fcc0916bb498f5c114335f7e7a0df7e1','a92a32fa9a29d5cf64221e7c49173e308f0f5255c7a065290fa302ecbc42dfe4','2026-07-11 14:44:58'),
(6,'192.168.1.220','standard','00c78026af6bc8d79369ca629ba76699c99592dce3835621f3ba029314e5afe4','14eaac0a9673a0cf09d437a65fb6fb86c3cf9086b219e250373f6b1cf88720ed','2026-07-11 14:44:58');
/*!40000 ALTER TABLE `scan_snapshots` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scans` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `state` varchar(20) NOT NULL DEFAULT 'running',
  `status` varchar(50) DEFAULT NULL,
  `date_begin` datetime DEFAULT current_timestamp(),
  `date_end` datetime DEFAULT NULL,
  `duration` int(11) unsigned DEFAULT NULL,
  `ports_count` int(11) unsigned NOT NULL DEFAULT 0,
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
  KEY `scans_ip_date` (`ip`,`date_begin`),
  KEY `scans_ip_id` (`ip`,`id`),
  KEY `scans_snapshot_id` (`snapshot_id`),
  KEY `scans_state` (`state`),
  KEY `scans_queue` (`state`,`mode`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scans` WRITE;
/*!40000 ALTER TABLE `scans` DISABLE KEYS */;
INSERT INTO `scans` VALUES
(1,'192.168.1.1','deep','complete','up','2026-07-11 12:43:37','2026-07-11 12:44:51',74,4,1,1,1,'nmap',NULL,'nmap -T3 -A -p- 192.168.1.1',NULL,NULL,'2026-06-27 08:12:01',NULL,NULL,NULL),
(2,'192.168.1.10','deep','complete','up','2026-07-11 09:42:52','2026-07-11 09:44:51',119,5,2,1,1,'nmap',NULL,'nmap -T3 -A -p- 192.168.1.10',NULL,NULL,'2026-07-01 06:45:18',NULL,NULL,NULL),
(3,'192.168.1.42','standard','complete','up','2026-07-11 07:44:26','2026-07-11 07:44:51',25,3,3,1,1,'nmap',NULL,'nmap -T3 -A --top-ports 1000 192.168.1.42',NULL,NULL,'2026-07-10 07:54:12',NULL,NULL,NULL),
(4,'192.168.1.90','lightweight','complete','up','2026-07-11 13:44:47','2026-07-11 13:44:51',4,2,4,1,1,'nmap',NULL,'nmap -F -T4 192.168.1.90',NULL,NULL,NULL,NULL,NULL,NULL),
(5,'192.168.1.210','deep','complete','up','2026-07-11 02:43:19','2026-07-11 02:44:51',92,4,5,1,1,'nmap',NULL,'nmap -T3 -A -p- 192.168.1.210',NULL,NULL,'2026-06-18 11:02:44',NULL,NULL,NULL),
(6,'192.168.1.220','standard','complete','up','2026-07-11 10:44:18','2026-07-11 10:44:51',33,3,6,1,1,'nmap',NULL,'nmap -T3 -A --top-ports 1000 192.168.1.220',NULL,NULL,'2026-07-03 14:22:09',NULL,NULL,NULL),
(7,'192.168.1.15','standard','timeout',NULL,'2026-07-10 22:44:51','2026-07-10 23:14:51',1800,0,NULL,0,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'nmap timed out after 30 minutes'),
(8,'192.168.1.120','lightweight','complete','down','2026-07-11 08:44:51','2026-07-11 08:44:53',2,0,NULL,0,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `scans` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stats` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date_begin` datetime DEFAULT current_timestamp(),
  `date_end` datetime DEFAULT current_timestamp(),
  `nb_scan` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`),
  KEY `stats_ip_date_begin` (`ip`,`date_begin`),
  KEY `stats_date_begin` (`date_begin`) USING BTREE,
  KEY `stats_date_end` (`date_end`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `stats` WRITE;
/*!40000 ALTER TABLE `stats` DISABLE KEYS */;
INSERT INTO `stats` VALUES
(1,'192.168.1.1','74:83:c2:10:00:01','Up','2026-07-04 14:44:51','2026-07-11 14:44:51',672),
(2,'192.168.1.10','00:11:32:20:00:10','Up','2026-07-04 14:44:51','2026-07-11 14:44:51',672),
(3,'192.168.1.15','b8:27:eb:30:00:15','Up','2026-07-08 14:44:51','2026-07-10 20:44:51',210),
(4,'192.168.1.15','b8:27:eb:30:00:15','Down','2026-07-10 20:44:51','2026-07-11 14:44:51',72),
(5,'192.168.1.42','f8:db:88:40:00:42','Up','2026-07-07 14:44:51','2026-07-11 06:44:51',350),
(6,'192.168.1.42','f8:db:88:40:00:42','Down','2026-07-11 06:44:51','2026-07-11 07:44:51',4),
(7,'192.168.1.42','f8:db:88:40:00:42','Up','2026-07-11 07:44:51','2026-07-11 14:44:51',28),
(8,'192.168.1.90','f4:f5:d8:50:00:90','Up','2026-07-05 14:44:51','2026-07-11 12:44:51',560),
(9,'192.168.1.90','f4:f5:d8:50:00:90','arp-down','2026-07-11 12:44:51','2026-07-11 13:44:51',3),
(10,'192.168.1.90','f4:f5:d8:50:00:90','Up','2026-07-11 13:44:51','2026-07-11 14:44:51',4),
(11,'192.168.1.120','b8:27:eb:60:01:20','Up','2026-07-09 14:44:51','2026-07-11 08:44:51',160),
(12,'192.168.1.120','b8:27:eb:60:01:20','Down','2026-07-11 08:44:51','2026-07-11 14:44:51',24),
(13,'192.168.1.210','3c:52:a1:70:02:10','Up','2026-07-04 14:44:51','2026-07-11 14:44:51',672),
(14,'192.168.1.220','00:40:8c:80:02:20','Up','2026-07-08 14:44:51','2026-07-11 11:44:51',260),
(15,'192.168.1.220','00:40:8c:80:02:20','Down','2026-07-11 11:44:51','2026-07-11 14:44:51',12),
(16,'192.168.1.201','3c:22:fb:91:02:01','Down','2026-07-10 14:44:51','2026-07-11 04:44:51',30),
(17,'192.168.1.201','3c:22:fb:91:02:01','Up','2026-07-11 04:44:51','2026-07-11 14:44:51',40);
/*!40000 ALTER TABLE `stats` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `stats_old`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stats_old` (
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `stats_old` WRITE;
/*!40000 ALTER TABLE `stats_old` DISABLE KEYS */;
/*!40000 ALTER TABLE `stats_old` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `login` varchar(50) DEFAULT NULL,
  `pass` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `update_status` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = latin1 */ ;
/*!50003 SET character_set_results = latin1 */ ;
/*!50003 SET collation_connection  = latin1_swedish_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `update_status`(IN p_ip VARCHAR(50), IN p_mac VARCHAR(50), IN p_status VARCHAR(50))
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
END
;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Keep demo activity recent whenever this archive is restored.
USE `ping`;
SET @fenping_demo_shift_seconds = TIMESTAMPDIFF(
  SECOND,
  (SELECT MAX(`date`) FROM `ping`),
  NOW() - INTERVAL 2 MINUTE
);
UPDATE `ping`
SET `date`=DATE_ADD(`date`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `leases`
SET `ends`=DATE_ADD(`ends`, INTERVAL @fenping_demo_shift_seconds SECOND),
    `first_seen`=DATE_ADD(`first_seen`, INTERVAL @fenping_demo_shift_seconds SECOND),
    `last_seen`=DATE_ADD(`last_seen`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `device_approvals`
SET `approved_at`=DATE_ADD(`approved_at`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `stats`
SET `date_begin`=DATE_ADD(`date_begin`, INTERVAL @fenping_demo_shift_seconds SECOND),
    `date_end`=DATE_ADD(`date_end`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `scans`
SET `date_begin`=DATE_ADD(`date_begin`, INTERVAL @fenping_demo_shift_seconds SECOND),
    `date_end`=DATE_ADD(`date_end`, INTERVAL @fenping_demo_shift_seconds SECOND),
    `last_boot`=DATE_ADD(`last_boot`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `scan_snapshots`
SET `created_at`=DATE_ADD(`created_at`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `scan_port_changes`
SET `created_at`=DATE_ADD(`created_at`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `netboot_images`
SET `created_at`=DATE_ADD(`created_at`, INTERVAL @fenping_demo_shift_seconds SECOND);

/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.6-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: 127.0.0.1    Database: ping
-- ------------------------------------------------------
-- Server version	11.8.8-MariaDB-ubu2404

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

--
-- Current Database: `ping`
--

/*!40000 DROP DATABASE IF EXISTS `ping`*/;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ping` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci */;

USE `ping`;

--
-- Table structure for table `device_approvals`
--

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

--
-- Dumping data for table `device_approvals`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `device_approvals` WRITE;
/*!40000 ALTER TABLE `device_approvals` DISABLE KEYS */;
INSERT INTO `device_approvals` VALUES
('3c:22:fb:95:02:05','2026-06-05 23:33:01'),
('8c:f5:a3:92:02:02','2026-06-20 23:33:01');
/*!40000 ALTER TABLE `device_approvals` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `ips`
--

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

--
-- Dumping data for table `ips`
--

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

--
-- Table structure for table `leases`
--

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

--
-- Dumping data for table `leases`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `leases` WRITE;
/*!40000 ALTER TABLE `leases` DISABLE KEYS */;
INSERT INTO `leases` VALUES
('192.168.1.201','3c:22:fb:91:02:01','alex-iphone','2026-07-11 07:33:01','2026-06-24 23:33:01','2026-07-10 23:31:01',1),
('192.168.1.205','3c:22:fb:95:02:05','old-phone','2026-06-28 23:33:01','2026-05-31 23:33:01','2026-06-28 23:33:01',0),
('192.168.1.210','3c:52:a1:70:02:10','laser-printer','2026-07-11 08:33:01','2026-05-11 23:33:01','2026-07-10 23:28:01',1),
('192.168.1.202','8c:f5:a3:92:02:02','meeting-tablet','2026-07-11 09:33:01','2026-06-09 23:33:01','2026-07-10 23:30:01',1),
('192.168.1.204','d8:f1:5b:94:02:04','air-quality','2026-07-11 10:33:01','2026-07-08 23:33:01','2026-07-10 23:29:01',1),
('192.168.1.203','f8:db:88:93:02:03','guest-laptop','2026-07-11 01:33:01','2026-07-09 23:33:01','2026-07-10 22:56:01',1);
/*!40000 ALTER TABLE `leases` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `netboot_images`
--

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

--
-- Dumping data for table `netboot_images`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `netboot_images` WRITE;
/*!40000 ALTER TABLE `netboot_images` DISABLE KEYS */;
INSERT INTO `netboot_images` VALUES
(1,'Linux Rescue','demo-linux-rescue.ipxe','linux-rescue.ipxe',223,'2026-06-19 23:33:01'),
(2,'Hardware Diagnostics','demo-diagnostics.ipxe','diagnostics.ipxe',201,'2026-06-26 23:33:01');
/*!40000 ALTER TABLE `netboot_images` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `oui_vendors`
--

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

--
-- Dumping data for table `oui_vendors`
--

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

--
-- Table structure for table `ping`
--

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

--
-- Dumping data for table `ping`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `ping` WRITE;
/*!40000 ALTER TABLE `ping` DISABLE KEYS */;
INSERT INTO `ping` VALUES
('192.168.1.1','74:83:c2:10:00:01','Up','2026-07-10 23:31:01'),
('192.168.1.10','00:11:32:20:00:10','Up','2026-07-10 23:31:01'),
('192.168.1.120','b8:27:eb:60:01:20','Down','2026-07-10 23:31:01'),
('192.168.1.15','b8:27:eb:30:00:15','Down','2026-07-10 23:31:01'),
('192.168.1.201','3c:22:fb:91:02:01','Up','2026-07-10 23:31:01'),
('192.168.1.202','8c:f5:a3:92:02:02','Up','2026-07-10 23:31:01'),
('192.168.1.203','f8:db:88:93:02:03','Down','2026-07-10 23:31:01'),
('192.168.1.204','d8:f1:5b:94:02:04','Up','2026-07-10 23:31:01'),
('192.168.1.210','3c:52:a1:70:02:10','Up','2026-07-10 23:31:01'),
('192.168.1.220','00:40:8c:80:02:20','Down','2026-07-10 23:31:01'),
('192.168.1.42','f8:db:88:40:00:42','Up','2026-07-10 23:31:01'),
('192.168.1.90','f4:f5:d8:50:00:90','Up','2026-07-10 23:31:01');
/*!40000 ALTER TABLE `ping` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `range`
--

DROP TABLE IF EXISTS `range`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `range` (
  `ip_begin` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_uca1400_ai_ci DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  KEY `range_ip_begin` (`ip_begin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `range`
--

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

--
-- Table structure for table `scan_port_changes`
--

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

--
-- Dumping data for table `scan_port_changes`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_port_changes` WRITE;
/*!40000 ALTER TABLE `scan_port_changes` DISABLE KEYS */;
INSERT INTO `scan_port_changes` VALUES
(1,2,'192.168.1.10','deep','appeared','tcp',5001,NULL,NULL,'https','Synology DSM 7.2','2026-07-10 18:33:01'),
(2,5,'192.168.1.210','deep','changed','tcp',80,'http','HP Embedded Web Server 4.2','http','HP Embedded Web Server 5.1','2026-07-10 11:33:01'),
(3,6,'192.168.1.220','standard','disappeared','tcp',22,'ssh','Dropbear sshd 2020.81',NULL,NULL,'2026-07-10 19:33:01');
/*!40000 ALTER TABLE `scan_port_changes` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `scan_snapshots`
--

DROP TABLE IF EXISTS `scan_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_snapshots` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) NOT NULL,
  `mode` varchar(20) NOT NULL,
  `result_hash` char(64) NOT NULL,
  `xml` mediumblob NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `scan_snapshots_result` (`ip`,`mode`,`result_hash`),
  KEY `scan_snapshots_ip_mode_id` (`ip`,`mode`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scan_snapshots`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scan_snapshots` WRITE;
/*!40000 ALTER TABLE `scan_snapshots` DISABLE KEYS */;
INSERT INTO `scan_snapshots` VALUES
(1,'192.168.1.1','deep','ba6e58d3d0f346ea08f15eb4ca3ffb651304149eaba8d11f6e95b72f1d91e7cd','<nmaprun scanner=\"nmap\" args=\"nmap -T3 -A -p- 192.168.1.1\" startstr=\"Demo scan\"><scaninfo type=\"syn\" protocol=\"tcp\" numservices=\"65535\" services=\"1-65535\"/><host><status state=\"up\"/><address addr=\"192.168.1.1\" addrtype=\"ipv4\"/><address addr=\"74:83:C2:10:00:01\" addrtype=\"mac\" vendor=\"Ubiquiti Inc.\"/><hostnames><hostname name=\"gateway\" type=\"PTR\"/></hostnames><ports><port protocol=\"tcp\" portid=\"22\"><state state=\"open\"/><service name=\"ssh\" product=\"OpenSSH\" version=\"9.6\"/></port><port protocol=\"tcp\" portid=\"53\"><state state=\"open\"/><service name=\"domain\" product=\"dnsmasq\" version=\"2.90\"/></port><port protocol=\"tcp\" portid=\"80\"><state state=\"open\"/><service name=\"http\" product=\"nginx\" version=\"1.26.1\"/></port><port protocol=\"tcp\" portid=\"443\"><state state=\"open\"/><service name=\"https\" product=\"nginx\" version=\"1.26.1\" tunnel=\"ssl\"/></port></ports><os><osmatch name=\"Linux 5.15 - 6.8\" accuracy=\"100\"/></os><uptime lastboot=\"2026-06-27 08:12:01\"/></host><runstats><finished elapsed=\"73.44\"/></runstats></nmaprun>','2026-07-10 21:33:01'),
(2,'192.168.1.10','deep','033e3497a994c13e4df4e9d34bb1bdf22372bec486a2242bfa78f9077fc1a05f','<nmaprun scanner=\"nmap\" args=\"nmap -T3 -A -p- 192.168.1.10\" startstr=\"Demo scan\"><scaninfo type=\"syn\" protocol=\"tcp\" numservices=\"65535\" services=\"1-65535\"/><host><status state=\"up\"/><address addr=\"192.168.1.10\" addrtype=\"ipv4\"/><address addr=\"00:11:32:20:00:10\" addrtype=\"mac\" vendor=\"Synology Incorporated\"/><hostnames><hostname name=\"nas\" type=\"PTR\"/></hostnames><ports><port protocol=\"tcp\" portid=\"22\"><state state=\"open\"/><service name=\"ssh\" product=\"OpenSSH\" version=\"9.2\"/></port><port protocol=\"tcp\" portid=\"80\"><state state=\"open\"/><service name=\"http\" product=\"nginx\" version=\"1.24.0\"/></port><port protocol=\"tcp\" portid=\"443\"><state state=\"open\"/><service name=\"https\" product=\"nginx\" version=\"1.24.0\" tunnel=\"ssl\"/></port><port protocol=\"tcp\" portid=\"445\"><state state=\"open\"/><service name=\"microsoft-ds\" product=\"Samba smbd\" version=\"4.18.6\"/></port><port protocol=\"tcp\" portid=\"5001\"><state state=\"open\"/><service name=\"https\" product=\"Synology DSM\" version=\"7.2\" tunnel=\"ssl\"/></port></ports><os><osmatch name=\"Linux 5.10\" accuracy=\"100\"/></os><uptime lastboot=\"2026-07-01 06:45:18\"/></host><runstats><finished elapsed=\"118.17\"/></runstats></nmaprun>','2026-07-10 18:33:01'),
(3,'192.168.1.42','standard','1159628dc246568ae6871aab63764a33f86a6f7d28689c9d597180e33b945bf3','<nmaprun scanner=\"nmap\" args=\"nmap -T3 -A --top-ports 1000 192.168.1.42\" startstr=\"Demo scan\"><scaninfo type=\"syn\" protocol=\"tcp\" numservices=\"1000\" services=\"top-1000\"/><host><status state=\"up\"/><address addr=\"192.168.1.42\" addrtype=\"ipv4\"/><address addr=\"F8:DB:88:40:00:42\" addrtype=\"mac\" vendor=\"Dell Technologies\"/><hostnames><hostname name=\"office-pc\" type=\"PTR\"/></hostnames><ports><port protocol=\"tcp\" portid=\"135\"><state state=\"open\"/><service name=\"msrpc\" product=\"Microsoft Windows RPC\"/></port><port protocol=\"tcp\" portid=\"445\"><state state=\"open\"/><service name=\"microsoft-ds\" product=\"Microsoft Windows 11\"/></port><port protocol=\"tcp\" portid=\"3389\"><state state=\"open\"/><service name=\"ms-wbt-server\" product=\"Microsoft Terminal Services\"/></port></ports><os><osmatch name=\"Microsoft Windows 11\" accuracy=\"100\"/></os><uptime lastboot=\"2026-07-10 07:54:12\"/></host><runstats><finished elapsed=\"24.70\"/></runstats></nmaprun>','2026-07-10 16:33:01'),
(4,'192.168.1.90','lightweight','4636c7e0a28abc3e623b4f4b8d515bc26ac0edf853746ba9c63d6aed598cb6d5','<nmaprun scanner=\"nmap\" args=\"nmap -F -T4 192.168.1.90\" startstr=\"Demo scan\"><scaninfo type=\"syn\" protocol=\"tcp\" numservices=\"100\" services=\"top-100\"/><host><status state=\"up\"/><address addr=\"192.168.1.90\" addrtype=\"ipv4\"/><address addr=\"F4:F5:D8:50:00:90\" addrtype=\"mac\" vendor=\"Google, Inc.\"/><hostnames><hostname name=\"living-room-tv\" type=\"PTR\"/></hostnames><ports><port protocol=\"tcp\" portid=\"8008\"><state state=\"open\"/><service name=\"http\" product=\"Google Cast\"/></port><port protocol=\"tcp\" portid=\"8009\"><state state=\"open\"/><service name=\"ajp13\" product=\"Google Cast\"/></port></ports><os><osmatch name=\"Linux 4.9\" accuracy=\"96\"/></os></host><runstats><finished elapsed=\"3.31\"/></runstats></nmaprun>','2026-07-10 22:33:01'),
(5,'192.168.1.210','deep','058f2138b6d2057e7ddd0a8525a266312f42ea95a21bb219e126dfceb807260d','<nmaprun scanner=\"nmap\" args=\"nmap -T3 -A -p- 192.168.1.210\" startstr=\"Demo scan\"><scaninfo type=\"syn\" protocol=\"tcp\" numservices=\"65535\" services=\"1-65535\"/><host><status state=\"up\"/><address addr=\"192.168.1.210\" addrtype=\"ipv4\"/><address addr=\"3C:52:A1:70:02:10\" addrtype=\"mac\" vendor=\"HP Inc.\"/><hostnames><hostname name=\"laser-printer\" type=\"PTR\"/></hostnames><ports><port protocol=\"tcp\" portid=\"80\"><state state=\"open\"/><service name=\"http\" product=\"HP Embedded Web Server\"/></port><port protocol=\"tcp\" portid=\"443\"><state state=\"open\"/><service name=\"https\" product=\"HP Embedded Web Server\" tunnel=\"ssl\"/></port><port protocol=\"tcp\" portid=\"631\"><state state=\"open\"/><service name=\"ipp\" product=\"CUPS\" version=\"2.4\"/></port><port protocol=\"tcp\" portid=\"9100\"><state state=\"open\"/><service name=\"jetdirect\" product=\"HP JetDirect\"/></port></ports><os><osmatch name=\"HP printer embedded Linux\" accuracy=\"100\"/></os><uptime lastboot=\"2026-06-18 11:02:44\"/></host><runstats><finished elapsed=\"91.03\"/></runstats></nmaprun>','2026-07-10 11:33:01'),
(6,'192.168.1.220','standard','df64ce474800bef5f08383538b807d8672768c153d87c15b5060d6de9f96ab60','<nmaprun scanner=\"nmap\" args=\"nmap -T3 -A --top-ports 1000 192.168.1.220\" startstr=\"Demo scan\"><scaninfo type=\"syn\" protocol=\"tcp\" numservices=\"1000\" services=\"top-1000\"/><host><status state=\"up\"/><address addr=\"192.168.1.220\" addrtype=\"ipv4\"/><address addr=\"00:40:8C:80:02:20\" addrtype=\"mac\" vendor=\"Axis Communications AB\"/><hostnames><hostname name=\"front-camera\" type=\"PTR\"/></hostnames><ports><port protocol=\"tcp\" portid=\"80\"><state state=\"open\"/><service name=\"http\" product=\"AXIS Camera Station\" version=\"5.56\"/></port><port protocol=\"tcp\" portid=\"443\"><state state=\"open\"/><service name=\"https\" product=\"AXIS Camera Station\" version=\"5.56\" tunnel=\"ssl\"/></port><port protocol=\"tcp\" portid=\"554\"><state state=\"open\"/><service name=\"rtsp\" product=\"AXIS Media Control\"/></port></ports><os><osmatch name=\"Linux 4.14\" accuracy=\"100\"/></os><uptime lastboot=\"2026-07-03 14:22:09\"/></host><runstats><finished elapsed=\"32.08\"/></runstats></nmaprun>','2026-07-10 19:33:01');
/*!40000 ALTER TABLE `scan_snapshots` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `scans`
--

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
  `error` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scans_ip_date` (`ip`,`date_begin`),
  KEY `scans_ip_id` (`ip`,`id`),
  KEY `scans_snapshot_id` (`snapshot_id`),
  KEY `scans_state` (`state`),
  KEY `scans_queue` (`state`,`mode`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scans`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `scans` WRITE;
/*!40000 ALTER TABLE `scans` DISABLE KEYS */;
INSERT INTO `scans` VALUES
(1,'192.168.1.1','deep','complete','up','2026-07-10 21:31:47','2026-07-10 21:33:01',74,4,1,1,1,NULL),
(2,'192.168.1.10','deep','complete','up','2026-07-10 18:31:02','2026-07-10 18:33:01',119,5,2,1,1,NULL),
(3,'192.168.1.42','standard','complete','up','2026-07-10 16:32:36','2026-07-10 16:33:01',25,3,3,1,1,NULL),
(4,'192.168.1.90','lightweight','complete','up','2026-07-10 22:32:57','2026-07-10 22:33:01',4,2,4,1,1,NULL),
(5,'192.168.1.210','deep','complete','up','2026-07-10 11:31:29','2026-07-10 11:33:01',92,4,5,1,1,NULL),
(6,'192.168.1.220','standard','complete','up','2026-07-10 19:32:28','2026-07-10 19:33:01',33,3,6,1,1,NULL),
(7,'192.168.1.15','standard','timeout',NULL,'2026-07-10 07:33:01','2026-07-10 08:03:01',1800,0,NULL,0,1,'nmap timed out after 30 minutes'),
(8,'192.168.1.120','lightweight','complete','down','2026-07-10 17:33:01','2026-07-10 17:33:03',2,0,NULL,0,1,NULL);
/*!40000 ALTER TABLE `scans` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `stats`
--

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

--
-- Dumping data for table `stats`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `stats` WRITE;
/*!40000 ALTER TABLE `stats` DISABLE KEYS */;
INSERT INTO `stats` VALUES
(1,'192.168.1.1','74:83:c2:10:00:01','Up','2026-07-03 23:33:01','2026-07-10 23:33:01',672),
(2,'192.168.1.10','00:11:32:20:00:10','Up','2026-07-03 23:33:01','2026-07-10 23:33:01',672),
(3,'192.168.1.15','b8:27:eb:30:00:15','Up','2026-07-07 23:33:01','2026-07-10 05:33:01',210),
(4,'192.168.1.15','b8:27:eb:30:00:15','Down','2026-07-10 05:33:01','2026-07-10 23:33:01',72),
(5,'192.168.1.42','f8:db:88:40:00:42','Up','2026-07-06 23:33:01','2026-07-10 15:33:01',350),
(6,'192.168.1.42','f8:db:88:40:00:42','Down','2026-07-10 15:33:01','2026-07-10 16:33:01',4),
(7,'192.168.1.42','f8:db:88:40:00:42','Up','2026-07-10 16:33:01','2026-07-10 23:33:01',28),
(8,'192.168.1.90','f4:f5:d8:50:00:90','Up','2026-07-04 23:33:01','2026-07-10 21:33:01',560),
(9,'192.168.1.90','f4:f5:d8:50:00:90','arp-down','2026-07-10 21:33:01','2026-07-10 22:33:01',3),
(10,'192.168.1.90','f4:f5:d8:50:00:90','Up','2026-07-10 22:33:01','2026-07-10 23:33:01',4),
(11,'192.168.1.120','b8:27:eb:60:01:20','Up','2026-07-08 23:33:01','2026-07-10 17:33:01',160),
(12,'192.168.1.120','b8:27:eb:60:01:20','Down','2026-07-10 17:33:01','2026-07-10 23:33:01',24),
(13,'192.168.1.210','3c:52:a1:70:02:10','Up','2026-07-03 23:33:01','2026-07-10 23:33:01',672),
(14,'192.168.1.220','00:40:8c:80:02:20','Up','2026-07-07 23:33:01','2026-07-10 20:33:01',260),
(15,'192.168.1.220','00:40:8c:80:02:20','Down','2026-07-10 20:33:01','2026-07-10 23:33:01',12),
(16,'192.168.1.201','3c:22:fb:91:02:01','Down','2026-07-09 23:33:01','2026-07-10 13:33:01',30),
(17,'192.168.1.201','3c:22:fb:91:02:01','Up','2026-07-10 13:33:01','2026-07-10 23:33:01',40);
/*!40000 ALTER TABLE `stats` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `stats_old`
--

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

--
-- Dumping data for table `stats_old`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `stats_old` WRITE;
/*!40000 ALTER TABLE `stats_old` DISABLE KEYS */;
/*!40000 ALTER TABLE `stats_old` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `login` varchar(50) DEFAULT NULL,
  `pass` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Dumping routines for database 'ping'
--
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
CREATE DEFINER=`root`@`%` PROCEDURE `update_status`(IN p_ip VARCHAR(50), IN p_mac VARCHAR(50), IN p_status VARCHAR(50))
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
END ;;
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

-- Dump completed on 2026-07-10 23:33:07

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
    `date_end`=DATE_ADD(`date_end`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `scan_snapshots`
SET `created_at`=DATE_ADD(`created_at`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `scan_port_changes`
SET `created_at`=DATE_ADD(`created_at`, INTERVAL @fenping_demo_shift_seconds SECOND);
UPDATE `netboot_images`
SET `created_at`=DATE_ADD(`created_at`, INTERVAL @fenping_demo_shift_seconds SECOND);

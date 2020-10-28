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
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `mac` (`mac`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE `leases` (
  `ip` varchar(50) DEFAULT NULL,
  `starts` varchar(50) DEFAULT NULL,
  `ends` varchar(50) DEFAULT NULL,
  `tstp` varchar(50) DEFAULT NULL,
  `cltt` varchar(50) DEFAULT NULL,
  `hardware-ethernet` varchar(50) DEFAULT NULL,
  `client-hostname` varchar(50) DEFAULT NULL,
  `vendor-class-identifier` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `ping` (
  `ip` varchar(50) NOT NULL,
  `mac` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `range` (
  `ip_begin` varchar(50) CHARACTER SET utf8 DEFAULT NULL,
  `type` varchar(50) COLLATE utf8_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

CREATE TABLE `stats` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date_begin` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_end` datetime DEFAULT CURRENT_TIMESTAMP,
  `nb_scan` int(11) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `ip` (`ip`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

CREATE TABLE `stats_old` (
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `users` (
  `login` varchar(50) DEFAULT NULL,
  `pass` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE `vendors` (
  `mac` varchar(50) NOT NULL,
  `vendors` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

DELIMITER ;;
CREATE PROCEDURE `update_status`(IN p_ip VARCHAR(50), IN p_mac VARCHAR(50), IN p_status VARCHAR(50))
BEGIN
DECLARE var_id INT;
DECLARE var_status VARCHAR(50);
DECLARE var_ip VARCHAR(50);
DECLARE var_mac VARCHAR(50);
DECLARE var_date_begin DATETIME;
SELECT id,mac,ip,status,date_begin INTO var_id,var_mac,var_ip,var_status,var_date_begin FROM stats WHERE ip=p_ip ORDER BY id DESC LIMIT 1;
IF var_status = p_status AND (var_ip=p_ip OR (var_ip IS NULL AND p_ip IS NULL)) AND (var_mac=p_mac OR (var_mac IS NULL AND p_mac IS NULL)) THEN
  UPDATE stats SET date_end=CURRENT_TIMESTAMP, nb_scan=nb_scan+1 WHERE id=var_id;
ELSE
  INSERT INTO stats (ip,mac,status) VALUES (p_ip, p_mac, p_status);
END IF;
END;;
DELIMITER ;
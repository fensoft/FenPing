#!/usr/bin/env python3

import gzip
import json
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
CONVERTER = ROOT / "tools" / "convert-v1.2-backup.py"


SQL = """-- MySQL dump
-- Host: localhost    Database: ping
CREATE TABLE `ips` (
  `id` int NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `important` tinyint DEFAULT NULL,
  `dns` varchar(50) DEFAULT NULL
);
INSERT INTO `ips` VALUES (1,'router\\'s','00:11:22:33:44:55','192.168.1.1',1,'207.246.121.077 admin 8.8.8.8');
CREATE TABLE `leases` (
  `ip` varchar(50) DEFAULT NULL,
  `starts` varchar(50) DEFAULT NULL,
  `ends` varchar(50) DEFAULT NULL,
  `tstp` varchar(50) DEFAULT NULL,
  `cltt` varchar(50) DEFAULT NULL,
  `hardware-ethernet` varchar(50) DEFAULT NULL,
  `client-hostname` varchar(50) DEFAULT NULL,
  `vendor-class-identifier` varchar(50) DEFAULT NULL
);
INSERT INTO `leases` VALUES
('192.168.1.20','2026-01-01 00:00:00','2026-01-02 00:00:00',NULL,'2026-01-01 01:00:00','AA:BB:CC:DD:EE:FF','first',NULL),
('192.168.1.20','2025-12-31 23:00:00','2026-01-03 00:00:00',NULL,'2026-01-01 02:00:00','aa:bb:cc:dd:ee:ff','second',NULL),
('not-an-ip','2026-01-01 00:00:00','2026-01-02 00:00:00',NULL,NULL,'bad','bad',NULL);
CREATE TABLE `range` (
  `ip_begin` varchar(50) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL
);
INSERT INTO `range` VALUES ('192.168.1.1','network'),('15','gates');
CREATE TABLE `stats` (
  `id` int NOT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date_begin` datetime DEFAULT NULL,
  `date_end` datetime DEFAULT NULL,
  `nb_scan` int NOT NULL
);
INSERT INTO `stats` VALUES (9,'192.168.1.20',NULL,'Up','2026-01-01 00:00:00','2026-01-01 01:00:00',3);
CREATE TABLE `vendors` (
  `mac` varchar(50) NOT NULL,
  `vendors` varchar(50) DEFAULT NULL
);
INSERT INTO `vendors` VALUES ('aa:bb:cc:dd:ee:ff','Example');
"""


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="fenping-converter-test-") as temporary:
        root = Path(temporary)
        source = root / "legacy.sql.gz"
        target = root / "converted.tgz"
        with gzip.open(source, "wt", encoding="utf-8") as stream:
            stream.write(SQL)

        result = subprocess.run(
            [sys.executable, str(CONVERTER), str(source), str(target)],
            check=True,
            capture_output=True,
            text=True,
        )
        assert "MySQL connections used: 0" in result.stdout

        with tarfile.open(target, "r:gz") as archive:
            names = set(archive.getnames())
            assert {"manifest.json", "db.json", "netboot-index.json", "netboot"} <= names
            manifest = json.load(archive.extractfile("manifest.json"))
            database = json.load(archive.extractfile("db.json"))

        assert manifest["format"] == "fenping-backup"
        assert manifest["version"] == "1.6"
        assert manifest["database"]["rows"] == 5
        assert database["format"] == "fenping-db"
        assert database["conversion"]["offline"] is True
        assert "vendors" not in database["tables"]
        assert database["tables"]["ips"]["rows"][0][1] == "router's"
        assert database["tables"]["ips"]["rows"][0][5] == "207.246.121.77 8.8.8.8"
        assert database["tables"]["stats"]["rows"][0][0] == 9
        assert database["tables"]["range"]["rows"] == [
            ["192.168.1.1", "network"],
            ["192.168.1.15", "gates"],
        ]
        assert database["tables"]["leases"]["rows"] == [[
            "192.168.1.20",
            "aa:bb:cc:dd:ee:ff",
            "second",
            "2026-01-03 00:00:00",
            "2025-12-31 23:00:00",
            "2026-01-01 02:00:00",
            1,
        ]]

    print("legacy backup converter tests passed")


if __name__ == "__main__":
    main()

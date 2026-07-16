#!/usr/bin/env python3

import gzip
import io
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
INSERT INTO `range` VALUES ('192.168.1.1','r&eacute;seau &amp; serveurs'),('15','gates &#47; doors');
CREATE TABLE `ping` (
  `ip` varchar(50) NOT NULL,
  `mac` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date` datetime NOT NULL
);
INSERT INTO `ping` VALUES
('192.168.1.1','00:11:22:33:44:55','Up','2026-01-01 02:00:00'),
('192.168.1.20','AA:BB:CC:DD:EE:FF','Up','2026-01-01 02:00:00'),
('192.168.1.30','02-00-00-00-00-30','Down','2026-01-01 02:00:00'),
('192.168.1.31','invalid','Down','2026-01-01 02:00:00');
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

NMAP_XML = b"""<?xml version="1.0" encoding="UTF-8"?>
<nmaprun scanner="nmap" args="nmap -T2 -A -p- 192.168.1.20" start="1767225600" version="7.98">
<scaninfo type="syn" protocol="tcp" services="1-65535"/>
<host starttime="1767225601" endtime="1767225660">
<status state="up" reason="arp-response" reason_ttl="0"/>
<address addr="192.168.1.20" addrtype="ipv4"/>
<address addr="AA:BB:CC:DD:EE:FF" addrtype="mac" vendor="Example Devices"/>
<hostnames><hostname name="example-device" type="PTR"/></hostnames>
<ports>
<extraports state="closed" count="65534"><extrareasons reason="resets" count="65534"/></extraports>
<port protocol="tcp" portid="443"><state state="open" reason="syn-ack" reason_ttl="64"/><service name="https" product="Example HTTP" version="1.2" tunnel="ssl" method="probed" conf="10"><cpe>cpe:/a:example:http:1.2</cpe></service><script id="http-title" output="Example"><table key="titles"><elem>Example</elem></table></script></port>
</ports>
<os><osmatch name="Example OS" accuracy="100"><osclass type="router" vendor="Example" osfamily="Linux" osgen="6" accuracy="100"><cpe>cpe:/o:linux:linux_kernel:6</cpe></osclass></osmatch></os>
<uptime seconds="3600" lastboot="Thu Jan  1 00:01:00 2026"/>
<distance value="1"/>
<hostscript><script id="uptime" output="one hour"/></hostscript>
<trace proto="tcp" port="443"><hop ttl="1" ipaddr="192.168.1.20" host="example-device" rtt="0.42"/></trace>
</host>
<runstats><finished time="1767225661" elapsed="61.2"/><hosts up="1" down="0" total="1"/></runstats>
</nmaprun>
"""


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="fenping-converter-test-") as temporary:
        root = Path(temporary)
        source = root / "legacy.sql.tgz"
        nmap = root / "legacy.nmap.tgz"
        target = root / "converted.tgz"
        sql_bytes = gzip.compress(SQL.encode("utf-8"))
        with tarfile.open(source, "w:gz") as archive:
            member = tarfile.TarInfo("database/legacy.sql.gz")
            member.size = len(sql_bytes)
            archive.addfile(member, io.BytesIO(sql_bytes))
        with tarfile.open(nmap, "w:gz") as archive:
            member = tarfile.TarInfo("nmap/192.168.1.20.xml")
            member.size = len(NMAP_XML)
            archive.addfile(member, io.BytesIO(NMAP_XML))

        result = subprocess.run(
            [
                sys.executable,
                str(CONVERTER),
                str(source),
                str(nmap),
                "--target",
                str(target),
            ],
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
        assert manifest["counts"]["scan_rows"] == 1
        assert manifest["counts"]["scan_snapshot_rows"] == 1
        assert database["format"] == "fenping-db"
        assert database["conversion"]["offline"] is True
        assert database["conversion"]["nmap_source"] == "legacy.nmap.tgz"
        assert "vendors" not in database["tables"]
        assert database["tables"]["ips"]["rows"][0][1] == "router's"
        assert database["tables"]["ips"]["rows"][0][5] == "207.246.121.77 8.8.8.8"
        assert database["tables"]["stats"]["rows"][0][0] == 9
        assert database["tables"]["range"]["rows"] == [
            ["192.168.1.1", "réseau & serveurs"],
            ["192.168.1.15", "gates / doors"],
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
        approvals = database["tables"]["device_approvals"]
        assert approvals["columns"] == ["mac", "approved_at"]
        assert [row[0] for row in approvals["rows"]] == [
            "02:00:00:00:00:30",
            "aa:bb:cc:dd:ee:ff",
        ]
        assert len({row[1] for row in approvals["rows"]}) == 1
        assert approvals["rows"][0][1]
        assert all(row[0] != "00:11:22:33:44:55" for row in approvals["rows"])
        assert database["tables"]["scan_snapshots"]["rows"][0][1:3] == [
            "192.168.1.20",
            "deep",
        ]
        scan = database["tables"]["scans"]["rows"][0]
        assert scan[1:5] == ["192.168.1.20", "deep", "complete", "up"]
        assert scan[8:10] == [1, 1]
        port = database["tables"]["scan_snapshot_ports"]["rows"][0]
        assert port[2:5] == ["tcp", 443, "open"]
        assert port[7:12] == ["https", "Example HTTP", "1.2", None, "ssl"]
        assert database["tables"]["scan_snapshot_os_matches"]["rows"][0][3:] == [
            "Example OS",
            100,
        ]
        assert len(database["tables"]["scan_snapshot_scripts"]["rows"]) == 2
        assert len(database["tables"]["scan_snapshot_script_nodes"]["rows"]) == 2
        assert database["tables"]["scan_snapshot_trace_hops"]["rows"][0][5:8] == [
            1,
            "192.168.1.20",
            "example-device",
        ]
        table_rows = sum(len(table["rows"]) for table in database["tables"].values())
        assert manifest["database"]["rows"] == table_rows

    print("legacy backup converter tests passed")


if __name__ == "__main__":
    main()

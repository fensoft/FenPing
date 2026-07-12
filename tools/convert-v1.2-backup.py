#!/usr/bin/env python3
"""Convert legacy FenPing SQL and nmap backups to a current backup archive.

This tool intentionally parses mysqldump output directly.  It does not import a
MySQL client library, open a network connection, or require a database server.

Conversion limits and intentional transformations:

* The obsolete ``vendors`` exact-MAC cache is discarded.  Current FenPing
  rebuilds ``oui_vendors`` from its local IEEE registry.
* Invalid DNS tokens are discarded, while numeric IPv4 octets with legacy
  leading zeroes are normalized (for example, ``.077`` becomes ``.77``).
* Legacy leases are normalized and rows sharing the same (MAC, IP) are merged.
  The removed ``tstp`` and ``vendor-class-identifier`` columns are not retained.
* Category rows are retained, but short boundaries such as ``15`` are expanded
  onto the source dump's dominant IPv4 /24.
* SQL schema definitions, indexes, engines, collations, triggers, and original
  AUTO_INCREMENT settings are not copied.  Restore applies the current db.sql;
  explicit row IDs are retained where the legacy table contains them.
* The nmap archive contributes one latest scan per IPv4 address.  Files must be
  named ``IP.xml``; every usable result is imported as a deep scan.
* The legacy backups have no netboot file payload, so the generated archive
  contains an empty netboot directory and no netboot image records.
* Tables and fields introduced after FenPing 1.2 have no source data.  Restore
  leaves them empty or uses current schema defaults (including host scan fields).
* Malformed UTF-8 input bytes are replaced with the Unicode replacement
  character so that db.json remains valid UTF-8.
"""

from __future__ import annotations

import argparse
import contextlib
import gzip
import hashlib
import ipaddress
import json
import math
import os
import re
import socket
import sys
import tarfile
import tempfile
import xml.etree.ElementTree as ET
from collections import Counter
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from io import TextIOWrapper
from pathlib import Path
from typing import BinaryIO, Iterator, TextIO


BACKUP_VERSION = "1.6"
SUPPORTED_TABLES = (
    "ips",
    "leases",
    "ping",
    "range",
    "stats",
    "stats_old",
    "users",
)
SCAN_TABLE_COLUMNS = {
    "scan_snapshots": [
        "id", "ip", "mode", "result_hash", "content_hash", "created_at"
    ],
    "scans": [
        "id", "ip", "mode", "state", "status", "date_begin", "date_end",
        "duration", "ports_count", "snapshot_id", "result_changed",
        "port_changes_processed", "scanner", "scanner_version", "scan_args",
        "host_reason", "host_reason_ttl", "last_boot", "uptime_seconds",
        "distance", "error",
    ],
    "scan_snapshot_addresses": [
        "id", "snapshot_id", "position", "address", "address_type", "vendor"
    ],
    "scan_snapshot_hostnames": [
        "id", "snapshot_id", "position", "hostname", "hostname_type"
    ],
    "scan_snapshot_scopes": [
        "snapshot_id", "protocol", "port_begin", "port_end"
    ],
    "scan_snapshot_ports": [
        "id", "snapshot_id", "protocol", "port", "state", "reason",
        "reason_ttl", "service", "product", "version", "extra_info", "tunnel",
        "method", "confidence", "os_type",
    ],
    "scan_snapshot_port_cpes": ["port_id", "position", "cpe"],
    "scan_snapshot_extra_ports": [
        "id", "snapshot_id", "position", "state", "count"
    ],
    "scan_snapshot_extra_reasons": [
        "id", "extra_port_id", "position", "reason", "count", "protocol", "ports"
    ],
    "scan_snapshot_os_matches": [
        "id", "snapshot_id", "position", "name", "accuracy"
    ],
    "scan_snapshot_os_classes": [
        "id", "os_match_id", "position", "vendor", "os_family",
        "os_generation", "device_type", "accuracy",
    ],
    "scan_snapshot_os_cpes": ["os_class_id", "position", "cpe"],
    "scan_snapshot_scripts": [
        "id", "snapshot_id", "port_id", "position", "script_id", "output"
    ],
    "scan_snapshot_script_nodes": [
        "id", "script_id", "parent_id", "position", "node_type", "node_key", "value"
    ],
    "scan_snapshot_trace_hops": [
        "id", "snapshot_id", "position", "protocol", "port", "ttl", "ip",
        "hostname", "rtt",
    ],
}
EXPORT_TABLES = SUPPORTED_TABLES + tuple(SCAN_TABLE_COLUMNS)
MAX_NMAP_MEMBER_BYTES = 64 * 1024 * 1024
MAX_NMAP_ARCHIVE_BYTES = 1024 * 1024 * 1024
CREATE_TABLE_RE = re.compile(r"^CREATE TABLE\s+`([^`]+)`\s*\(")
COLUMN_RE = re.compile(r"^\s*`([^`]+)`\s+")
INSERT_RE = re.compile(
    r"^INSERT INTO\s+`([^`]+)`(?:\s*\((.*?)\))?\s+VALUES\s*",
    re.IGNORECASE | re.DOTALL,
)
INTEGER_RE = re.compile(r"^[+-]?\d+$")
FLOAT_RE = re.compile(
    r"^[+-]?(?:\d+\.\d*|\d*\.\d+|\d+)(?:[eE][+-]?\d+)?$"
)
MAC_RE = re.compile(r"^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$")
IPV4_OCTETS_RE = re.compile(r"^\d{1,3}(?:\.\d{1,3}){3}$")


class ConversionError(RuntimeError):
    pass


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Convert FenPing 1.2 SQL and nmap archives to a 1.6 db.json .tgz "
            "archive without using a MySQL server."
        )
    )
    parser.add_argument(
        "sql", type=Path,
        help="legacy .sql, .sql.gz, or .sql.tgz backup",
    )
    parser.add_argument(
        "nmap", type=Path,
        help="legacy .tgz containing the latest nmap result as IP.xml",
    )
    parser.add_argument(
        "--target", type=Path,
        help="output .tgz or .tar.gz (default: SQL name without .sql plus .tgz)",
    )
    parser.add_argument(
        "--force", action="store_true", help="replace an existing output archive"
    )
    return parser.parse_args()


def default_target(source: Path) -> Path:
    name = source.name
    if name.endswith(".sql.tar.gz"):
        name = name[: -len(".sql.tar.gz")]
    elif name.endswith(".sql.tgz"):
        name = name[: -len(".sql.tgz")]
    elif name.endswith(".sql.gz"):
        name = name[: -len(".sql.gz")]
    elif name.endswith(".sql"):
        name = name[: -len(".sql")]
    else:
        name = source.stem
    return source.with_name(name + ".tgz")


@contextlib.contextmanager
def open_dump(path: Path) -> Iterator[TextIO]:
    if path.name.endswith((".tgz", ".tar.gz")):
        with tarfile.open(path, "r:*") as archive:
            members = [
                member for member in archive.getmembers()
                if member.isfile() and member.name.lower().endswith((".sql", ".sql.gz"))
            ]
            if len(members) != 1:
                raise ConversionError(
                    "SQL archive must contain exactly one .sql or .sql.gz file"
                )
            raw = archive.extractfile(members[0])
            if raw is None:
                raise ConversionError("failed to read SQL archive member")
            binary: BinaryIO = gzip.GzipFile(fileobj=raw) if members[0].name.lower().endswith(".gz") else raw
            with binary, contextlib.closing(
                TextIOWrapper(binary, encoding="utf-8", errors="replace", newline="")
            ) as stream:
                yield stream
        return
    if path.name.endswith(".gz"):
        with gzip.open(path, "rt", encoding="utf-8", errors="replace", newline="") as stream:
            yield stream
        return
    with path.open("rt", encoding="utf-8", errors="replace", newline="") as stream:
        yield stream


def read_schema(path: Path) -> tuple[dict[str, list[str]], str]:
    schemas: dict[str, list[str]] = {}
    database_name = "ping"
    current_table: str | None = None

    with open_dump(path) as source:
        for line in source:
            if line.startswith("-- Host:"):
                match = re.search(r"\bDatabase:\s*(\S+)", line)
                if match:
                    database_name = match.group(1)

            match = CREATE_TABLE_RE.match(line)
            if match:
                current_table = match.group(1)
                schemas[current_table] = []
                continue

            if current_table is None:
                continue
            if line.startswith(")"):
                current_table = None
                continue
            match = COLUMN_RE.match(line)
            if match:
                schemas[current_table].append(match.group(1))

    if not any(table in schemas for table in SUPPORTED_TABLES):
        raise ConversionError("no supported FenPing tables found in SQL dump")
    return schemas, database_name


def parse_column_list(value: str) -> list[str]:
    columns = []
    for item in value.split(","):
        item = item.strip()
        if len(item) < 2 or item[0] != "`" or item[-1] != "`":
            raise ConversionError(f"unsupported INSERT column list: {value}")
        columns.append(item[1:-1].replace("``", "`"))
    return columns


class ValuesParser:
    def __init__(self, value: str, table: str):
        self.value = value
        self.table = table
        self.position = 0

    def rows(self) -> Iterator[list[object]]:
        self._skip_space()
        while self.position < len(self.value):
            if self.value[self.position] == ";":
                self.position += 1
                self._skip_space()
                if self.position != len(self.value):
                    self._fail("unexpected text after INSERT")
                return
            self._expect("(")
            row: list[object] = []
            while True:
                self._skip_space()
                row.append(self._parse_value())
                self._skip_space()
                if self._take(","):
                    continue
                self._expect(")")
                break
            yield row
            self._skip_space()
            if self._take(","):
                self._skip_space()
                continue
            if self._take(";"):
                self._skip_space()
                if self.position != len(self.value):
                    self._fail("unexpected text after INSERT")
                return
            self._fail("expected ',' or ';' after row")

    def _parse_value(self) -> object:
        if self.position >= len(self.value):
            self._fail("unexpected end of INSERT")
        if self.value[self.position] == "'":
            return self._parse_string()

        start = self.position
        while (
            self.position < len(self.value)
            and self.value[self.position] not in ",)"
        ):
            self.position += 1
        token = self.value[start : self.position].strip()
        if token.upper() == "NULL":
            return None
        if INTEGER_RE.fullmatch(token):
            return int(token)
        if FLOAT_RE.fullmatch(token):
            return float(token)
        self._fail(f"unsupported unquoted value {token!r}")

    def _parse_string(self) -> str:
        self.position += 1
        result: list[str] = []
        escapes = {
            "0": "\0",
            "b": "\b",
            "n": "\n",
            "r": "\r",
            "t": "\t",
            "Z": "\x1a",
        }
        while self.position < len(self.value):
            char = self.value[self.position]
            self.position += 1
            if char == "'":
                if self.position < len(self.value) and self.value[self.position] == "'":
                    result.append("'")
                    self.position += 1
                    continue
                return "".join(result)
            if char == "\\":
                if self.position >= len(self.value):
                    self._fail("unterminated string escape")
                escaped = self.value[self.position]
                self.position += 1
                result.append(escapes.get(escaped, escaped))
            else:
                result.append(char)
        self._fail("unterminated string")

    def _skip_space(self) -> None:
        while self.position < len(self.value) and self.value[self.position].isspace():
            self.position += 1

    def _take(self, expected: str) -> bool:
        if self.position < len(self.value) and self.value[self.position] == expected:
            self.position += 1
            return True
        return False

    def _expect(self, expected: str) -> None:
        if not self._take(expected):
            self._fail(f"expected {expected!r}")

    def _fail(self, message: str):
        raise ConversionError(
            f"invalid INSERT for {self.table} near character {self.position}: {message}"
        )


def json_line(row: list[object]) -> str:
    return json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n"


def parse_datetime(value: object, fallback: str) -> str:
    if isinstance(value, str):
        try:
            return datetime.strptime(value.strip(), "%Y-%m-%d %H:%M:%S").strftime(
                "%Y-%m-%d %H:%M:%S"
            )
        except ValueError:
            pass
    return fallback


def valid_lease_ip(value: str) -> bool:
    try:
        return isinstance(ipaddress.ip_address(value), ipaddress.IPv4Address)
    except ValueError:
        return False


def normalize_legacy_dns(value: object) -> tuple[str | None, int]:
    if value is None:
        return None, 0
    tokens = re.split(r"[\s,;]+", str(value).strip())
    servers: list[str] = []
    invalid = 0
    for token in filter(None, tokens):
        normalized: str | None = None
        try:
            address = ipaddress.ip_address(token)
            if isinstance(address, ipaddress.IPv4Address):
                normalized = str(address)
        except ValueError:
            if IPV4_OCTETS_RE.fullmatch(token):
                octets = [int(part, 10) for part in token.split(".")]
                if all(octet <= 255 for octet in octets):
                    normalized = ".".join(str(octet) for octet in octets)
        if normalized is None:
            invalid += 1
        elif normalized not in servers:
            servers.append(normalized)
    return (" ".join(servers) or None), invalid


def ipv4_network(value: object) -> str | None:
    try:
        address = ipaddress.ip_address(str(value or "").strip())
    except ValueError:
        return None
    if not isinstance(address, ipaddress.IPv4Address):
        return None
    return str(address).rsplit(".", 1)[0]


def normalize_legacy_ranges(
    rows: list[list[object]],
    columns: list[str],
    host_networks: Counter[str],
) -> list[list[object]]:
    if "ip_begin" not in columns:
        return rows
    index = columns.index("ip_begin")
    category_networks: Counter[str] = Counter()
    for row in rows:
        network = ipv4_network(row[index])
        if network is not None:
            category_networks[network] += 1
    networks = category_networks or host_networks
    if not networks:
        return rows
    source_network = networks.most_common(1)[0][0]

    for row in rows:
        boundary = str(row[index] or "").strip()
        if boundary.isdigit() and 0 <= int(boundary) <= 255:
            row[index] = f"{source_network}.{int(boundary)}"
    return rows


def merge_lease(
    leases: dict[tuple[str, str], list[object]],
    columns: list[str],
    row: list[object],
    fallback_time: str,
) -> bool:
    values = dict(zip(columns, row))
    ip = str(values.get("ip") or "").strip()
    mac = str(values.get("hardware-ethernet") or "").strip().lower()
    if not valid_lease_ip(ip) or not MAC_RE.fullmatch(mac):
        return False

    hostname_value = values.get("client-hostname")
    hostname = str(hostname_value).strip() if hostname_value is not None else ""
    ends = parse_datetime(values.get("ends"), fallback_time)
    first_seen = parse_datetime(values.get("starts"), fallback_time)
    last_seen = parse_datetime(values.get("cltt") or values.get("starts"), fallback_time)
    key = (mac, ip)
    existing = leases.get(key)
    if existing is None:
        leases[key] = [ip, mac, hostname or None, ends, first_seen, last_seen, 1]
    else:
        if hostname and (existing[2] is None or hostname > existing[2]):
            existing[2] = hostname
        existing[3] = max(str(existing[3]), ends)
        existing[4] = min(str(existing[4]), first_seen)
        existing[5] = max(str(existing[5]), last_seen)
    return True


def write_spool(stream: TextIO, row: list[object]) -> None:
    stream.write(json_line(row))


class ScanSpoolWriter:
    def __init__(self, paths: dict[str, Path]):
        self.streams = {
            table: paths[table].open("wt", encoding="utf-8", newline="\n")
            for table in SCAN_TABLE_COLUMNS
        }
        self.counts = {table: 0 for table in SCAN_TABLE_COLUMNS}
        self.ids = {table: 0 for table in SCAN_TABLE_COLUMNS}

    def close(self) -> None:
        for stream in self.streams.values():
            stream.close()

    def next_id(self, table: str) -> int:
        self.ids[table] += 1
        return self.ids[table]

    def row(self, table: str, values: list[object]) -> None:
        expected = len(SCAN_TABLE_COLUMNS[table])
        if len(values) != expected:
            raise ConversionError(
                f"internal error: {table} row has {len(values)} values, expected {expected}"
            )
        write_spool(self.streams[table], values)
        self.counts[table] += 1


def optional_text(value: object) -> str | None:
    text = str(value or "").strip()
    return text or None


def optional_int(value: object) -> int | None:
    try:
        return int(str(value)) if value not in (None, "") else None
    except (TypeError, ValueError):
        return None


def optional_float(value: object) -> float | None:
    try:
        return float(str(value)) if value not in (None, "") else None
    except (TypeError, ValueError):
        return None


def epoch_datetime(value: object) -> str | None:
    try:
        return datetime.fromtimestamp(int(str(value)), timezone.utc).strftime(
            "%Y-%m-%d %H:%M:%S"
        )
    except (OverflowError, TypeError, ValueError):
        return None


def nmap_last_boot(uptime: ET.Element | None, end_epoch: int | None) -> str | None:
    if uptime is None:
        return None
    seconds = optional_int(uptime.get("seconds"))
    if seconds is not None and end_epoch is not None:
        return epoch_datetime(end_epoch - seconds)
    value = uptime.get("lastboot", "").strip()
    if not value:
        return None
    try:
        parsed = parsedate_to_datetime(value)
        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)
        return parsed.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    except (TypeError, ValueError):
        try:
            parsed = datetime.strptime(value, "%a %b %d %H:%M:%S %Y")
            return parsed.strftime("%Y-%m-%d %H:%M:%S")
        except ValueError:
            return None


def parse_port_ranges(value: str) -> list[tuple[int, int]]:
    ranges: list[tuple[int, int]] = []
    for item in value.split(","):
        item = item.strip()
        if not item:
            continue
        if "-" in item:
            left, right = item.split("-", 1)
        else:
            left = right = item
        if not left.isdigit() or not right.isdigit():
            continue
        begin = max(0, int(left))
        end = min(65535, int(right))
        if begin <= end:
            ranges.append((begin, end))
    return ranges


def script_children(element: ET.Element) -> list[ET.Element]:
    return [child for child in element if child.tag in ("table", "elem")]


def write_script_nodes(
    writer: ScanSpoolWriter,
    script_id: int,
    element: ET.Element,
    parent_id: int | None = None,
) -> None:
    for position, node in enumerate(script_children(element)):
        node_id = writer.next_id("scan_snapshot_script_nodes")
        value = optional_text(node.text)
        writer.row(
            "scan_snapshot_script_nodes",
            [
                node_id,
                script_id,
                parent_id,
                position,
                node.tag,
                optional_text(node.get("key")),
                value,
            ],
        )
        write_script_nodes(writer, script_id, node, node_id)


def write_scripts(
    writer: ScanSpoolWriter,
    snapshot_id: int,
    port_id: int | None,
    scripts: list[ET.Element],
) -> None:
    for position, script in enumerate(scripts):
        script_row_id = writer.next_id("scan_snapshot_scripts")
        writer.row(
            "scan_snapshot_scripts",
            [
                script_row_id,
                snapshot_id,
                port_id,
                position,
                script.get("id", ""),
                optional_text(script.get("output")),
            ],
        )
        write_script_nodes(writer, script_row_id, script)


def nmap_result_hash(host: ET.Element | None, ip: str) -> str:
    status = host.find("status").get("state", "") if host is not None and host.find("status") is not None else "down"
    signature: dict[str, object] = {"ip": ip, "status": status, "ports": [], "os": []}
    if host is not None:
        signature["addresses"] = sorted(
            (item.get("addr", ""), item.get("addrtype", ""), item.get("vendor", ""))
            for item in host.findall("address")
        )
        signature["hostnames"] = sorted(
            (item.get("name", ""), item.get("type", ""))
            for item in host.findall("./hostnames/hostname")
        )
        signature["ports"] = sorted(
            (
                item.get("protocol", ""),
                optional_int(item.get("portid")) or 0,
                (item.find("state").get("state", "") if item.find("state") is not None else ""),
                (item.find("service").get("name", "") if item.find("service") is not None else ""),
                (item.find("service").get("product", "") if item.find("service") is not None else ""),
                (item.find("service").get("version", "") if item.find("service") is not None else ""),
            )
            for item in host.findall("./ports/port")
        )
        signature["os"] = sorted(
            (item.get("name", ""), optional_int(item.get("accuracy")) or 0)
            for item in host.findall("./os/osmatch")
        )
    encoded = json.dumps(signature, ensure_ascii=False, separators=(",", ":"), sort_keys=True)
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def write_nmap_snapshot(
    writer: ScanSpoolWriter,
    snapshot_id: int,
    root: ET.Element,
    host: ET.Element,
) -> None:
    scopes: set[tuple[str, int, int]] = set()
    for scan_info in root.findall("scaninfo"):
        protocol = scan_info.get("protocol", "").strip().lower()
        if not protocol:
            continue
        for begin, end in parse_port_ranges(scan_info.get("services", "")):
            scopes.add((protocol, begin, end))
    for protocol, begin, end in sorted(scopes):
        writer.row("scan_snapshot_scopes", [snapshot_id, protocol, begin, end])

    addresses: set[tuple[str, str]] = set()
    address_position = 0
    for address in host.findall("address"):
        value = address.get("addr", "")
        address_type = address.get("addrtype", "")
        if not value or (address_type, value) in addresses:
            continue
        addresses.add((address_type, value))
        writer.row(
            "scan_snapshot_addresses",
            [
                writer.next_id("scan_snapshot_addresses"),
                snapshot_id,
                address_position,
                value,
                address_type,
                optional_text(address.get("vendor")),
            ],
        )
        address_position += 1

    hostnames: set[tuple[str, str]] = set()
    hostname_position = 0
    for hostname in host.findall("./hostnames/hostname"):
        name = hostname.get("name", "")
        hostname_type = hostname.get("type", "")
        if not name or (hostname_type, name) in hostnames:
            continue
        hostnames.add((hostname_type, name))
        writer.row(
            "scan_snapshot_hostnames",
            [
                writer.next_id("scan_snapshot_hostnames"),
                snapshot_id,
                hostname_position,
                name,
                hostname_type,
            ],
        )
        hostname_position += 1

    ports = host.findall("./ports/port")
    for port in ports:
        port_id = writer.next_id("scan_snapshot_ports")
        state = port.find("state")
        service = port.find("service")
        writer.row(
            "scan_snapshot_ports",
            [
                port_id,
                snapshot_id,
                port.get("protocol", "").lower(),
                optional_int(port.get("portid")) or 0,
                state.get("state", "") if state is not None else "",
                optional_text(state.get("reason")) if state is not None else None,
                optional_int(state.get("reason_ttl")) if state is not None else None,
                optional_text(service.get("name")) if service is not None else None,
                optional_text(service.get("product")) if service is not None else None,
                optional_text(service.get("version")) if service is not None else None,
                optional_text(service.get("extrainfo")) if service is not None else None,
                optional_text(service.get("tunnel")) if service is not None else None,
                optional_text(service.get("method")) if service is not None else None,
                optional_int(service.get("conf")) if service is not None else None,
                optional_text(service.get("ostype")) if service is not None else None,
            ],
        )
        if service is not None:
            for position, cpe in enumerate(service.findall("cpe")):
                value = "".join(cpe.itertext()).strip()
                if value:
                    writer.row("scan_snapshot_port_cpes", [port_id, position, value])
        write_scripts(writer, snapshot_id, port_id, port.findall("script"))

    for position, extra in enumerate(host.findall("./ports/extraports")):
        extra_id = writer.next_id("scan_snapshot_extra_ports")
        writer.row(
            "scan_snapshot_extra_ports",
            [
                extra_id,
                snapshot_id,
                position,
                extra.get("state", ""),
                optional_int(extra.get("count")) or 0,
            ],
        )
        for reason_position, reason in enumerate(extra.findall("extrareasons")):
            writer.row(
                "scan_snapshot_extra_reasons",
                [
                    writer.next_id("scan_snapshot_extra_reasons"),
                    extra_id,
                    reason_position,
                    reason.get("reason", ""),
                    optional_int(reason.get("count")) or 0,
                    optional_text(reason.get("proto")),
                    optional_text(reason.get("ports")),
                ],
            )

    for position, match in enumerate(host.findall("./os/osmatch")):
        match_id = writer.next_id("scan_snapshot_os_matches")
        writer.row(
            "scan_snapshot_os_matches",
            [
                match_id,
                snapshot_id,
                position,
                match.get("name", ""),
                optional_int(match.get("accuracy")) or 0,
            ],
        )
        for class_position, os_class in enumerate(match.findall("osclass")):
            class_id = writer.next_id("scan_snapshot_os_classes")
            writer.row(
                "scan_snapshot_os_classes",
                [
                    class_id,
                    match_id,
                    class_position,
                    optional_text(os_class.get("vendor")),
                    optional_text(os_class.get("osfamily")),
                    optional_text(os_class.get("osgen")),
                    optional_text(os_class.get("type")),
                    optional_int(os_class.get("accuracy")),
                ],
            )
            for cpe_position, cpe in enumerate(os_class.findall("cpe")):
                value = "".join(cpe.itertext()).strip()
                if value:
                    writer.row("scan_snapshot_os_cpes", [class_id, cpe_position, value])

    host_script = host.find("hostscript")
    if host_script is not None:
        write_scripts(writer, snapshot_id, None, host_script.findall("script"))

    trace = host.find("trace")
    if trace is not None:
        protocol = optional_text(trace.get("proto"))
        trace_port = optional_int(trace.get("port"))
        for position, hop in enumerate(trace.findall("hop")):
            writer.row(
                "scan_snapshot_trace_hops",
                [
                    writer.next_id("scan_snapshot_trace_hops"),
                    snapshot_id,
                    position,
                    protocol,
                    trace_port,
                    optional_int(hop.get("ttl")) or 0,
                    hop.get("ipaddr", ""),
                    optional_text(hop.get("host")),
                    optional_float(hop.get("rtt")),
                ],
            )


def nmap_member_ip(member: tarfile.TarInfo) -> str | None:
    if not member.isfile() or not member.name.lower().endswith(".xml"):
        return None
    filename = Path(member.name).name
    value = filename[:-4]
    try:
        address = ipaddress.ip_address(value)
    except ValueError as error:
        raise ConversionError(f"nmap file is not named IP.xml: {member.name}") from error
    if not isinstance(address, ipaddress.IPv4Address):
        raise ConversionError(f"nmap filename must use IPv4: {member.name}")
    return str(address)


def parse_nmap_archive(
    source_path: Path,
    spool_paths: dict[str, Path],
) -> tuple[dict[str, int], dict[str, int]]:
    writer = ScanSpoolWriter(spool_paths)
    archive_bytes = 0
    scan_count = 0
    snapshot_count = 0
    snapshot_bytes = 0
    try:
        with tarfile.open(source_path, "r:*") as archive:
            members: list[tuple[int, tarfile.TarInfo, str]] = []
            seen: set[str] = set()
            for member in archive.getmembers():
                ip = nmap_member_ip(member)
                if ip is None:
                    continue
                if ip in seen:
                    raise ConversionError(f"duplicate nmap result for {ip}")
                if member.size > MAX_NMAP_MEMBER_BYTES:
                    raise ConversionError(f"nmap XML is too large: {member.name}")
                archive_bytes += member.size
                if archive_bytes > MAX_NMAP_ARCHIVE_BYTES:
                    raise ConversionError("nmap archive is too large")
                seen.add(ip)
                members.append((int(ipaddress.ip_address(ip)), member, ip))
            if not members:
                raise ConversionError("nmap archive contains no IP.xml files")

            for _, member, ip in sorted(members):
                extracted = archive.extractfile(member)
                if extracted is None:
                    raise ConversionError(f"failed to read nmap XML: {member.name}")
                xml = extracted.read(MAX_NMAP_MEMBER_BYTES + 1)
                if len(xml) > MAX_NMAP_MEMBER_BYTES:
                    raise ConversionError(f"nmap XML is too large: {member.name}")
                try:
                    root = ET.fromstring(xml)
                except ET.ParseError as error:
                    raise ConversionError(f"invalid nmap XML {member.name}: {error}") from error
                if root.tag != "nmaprun":
                    raise ConversionError(f"not an nmap XML file: {member.name}")

                hosts = root.findall("host")
                if len(hosts) > 1:
                    raise ConversionError(
                        f"nmap XML must contain at most one host: {member.name}"
                    )
                host = hosts[0] if hosts else None
                xml_ips = {
                    item.get("addr", "")
                    for item in host.findall("address")
                    if host is not None and item.get("addrtype") == "ipv4"
                } if host is not None else set()
                if xml_ips and ip not in xml_ips:
                    raise ConversionError(
                        f"nmap filename {ip}.xml does not match XML address"
                    )

                status_element = host.find("status") if host is not None else None
                status = status_element.get("state", "unknown") if status_element is not None else "down"
                finished = root.find("./runstats/finished")
                start_epoch = optional_int(root.get("start"))
                end_epoch = optional_int(finished.get("time")) if finished is not None else None
                if end_epoch is None and host is not None:
                    end_epoch = optional_int(host.get("endtime"))
                if start_epoch is None:
                    start_epoch = optional_int(host.get("starttime")) if host is not None else None
                if start_epoch is None:
                    start_epoch = member.mtime or int(datetime.now(timezone.utc).timestamp())
                if end_epoch is None:
                    end_epoch = start_epoch
                elapsed = optional_float(finished.get("elapsed")) if finished is not None else None
                duration = math.ceil(elapsed) if elapsed is not None else max(0, end_epoch - start_epoch)
                ports = host.findall("./ports/port") if host is not None else []

                scan_id = writer.next_id("scans")
                snapshot_id: int | None = None
                if host is not None and status == "up":
                    snapshot_id = writer.next_id("scan_snapshots")
                    content_hash = hashlib.sha256(xml).hexdigest()
                    writer.row(
                        "scan_snapshots",
                        [
                            snapshot_id,
                            ip,
                            "deep",
                            nmap_result_hash(host, ip),
                            content_hash,
                            epoch_datetime(end_epoch),
                        ],
                    )
                    write_nmap_snapshot(writer, snapshot_id, root, host)
                    snapshot_count += 1
                    snapshot_bytes += len(xml)

                uptime = host.find("uptime") if host is not None else None
                distance = host.find("distance") if host is not None else None
                writer.row(
                    "scans",
                    [
                        scan_id,
                        ip,
                        "deep",
                        "complete",
                        status,
                        epoch_datetime(start_epoch),
                        epoch_datetime(end_epoch),
                        duration,
                        len(ports),
                        snapshot_id,
                        1 if snapshot_id is not None else 0,
                        0 if snapshot_id is not None else 1,
                        optional_text(root.get("scanner")),
                        optional_text(root.get("version")),
                        optional_text(root.get("args")),
                        optional_text(status_element.get("reason")) if status_element is not None else None,
                        optional_int(status_element.get("reason_ttl")) if status_element is not None else None,
                        nmap_last_boot(uptime, end_epoch),
                        optional_int(uptime.get("seconds")) if uptime is not None else None,
                        optional_int(distance.get("value")) if distance is not None else None,
                        None,
                    ],
                )
                scan_count += 1
    finally:
        writer.close()

    return writer.counts, {
        "scan_rows": scan_count,
        "scan_snapshot_rows": snapshot_count,
        "scan_snapshot_bytes": snapshot_bytes,
    }


def parse_data(
    source_path: Path,
    schemas: dict[str, list[str]],
    spool_paths: dict[str, Path],
    fallback_time: str,
) -> tuple[dict[str, list[str]], dict[str, int], dict[str, int]]:
    export_columns = {
        table: list(schemas[table])
        for table in SUPPORTED_TABLES
        if table in schemas
    }
    row_counts = {table: 0 for table in export_columns}
    skipped_counts: dict[str, int] = {}
    leases: dict[tuple[str, str], list[object]] = {}
    ranges: list[list[object]] = []
    host_networks: Counter[str] = Counter()
    streams = {
        table: path.open("wt", encoding="utf-8", newline="\n")
        for table, path in spool_paths.items()
        if table not in ("leases", "range")
    }

    try:
        with open_dump(source_path) as source:
            lines = enumerate(source, 1)
            for line_number, line in lines:
                if not line.startswith("INSERT INTO"):
                    continue
                statement = line
                while not statement.rstrip().endswith(";"):
                    try:
                        _, continuation = next(lines)
                    except StopIteration as error:
                        raise ConversionError(
                            f"unterminated INSERT on line {line_number}"
                        ) from error
                    statement += continuation

                match = INSERT_RE.match(statement)
                if not match:
                    raise ConversionError(f"unsupported INSERT syntax on line {line_number}")
                table = match.group(1)
                if table not in export_columns:
                    skipped_counts[table] = skipped_counts.get(table, 0) + sum(
                        1 for _ in ValuesParser(statement[match.end() :], table).rows()
                    )
                    continue

                insert_columns = (
                    parse_column_list(match.group(2))
                    if match.group(2) is not None
                    else schemas[table]
                )
                if export_columns[table] != insert_columns:
                    raise ConversionError(
                        f"inconsistent columns in INSERT for {table} on line {line_number}"
                    )
                for row in ValuesParser(statement[match.end() :], table).rows():
                    if len(row) != len(insert_columns):
                        raise ConversionError(
                            f"row for {table} has {len(row)} values; "
                            f"expected {len(insert_columns)}"
                        )
                    if table == "leases":
                        if not merge_lease(leases, insert_columns, row, fallback_time):
                            skipped_counts["invalid leases"] = (
                                skipped_counts.get("invalid leases", 0) + 1
                            )
                    elif table == "range":
                        ranges.append(row)
                    else:
                        if table == "ips" and "ip" in insert_columns:
                            network = ipv4_network(row[insert_columns.index("ip")])
                            if network is not None:
                                host_networks[network] += 1
                        if table == "ips" and "dns" in insert_columns:
                            dns_index = insert_columns.index("dns")
                            row[dns_index], invalid_dns = normalize_legacy_dns(row[dns_index])
                            if invalid_dns:
                                skipped_counts["invalid DNS tokens"] = (
                                    skipped_counts.get("invalid DNS tokens", 0) + invalid_dns
                                )
                        write_spool(streams[table], row)
                        row_counts[table] += 1
    finally:
        for stream in streams.values():
            stream.close()

    if "leases" in export_columns:
        export_columns["leases"] = [
            "ip",
            "hardware-ethernet",
            "client-hostname",
            "ends",
            "first_seen",
            "last_seen",
            "active",
        ]
        with spool_paths["leases"].open("wt", encoding="utf-8", newline="\n") as stream:
            for key in sorted(leases):
                write_spool(stream, leases[key])
                row_counts["leases"] += 1

    if "range" in export_columns:
        ranges = normalize_legacy_ranges(ranges, export_columns["range"], host_networks)
        with spool_paths["range"].open("wt", encoding="utf-8", newline="\n") as stream:
            for row in ranges:
                write_spool(stream, row)
                row_counts["range"] += 1

    return export_columns, row_counts, skipped_counts


def write_database_json(
    path: Path,
    sql_source_name: str,
    nmap_source_name: str,
    created_at: str,
    columns: dict[str, list[str]],
    row_counts: dict[str, int],
    spool_paths: dict[str, Path],
) -> None:
    with path.open("wt", encoding="utf-8", newline="\n") as output:
        output.write("{\n")
        output.write('    "format": "fenping-db",\n')
        output.write(f'    "version": {json.dumps(BACKUP_VERSION)},\n')
        output.write(f'    "created_at": {json.dumps(created_at)},\n')
        output.write(
            '    "conversion": '
            + json.dumps(
                {
                    "source": sql_source_name,
                    "nmap_source": nmap_source_name,
                    "source_format": "fenping-1.2-sql+nmap-xml",
                    "offline": True,
                },
                ensure_ascii=False,
            )
            + ",\n"
        )
        output.write('    "tables": {\n')

        first_table = True
        for table in EXPORT_TABLES:
            if table not in columns:
                continue
            if not first_table:
                output.write(",\n")
            first_table = False
            output.write(f"        {json.dumps(table)}: {{\n")
            output.write(
                '            "columns": '
                + json.dumps(columns[table], ensure_ascii=False, separators=(",", ":"))
                + ",\n"
            )
            output.write('            "rows": [')
            with spool_paths[table].open("rt", encoding="utf-8") as rows:
                for index, row in enumerate(rows):
                    output.write("\n" if index == 0 else ",\n")
                    output.write("                " + row.rstrip("\n"))
            if row_counts[table]:
                output.write("\n            ")
            output.write("]\n        }")
        output.write("\n    }\n}\n")


def write_json(path: Path, value: object) -> None:
    with path.open("wt", encoding="utf-8", newline="\n") as stream:
        json.dump(value, stream, ensure_ascii=False, indent=4)
        stream.write("\n")


def write_archive(
    target: Path,
    stage: Path,
    database_name: str,
    created_at: str,
    table_count: int,
    row_count: int,
    scan_counts: dict[str, int],
    force: bool,
) -> None:
    database_path = stage / "db.json"
    manifest = {
        "format": "fenping-backup",
        "version": BACKUP_VERSION,
        "created_at": created_at,
        "hostname": socket.gethostname() or "fenping-offline-converter",
        "includes": {
            "db": "db.json",
            "netboot": "netboot/",
            "netboot_index": "netboot-index.json",
        },
        "counts": {
            "netboot_files": 0,
            "scan_rows": scan_counts["scan_rows"],
            "scan_snapshot_rows": scan_counts["scan_snapshot_rows"],
            "scan_snapshot_bytes": scan_counts["scan_snapshot_bytes"],
            "netboot_rows": 0,
        },
        "database": {
            "name": database_name,
            "tables": table_count,
            "rows": row_count,
            "bytes": database_path.stat().st_size,
        },
    }
    write_json(stage / "manifest.json", manifest)
    write_json(stage / "netboot-index.json", [])
    (stage / "netboot").mkdir()

    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists() and not force:
        raise ConversionError(f"target already exists (use --force): {target}")
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=target.name + ".", suffix=".tmp", dir=target.parent
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        with tarfile.open(temporary, "w:gz") as archive:
            for name in ("manifest.json", "db.json", "netboot-index.json", "netboot"):
                archive.add(stage / name, arcname=name, recursive=True)
        temporary.chmod(0o600)
        if target.exists() and not force:
            raise ConversionError(f"target already exists (use --force): {target}")
        os.replace(temporary, target)
    finally:
        temporary.unlink(missing_ok=True)


def convert(
    sql_source: Path,
    nmap_source: Path,
    target: Path,
    force: bool,
) -> tuple[int, int, dict[str, int], dict[str, int]]:
    sql_source = sql_source.resolve()
    nmap_source = nmap_source.resolve()
    target = target.resolve()
    if not sql_source.is_file():
        raise ConversionError(f"SQL source is not a readable file: {sql_source}")
    if not nmap_source.is_file():
        raise ConversionError(f"nmap source is not a readable file: {nmap_source}")
    if sql_source == nmap_source:
        raise ConversionError("SQL and nmap sources must be different files")
    if target in (sql_source, nmap_source):
        raise ConversionError("sources and target must be different files")
    if not (target.name.endswith(".tgz") or target.name.endswith(".tar.gz")):
        raise ConversionError("target must end with .tgz or .tar.gz")

    schemas, database_name = read_schema(sql_source)
    created = datetime.now(timezone.utc)
    created_at = created.isoformat(timespec="seconds")
    fallback_time = created.strftime("%Y-%m-%d %H:%M:%S")

    with tempfile.TemporaryDirectory(prefix="fenping-v12-convert-") as stage_name:
        stage = Path(stage_name)
        spool_paths = {
            table: stage / f"{table}.rows"
            for table in SUPPORTED_TABLES
            if table in schemas
        }
        spool_paths.update({
            table: stage / f"{table}.rows" for table in SCAN_TABLE_COLUMNS
        })
        columns, row_counts, skipped_counts = parse_data(
            sql_source, schemas, spool_paths, fallback_time
        )
        scan_row_counts, scan_counts = parse_nmap_archive(nmap_source, spool_paths)
        columns.update({table: list(names) for table, names in SCAN_TABLE_COLUMNS.items()})
        row_counts.update(scan_row_counts)
        database_path = stage / "db.json"
        write_database_json(
            database_path,
            sql_source.name,
            nmap_source.name,
            created_at,
            columns,
            row_counts,
            spool_paths,
        )
        write_archive(
            target,
            stage,
            database_name,
            created_at,
            len(columns),
            sum(row_counts.values()),
            scan_counts,
            force,
        )
    return len(columns), sum(row_counts.values()), skipped_counts, scan_counts


def main() -> int:
    args = parse_args()
    target = args.target or default_target(args.sql)
    try:
        tables, rows, skipped, scans = convert(
            args.sql, args.nmap, target, args.force
        )
    except (ConversionError, OSError, gzip.BadGzipFile, tarfile.TarError) as error:
        print(f"conversion failed: {error}", file=sys.stderr)
        return 1

    print(f"backup written: {target.resolve()}")
    print(f"database: {tables} tables, {rows} rows")
    print(
        f"nmap: {scans['scan_rows']} latest scans, "
        f"{scans['scan_snapshot_rows']} usable snapshots"
    )
    if skipped:
        details = ", ".join(f"{name}: {count}" for name, count in sorted(skipped.items()))
        print(f"skipped legacy data: {details}")
    print("MySQL connections used: 0")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

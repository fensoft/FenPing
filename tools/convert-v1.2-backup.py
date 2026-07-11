#!/usr/bin/env python3
"""Convert a legacy FenPing SQL dump to a current JSON backup archive.

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
* A raw SQL dump has no netboot file payload, so the generated archive contains
  an empty netboot directory and no netboot image records.
* Tables and fields introduced after FenPing 1.2 have no source data.  Restore
  leaves them empty or uses current schema defaults (including host scan fields).
* Malformed UTF-8 input bytes are replaced with the Unicode replacement
  character so that db.json remains valid UTF-8.
"""

from __future__ import annotations

import argparse
import gzip
import ipaddress
import json
import os
import re
import socket
import sys
import tarfile
import tempfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterator, TextIO


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
            "Convert a FenPing 1.2 .sql or .sql.gz dump to a 1.6 db.json .tgz "
            "archive without using a MySQL server."
        )
    )
    parser.add_argument("source", type=Path, help="legacy .sql or .sql.gz dump")
    parser.add_argument(
        "target",
        nargs="?",
        type=Path,
        help="output .tgz or .tar.gz (default: source name with .tgz)",
    )
    parser.add_argument(
        "--force", action="store_true", help="replace an existing output archive"
    )
    return parser.parse_args()


def default_target(source: Path) -> Path:
    name = source.name
    if name.endswith(".sql.gz"):
        name = name[: -len(".sql.gz")]
    elif name.endswith(".sql"):
        name = name[: -len(".sql")]
    else:
        name = source.stem
    return source.with_name(name + ".tgz")


def open_dump(path: Path) -> TextIO:
    if path.name.endswith(".gz"):
        return gzip.open(path, "rt", encoding="utf-8", errors="replace", newline="")
    return path.open("rt", encoding="utf-8", errors="replace", newline="")


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
    source_name: str,
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
                {"source": source_name, "source_format": "fenping-1.2-sql", "offline": True},
                ensure_ascii=False,
            )
            + ",\n"
        )
        output.write('    "tables": {\n')

        first_table = True
        for table in SUPPORTED_TABLES:
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
            "scan_rows": 0,
            "scan_snapshot_rows": 0,
            "scan_snapshot_bytes": 0,
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


def convert(source: Path, target: Path, force: bool) -> tuple[int, int, dict[str, int]]:
    source = source.resolve()
    target = target.resolve()
    if not source.is_file():
        raise ConversionError(f"source is not a readable file: {source}")
    if target == source:
        raise ConversionError("source and target must be different files")
    if not (target.name.endswith(".tgz") or target.name.endswith(".tar.gz")):
        raise ConversionError("target must end with .tgz or .tar.gz")

    schemas, database_name = read_schema(source)
    created = datetime.now(timezone.utc)
    created_at = created.isoformat(timespec="seconds")
    fallback_time = created.strftime("%Y-%m-%d %H:%M:%S")

    with tempfile.TemporaryDirectory(prefix="fenping-sql-convert-") as stage_name:
        stage = Path(stage_name)
        spool_paths = {
            table: stage / f"{table}.rows"
            for table in SUPPORTED_TABLES
            if table in schemas
        }
        columns, row_counts, skipped_counts = parse_data(
            source, schemas, spool_paths, fallback_time
        )
        database_path = stage / "db.json"
        write_database_json(
            database_path,
            source.name,
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
            force,
        )
    return len(columns), sum(row_counts.values()), skipped_counts


def main() -> int:
    args = parse_args()
    source = args.source
    target = args.target or default_target(source)
    try:
        tables, rows, skipped = convert(source, target, args.force)
    except (ConversionError, OSError, gzip.BadGzipFile, tarfile.TarError) as error:
        print(f"conversion failed: {error}", file=sys.stderr)
        return 1

    print(f"backup written: {target.resolve()}")
    print(f"database: {tables} tables, {rows} rows")
    if skipped:
        details = ", ".join(f"{name}: {count}" for name, count in sorted(skipped.items()))
        print(f"skipped legacy data: {details}")
    print("MySQL connections used: 0")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

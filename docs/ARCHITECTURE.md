# FenPing Architecture

FenPing is a small LAN appliance that combines host inventory, DHCP/DNS management, ping history, nmap scan history, notifications, backup/restore, and netboot image selection in one web UI.

The current runtime is one host-networked Docker container named `fenping`. The container runs Apache/PHP, MariaDB, dnsmasq, cron, and scanner tools together. This is intentionally not the split Compose architecture.

## High-Level Flow

1. `restart.sh` creates persistent directories under `data/`.
2. `restart.sh` builds `fensoft/fenping:1.5`.
3. `restart.sh` removes the old `fenping` container if present, then starts one `fenping` container with host networking and reduced capabilities.
4. `boot.sh` starts MariaDB, initializes the DB if needed, and applies `db.sql`.
5. `boot.sh` renders dnsmasq config, creates cron jobs, sends the optional restart notification, regenerates host files, starts cron, and runs Apache in the foreground.
6. Apache serves the static Vue app and rewrites `/api/...` to `api.php`.

## Docker Build

`Dockerfile` has two stages:

- `frontend`: uses `ubuntu:26.04` with Node/npm to run `npm ci` and `npm run build`.
- runtime: uses `ubuntu:26.04`, installs Apache, PHP, MariaDB server/client, dnsmasq, cron, nmap, ping/arping tools, sudo, and iptables.

The runtime image contains the full app and all services needed by FenPing.

`config.php` is committed as a generic, environment-driven config file. Runtime values should come from `.env`/container environment variables; do not hardcode machine-specific secrets in `config.php`.

## Persistent Data

The container filesystem is disposable. Runtime state lives under `data/`.

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/db` | `/var/lib/mysql` | MariaDB data directory |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases |
| `data/dnsmasq.d` | `/etc/dnsmasq.d` | generated dnsmasq config |
| `data/nmap` | `/var/www/html/nmap` | latest nmap XML, metadata, and scan history |
| `data/netboot` | `/var/www/html/netboot` | uploaded netboot files |
| `data/backups` | `/var/www/html/backups` | backup archives and imported dumps |
| `data/state` | `/var/www/html/state` | optional state files |

Avoid destructive edits in `data/` unless explicitly requested.

## Backend Entry Points

### API

`api.php` is the JSON API front controller. It:

- Normalizes `/api/...` paths.
- Matches route patterns by hand.
- Converts typed params like `{id:int}` and `{ip:ipv4}`.
- Enforces route auth metadata.
- Returns direct JSON for success and `{ "error": "message" }` for errors.

Route modules:

- `routes/auth.php`: session, login, logout.
- `routes/system.php`: health, inventory, notify, ping refresh.
- `routes/hosts.php`: host CRUD, host detail/history, category create/rename/delete.
- `routes/scans.php`: scan queue, quick scans, scan status/history/XML/JSON.
- `routes/netboot.php`: netboot image list/upload/delete.

### CLI

`cli.php` is the operational command entrypoint:

```bash
docker exec fenping php /var/www/html/cli.php ping [1-254|DEBUG]
docker exec fenping php /var/www/html/cli.php hosts
docker exec fenping php /var/www/html/cli.php inventory [--quick] [1-254|IPv4]
docker exec fenping php /var/www/html/cli.php backup [backup.tgz]
docker exec fenping php /var/www/html/cli.php restore <backup.tgz|dump.sql.gz>
docker exec fenping php /var/www/html/cli.php discord-restart
```

Prefer adding operational jobs here instead of creating new shell scripts.

## Database

The configured application database is normally `ping`.

Important tables:

- `ips`: managed hosts, static IPs, MACs, flags, DNS/router options, netboot assignment.
- `ping`: latest ping status per IP.
- `stats`: status history used for stability and notifications.
- `range`: category separators keyed by starting IP.
- `leases`: imported dnsmasq lease data.
- `vendors`: cached MAC vendor lookups.
- `scans`: nmap scan metadata.
- `netboot_images`: uploaded netboot file metadata.
- `users`: legacy table still present in schema.

`db.sql` is run at container boot and after restore. Keep it idempotent with `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, safe cleanup/update statements, and repeatable procedures.

The `update_status` procedure appends to `stats` only when status/IP/MAC changes; otherwise it extends the current row.

## Scanning

`ping.php` implements the ping scanner:

- `php cli.php ping` scans the configured `/24`.
- `php cli.php ping 42` scans one host.
- Local IPs are marked `Up` directly using the interface MAC.
- Raw ICMP sockets are used when available.
- `/proc/net/arp` is read directly for MAC discovery.
- `arping` helps distinguish `arp` from `arp-down`.

`inventory.php` performs nmap scans:

- Default mode discovers live hosts with `nmap -n -sn`, then deep scans discovered hosts.
- Deep scan uses `-A -p- -sS -T3`.
- Quick scan targets one host with faster flags.
- Every nmap command has a 2-hour hard timeout; timed-out scans are recorded with the `timeout` state.
- XML is saved under `/var/www/html/nmap`.
- History pruning keeps one week and removes older duplicate scan signatures.

Avoid default inventory scans in tests unless the user accepts LAN scan traffic.

## dnsmasq, DHCP, DNS, And Netboot

`hosts.php` generates:

- `/etc/dnsmasq.d/fenping.dhcp-hosts`
- `/etc/dnsmasq.d/fenping.dhcp-opts`
- `/etc/dnsmasq.d/fenping.hosts`

`boot.sh` renders `/etc/dnsmasq.d/fenping.conf` from `dnsmasq.conf.template`.
The required `IFACE` environment variable selects the host network interface that dnsmasq binds to for DHCP, DNS, and TFTP. Startup fails if it is unset.

`php cli.php hosts` always rewrites generated files and reloads/starts local dnsmasq.

Netboot uploads live in `/var/www/html/netboot`; metadata lives in `netboot_images`.

## Frontend

The UI is a static Vue 3 app built by Vite.

Important files:

- `index.html`: Vite HTML entry.
- `frontend/main.js`: app bootstrap and Tabler imports.
- `frontend/App.vue`: main application, routes, modals, table, scans, notify, netboot, host detail.
- `frontend/styles.css`: app styling and dark mode.
- `package.json`: Vue, Vite, Tabler Core, Tabler Icons Webfont.

Apache serves real files directly and falls back all other non-API paths to `index.html`.

## Auth

Authentication is session based.

- `auth.php` manages the `FenPingSession` cookie.
- Login compares the submitted password with the configured password using `hash_equals`.
- Session validity is tied to the configured `SECRET` and password.
- Guest users can read inventory/health/notify/scans but cannot mutate state.

Never place secrets in docs, commits, logs, generated files, or the committed generic `config.php`.

## Backup And Restore

`backup.php` backs up:

- A MariaDB dump of the configured app DB.
- nmap XML files and history.
- netboot files.
- JSON indexes and a manifest.

Default backups go to `/var/www/html/backups/fenping-YYYYmmdd-HHMMSS.tgz`, mounted at `data/backups`.

Restore supports FenPing `.tgz` archives and raw `.sql` or `.sql.gz` dumps. After importing SQL, restore reapplies `db.sql` and regenerates dnsmasq files.

## Cron

`boot.sh` writes `/etc/cron.d/fenping`:

- Ping scan every 15 minutes.
- Inventory scan every hour.
- dnsmasq lease import every minute.

Locks use `flock` under `/tmp` to prevent overlapping jobs.

## Health

`GET /api/health` reports:

- HTTP/PHP status.
- DB connectivity.
- dnsmasq running.
- cron running.
- last ping scan time.
- last inventory scan time and metadata.

## Development And Testing

Typical checks:

```bash
bash -n boot.sh restart.sh tests/test.sh
docker build --check .
docker build -t fenping-check .
php -l api.php functions.php database.php cli.php ping.php hosts.php inventory.php scans.php health.php backup.php
php -l routes/auth.php routes/system.php routes/hosts.php routes/netboot.php routes/scans.php
curl -fsS http://127.0.0.1/api/health
curl -fsS http://127.0.0.1/api/inventory
```

Useful commands:

```bash
docker exec fenping php /var/www/html/cli.php hosts
docker exec fenping php /var/www/html/cli.php ping 1
docker exec fenping php /var/www/html/cli.php inventory --quick 1
docker exec fenping php /var/www/html/cli.php backup
docker logs -f fenping
```

Do not run broad scans or destructive restore tests unless the user asks.

## Things To Preserve

- Single-container runtime unless the user explicitly asks for split services again.
- Host networking for DHCP/DNS/scanning behavior.
- Idempotent `db.sql`.
- Direct JSON API responses, not `{ "ok": true }` wrappers.
- Guest read-only behavior.
- dnsmasq generation through PHP CLI.
- Vue static frontend, not PHP-rendered HTML.
- Persistent state under `data/`.
- Reduced Docker capabilities.

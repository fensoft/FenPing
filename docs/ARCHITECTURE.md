# FenPing Architecture

FenPing is a small LAN appliance that combines host inventory, DHCP/DNS management, ping history, nmap scan history, notifications, backup/restore, and netboot image selection in one web UI.

The runtime is managed by Docker Compose. The host-networked Alpine `fenping` container runs nginx/PHP-FPM, dnsmasq, BusyBox cron, and scanner tools. The separate `fenping-db` container uses the official MariaDB 11.8 image and owns the persistent SQL data.

## High-Level Flow

1. `restart.sh` creates persistent directories under `data/`.
2. `restart.sh` validates the Compose model and pulls the configured published FenPing and MariaDB images before stopping the current app.
3. Compose starts the network-disabled `fenping-db`, mounting `data/db`, the shared SQL socket, and the low-write MariaDB configuration. The official entrypoint upgrades a reused MariaDB data directory when needed, then Compose waits for its authenticated health check.
4. Compose starts `fenping` with host networking, reduced capabilities, and the remaining persistent mounts.
5. `boot.sh` waits for MariaDB over loopback and applies `db.sql`.
6. `boot.sh` verifies the local IEEE vendor registry in MariaDB and imports it only when changed, renders dnsmasq config, creates cron jobs, sends the optional restart notification, regenerates host files, validates nginx and PHP-FPM, and starts the services.
7. nginx runs in the foreground, serves the static Vue app from `/var/www/public`, and sends `/api/...` to PHP-FPM through a Unix socket. The public `api.php` entrypoint loads private application code from `/opt/fenping`.

## Docker Build

`Dockerfile` has two stages:

- `frontend`: uses `node:22-alpine` to run `npm ci` and `npm run build`.
- runtime: uses `alpine:3.23` and installs nginx, PHP 8.4 with FPM, the MariaDB client, dnsmasq, nmap with scripts, minimal IP/ping/arping tools, and sudo. BusyBox supplies cron, `timeout`, and the remaining shell utilities.

The application image no longer contains a MariaDB server. Compose uses the smaller purpose-built `mariadb:11.8` image for SQL.

Normal runtime deployment never builds locally. `publish.sh` automatically installs binfmt emulators, then uses a Docker-container Buildx builder to build and push `linux/arm64`, `linux/amd64`, and `linux/arm/v7` manifests with provenance and SBOM attestations. Compose pulls `FENPING_IMAGE:FENPING_VERSION`; the defaults are `fensoft/fenping:1.6`.

`./restart.sh dev` is the explicit local-development exception. It builds the current checkout for the Docker host platform as `FENPING_IMAGE:dev`, pulls only the database image, and starts Compose with image pulling disabled for that run. The override exists only in the script process, so the next normal restart uses the configured published version again.

`config.php` is committed as a generic, environment-driven config file. Runtime values should come from `.env`/container environment variables; do not hardcode machine-specific secrets in `config.php`.

For compatibility with the former embedded database, the default application login remains `root`. `DB_PASS` initializes the root password only when `data/db` is empty; an existing data directory keeps its stored credentials, so changing `.env` alone does not rotate that password.

MariaDB has no container network. The app and database share the named `db-socket` volume mounted at `/run/mysqld`, so SQL stays inaccessible over both the LAN and host loopback.

## Persistent Data

The container filesystem is disposable. Runtime state lives under `data/`.

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/db` | `fenping-db:/var/lib/mysql` | MariaDB data directory |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases |
| `data/dnsmasq.d` | `/etc/dnsmasq.d` | generated dnsmasq config |
| `data/netboot` | `/var/lib/fenping/netboot` | uploaded netboot files |
| `data/backups` | `/var/lib/fenping/backups` | backup archives and imported dumps |
| `data/state` | `/var/lib/fenping/state` | refreshed IEEE vendor registry and optional state files |

The web root contains only the built frontend, static scan stylesheet assets, favicons, and the public API entrypoint. PHP modules, CLI code, templates, schema files, `.env` in development, and all persistent state remain outside the web root. nginx also explicitly denies dotfiles, source/config extensions, and legacy runtime URL paths.

Avoid destructive edits in `data/` unless explicitly requested.

## Write Endurance

`mariadb-fenping.cnf` uses `innodb_flush_log_at_trx_commit=2` and a five-second `innodb_flush_log_at_timeout`. This groups durable redo flushes, with the explicit tradeoff that an operating-system crash or power loss can discard approximately five seconds of recent transactions. InnoDB doublewrite remains enabled for torn-page protection, binary/general/slow logging is disabled, and buffer-pool state is not dumped on shutdown.

Compose mounts size-limited tmpfs filesystems for both services. MariaDB's temporary tablespace, scan output, nginx upload buffering, locks, PHP sessions, and lease-import staging therefore avoid persistent writes. Login sessions are cleared on app-container restart. nginx discards routine access logs and sends warnings/errors to the bounded Docker `local` log. dnsmasq verbose DHCP logging is disabled. Persistent DHCP lease history is retained, while `dnsmasq.leases.php` upserts observed rows instead of rebuilding the table. Generated dnsmasq files use content comparison to avoid identical replacements.

Stable ping records update their activity timestamp at most once per day; actual status, IP, or MAC changes are still written immediately. The boot-time IEEE sync hashes both registries and skips the 57,000-row transaction when SQL is already current. Backups, netboot uploads, DHCP leases, changed scan results, and actual application mutations remain persistent by design.

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
- `routes/hosts.php`: host CRUD, managed-ID and inventory-IP detail/history, category create/rename/delete.
- `routes/ipam.php`: IPAM summary plus authenticated device approve/unapprove actions.
- `routes/scans.php`: scan profiles, queueing, scan status/history, and database-backed XML/JSON responses.
- `routes/netboot.php`: netboot image list/upload/delete.

### CLI

`cli.php` is the operational command entrypoint:

```bash
docker exec fenping php /opt/fenping/cli.php ping [1-254|DEBUG]
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory [--profile lightweight|standard|deep] [1-254|IPv4]
docker exec fenping php /opt/fenping/cli.php inventory --work
docker exec fenping php /opt/fenping/cli.php scan-port-backfill
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup [backup.tgz]
docker exec fenping php /opt/fenping/cli.php restore <backup.tgz|dump.sql.gz>
docker exec fenping php /opt/fenping/cli.php discord-restart
```

Prefer adding operational jobs here instead of creating new shell scripts.

## Database

The configured application database is normally `ping`.

Important tables:

- `ips`: managed hosts, static IPs, MACs, flags, DNS/router options, netboot assignment, and automatic scan schedule.
- `ping`: latest ping status per IP.
- `stats`: status history used for stability and notifications.
- `range`: category separators keyed by starting IP.
- `leases`: current and historical dnsmasq assignments keyed by MAC/IP, with first-seen, last-seen, expiry, and active state.
- `device_approvals`: reversible acknowledgements for dynamic devices, keyed by normalized MAC address.
- `oui_vendors`: locally imported IEEE assignments keyed by 24-, 28-, or 36-bit prefix.
- `scans`: nmap job metadata and references to stored results.
- `scan_snapshots`: deduplicated structured nmap result headers keyed by host, mode, semantic result hash, and exact retained-content hash.
- `scan_snapshot_*`: normalized scan scopes, addresses, hostnames, ports/CPEs, closed-port summaries, OS matches/classes/CPEs, NSE scripts and structured script nodes, and traceroute hops.
- `scan_port_changes`: appeared, disappeared, and service/version-change events linked to the scan that observed them.
- `netboot_images`: uploaded netboot file metadata.
- `users`: legacy table still present in schema.

`db.sql` is run at container boot and after restore. Keep it idempotent with `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, safe cleanup/update statements, and repeatable procedures.

The normalized scan-storage migration intentionally does not import legacy XML. When `scan_snapshots.xml` is detected, existing scan jobs, snapshots, and port-change events are discarded before the structured tables are created; other application tables are untouched.

The `update_status` procedure appends to `stats` immediately when status/IP/MAC changes; otherwise it extends the current row at most once per day.

## Scanning

`ping.php` implements the ping scanner:

- `php cli.php ping` scans the configured `/24`.
- `php cli.php ping 42` scans one host.
- Local IPs are marked `Up` directly using the interface MAC.
- Raw ICMP sockets are used when available.
- `/proc/net/arp` is read directly for MAC discovery.
- `arping` helps distinguish `arp` from `arp-down`.

`inventory.php` performs discovery and queued nmap scans:

- Default mode discovers live hosts with `nmap -n -sn`, excludes FenPing's own IP, and applies the automatic scan schedule.
- Managed hosts store `scan_profile` and `scan_interval_hours`. Automatic discovery queues only hosts whose latest successful scan for that profile is due; `0` disables scheduled scans. Explicit API and CLI scans ignore cadence. Unmanaged hosts retain the default Deep hourly behavior.
- A lock-protected coordinator claims queued jobs and runs no more than four nmap child processes concurrently.
- Only one job runs per IP at a time. Profiles are ordered lightweight, standard, then deep. A stronger request upgrades weaker queued work or waits behind weaker running work, while weaker requests never downgrade an active stronger job.
- Lightweight uses `-F -sS -T4` and has a five-minute timeout.
- Standard uses `-A --top-ports 1000 -sS -T3` and has a 30-minute timeout.
- Deep uses `-A -p- -sS -T3` and has a two-hour timeout.
- Alpine's BusyBox `timeout` sends `TERM` at the profile limit and `KILL` ten seconds later; GNU and BusyBox timeout exit codes are normalized into the same scan state.
- Profile scan API requests return HTTP `202` after enqueueing and start the coordinator in the background.
- Timed-out scans are recorded with the `timeout` state.
- Completed nmap XML is parsed once and discarded. Retained scan facts are stored in normalized `scan_snapshot_*` tables; no raw XML or binary scan column remains.
- Exact retained content is deduplicated with `content_hash`, while `result_hash` controls user-visible inventory change detection so volatile NSE output does not create noisy service-change events.
- Scan profiles are compared independently. Default scan details prefer the latest deep snapshot and fall back to the newest partial result only when no deep result exists.
- Selecting a lightweight or standard snapshot merges it at read time with the preceding deep snapshot. Partial values override matching ports; deep-only ports and OS data remain and each port carries its source profile.
- Port-change detection builds an effective view from the latest deep snapshot plus every newer partial observation in chronological order. It removes only ports included in each scan scope, retains richer version data when partial detection is incomplete, and records services in the first usable result as newly appeared.
- Service-change events are retained for one week, returned by `/api/notify`, rendered alongside host-status changes, and optionally posted to Discord after the scan transaction commits.
- Boot runs the idempotent `scan-port-backfill` command after schema setup. It chronologically replays retained structured snapshots, fills missing events with each scan's original completion time, and makes pre-feature scan changes immediately available to Notify.
- History pruning keeps one week of jobs plus the latest complete and latest changed result for each profile.

`oui.php` resolves vendors locally using longest-prefix matching over the official IEEE MA-L, MA-M, MA-S, and historical IAB listings. The Docker image contains a validated seed at `/usr/share/fenping/ieee-oui.json`; the monthly refresh command downloads the complete registries with short timeouts and atomically writes `/var/lib/fenping/state/ieee-oui.json`. Boot runs `oui-sync` after schema setup, hashes the source and SQL rows, and transactionally replaces `oui_vendors` only when they differ. A successful monthly download also performs this sync. Invalid or failed refreshes leave the previous data untouched. Inventory and host requests never perform vendor-network calls or disclose individual LAN MAC addresses; the JSON registry remains a lookup fallback if SQL is unavailable.

Avoid default inventory scans in tests unless the user accepts LAN scan traffic.

## dnsmasq, DHCP, DNS, And Netboot

`hosts.php` generates:

- `/etc/dnsmasq.d/fenping.dhcp-hosts`
- `/etc/dnsmasq.d/fenping.dhcp-opts`
- `/etc/dnsmasq.d/fenping.hosts`

`boot.sh` renders `/etc/dnsmasq.d/fenping.conf` from `dnsmasq.conf.template`.
The required `IFACE` environment variable selects the host network interface that dnsmasq binds to for DHCP, DNS, and TFTP. Startup fails if it is unset.

`php cli.php hosts` always validates candidate files with `dnsmasq --test`, atomically replaces the generated files, and reloads/starts local dnsmasq. If replacement or reload fails, it restores the previous files.

Host create, edit, and delete requests hold a shared dnsmasq update lock and a MariaDB transaction while generating and testing a candidate configuration. The candidate is applied before the SQL commit; an apply failure rolls back SQL, while a commit failure regenerates dnsmasq from the committed database state. Netboot image deletion uses the same path because it clears per-host boot assignments. Host names, MAC addresses, router octets, DNS server addresses, IP addresses, and netboot filenames are validated before they can be rendered into dnsmasq files.

Netboot uploads live in `/var/lib/fenping/netboot`; metadata lives in `netboot_images`. Browser downloads are streamed through `/api/netboot/images/{id}/file`; nginx denies the entire legacy `/netboot` URL path and never maps the storage directory into the document root, so uploaded PHP-like files cannot execute.

`dnsmasq.leases.php` parses and validates the current dnsmasq lease file into a memory-backed staging table. One InnoDB transaction upserts observed MAC/IP assignments and marks missing assignments inactive. `first_seen` is never overwritten, `last_seen` records the latest observation, expired or replaced assignments remain available as history, and readers never observe an empty or partially imported table.

`ipam.php` combines lease, ping, and status observations by MAC. Devices seen within seven days remain pending until approved or converted into a managed fixed host. Approval only updates `device_approvals`; it never changes DHCP access or reloads dnsmasq. Pool utilization counts the union of active unexpired leases and fixed MAC reservations within the configured dynamic range, so overlapping addresses count once.

## Frontend

The UI is a static Vue 3 app built by Vite and routed by Vue Router using browser history. nginx's SPA fallback serves `index.html` for direct navigation to frontend routes.

Important files:

- `index.html`: Vite HTML entry.
- `frontend/main.js`: app bootstrap and Tabler imports.
- `frontend/router.js`: named routes for inventory, IPAM, services, notifications, scans, host detail, and netboot.
- `frontend/App.vue`: application shell, authentication, cross-page actions, and modal orchestration.
- `frontend/pages/`: independent inventory, IPAM, services, notifications, scans, host detail, and netboot route components.
- `frontend/components/`: accessible application modal and smaller shared view components.
- `frontend/composables/`: abort-controller lifecycle, page refresh registration, reactive time, and modal focus management.
- `frontend/lib/api.js`: the only frontend `fetch` boundary, with consistent JSON/text errors and `AbortSignal` support.
- `frontend/lib/formatters.js`: shared status, date, duration, and size presentation helpers.
- `frontend/styles.css`: app styling and dark mode.
- `package.json`: Vue, Vite, Tabler Core, Tabler Icons Webfont.

Each route component owns and cancels its loader when it is replaced, preventing stale responses from updating another view. Scan and notification pages use a one-second reactive clock for running durations and relative times. Modal dialogs expose dialog semantics, trap Tab focus, close on Escape or backdrop interaction, mark the background inert, and restore focus to the opening control.

The IPAM route shows pool capacity, pending devices, and approved dynamic devices. Approve/unapprove actions are reversible; Reserve opens the existing fixed-host workflow with no address preselected. The Services route reads `/api/services` and lists open ports from each IP's newest usable scan. A newest deep result is used directly; a newest lightweight or standard result is merged with its preceding deep snapshot so deep-only ports and version details remain available with per-port source labels.

nginx serves real public files directly and falls back all other non-API paths to `index.html`.

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
- Deduplicated nmap snapshots through the database dump.
- netboot files.
- A netboot JSON index and a manifest.

Default backups go to `/var/lib/fenping/backups/fenping-YYYYmmdd-HHMMSS.tgz`, mounted at `data/backups`.

Restore supports FenPing `.tgz` archives and raw `.sql` or `.sql.gz` dumps. After importing SQL, restore reapplies `db.sql` and regenerates dnsmasq files.

## Cron

`boot.sh` writes the BusyBox root crontab at `/etc/crontabs/root`:

- Ping scan every 15 minutes.
- Inventory discovery and enqueueing every hour.
- Queue worker every minute; its internal lock prevents duplicate coordinators.
- IEEE vendor registry refresh on the first day of each month at 03:17.
- dnsmasq lease import every minute.

Locks use `flock` under `/tmp` to prevent overlapping jobs.

## Health

`GET /api/health` reports:

- HTTP/PHP status.
- DB connectivity.
- dnsmasq running.
- BusyBox `crond` running.
- last ping scan time.
- last inventory scan time and metadata.

## Development And Testing

Typical checks:

```bash
bash -n boot.sh restart.sh tests/test.sh
docker compose config --quiet
docker build --check .
docker build -t fenping-check .
php -l public/api.php api.php functions.php database.php cli.php ping.php hosts.php inventory.php ipam.php scans.php health.php backup.php
php -l routes/auth.php routes/system.php routes/hosts.php routes/ipam.php routes/netboot.php routes/scans.php
curl -fsS http://127.0.0.1/api/health
curl -fsS http://127.0.0.1/api/inventory
```

Useful commands:

```bash
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php ping 1
docker exec fenping php /opt/fenping/cli.php inventory --profile lightweight 1
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup
docker logs -f fenping
```

Do not run broad scans or destructive restore tests unless the user asks.

## Things To Preserve

- The Compose application/database split unless the user explicitly asks to change it.
- Host networking for DHCP/DNS/scanning behavior.
- Idempotent `db.sql`.
- Direct JSON API responses, not `{ "ok": true }` wrappers.
- Guest read-only behavior.
- dnsmasq generation through PHP CLI.
- Vue static frontend, not PHP-rendered HTML.
- Persistent state under `data/`.
- Reduced Docker capabilities.

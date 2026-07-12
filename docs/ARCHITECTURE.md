# FenPing Architecture

FenPing is a small LAN appliance that combines host inventory, DHCP/DNS management, ping history, nmap scan history, notifications, backup/restore, and netboot image selection in one web UI.

The runtime is managed by Docker Compose. One host-networked Alpine `fenping` container runs nginx/PHP-FPM, SQLite, dnsmasq, BusyBox cron, and scanner tools.

## High-Level Flow

1. `restart.sh` creates persistent directories under `data/`.
2. `restart.sh` validates the Compose model and pulls the configured published FenPing image before stopping the current app.
3. Compose starts `fenping` with host networking, reduced capabilities, and persistent mounts including `data/database`.
4. `boot.sh` creates or upgrades the SQLite schema, verifies integrity, downloads the IEEE vendor registries, and synchronizes changed assignments into SQLite.
5. `boot.sh` renders dnsmasq config, creates cron jobs, sends the optional restart notification, regenerates host files, validates nginx and PHP-FPM, and starts the services.
6. nginx runs in the foreground, serves the static Vue app from `/var/www/public`, and sends `/api/...` to PHP-FPM through a Unix socket. The public `api.php` entrypoint loads private application code from `/opt/fenping`.

## Docker Build

`Dockerfile` has two stages:

- `frontend`: uses `node:22-alpine` to run `npm ci` and `npm run build`.
- runtime: uses `alpine:3.23` and installs nginx, PHP 8.4 with FPM and PDO SQLite, dnsmasq, nmap with scripts, minimal IP/ping/arping tools, and doas. BusyBox supplies cron, `timeout`, and the remaining shell utilities.

The image contains no database server or SQL client process. SQLite is embedded through PDO and persists its database and WAL files on the application bind mount.

Normal runtime deployment never builds locally. `publish.sh` automatically installs binfmt emulators, then uses a Docker-container Buildx builder to build and push `linux/arm64`, `linux/amd64`, and `linux/arm/v7` manifests with provenance and SBOM attestations. Compose pulls `FENPING_IMAGE:FENPING_VERSION`; the defaults are `fensoft/fenping:1.6`.

`./restart.sh dev` is the explicit local-development exception. It builds the current checkout for the Docker host platform as `FENPING_IMAGE:dev` and starts Compose with image pulling disabled for that run. The override exists only in the script process, so the next normal restart uses the configured published version again.

`config.php` is committed as a generic, environment-driven config file. Runtime values should come from `.env`/container environment variables; do not hardcode machine-specific secrets in `config.php`.

`DATABASE_PATH` defaults to `/var/lib/fenping/database/fenping.sqlite3`. It can be overridden for testing, but production should keep it within the mounted database directory.

## Persistent Data

The container filesystem is disposable. Runtime state lives under `data/`.

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/database` | `/var/lib/fenping/database` | SQLite database and WAL files |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases |
| `data/dnsmasq.d` | `/etc/dnsmasq.d` | generated dnsmasq config |
| `data/netboot` | `/var/lib/fenping/netboot` | uploaded netboot files |
| `data/backups` | `/var/lib/fenping/backups` | backup archives and imported dumps |
| `data/state` | `/var/lib/fenping/state` | refreshed IEEE vendor registry and optional state files |

The web root contains only the built frontend, static scan stylesheet assets, favicons, and the public API entrypoint. PHP modules, CLI code, templates, schema files, `.env` in development, and all persistent state remain outside the web root. nginx also explicitly denies dotfiles, source/config extensions, and legacy runtime URL paths.

Avoid destructive edits in `data/` unless explicitly requested.

## Write Endurance

SQLite uses WAL mode and `synchronous=NORMAL`, trading possible loss of the newest committed transactions after an operating-system crash or power loss for fewer sync writes while preserving database consistency. A 30-second busy timeout serializes short competing writers, foreign keys are enforced, and SQLite temporary storage remains in memory.

Compose mounts size-limited tmpfs filesystems for scan output, nginx upload buffering, locks, PHP sessions, and lease-import staging. Login sessions are cleared on app-container restart. nginx discards routine access logs and sends warnings/errors to the bounded Docker `local` log. dnsmasq verbose DHCP logging is disabled. Persistent DHCP lease history is retained, while `dnsmasq.leases.php` upserts observed rows instead of rebuilding the table. Generated dnsmasq files use content comparison to avoid identical replacements.

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
docker exec fenping php /opt/fenping/cli.php database
docker exec fenping php /opt/fenping/cli.php ping [1-254|DEBUG]
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory [--profile lightweight|standard|deep] [1-254|IPv4]
docker exec fenping php /opt/fenping/cli.php inventory --work
docker exec fenping php /opt/fenping/cli.php scan-port-backfill
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup [backup.tgz]
docker exec fenping php /opt/fenping/cli.php restore <backup.tgz>
docker exec fenping php /opt/fenping/cli.php discord-restart
```

Prefer adding operational jobs here instead of creating new shell scripts.

## Database

The application database is the SQLite file at `DATABASE_PATH`. `database.php` configures WAL, `synchronous=NORMAL`, a 30-second busy timeout, foreign keys, memory-backed temporary storage, and the deterministic `ipv4_num()` ordering helper. `db.sql` is the canonical idempotent schema for new databases. Existing databases advance through ordered files in `migrations/`, with `PRAGMA user_version` recording the applied version.

SQLite permits concurrent readers and one writer. Mutation paths use immediate write transactions. Scan queue capacity is counted and claimed atomically, so concurrent coordinators cannot collectively exceed four running jobs. Ping detection completes before one batched transaction writes the latest state and status history.

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

`databaseInitialize()` creates a version-zero database from the current `db.sql`. For an older nonzero database, it requires every sequential `NNNN_description.sql` file through `DATABASE_SCHEMA_VERSION` and applies each file in its own `BEGIN IMMEDIATE` transaction. The framework advances `PRAGMA user_version` only after the SQL succeeds, so a failed migration rolls back both schema/data changes and the version. Migration files must not manage transactions or set `user_version` themselves.

Every schema change must update the canonical `db.sql`, increment both `DATABASE_SCHEMA_VERSION` and the final `PRAGMA user_version`, and add the next immutable migration file. Released migrations must never be edited or skipped.

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
- Managed hosts store `scan_profile` and `scan_interval_hours`. New managed hosts default to Standard every 24 hours, while existing settings remain unchanged. Automatic discovery queues only hosts whose latest successful scan for that profile is due; `0` disables scheduled scans. Explicit API and CLI scans ignore cadence. Unmanaged hosts use Lightweight every 24 hours, with their first scan distributed across deterministic UTC hour slots to avoid an initial queue spike.
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

`oui.php` resolves vendors locally using longest-prefix matching over the official IEEE MA-L, MA-M, MA-S, and historical IAB listings. The Docker image contains no vendor database. Boot and the monthly refresh command download the complete registries with short timeouts and atomically write `/var/lib/fenping/state/ieee-oui.json`, then hash the source and SQL rows and transactionally replace `oui_vendors` only when they differ. Invalid or failed refreshes leave previous cache and SQL data untouched; a first startup without IEEE connectivity continues without vendor names. Inventory and host requests never perform vendor-network calls or disclose individual LAN MAC addresses; the runtime JSON registry remains a lookup fallback if SQL is unavailable.

Avoid default inventory scans in tests unless the user accepts LAN scan traffic.

## dnsmasq, DHCP, DNS, And Netboot

`hosts.php` generates:

- `/etc/dnsmasq.d/fenping.dhcp-hosts`
- `/etc/dnsmasq.d/fenping.dhcp-opts`
- `/etc/dnsmasq.d/fenping.hosts`

`boot.sh` renders `/etc/dnsmasq.d/fenping.conf` from `dnsmasq.conf.template`.
The required `IFACE` environment variable selects the host network interface that dnsmasq binds to for DHCP, DNS, and TFTP. Startup fails if it is unset.

`php cli.php hosts` always validates candidate files with `dnsmasq --test`, atomically replaces the generated files, and reloads/starts local dnsmasq. If replacement or reload fails, it restores the previous files.

Host create, edit, and delete requests hold a shared dnsmasq update lock and an immediate SQLite transaction while generating and testing a candidate configuration. The candidate is applied before the SQL commit; an apply failure rolls back SQL, while a commit failure regenerates dnsmasq from the committed database state. Netboot image deletion uses the same path because it clears per-host boot assignments. Host names, MAC addresses, router octets, DNS server addresses, IP addresses, and netboot filenames are validated before they can be rendered into dnsmasq files.

Netboot uploads live in `/var/lib/fenping/netboot`; metadata lives in `netboot_images`. Browser downloads are streamed through `/api/netboot/images/{id}/file`; nginx denies the entire legacy `/netboot` URL path and never maps the storage directory into the document root, so uploaded PHP-like files cannot execute.

`dnsmasq.leases.php` parses and validates the current dnsmasq lease file into a temporary staging table. One immediate SQLite transaction upserts observed MAC/IP assignments and marks missing assignments inactive. `first_seen` is never overwritten, `last_seen` records the latest observation, expired or replaced assignments remain available as history, and readers never observe an empty or partially imported table.

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

Inventory filters use persisted three-way status, importance, and new-device choices. Stored checkbox-era preferences are normalized into the current string-valued filter document on load, while search and all filter dimensions combine with AND semantics.

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

`backup.php` creates a version 1.6 archive containing:

- `db.json`, with the configured app DB represented as named tables, column lists, and rows.
- Deduplicated nmap snapshots through `db.json`.
- netboot files.
- A netboot JSON index and a manifest.

Default backups go to `/var/lib/fenping/backups/fenping-YYYYmmdd-HHMMSS.tgz`, mounted at `data/backups`.

Restore supports version 1.6 (and compatible later 1.x) FenPing `.tgz` archives. It validates `manifest.json` and `db.json`, initializes or migrates the database to the current schema, imports the JSON data transactionally, restores netboot files, and regenerates dnsmasq files. Pre-1.6 SQL-based archives and raw `.sql`/`.sql.gz` dumps are intentionally unsupported.

The 1.x JSON contract is forward-compatible with future FenPing backups: later 1.x writers may add top-level metadata, tables, and columns, while preserving the existing `tables.<name>.columns` plus parallel `rows` structure. Readers ignore unknown metadata, tables, and columns, so a 1.6 reader can restore the subset it understands from a later 1.x backup. Removing or changing existing fields requires a new major backup version. Future application releases must continue accepting version 1.6 backups and fill newly introduced columns from schema defaults. The optional `restore.timestamp_shift` extension is used only by the synthetic demo to keep its dated activity relative to restore time.

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
- SQLite connectivity and engine identity.
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
npm test
npm run build
php -l public/api.php api.php functions.php database.php cli.php ping.php hosts.php inventory.php ipam.php scans.php health.php backup.php tests/backup_format.php tests/database_migrations.php
php -l routes/auth.php routes/system.php routes/hosts.php routes/ipam.php routes/netboot.php routes/scans.php
php tests/database_migrations.php
DATABASE_PATH=/tmp/fenping-sqlite-test.sqlite3 php tests/sqlite.php
DATABASE_PATH=/tmp/fenping-scan-test.sqlite3 php tests/scan_storage.php
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

- The single host-networked application container with embedded SQLite.
- Host networking for DHCP/DNS/scanning behavior.
- Idempotent `db.sql`.
- Direct JSON API responses, not `{ "ok": true }` wrappers.
- Guest read-only behavior.
- dnsmasq generation through PHP CLI.
- Vue static frontend, not PHP-rendered HTML.
- Persistent state under `data/`.
- Reduced Docker capabilities.

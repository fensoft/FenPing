# FenPing Architecture

FenPing is a small LAN appliance that combines host inventory, DHCP/DNS management, ping history, nmap scan history, notifications, backup/restore, and netboot image selection in one web UI.

The runtime is managed by Docker Compose. One host-networked Alpine `fenping` container runs nginx/PHP-FPM, SQLite, dnsmasq, BusyBox cron, and scanner tools.

## High-Level Flow

1. `restart.sh` creates persistent directories under `data/`.
2. restart.sh records the running image digest, pulls or builds and pins the target image, then creates and restore-tests a pre-upgrade archive before stopping the current app. Upgrade outcomes are journaled for rollback.
3. Compose starts `fenping` with host networking, reduced capabilities, and persistent mounts including `data/database`.
4. `boot.sh` runs the blocking startup doctor before opening SQLite or starting any daemon.
5. `boot.sh` creates or upgrades the SQLite schema, verifies integrity, downloads the IEEE vendor registries, and synchronizes changed assignments into SQLite.
6. `boot.sh` renders dnsmasq config, creates cron jobs, sends the optional restart notification, regenerates host files, validates nginx and PHP-FPM, and starts the services.
7. nginx runs in the foreground, serves the static Vue app from `/var/www/public`, sends JSON `/api/...` requests to PHP-FPM through a Unix socket, and serves the Nchan-backed `/api/events` SSE stream directly. The public `api.php` entrypoint loads private application code from `/opt/fenping`.

## Docker Build

`Dockerfile` has two stages:

- `frontend`: uses `node:22-alpine` to run `npm ci` and `npm run build`.
- runtime: uses `alpine:3.23` and installs nginx with its Nchan dynamic module, PHP 8.4 with FPM and PDO SQLite, dnsmasq, nmap with default scripts plus `broadcast-dhcp-discover`, minimal IP/ping/arping tools, and doas. BusyBox supplies cron, `timeout`, and the remaining shell utilities.

The image contains no database server or SQL client process. SQLite is embedded through PDO and persists its database and WAL files on the application bind mount.

Normal runtime deployment never builds locally. `publish.sh` automatically installs binfmt emulators, then uses a Docker-container Buildx builder to build and push `linux/arm64`, `linux/amd64`, and `linux/arm/v7` manifests with provenance and SBOM attestations. Compose pulls `FENPING_IMAGE:FENPING_VERSION`; the defaults are `fensoft/fenping:1.7`.

`./restart.sh dev` is the explicit local-development exception. It builds the current checkout for the Docker host platform as `FENPING_IMAGE:dev` and starts Compose with image pulling disabled for that run. The override exists only in the script process, so the next normal restart uses the configured published version again.

`FenPing\Config\AppConfig` loads the committed environment contract at runtime. Values should come from `.env`/container environment variables; do not hardcode machine-specific secrets.

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

Startup does not change the owner or mode of the database directory or SQLite files. Instead, the `www-data` worker adopts the bind mount's numeric owner and group before database initialization.

The web root contains only the built frontend, static scan stylesheet assets, favicons, and the public API entrypoint. PHP modules, CLI code, templates, schema files, `.env` in development, and all persistent state remain outside the web root. nginx also explicitly denies dotfiles, source/config extensions, and legacy runtime URL paths.

Avoid destructive edits in `data/` unless explicitly requested.

## Write Endurance

SQLite uses WAL mode and `synchronous=NORMAL`, trading possible loss of the newest committed transactions after an operating-system crash or power loss for fewer sync writes while preserving database consistency. A 30-second busy timeout serializes short competing writers, foreign keys are enforced, and SQLite temporary storage remains in memory.

Compose mounts size-limited tmpfs filesystems for scan output, nginx upload buffering, locks, PHP sessions, and lease-import staging. Login sessions are cleared on app-container restart. nginx discards routine access logs and sends warnings/errors to the bounded Docker `local` log. dnsmasq verbose DHCP logging is disabled. Persistent DHCP lease history is retained, while `dnsmasq.leases.php` upserts observed rows instead of rebuilding the table. Generated dnsmasq files use content comparison to avoid identical replacements.

Stable ping records update their activity timestamp at most once per day; actual status, IP, or MAC changes are still written immediately. The boot-time IEEE sync hashes both registries and skips the 57,000-row transaction when SQL is already current. Backups, netboot uploads, DHCP leases, changed scan results, and actual application mutations remain persistent by design.

## Backend Entry Points

### API

`api.php` is a thin stable entrypoint that loads the Composer autoloader and delegates to `FenPing\Application`. The typed API kernel normalizes and matches `/api/...` routes, converts typed parameters, enforces explicit auth policy, and returns direct success documents or `{ "error": "message" }` errors.

Class-owned implementation modules live under `src/Backend/` and are composed by the injected `FenPing\Backend\Backend` object. The former root modules, route directory, procedural compatibility functions, and `src/Legacy/` tree are absent; an architecture test enforces one primary type per production file, no free functions, and a 400-line ceiling.

The typed API kernel, router, request, authorization policies, and JSON/XML/file responses live under `src/Api/`. Domain-facing behavior is exposed through injected services and repositories under `src/`.

### Live Updates

Nchan runs inside the existing nginx process and exposes guest-readable `GET /api/events` as an EventSource stream. Backend producers post only to a loopback-restricted publisher location. Messages are versioned invalidation hints containing scope names and a UTC timestamp, never LAN records, credentials, or authorization state. The in-memory channel retains at most 32 messages for five minutes and does not use Redis.

`FenPing\Realtime\LiveUpdatePublisher` is injected only by the application composition root. API routes publish after successful 2xx responses, while CLI, cron, scan-worker, ping, lease, conflict, backup, operation, OUI, and Docker-network paths publish after their relevant commit or successful state change. The Nchan transport uses a short local timeout and never throws, so nginx startup order or live-update downtime cannot alter a domain operation's result.

Each browser tab owns one EventSource through `frontend/lib/liveUpdates.js`. Mounted views subscribe to relevant scopes and refetch their existing JSON APIs after events received within 250 milliseconds are merged. Reconnects and returning a hidden tab to the foreground trigger an `all` reconciliation. Existing mutation refreshes and safety polling remain; active user-started scans wait for scan events with a 15-second fallback instead of polling every second.

### CLI

`cli.php` is the operational command entrypoint:

```bash
docker exec fenping php /opt/fenping/cli.php database
docker exec fenping php /opt/fenping/cli.php ping [--network IPv4/24] [1-254|DEBUG]
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory [--network IPv4/24] [--profile lightweight|standard|deep] [1-254|IPv4]
docker exec fenping php /opt/fenping/cli.php inventory --work
docker exec fenping php /opt/fenping/cli.php scan-port-backfill
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup [backup.tgz]
docker exec fenping php /opt/fenping/cli.php backup-verify <backup.tgz>
docker exec fenping php /opt/fenping/cli.php backup-maintenance <daily|verify>
docker exec fenping php /opt/fenping/cli.php restore <backup.tgz>
docker exec fenping php /opt/fenping/cli.php notify-restart
docker exec fenping php /opt/fenping/cli.php discord-restart
```

Prefer adding operational jobs here instead of creating new shell scripts.

## Database

The application database is the SQLite file at `DATABASE_PATH`. `FenPing\Database\DatabaseManager` configures WAL, `synchronous=NORMAL`, a 30-second busy timeout, foreign keys, memory-backed temporary storage, and the deterministic `ipv4_num()` ordering helper. `db.sql` is the canonical idempotent schema for new databases. Existing databases advance through ordered files in `migrations/`, with `PRAGMA user_version` recording the applied version.

SQLite permits concurrent readers and one writer. Mutation paths use immediate write transactions. Scan queue capacity, per-network running counts, and rolling scheduled-start budgets are evaluated and claimed in one immediate transaction, so concurrent coordinators cannot exceed configured limits. Budget-deferred work on one network does not block eligible manual work or another network. Ping detection completes before one batched transaction writes the latest state and status history.

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

`FenPing\Ping\PingScanner` implements the ping scanner:

- `php cli.php ping` scans the configured `/24`.
- `php cli.php ping 42` scans one host.
- Local IPs are marked `Up` directly using the interface MAC.
- Raw ICMP sockets are used when available.
- `/proc/net/arp` is read directly for MAC discovery.
- `arping` helps distinguish `arp` from `arp-down`.

The inventory and scan services under `src/Inventory/` and `src/Scan/` perform discovery and queued nmap scans:

- Default mode selects one configured, routed `/24` using the persistent inventory round-robin cursor, discovers live hosts with `nmap -n -sn`, excludes FenPing's own IP, and applies the automatic scan schedule. Ping uses an independent cursor. FenPing only reads the kernel route table and never adds a route.
- Managed hosts store `scan_profile` and `scan_interval_hours`. New managed hosts default to Standard every 24 hours, while existing settings remain unchanged. Automatic discovery queues only hosts whose latest successful scan for that profile is due; `0` disables scheduled scans. Explicit API and CLI scans ignore cadence. Unmanaged hosts use Lightweight every 24 hours, with their first scan distributed across deterministic UTC hour slots to avoid an initial queue spike.
- A lock-protected coordinator claims queued jobs up to `SCAN_GLOBAL_CONCURRENCY` (default four), `SCAN_NETWORK_CONCURRENCY` (default two per `/24`), and optional per-CIDR overrides.
- Automatic jobs are marked `scheduled` and limited by `SCAN_NETWORK_DAILY_BUDGET` scheduled starts per rolling 24 hours. Manual API and explicit CLI jobs bypass and do not consume this budget; requesting a queued scheduled job manually promotes it.
- Only one job runs per IP at a time. Profiles are ordered lightweight, standard, then deep. A stronger request upgrades weaker queued work or waits behind weaker running work, while weaker requests never downgrade an active stronger job.
- Queue metadata exposes a one-based eligible position, `ready`, `global_concurrency`, `network_concurrency`, or `daily_budget` reason, and the exact next budget-eligible timestamp.
- Lightweight uses `-F -sS -T4` and has a five-minute timeout.
- Standard uses `-A --top-ports 1000 -sS -T3` and has a 30-minute timeout.
- Deep uses `-A -p- -sS -T3` and has a two-hour timeout.
- The scan supervisor runs nmap directly with `--stats-every 5s`, parses timing output into monotonic persisted phase/percentage updates, polls cancellation at least once per second, and enforces profile timeouts in PHP. Cancellation and timeout send `TERM` and escalate to `KILL` after ten seconds.
- Profile scan API requests return HTTP `202` after enqueueing and start the coordinator in the background.
- Authenticated queued cancellation completes immediately; running cancellation returns HTTP `202` until the worker records `cancelled`. Terminal writes are guarded so cancellation wins completion, failure, and timeout races.
- Timed-out scans retain their last progress percentage and are recorded with the `timeout` phase and state.
- Completed nmap XML is parsed once and discarded. Retained scan facts are stored in normalized `scan_snapshot_*` tables; no raw XML or binary scan column remains.
- Exact retained content is deduplicated with `content_hash`, while `result_hash` controls user-visible inventory change detection so volatile NSE output does not create noisy service-change events.
- Scan profiles are compared independently. Default scan details prefer the latest deep snapshot and fall back to the newest partial result only when no deep result exists.
- Selecting a lightweight or standard snapshot merges it at read time with the preceding deep snapshot. Partial values override matching ports; deep-only ports and OS data remain and each port carries its source profile.
- Port-change detection builds an effective view from the latest deep snapshot plus every newer partial observation in chronological order. It removes only ports included in each scan scope, retains richer version data when partial detection is incomplete, and records services in the first usable result as newly appeared.
- Service-change events are retained for one week, returned by `/api/notify`, and rendered alongside host-status changes.
- A singleton `notification_delivery_settings` row supplies the shared restart, conflict, normal/important host-status, and normal/important service-change rules plus the selected Telegram destination. Authenticated chat discovery ingests `getUpdates` into the local-only `telegram_known_chats` table, retaining chat and sender display details while excluding all Telegram IDs and discovery state from backups. A bot-token fingerprint clears stale destinations when the configured bot changes. Discord and Telegram independently deliver the same permitted events; every Discord delivery receives the environment-derived mention with explicit `allowed_mentions` when one is configured.
- Boot runs the idempotent `scan-port-backfill` command after schema setup. It chronologically replays retained structured snapshots, fills missing events with each scan's original completion time, and makes pre-feature scan changes immediately available to Notify.
- History pruning keeps one week of jobs plus the latest complete and latest changed result for each profile.

`FenPing\Oui\OuiRegistryService` and `FenPing\Vendor\VendorLookup` resolve vendors locally using longest-prefix matching over the official IEEE MA-L, MA-M, MA-S, and historical IAB listings. The Docker image contains no vendor database. Boot and the monthly refresh command download the complete registries with short timeouts and atomically write `/var/lib/fenping/state/ieee-oui.json`, then hash the source and SQL rows and transactionally replace `oui_vendors` only when they differ. Invalid or failed refreshes leave previous cache and SQL data untouched; a first startup without IEEE connectivity continues without vendor names. Inventory and host requests never perform vendor-network calls or disclose individual LAN MAC addresses; the runtime JSON registry remains a lookup fallback if SQL is unavailable.

Avoid default inventory scans in tests unless the user accepts LAN scan traffic.

## dnsmasq, DHCP, DNS, And Netboot

The DHCP services under `src/Dhcp/` generate:

- `/etc/dnsmasq.d/fenping.dhcp-hosts`
- `/etc/dnsmasq.d/fenping.dhcp-opts`
- `/etc/dnsmasq.d/fenping.hosts`

`boot.sh` renders `/etc/dnsmasq.d/fenping.conf` from `dnsmasq.conf.template`.
The required `IFACE` environment variable selects the host network interface that dnsmasq binds to for DHCP, DNS, and TFTP. Startup fails if it is unset.

`FenPing\Doctor\DoctorService` runs on every startup before database initialization. It aggregates interface/address/routing, configured-router ARP reachability, pool endpoint overlap, TCP/UDP bind availability, persistent-write/SQLite-WAL, and active DHCP-discovery results. When `DHCP_DEFAULT_ROUTER` is absent, router reachability is reported as not configured and the rendered dnsmasq configuration explicitly suppresses DHCP option 3. DNS TCP/UDP 53, interface-bound DHCP UDP 67, TFTP UDP 69, and wildcard HTTP TCP 80 must all be available. Nmap's retained `broadcast-dhcp-discover` script is restricted to `IFACE` and uses its fixed client MAC with a five-second response timeout. Any offer or inability to complete the safety probe blocks all services; `restart.sh` stops the resulting restart loop and retains the failed replacement for logs or rollback.

The authenticated `GET /api/doctor` route invokes the exact `doctor --runtime --json` CLI command as root through the constrained `doas` policy. Runtime mode uses `ss` to inspect DNS, DHCP, TFTP, and HTTP listeners. It accepts direct process metadata when available and, under the reduced capability set where dnsmasq file-descriptor ownership is intentionally hidden, requires the expected dnsmasq or nginx process to be live. DHCP discovery removes only a response whose server identifier is FenPing's configured appliance address. The admin-only Vue Operations page displays the exception-first health dashboard plus the complete Doctor report and remediation text; PHP-FPM never receives a general-purpose privileged command capability.

`DHCP_NETWORK` is a required canonical `/24`. `EXTRA_NETWORKS` optionally lists comma-separated scan-only `/24` networks. FenPing reports whether a connected or static non-default route covers each extra network, but the status is informational and does not disable scanning. Default and partial routes do not count as explicit routes. dnsmasq generation and all DHCP, host, category, and netboot mutations remain restricted to `DHCP_NETWORK`.

When `DOCKER_SOCKET` points to the optional read-only Compose bind, a root-only Docker client reads `/networks` and writes an atomic, PHP-readable runtime cache under `/run/fenping`. `AppConfig` merges that cache on each process construction. A self-reconnecting `/events` listener debounces network create/connect/disconnect/destroy/update/remove bursts and performs full refreshes; boot, hourly cron, and the parameterless guest `POST /api/networks/refresh` route reconcile missed events. The API can invoke only the exact `docker-networks-refresh --api` command through `doas`, and repeated guest calls are coalesced. The socket's read-only mount flag does not restrict Docker API privileges.

`INVENTORY_DOWN_RETENTION_DAYS` defaults to 7. Inventory omits unreserved hosts whose current Down status began more than that many days ago. Reserved hosts remain visible, and filtering never deletes status or scan history.

`php cli.php hosts` always validates candidate files with `dnsmasq --test`, atomically replaces the generated files, and reloads/starts local dnsmasq. If replacement or reload fails, it restores the previous files.

Host create, edit, and delete requests hold a shared dnsmasq update lock and an immediate SQLite transaction while generating and testing a candidate configuration. The candidate is applied before the SQL commit; an apply failure rolls back SQL, while a commit failure regenerates dnsmasq from the committed database state. Netboot image deletion uses the same path because it clears per-host boot assignments. Host names, MAC addresses, router octets, DNS server addresses, IP addresses, and netboot filenames are validated before they can be rendered into dnsmasq files.

Netboot uploads live in `/var/lib/fenping/netboot`; metadata lives in `netboot_images`. Browser downloads are streamed through `/api/netboot/images/{id}/file`; nginx denies the entire legacy `/netboot` URL path and never maps the storage directory into the document root, so uploaded PHP-like files cannot execute.

`dnsmasq.leases.php` parses and validates the current dnsmasq lease file into a temporary staging table. One immediate SQLite transaction upserts observed MAC/IP assignments and marks missing assignments inactive. `first_seen` is never overwritten, `last_seen` records the latest observation, expired or replaced assignments remain available as history, and readers never observe an empty or partially imported table.

`FenPing\Ipam\IpamService` combines lease, ping, and status observations by MAC. Devices seen within seven days remain pending until approved or converted into a managed fixed host. Approval only updates `device_approvals`; it never changes DHCP access or reloads dnsmasq. Pool utilization counts the union of active unexpired leases and fixed MAC reservations within the configured dynamic range, so overlapping addresses count once.

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

Each route component owns and cancels its loader when it is replaced, preventing stale responses from updating another view. Scan and notification pages use a one-second reactive clock only for running durations and relative times; data invalidation uses the application-level EventSource. Modal dialogs expose dialog semantics, trap Tab focus, close on Escape or backdrop interaction, mark the background inert, and restore focus to the opening control.

Inventory filters use persisted three-way status, importance, and new-device choices. Stored checkbox-era preferences are normalized into the current string-valued filter document on load, while search and all filter dimensions combine with AND semantics.

The IPAM route lists every configured subnet and combines their pending devices, approved dynamic devices, and active conflicts. Each device row identifies its subnet. Pool capacity and fixed reservations remain specific to `DHCP_NETWORK`; Reserve is therefore offered only for devices observed there. Approve/unapprove actions are reversible across configured subnets. The Services route reads `/api/services` and lists open ports from each IP's newest usable scan. A newest deep result is used directly; a newest lightweight or standard result is merged with its preceding deep snapshot so deep-only ports and version details remain available with per-port source labels.

nginx serves real public files directly and falls back all other non-API paths to `index.html`.

## Auth

Authentication is session based.

- `auth.php` manages the `FenPingSession` cookie.
- Login compares the submitted password with the configured password using `hash_equals`.
- Session validity is tied to the configured `SECRET` and password.
- Guest users can read inventory/health/notify/scans but cannot mutate state.

Never place secrets in docs, commits, logs, generated files, or the environment-driven `AppConfig`.

## Backup And Restore

`FenPing\Backup\BackupService` creates a version 1.6 archive containing:

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
- Verified managed backup every day at 02:23 UTC.
- Round-robin restore test every Sunday at 04:41 UTC.
- SQLite integrity check every day at 01:43 UTC.

Locks use `flock` under `/tmp` to prevent overlapping jobs.

## Health

`GET /api/health` is the operator-health document. It reports exception counts for new devices and important hosts down; queued, running, recently failed, and timed-out scans; oldest queue age; freshness for ping, discovery, lease import, OUI refresh, and backups; SQLite, WAL, disk, and integrity state; DHCP-pool utilization; dnsmasq-generation failures; and notification-delivery failures. Thresholds are environment-driven.

Operation outcomes are stored in `operation_status`; recent failure events are retained in `operation_failures` for the configurable reporting window. Recording is best-effort and cannot change the outcome of the monitored operation.

Health has two probe contracts:

- `GET /api/health/live` reports whether PHP can answer and does not depend on downstream services.
- `GET /api/health/ready` requires SQLite, dnsmasq, BusyBox `crond`, and a non-failed integrity status. It returns HTTP `503` when not ready.

Docker Compose uses readiness for container health. The full health document may be `warning` for stale jobs or recent operational failures without making the container unavailable.

## Development And Testing

Typical checks:

```bash
bash -n boot.sh restart.sh tests/test.sh
docker compose config --quiet
docker build --check .
docker build -t fenping-check .
npm test
npm run test:browser
npm run build
composer validate --strict
composer dump-autoload --optimize --strict-psr
composer test
find src tests/Php -name '*.php' -type f -print0 | xargs -0 -n1 php -l
docker build --target backend-test -t fenping-backend-test .
docker build --target frontend-test -t fenping-frontend-test .
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

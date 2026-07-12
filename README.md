# FenPing

FenPing is a compact LAN appliance for device discovery, uptime history, DHCP/DNS host management, nmap scan history, notifications, backups, and netboot image assignment.

It uses a static Vue/Vite frontend with Vue Router, an nginx/PHP-FPM API and CLI backend, SQLite, dnsmasq, BusyBox cron, nmap, ping, ARP, and arping. Docker Compose runs the complete appliance in one container.

## Features

- Live inventory table for known and newly discovered devices.
- Status tracking with `Up`, `Down`, `arp`, and `arp-down` states.
- Stability, host history, and a 24-hour notify view.
- Static DHCP/DNS host management through dnsmasq.
- Transactional DHCP lease history with stable first-seen and last-seen timestamps.
- Device onboarding with reversible MAC approval and DHCP pool utilization tracking.
- Transactional DHCP updates: host changes are validated and syntax-checked before the database and dnsmasq configuration are committed together.
- Category/range separators with collapsible groups and rename support.
- Lightweight, standard, and deep nmap profiles with deduplicated database history; partial results never replace the latest deep snapshot.
- Service-change notifications when an open port appears, disappears, or reports a different service/version.
- Searchable service inventory showing every open service per computer from the latest deep or merged partial scan.
- Local MAC vendor resolution from the IEEE MA-L, MA-M, MA-S, and IAB registries, without sending device addresses to a third party.
- Netboot image upload, delete, and per-host boot image selection.
- Guest read-only mode and admin login.
- Abortable route loading, live running durations, and keyboard-accessible modal dialogs.
- Dark mode.
- `/api/health` appliance status endpoint.
- Optional Discord webhook notifications.
- Backup and restore CLI for upgrades.

## Screenshots

### Inventory

![Inventory](img/screenshot-inventory.png)

### Services

![Services](img/screenshot-services.png)

### Notify

![Notify](img/screenshot-notify.png)

### IPAM

![IPAM](img/screenshot-ipam.png)

### Scans

![Scans](img/screenshot-scans.png)

### Netboot Images

![Netboot Images](img/screenshot-netboot.png)

### Host Detail

![Host Detail](img/screenshot-host.png)

### Scan Details

![Scan Details](img/screenshot-scan.png)

## Runtime Layout

FenPing runs through Docker Compose with one container:

- `fenping` uses host networking and runs nginx/PHP-FPM, dnsmasq, BusyBox cron, nmap, ping/ARP tools, and the application CLI on Alpine Linux.

Application data is stored in the local SQLite database at `data/database/fenping.sqlite3`. `boot.sh` applies the idempotent schema and verifies database integrity before nginx and PHP-FPM start.

## Install

1. Copy the environment template:

   ```bash
   cp env.template .env
   ```

2. Edit `.env` for your LAN.

3. Pull and start the published image:

   ```bash
   ./restart.sh
   ```

4. Open FenPing:

   ```text
   http://<FENPING_IP>/
   ```

## Configuration

Important `.env` values:

| Variable | Description |
| --- | --- |
| `IP` | FenPing LAN address. |
| `IFACE` | Required host network interface that dnsmasq binds to for DHCP, DNS, and TFTP, for example `eth0`. |
| `FENPING_IMAGE` | Docker Hub repository pulled by `restart.sh`. Defaults to `fensoft/fenping`. |
| `FENPING_VERSION` | Published image tag pulled by `restart.sh`. Defaults to `1.6`. |
| `DATABASE_PATH` | SQLite file inside the container. Defaults to `/var/lib/fenping/database/fenping.sqlite3`. |
| `NETWORK` | `/24` prefix, for example `10.10.10`. |
| `DHCP_DEFAULT_ROUTER` | Router handed out by DHCP. |
| `DHCP_DYNAMIC_BEGIN` | First dynamic DHCP address, last octet only. |
| `DHCP_DYNAMIC_END` | Last dynamic DHCP address, last octet only. |
| `PASSWORD` | Admin login password. Empty means a blank login password. |
| `SECRET` | Session signing secret. |
| `DISCORD_WEBHOOK_URL` | Optional Discord webhook for host status, service changes, and restart notifications. |

Managed hosts require a valid IPv4 address and six-octet MAC address. Host names are optional; when set, they must contain one DNS label using letters, numbers, and internal hyphens. Per-host DNS overrides accept one or more IPv4 addresses separated by spaces, commas, or semicolons.

## Publishing Images

Log in to Docker Hub, then publish the versioned multi-architecture image:

```bash
docker login
./publish.sh 1.6
```

The targets are exactly `linux/arm64`, `linux/amd64`, and `linux/arm/v7`. The script automatically runs `tonistiigi/binfmt --install all`, so publishing requires permission to start a privileged Docker container. Set `PUBLISH_LATEST=0` to omit the `latest` tag, or set `FENPING_IMAGE` to publish another Docker Hub repository. The script uses a reusable `fenping-multiarch` Buildx container builder, pushes the version and `latest` manifests, attaches provenance and an SBOM, and inspects the published result.

By default, `restart.sh` never builds the application image. It pulls `FENPING_IMAGE:FENPING_VERSION` before stopping the current app, so a missing or inaccessible tag leaves the running deployment untouched.

To build the current checkout for the Docker host's platform, tag it as `FENPING_IMAGE:dev`, and restart with that local image:

```bash
./restart.sh dev
```

Development mode builds before stopping the running app and prevents Compose from pulling over the local `dev` tag. A later normal `./restart.sh` returns to the version configured by `FENPING_VERSION` in `.env`.

## Persistent Data

Do not delete `data/` casually. It is the appliance state.

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/database` | `/var/lib/fenping/database` | SQLite database and WAL files. |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases. |
| `data/dnsmasq.d` | `/etc/dnsmasq.d` | Generated dnsmasq config files. |
| `data/netboot` | `/var/lib/fenping/netboot` | Uploaded netboot files. |
| `data/backups` | `/var/lib/fenping/backups` | Backup archives and imported dumps. |
| `data/state` | `/var/lib/fenping/state` | Refreshed IEEE vendor registry and optional state/health files. |

nginx serves only `/var/www/public`, which contains the built frontend and the small API entrypoint. PHP application code lives in `/opt/fenping`; runtime files under `/var/lib/fenping` are not directly web-accessible. nginx explicitly rejects dotfiles, private extensions, and legacy runtime paths, while netboot files can only be downloaded through the validated API route.

### SSD write endurance

SQLite uses WAL mode with `synchronous=NORMAL`, a 30-second busy timeout, foreign-key enforcement, and memory-backed temporary tables. This keeps the database consistent while reducing per-transaction sync writes; an operating-system crash or power loss may lose the most recent committed transactions. DHCP leases, scans, and application data remain persistent.

Routine writes are also limited with memory-backed `/tmp` and `/run` for scan temporaries, nginx upload buffering, locks, PHP sessions, and lease-import staging; nginx access logging and verbose DHCP logging are disabled; Docker logs are compressed and rotated; unchanged dnsmasq files are not replaced; lease imports upsert observed rows instead of rebuilding the table; stable ping-history rows are extended at most once per day; and an unchanged IEEE registry is not rewritten into SQLite at boot. Login sessions are intentionally cleared when the app container restarts because their files live in `/run`.

## CLI

Run operational commands from the container:

```bash
docker exec fenping php /opt/fenping/cli.php ping
docker exec fenping php /opt/fenping/cli.php database
docker exec fenping php /opt/fenping/cli.php ping 10
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory
docker exec fenping php /opt/fenping/cli.php inventory --profile lightweight 10.10.10.10
docker exec fenping php /opt/fenping/cli.php inventory --profile standard 10.10.10.10
docker exec fenping php /opt/fenping/cli.php inventory --profile deep 10.10.10.10
docker exec fenping php /opt/fenping/cli.php inventory --work
docker exec fenping php /opt/fenping/cli.php scan-port-backfill
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup
docker exec fenping php /opt/fenping/cli.php restore /var/lib/fenping/backups/fenping-YYYYmmdd-HHMMSS.tgz
docker exec fenping php /opt/fenping/cli.php discord-restart
```

Cron inside the container runs:

- `ping` every 15 minutes.
- inventory discovery every hour; discovered hosts are queued only when their scan cadence is due.
- the inventory worker runs queued scans with a maximum concurrency of four.
- the local IEEE OUI registry is refreshed monthly on the first day at 03:17.
- dnsmasq lease import every minute.

The image does not embed a vendor registry. At startup, and again through a monthly background job, FenPing downloads and validates the complete public MA-L, MA-M, MA-S, and historical IAB CSV files from the [IEEE Registration Authority public listings](https://standards.ieee.org/products-programs/regauth/). A successful refresh atomically replaces `data/state/ieee-oui.json` and transactionally updates the SQL table only when assignments changed. Inventory requests query this local prefix index; individual LAN MAC addresses are never sent outside the appliance. If a later download or SQL import fails, FenPing retains the previous registry and SQL data.

Completed nmap output is stored in SQLite. FenPing keeps one XML snapshot per distinct semantic result and scan profile, so unchanged scans reuse the existing snapshot. Lightweight checks Nmap's 100 most common TCP ports with a five-minute limit. Standard checks the top 1,000 TCP ports with service, OS, default-script, and traceroute detection with a 30-minute limit. Deep performs the same detection across all 65,535 TCP ports with a two-hour limit. The normal detail view prefers the latest deep result. Selecting a lightweight or standard result merges it over the preceding deep snapshot: partial observations replace matching ports while deep-only ports and OS data remain visible with source labels. Existing `quick` history remains readable as Lightweight. OS detection shows every 100% match, or only the highest-accuracy match when nmap has no 100% result.

Completed scans also build an effective open-port view. A deep scan observes the full TCP range; lightweight and standard scans change only the ports listed in their Nmap scan scope. Services in the first usable result are recorded as newly appeared, and later appearances, disappearances, and confirmed service/version changes are stored for seven days, displayed on Notify, and sent to Discord when a webhook is configured. Missing version data from a partial scan does not erase version details learned by a deeper scan.

Managed hosts have an automatic scan profile and cadence. The default remains Deep every hour for compatibility. Set the cadence to `0` in the host editor to disable scheduled scans, or enter any interval up to 8,760 hours. The hourly discovery job queues a host only when its latest successful scan using the selected profile is older than that interval. Manual scans and explicit CLI targets ignore cadence. Unmanaged discovered devices continue to use Deep every hour.

At boot, `scan-port-backfill` replays stored snapshots in chronological order and inserts any missing service-change events using their original scan timestamps. The replay is idempotent, so it can also be run manually after restoring older scan history.

## Backup And Restore

### Upgrading from MariaDB

SQLite starts with a fresh database and does not automatically import `data/db`. The old MariaDB directory is left in place for rollback but is no longer mounted. To preserve an older installation manually, create a version 1.6 FenPing archive before upgrading and restore that archive after the SQLite deployment starts.

### Screenshot demo

The versioned `demo/` source contains a synthetic network with inventory, IPAM, history, notifications, services, scans, and netboot examples. To rebuild its backup, preserve the current state, and restore the demo:

```bash
./restart.sh demo
```

The generated archive is `data/backups/fenping-demo.tgz`. Before restoring it, the command creates a timestamped `data/backups/fenping-before-demo-*.tgz` containing the current database and netboot files. Demo timestamps shift to the restore time so recent activity remains suitable for screenshots.

Create a full backup archive before upgrades:

```bash
docker exec fenping php /opt/fenping/cli.php backup
```

Restore from a FenPing archive:

```bash
docker exec fenping php /opt/fenping/cli.php restore /var/lib/fenping/backups/fenping-YYYYmmdd-HHMMSS.tgz
```

Backups use the database-neutral version 1.6 JSON format (`db.json` inside the archive). Existing version 1.6 archives created by MariaDB-based FenPing releases remain restorable into SQLite. SQL-based backups from earlier versions are not supported.

Legacy 1.2 SQL and nmap backups can be converted offline, without a MariaDB/MySQL server. The nmap archive must contain one latest result per host named `IP.xml`:

```bash
python3 tools/convert-v1.2-backup.py legacy.sql.tgz legacy.nmap.tgz
```

The converter parses mysqldump and nmap XML data directly, migrates legacy leases to the current shape, decodes HTML entities used by 1.2 category names, imports each XML file as the host's latest deep scan, and creates a restore-compatible archive with an empty netboot directory. The output defaults to the SQL filename without `.sql` (for example, `legacy.tgz`). Use `--target converted.tgz` to select another destination and `--force` to replace an existing target.

## Admin Workflow

The UI starts in guest mode. Guests can view inventory, IPAM utilization, services, history, scans, health, and notifications, but cannot approve devices or change DHCP/DNS/netboot state.

After login, admins can create/edit hosts, add/rename/delete categories, trigger ping refreshes and choose lightweight, standard, or deep host scans, upload/delete netboot images, and assign netboot images to hosts.

Netboot uploads accept UEFI applications (`.efi`), iPXE/PXE loaders (`.kpxe`, `.kkpxe`, `.kkkpxe`, `.pxe`, `.lkrn`), PXELINUX loaders (`.0`), and iPXE scripts (`.ipxe`). FenPing validates both the filename extension and the file content. PHP execution is disabled in the netboot directory.

## API

The PHP API is routed by `api.php` and grouped under `routes/`.

Useful endpoints:

| Method | Route | Description |
| --- | --- | --- |
| `GET` | `/api/health` | Appliance health. |
| `GET` | `/api/inventory` | Network inventory. |
| `GET` | `/api/ipam` | DHCP pool utilization plus pending and approved dynamic devices. |
| `PUT` | `/api/ipam/devices/{mac}/approval` | Acknowledge a new device without changing DHCP behavior. |
| `DELETE` | `/api/ipam/devices/{mac}/approval` | Mark an acknowledged dynamic device as new again. |
| `GET` | `/api/notify` | Last 24 hours of changes. |
| `GET` | `/api/services` | Current open services by host using the latest effective scan. |
| `POST` | `/api/ping/refresh` | Run ping scan and wait for completion. |
| `GET` | `/api/history/{ip}` | Status history for a host. |
| `GET` | `/api/hosts/by-ip/{ip}/detail` | Combined identity, status history, and scan details for an inventory device. |
| `GET` | `/api/scans/{ip}` | Preferred scan result as JSON, favoring the latest deep result. |
| `GET` | `/api/scans/profiles` | List available scan profiles and timeout limits. |
| `POST` | `/api/scans/{ip}` | Queue the requested `lightweight`, `standard`, or `deep` profile and return HTTP `202`. |
| `GET` | `/api/scans/{ip}/xml` | Compatibility XML generated from the normalized scan tables. |
| `POST` | `/api/scans/{ip}/quick` | Legacy alias that queues a lightweight scan. |
| `GET` | `/api/netboot/images` | List netboot images. |
| `POST` | `/api/netboot/images` | Upload a netboot image. |
| `GET` | `/api/netboot/images/{id}/file` | Download a netboot image. |
| `DELETE` | `/api/netboot/images/{id}` | Delete a netboot image. |

Errors return JSON:

```json
{ "error": "message" }
```

## Checks

Useful checks before committing:

```bash
bash -n boot.sh restart.sh tests/test.sh
docker compose config --quiet
docker build --check .
docker build -t fenping-check .
php -l public/api.php api.php functions.php database.php cli.php ping.php hosts.php inventory.php ipam.php scans.php health.php backup.php
php -l routes/auth.php routes/system.php routes/hosts.php routes/ipam.php routes/netboot.php routes/scans.php
DATABASE_PATH=/tmp/fenping-sqlite-test.sqlite3 php tests/sqlite.php
DATABASE_PATH=/tmp/fenping-scan-test.sqlite3 php tests/scan_storage.php
```

Smoke test a running instance:

```bash
SITE=http://<FENPING_IP> PASS=<admin-password> ./tests/test.sh
```

## Troubleshooting

### Health Is Degraded

```bash
curl -fsS http://127.0.0.1/api/health
docker logs --tail=100 fenping
```

### dnsmasq Does Not Update

```bash
docker exec fenping php /opt/fenping/cli.php hosts
docker logs -f fenping
```

### Scans Are Missing

```bash
docker exec fenping php /opt/fenping/cli.php inventory --profile lightweight 10.10.10.10
docker exec fenping php /opt/fenping/cli.php inventory --work
```

### Multi-architecture Build Is Slow During npm install

The Dockerfile uses an npm cache mount and conservative retry settings. Build and push through the release script so Buildx can reuse its container cache:

```bash
./publish.sh 1.6
```

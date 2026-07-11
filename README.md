# FenPing

FenPing is a compact LAN appliance for device discovery, uptime history, DHCP/DNS host management, nmap scan history, notifications, backups, and netboot image assignment.

It uses a static Vue/Vite frontend with Vue Router, a PHP API/CLI backend, MariaDB, dnsmasq, cron, nmap, ping, ARP, and arping. Docker Compose runs the appliance and database as separate containers.

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

FenPing runs through Docker Compose with two containers:

- `fenping` uses host networking and runs Apache/PHP, dnsmasq, cron, nmap, ping/ARP tools, and the application CLI.
- `fenping-db` runs the official MariaDB 11.8 image and owns `data/db`.

MariaDB has networking disabled. The app connects through a shared Unix socket, preserving compatibility with the existing `root@localhost` account without exposing an SQL port. Compose waits for an authenticated database health check before starting the app, and `boot.sh` applies the idempotent schema before Apache starts.

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
| `FENPING_VERSION` | Published image tag pulled by `restart.sh`. Defaults to `1.5`. |
| `DB_PORT` | TCP port used only when connecting to an external database instead of the Compose Unix socket. Defaults to `3306`. |
| `DB_USER` | MariaDB application login. Defaults to `root` for compatibility with existing installations. |
| `DB_PASS` | MariaDB login password and initial root password for a new data directory. Keep it equal to the existing root password when reusing `data/db`; changing this value alone does not rotate an initialized database password. |
| `DB_NAME` | Application database. Defaults to `ping`. |
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
./publish.sh 1.5
```

The targets are exactly `linux/arm64`, `linux/amd64`, and `linux/arm/v7`. The script automatically runs `tonistiigi/binfmt --install all`, so publishing requires permission to start a privileged Docker container. Set `PUBLISH_LATEST=0` to omit the `latest` tag, or set `FENPING_IMAGE` to publish another Docker Hub repository. The script uses a reusable `fenping-multiarch` Buildx container builder, pushes the version and `latest` manifests, attaches provenance and an SBOM, and inspects the published result.

`restart.sh` never builds the application image. It pulls `FENPING_IMAGE:FENPING_VERSION` before stopping the current app, so a missing or inaccessible tag leaves the running deployment untouched.

## Persistent Data

Do not delete `data/` casually. It is the appliance state.

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/db` | `fenping-db:/var/lib/mysql` | MariaDB data. |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases. |
| `data/dnsmasq.d` | `/etc/dnsmasq.d` | Generated dnsmasq config files. |
| `data/netboot` | `/var/lib/fenping/netboot` | Uploaded netboot files. |
| `data/backups` | `/var/lib/fenping/backups` | Backup archives and imported dumps. |
| `data/state` | `/var/lib/fenping/state` | Refreshed IEEE vendor registry and optional state/health files. |

Apache serves only `/var/www/public`, which contains the built frontend and the small API entrypoint. PHP application code lives in `/opt/fenping`; runtime files under `/var/lib/fenping` are not directly web-accessible.

### SSD write endurance

FenPing groups MariaDB redo-log flushes into five-second intervals while retaining InnoDB's doublewrite protection. A host power loss or operating-system crash can therefore lose up to approximately five seconds of recent database changes; a MariaDB process crash remains recoverable from the operating-system cache. DHCP leases, scans, and application data remain persistent.

Routine writes are also limited outside MariaDB: both services use memory-backed `/tmp`, while the app also uses memory-backed `/run` for scan temporaries, locks, PHP sessions, and lease-import staging; Apache access logging and verbose DHCP logging are disabled; Docker logs are compressed and rotated; unchanged dnsmasq files are not replaced; lease imports upsert observed rows instead of rebuilding the table; stable ping-history rows are extended at most once per day; and an unchanged IEEE registry is not rewritten into SQL at boot. Login sessions are intentionally cleared when the app container restarts because their files live in `/run`.

## CLI

Run operational commands from the container:

```bash
docker exec fenping php /opt/fenping/cli.php ping
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

The image includes a vendor registry seed downloaded from the [IEEE Registration Authority public listings](https://standards.ieee.org/products-programs/regauth/). Every boot verifies the SQL registry against the last validated data and transactionally imports it only when changed. A monthly background job downloads and validates the complete public MA-L, MA-M, MA-S, and historical IAB CSV files, atomically replaces `data/state/ieee-oui.json`, and refreshes the SQL table. Inventory requests query this local prefix index; individual LAN MAC addresses are never sent outside the appliance. If a download or SQL import fails, FenPing retains the previous registry and can fall back to the image seed.

Completed nmap output is stored in MariaDB. FenPing keeps one XML snapshot per distinct semantic result and scan profile, so unchanged scans reuse the existing snapshot. Lightweight checks Nmap's 100 most common TCP ports with a five-minute limit. Standard checks the top 1,000 TCP ports with service, OS, default-script, and traceroute detection with a 30-minute limit. Deep performs the same detection across all 65,535 TCP ports with a two-hour limit. The normal detail view prefers the latest deep result. Selecting a lightweight or standard result merges it over the preceding deep snapshot: partial observations replace matching ports while deep-only ports and OS data remain visible with source labels. Existing `quick` history remains readable as Lightweight. OS detection shows every 100% match, or only the highest-accuracy match when nmap has no 100% result.

Completed scans also build an effective open-port view. A deep scan observes the full TCP range; lightweight and standard scans change only the ports listed in their Nmap scan scope. Services in the first usable result are recorded as newly appeared, and later appearances, disappearances, and confirmed service/version changes are stored for seven days, displayed on Notify, and sent to Discord when a webhook is configured. Missing version data from a partial scan does not erase version details learned by a deeper scan.

Managed hosts have an automatic scan profile and cadence. The default remains Deep every hour for compatibility. Set the cadence to `0` in the host editor to disable scheduled scans, or enter any interval up to 8,760 hours. The hourly discovery job queues a host only when its latest successful scan using the selected profile is older than that interval. Manual scans and explicit CLI targets ignore cadence. Unmanaged discovered devices continue to use Deep every hour.

At boot, `scan-port-backfill` replays stored snapshots in chronological order and inserts any missing service-change events using their original scan timestamps. The replay is idempotent, so it can also be run manually after restoring older scan history.

## Backup And Restore

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

Restore from a raw SQL dump:

```bash
docker exec fenping php /opt/fenping/cli.php restore /var/lib/fenping/backups/db.sql.gz
```

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
| `GET` | `/api/scans/{ip}` | Preferred scan result as JSON, favoring the latest deep result. |
| `GET` | `/api/scans/profiles` | List available scan profiles and timeout limits. |
| `POST` | `/api/scans/{ip}` | Queue the requested `lightweight`, `standard`, or `deep` profile and return HTTP `202`. |
| `GET` | `/api/scans/{ip}/xml` | Preferred database-backed scan XML. |
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
./publish.sh 1.5
```

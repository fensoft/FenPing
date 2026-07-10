# FenPing

FenPing is a compact LAN appliance for device discovery, uptime history, DHCP/DNS host management, nmap scan history, notifications, backups, and netboot image assignment.

It uses a static Vue/Vite frontend with Vue Router, a PHP API/CLI backend, MariaDB, dnsmasq, cron, nmap, ping, ARP, and arping. The default runtime is one Docker container.

## Features

- Live inventory table for known and newly discovered devices.
- Status tracking with `Up`, `Down`, `arp`, and `arp-down` states.
- Stability, host history, and a 24-hour notify view.
- Static DHCP/DNS host management through dnsmasq.
- Transactional DHCP lease history with stable first-seen and last-seen timestamps.
- Device onboarding with reversible MAC approval and DHCP pool utilization tracking.
- Transactional DHCP updates: host changes are validated and syntax-checked before the database and dnsmasq configuration are committed together.
- Category/range separators with collapsible groups and rename support.
- Quick and deep nmap scans with deduplicated database history; quick results never replace the latest deep snapshot.
- Service-change notifications when an open port appears, disappears, or reports a different service/version.
- Searchable service inventory showing every open service per computer from the latest deep or merged quick scan.
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

![Inventory](img/screenshot-inventory.svg)

### Notify

![Notify](img/screenshot-notify.svg)

### Netboot Images

![Netboot Images](img/screenshot-netboot.svg)

### Scan Details

![Scan Details](img/screenshot-scan.svg)

## Runtime Layout

FenPing currently runs as one host-networked container named `fenping`.

Inside that container:

- Apache serves the static Vue app and routes `/api/...` to PHP.
- MariaDB stores inventory, ping, stats, scan jobs/snapshots, category, auth, and netboot metadata.
- dnsmasq serves DHCP, DNS, leases, and TFTP/netboot settings.
- cron runs ping scans, inventory scans, and lease imports.
- PHP CLI commands handle host generation, scanning, backup/restore, and notifications.

This intentionally is not a split Compose deployment.

## Install

1. Copy the environment template:

   ```bash
   cp env.template .env
   ```

2. Edit `.env` for your LAN.

3. Build and start:

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
| `NETWORK` | `/24` prefix, for example `10.10.10`. |
| `DHCP_DEFAULT_ROUTER` | Router handed out by DHCP. |
| `DHCP_DYNAMIC_BEGIN` | First dynamic DHCP address, last octet only. |
| `DHCP_DYNAMIC_END` | Last dynamic DHCP address, last octet only. |
| `PASSWORD` | Admin login password. Empty means a blank login password. |
| `SECRET` | Session signing secret. |
| `DISCORD_WEBHOOK_URL` | Optional Discord webhook for host status, service changes, and restart notifications. |

Managed hosts require a valid IPv4 address and six-octet MAC address. Host names are optional; when set, they must contain one DNS label using letters, numbers, and internal hyphens. Per-host DNS overrides accept one or more IPv4 addresses separated by spaces, commas, or semicolons.

## Persistent Data

Do not delete `data/` casually. It is the appliance state.

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/db` | `/var/lib/mysql` | MariaDB data. |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases. |
| `data/dnsmasq.d` | `/etc/dnsmasq.d` | Generated dnsmasq config files. |
| `data/netboot` | `/var/lib/fenping/netboot` | Uploaded netboot files. |
| `data/backups` | `/var/lib/fenping/backups` | Backup archives and imported dumps. |
| `data/state` | `/var/lib/fenping/state` | Refreshed IEEE vendor registry and optional state/health files. |

Apache serves only `/var/www/public`, which contains the built frontend and the small API entrypoint. PHP application code lives in `/opt/fenping`; runtime files under `/var/lib/fenping` are not directly web-accessible.

### SSD write endurance

FenPing groups MariaDB redo-log flushes into five-second intervals while retaining InnoDB's doublewrite protection. A host power loss or operating-system crash can therefore lose up to approximately five seconds of recent database changes; a MariaDB process crash remains recoverable from the operating-system cache. DHCP leases, scans, and application data remain persistent.

Routine writes are also limited outside MariaDB: `/tmp` and `/run` use memory-backed filesystems for scan temporaries, MariaDB temporary tablespaces, locks, PHP sessions, and lease-import staging; Apache access logging and verbose DHCP logging are disabled; Docker logs are compressed and rotated; unchanged dnsmasq files are not replaced; lease imports upsert observed rows instead of rebuilding the table; stable ping-history rows are extended at most once per day; and an unchanged IEEE registry is not rewritten into SQL at boot. Login sessions are intentionally cleared when the container restarts because their files live in `/run`.

## CLI

Run operational commands from the container:

```bash
docker exec fenping php /opt/fenping/cli.php ping
docker exec fenping php /opt/fenping/cli.php ping 10
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory
docker exec fenping php /opt/fenping/cli.php inventory --quick 10.10.10.10
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
- inventory discovery every hour; discovered hosts are queued for deep scans.
- the inventory worker runs queued scans with a maximum concurrency of four.
- the local IEEE OUI registry is refreshed monthly on the first day at 03:17.
- dnsmasq lease import every minute.

The image includes a vendor registry seed downloaded from the [IEEE Registration Authority public listings](https://standards.ieee.org/products-programs/regauth/). Every boot verifies the SQL registry against the last validated data and transactionally imports it only when changed. A monthly background job downloads and validates the complete public MA-L, MA-M, MA-S, and historical IAB CSV files, atomically replaces `data/state/ieee-oui.json`, and refreshes the SQL table. Inventory requests query this local prefix index; individual LAN MAC addresses are never sent outside the appliance. If a download or SQL import fails, FenPing retains the previous registry and can fall back to the image seed.

Completed nmap output is stored in MariaDB. FenPing keeps one XML snapshot per distinct semantic result and scan mode, so unchanged scans reuse the existing snapshot. Quick and deep scans have separate change baselines, and the normal detail view prefers the latest deep result. Selecting a quick result merges it over the preceding deep snapshot: quick observations replace matching ports while deep-only ports and OS data remain visible with source labels. OS detection shows every 100% match, or only the highest-accuracy match when nmap has no 100% result.

Completed scans also build an effective open-port view. A deep scan observes the full TCP range; a quick scan changes only the ports listed in its Nmap scan scope. Services in the first usable result are recorded as newly appeared, and later appearances, disappearances, and confirmed service/version changes are stored for seven days, displayed on Notify, and sent to Discord when a webhook is configured. Missing version data from a quick scan does not erase version details learned by a deep scan.

At boot, `scan-port-backfill` replays stored snapshots in chronological order and inserts any missing service-change events using their original scan timestamps. The replay is idempotent, so it can also be run manually after restoring older scan history.

## Backup And Restore

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

After login, admins can create/edit hosts, add/rename/delete categories, trigger ping refreshes and quick scans, upload/delete netboot images, and assign netboot images to hosts.

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
| `GET` | `/api/scans/{ip}` | Preferred scan result as JSON (deep before quick). |
| `GET` | `/api/scans/{ip}/xml` | Preferred database-backed scan XML. |
| `POST` | `/api/scans/{ip}/quick` | Queue a quick scan and return HTTP `202`. |
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
docker exec fenping php /opt/fenping/cli.php inventory --quick 10.10.10.10
docker exec fenping php /opt/fenping/cli.php inventory --work
```

### Docker Build Is Slow During npm install

The Dockerfile uses an npm cache mount and conservative retry settings. Keeping BuildKit enabled helps:

```bash
DOCKER_BUILDKIT=1 docker build --progress=plain --network=host -t fensoft/fenping:1.5 .
```

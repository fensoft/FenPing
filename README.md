# FenPing

FenPing is a small LAN appliance for discovering devices, tracking uptime changes, and managing local DNS, DHCP, and netboot settings from one compact web UI.

It runs as a Docker container, scans a `/24` network, stores history in MariaDB, serves the frontend with Apache/PHP, and uses `dnsmasq` for DHCP, DNS, and TFTP.

## Features

- Live inventory table for known and newly discovered devices.
- Status tracking with `Up`, `Down`, `arp`, and `arp-down` states.
- Stability and history views for noisy or unreliable hosts.
- Static DHCP/DNS host management.
- Category/range separators with collapsible groups.
- Quick and deep nmap scans, saved with history.
- Netboot image upload and per-host boot image selection.
- Guest read-only mode and admin login.
- Dark mode.
- `/api/health` status endpoint.
- Optional Discord webhook notifications for host status changes and restarts.

## Screenshots

### Inventory

![Inventory](img/screenshot-inventory.svg)

### Notify

![Notify](img/screenshot-notify.svg)

### Netboot Images

![Netboot Images](img/screenshot-netboot.svg)

### Scan Details

![Scan Details](img/screenshot-scan.svg)

## Requirements

- Docker with BuildKit enabled.
- A Linux host connected to the LAN you want FenPing to manage.
- Permission to bind DHCP/DNS/TFTP ports on the host network.

FenPing is intended to be a LAN appliance. In the default `restart.sh`, the container runs with host networking and a reduced capability set.

## Install

1. Copy the environment template:

   ```bash
   cp env.template .env
   ```

2. Edit `.env` for your network.

3. Build and start:

   ```bash
   ./restart.sh
   ```

4. Open FenPing in your browser:

   ```text
   http://<FENPING_IP>/
   ```

## Configuration

`.env` controls the container and runtime config.

| Variable | Description |
| --- | --- |
| `IP` | FenPing address on your LAN. |
| `NETWORK` | `/24` network prefix, for example `10.10.10`. |
| `DEFAULT_GATEWAY` | Gateway used when not running in host network mode. |
| `DHCP_DEFAULT_ROUTER` | Router handed out by DHCP. |
| `DHCP_DYNAMIC_BEGIN` | First dynamic DHCP address, last octet only. |
| `DHCP_DYNAMIC_END` | Last dynamic DHCP address, last octet only. |
| `OTHER_NETWORKS` | Optional extra CIDR addresses to add in non-host mode. |
| `PASSWORD` | Admin password. Empty means a blank login password. |
| `SECRET` | Session signing secret. |
| `DISCORD_WEBHOOK_URL` | Optional Discord webhook for status-change and restart notifications. |

## Persistent Data

`restart.sh` mounts these host directories into the container:

| Host path | Container path | Purpose |
| --- | --- | --- |
| `data/db` | `/var/lib/mysql` | MariaDB data. |
| `data/dnsmasq` | `/var/lib/misc` | dnsmasq leases. |
| `data/nmap` | `/var/www/html/nmap` | nmap XML and scan history. |
| `data/netboot` | `/var/www/html/netboot` | Uploaded netboot files. |

## Admin Workflow

The UI starts in guest mode. Guests can view inventory, history, scans, and notifications, but cannot change DHCP/DNS/netboot state.

After login, admins can:

- Create hosts from discovered MAC addresses.
- Edit host name, IP, MAC, DNS, router, web flag, important flag, and router/repeater flag.
- Add or delete categories.
- Trigger ping refreshes.
- Trigger per-host quick scans.
- Upload or delete netboot images.
- Assign a netboot image to a host.

## Status Semantics

| Status | Meaning |
| --- | --- |
| `Up` | Host replied to ping or local/self detection. |
| `Down` | No ping response and no useful ARP evidence. |
| `arp` | Host appears in ARP but does not reply to ping. |
| `arp-down` | Host was in ARP cache but ARP verification failed. |

Status changes are written to `stats`. The Notify page shows the last 24 hours of changes, and Discord notifications are generated from newly created `stats` rows.

## DHCP, DNS, And Netboot

FenPing generates dnsmasq files from the database:

- `/etc/dnsmasq.d/fenping.dhcp-hosts`
- `/etc/dnsmasq.d/fenping.dhcp-opts`
- `/etc/dnsmasq.d/fenping.hosts`

When host settings change, FenPing rewrites these files and reloads dnsmasq.

Netboot uploads are stored in `data/netboot` and served from:

```text
/netboot/<filename>
```

If a host has a selected netboot image, FenPing emits per-host dnsmasq DHCP options for TFTP server and boot filename.

## API

The PHP API is routed by `api.php` and grouped under `routes/`.

Useful endpoints:

| Method | Route | Description |
| --- | --- | --- |
| `GET` | `/api/health` | Appliance health. |
| `GET` | `/api/inventory` | Network inventory. |
| `GET` | `/api/notify` | Last 24 hours of changes. |
| `POST` | `/api/ping/refresh` | Run ping scan and wait for completion. |
| `GET` | `/api/history/{ip}` | Status history for a host. |
| `GET` | `/api/scans/{ip}` | Latest scan as JSON. |
| `GET` | `/api/scans/{ip}/xml` | Latest scan XML. |
| `GET` | `/api/scans/{ip}/history` | Scan history metadata. |
| `POST` | `/api/scans/{ip}/quick` | Run quick scan. |
| `GET` | `/api/netboot/images` | List netboot images. |
| `POST` | `/api/netboot/images` | Upload a netboot image. |
| `DELETE` | `/api/netboot/images/{id}` | Delete a netboot image. |

Errors return JSON:

```json
{ "error": "message" }
```

## CLI

Common commands inside the container:

```bash
php /var/www/html/cli.php ping
php /var/www/html/cli.php ping 10
php /var/www/html/cli.php hosts
php /var/www/html/cli.php inventory
php /var/www/html/cli.php inventory --quick 10.10.10.10
php /var/www/html/cli.php discord-restart
```

Cron runs:

- `ping` every 15 minutes.
- `inventory` every hour.
- dnsmasq lease import every minute.

Loading the inventory page as admin also triggers a fresh ping scan.

## Tests And Checks

Static checks used during development:

```bash
docker build --check .
docker build --target frontend -t fenping-frontend-check .
docker build -t fenping-check .
bash -n boot.sh restart.sh tests/test.sh
```

Smoke test against a running instance:

```bash
SITE=http://<FENPING_IP> PASS=<admin-password> ./tests/test.sh
```

Set `TEST_IP` to include scan route checks:

```bash
SITE=http://<FENPING_IP> PASS=<admin-password> TEST_IP=10.10.10.10 ./tests/test.sh
```

## Troubleshooting

### Discord does not send

Check that `DISCORD_WEBHOOK_URL` is present in `.env`, then restart the container. You can test without waiting for a real status change:

```bash
docker exec fenping php /var/www/html/cli.php discord-restart
```

### dnsmasq does not update

Regenerate and reload dnsmasq:

```bash
docker exec fenping php /var/www/html/cli.php hosts
```

### Scans are missing

Run a quick scan for one host:

```bash
docker exec fenping php /var/www/html/cli.php inventory --quick 10.10.10.10
```

### Docker build is slow during npm install

The Dockerfile uses an npm cache mount and conservative npm retry settings. Keeping BuildKit enabled helps a lot:

```bash
DOCKER_BUILDKIT=1 docker build --progress=plain --network=host -t fensoft/fenping:1.4 .
```

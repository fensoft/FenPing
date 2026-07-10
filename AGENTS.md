# AGENTS.md

Operational notes for future Codex sessions in this repository.

## Project Snapshot

FenPing is a LAN appliance for inventory, DHCP/DNS management, ping status history, nmap scan history, notifications, backups/restores, and netboot image assignment.

Main stack:
- PHP API and CLI with a hand-written front controller in `api.php`.
- Static Vue 3 + Vite frontend styled with Tabler.
- One Docker container running Apache/PHP, MariaDB, dnsmasq, cron, nmap, ping, ARP/arping tools.
- MariaDB stores app data.
- dnsmasq provides DHCP, DNS, TFTP, and leases.

## Runtime Shape

This repo currently uses a single container, not a split Compose stack.

- `restart.sh` builds `fensoft/fenping:1.5`.
- `restart.sh` runs one container named `fenping` with host networking.
- `boot.sh` starts MariaDB, applies `db.sql`, renders dnsmasq config, starts cron, sends restart notification, regenerates host files, and runs Apache in the foreground.
- Cron inside the container runs ping, hourly inventory discovery, the four-concurrent-scan queue worker, and lease import jobs.

Do not reintroduce `docker-compose.yml`, nginx/PHP-FPM, Ofelia, or separate DB/dnsmasq containers unless the user explicitly asks for the split architecture again.

## Important Files

- `Dockerfile`: multi-stage build; Vite frontend first, then Ubuntu runtime with Apache/PHP/MariaDB/dnsmasq/cron.
- `restart.sh`: builds and starts the single host-networked container.
- `boot.sh`: single-container service bootstrap.
- `config.php`: committed generic PHP config that reads runtime values from environment variables.
- `mariadb-fenping.cnf`: low-write MariaDB durability/logging policy; preserves InnoDB doublewrite protection.
- `cli.php`: CLI commands: `ping`, `hosts`, `inventory`, `oui-refresh`, `oui-sync`, `backup`, `restore`, `discord-restart`.
- `api.php`: JSON API front controller.
- `routes/`: route modules for auth, system, hosts/categories, scans, netboot.
- `functions.php`: domain helpers for inventory, host CRUD, categories, history, notify, netboot.
- `database.php`: PDO singleton.
- `db.sql`: idempotent schema/migration SQL and `update_status`.
- `ping.php`: ping scanner and status writer.
- `hosts.php`: DHCP field validation, transactional dnsmasq candidate generation, and local reload/start logic.
- `inventory.php`, `scans.php`: nmap scanning, XML parsing, scan metadata/history.
- `oui.php`: local IEEE MA-L/MA-M/MA-S/IAB vendor index loading and atomic refresh.
- `backup.php`: backup/restore implementation.
- `frontend/`: Vue app source.
- `docs/ARCHITECTURE.md`: deeper project overview.

## Persistent Data

Do not casually delete or rewrite files under `data/`.

- `data/db` -> `/var/lib/mysql`
- `data/dnsmasq` -> `/var/lib/misc`
- `data/dnsmasq.d` -> `/etc/dnsmasq.d`
- `data/netboot` -> `/var/lib/fenping/netboot`
- `data/backups` -> `/var/lib/fenping/backups`
- `data/state` -> `/var/lib/fenping/state`

Apache's document root is `/var/www/public`. Application code lives in `/opt/fenping`; never move runtime data or `.env` back under the document root.

## CLI

Run commands through the single container:

```bash
docker exec fenping php /opt/fenping/cli.php ping [1-254|DEBUG]
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory [--quick] [1-254|IPv4]
docker exec fenping php /opt/fenping/cli.php inventory --work
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup [backup.tgz]
docker exec fenping php /opt/fenping/cli.php restore <backup.tgz|dump.sql.gz>
docker exec fenping php /opt/fenping/cli.php discord-restart
```

## API Shape

Routes are declared in `routes/*.php` and merged in `api.php`. Success responses are direct JSON with HTTP 200. Errors use 4xx/5xx plus:

```json
{ "error": "message" }
```

Guest mode is read-only. Mutating routes require authenticated session/body auth.

Host and netboot mutations that affect dnsmasq must use `commitDhcpMutation()` so database changes and generated configuration stay coordinated. Do not write DHCP fields directly without the validators in `hosts.php`.

## Tests Before Commit

Use the applicable subset:

```bash
bash -n boot.sh restart.sh tests/test.sh
docker build --check .
docker build -t fenping-check .
npm run build
php -l public/api.php api.php functions.php database.php cli.php ping.php hosts.php inventory.php scans.php health.php backup.php oui.php
php -l routes/auth.php routes/system.php routes/hosts.php routes/netboot.php routes/scans.php
```

If PHP or Node is unavailable on the host, run syntax checks inside the container/image.

## Gotchas

- Never commit `.env`, cookies, DB dumps, webhook URLs, or machine-specific private values.
- Keep `config.php` generic and environment-driven; do not hardcode local secrets or host-specific values in it.
- Do not revert unrelated dirty work.
- `data/` is live state.
- Keep `db.sql` idempotent.
- The MariaDB five-second flush window is an intentional SSD-endurance tradeoff. Do not disable InnoDB doublewrite protection.
- `/tmp` and `/run` are tmpfs; persistent state must remain under the documented bind mounts.
- API-triggered sudo calls expect `/usr/bin/php` in Dockerfile sudoers.
- Inventory commands enqueue scans; `inventory --work` is the lock-protected four-process queue coordinator.
- MAC vendor lookups must remain local; refresh the complete public IEEE registries through `oui-refresh` instead of sending individual LAN MAC addresses to an external API.
- Lease imports must retain the `(hardware-ethernet, ip)` history and use the staging/upsert transaction in `dnsmasq.leases.php`; do not restore truncate-and-reinsert behavior.
- dnsmasq generation happens through `php cli.php hosts`.
- Cron is inside the container; do not look for Ofelia.
- Avoid full `/24` inventory scans unless the user accepts LAN scan traffic.

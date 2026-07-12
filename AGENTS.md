# AGENTS.md

Operational notes for future Codex sessions in this repository.

## Project Snapshot

FenPing is a LAN appliance for inventory, DHCP/DNS management, ping status history, nmap scan history, notifications, backups/restores, and netboot image assignment.

Main stack:
- PHP API and CLI with a hand-written front controller in `api.php`.
- Static Vue 3 + Vite frontend styled with Tabler.
- A host-networked Alpine application container running nginx/PHP-FPM, dnsmasq, BusyBox cron, nmap, ping, and ARP/arping tools.
- SQLite stores application data inside the FenPing container's persistent database mount.
- dnsmasq provides DHCP, DNS, TFTP, and leases.

## Runtime Shape

This repo uses Docker Compose with one application service.

- `restart.sh` normally pulls published images and starts the Compose project. `./restart.sh dev` explicitly builds the current platform as the local `dev` tag.
- `fenping` uses host networking for DHCP/DNS/TFTP and the web UI.
- `fenping` owns the SQLite database at `/var/lib/fenping/database/fenping.sqlite3`, mounted from `data/database`.
- `boot.sh` initializes and checks SQLite, backfills service-change notifications, refreshes the IEEE vendor registry into persistent state and SQL, renders dnsmasq config, starts BusyBox cron, sends the restart notification, regenerates host files, starts PHP-FPM, and runs nginx in the foreground.
- Cron inside the container runs ping, hourly inventory discovery, the four-concurrent-scan queue worker, and lease import jobs.

Do not add a separate database service or split dnsmasq into another container unless the user explicitly asks.

## Important Files

- `docker-compose.yml`: single application service, mounts, capabilities, and logging limits.
- `Dockerfile`: multi-stage build; Vite frontend first, then an Alpine application runtime with nginx/PHP-FPM and PDO SQLite, dnsmasq, BusyBox cron, and networking tools.
- `restart.sh`: validates, pulls `FENPING_IMAGE:FENPING_VERSION`, and starts the Compose project; `dev` mode builds and runs `FENPING_IMAGE:dev` locally.
- `publish.sh`: multi-architecture Buildx release command that pushes the versioned Docker Hub image and `latest` by default.
- `demo/`: versioned synthetic screenshot database, netboot files, and backup metadata. `./restart.sh demo` rebuilds and restores it after preserving the current state.
- `boot.sh`: application-service bootstrap and database schema application.
- `config.php`: committed generic PHP config that reads runtime values from environment variables.
- `cli.php`: CLI commands: `ping`, `hosts`, `inventory`, `scan-port-backfill`, `oui-refresh`, `oui-sync`, `backup`, `restore`, `discord-restart`.
- `api.php`: JSON API front controller.
- `routes/`: route modules for auth, system, hosts/categories, IPAM, scans, netboot.
- `functions.php`: domain helpers for inventory, host CRUD, categories, history, notify, netboot.
- `ipam.php`: dynamic-device approval, observation aggregation, and DHCP pool utilization.
- `database.php`: configured PDO SQLite singleton, schema initialization and ordered migrations, integrity checks, transaction helpers, and status-history writer.
- `db.sql`: canonical idempotent SQLite schema for new databases, tracked with `PRAGMA user_version`.
- `migrations/`: immutable sequential SQLite upgrades for existing nonzero schema versions.
- `ping.php`: ping scanner and status writer.
- `hosts.php`: DHCP field validation, transactional dnsmasq candidate generation, and local reload/start logic.
- `inventory.php`, `scans.php`: nmap scanning, XML parsing, scan metadata/history, and effective port-change detection.
- `oui.php`: local IEEE MA-L/MA-M/MA-S/IAB vendor index loading and atomic refresh.
- `backup.php`: backup/restore implementation.
- `frontend/App.vue`: Vue application shell and cross-page orchestration.
- `frontend/router.js`, `frontend/pages/`: Vue Router configuration and route-level inventory, IPAM, services, scans, notifications, host-detail, and netboot components.
- `frontend/components/`, `frontend/composables/`, `frontend/lib/`: shared UI, lifecycle, API, and formatting modules.
- `docs/ARCHITECTURE.md`: deeper project overview.

## Persistent Data

Do not casually delete or rewrite files under `data/`.

- `data/database` -> `/var/lib/fenping/database`
- `data/dnsmasq` -> `/var/lib/misc`
- `data/dnsmasq.d` -> `/etc/dnsmasq.d`
- `data/netboot` -> `/var/lib/fenping/netboot`
- `data/backups` -> `/var/lib/fenping/backups`
- `data/state` -> `/var/lib/fenping/state`

nginx's document root is `/var/www/public`. Application code lives in `/opt/fenping`; never move runtime data or `.env` back under the document root.

## CLI

Run application commands through the `fenping` container:

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
! ./publish.sh 'invalid version!' # validation-only negative check
docker compose config --quiet
docker build --check .
docker build -t fenping-check .
npm run build
php -l public/api.php api.php functions.php database.php cli.php ping.php hosts.php inventory.php ipam.php scans.php health.php backup.php oui.php tests/backup_format.php tests/database_migrations.php
php -l routes/auth.php routes/system.php routes/hosts.php routes/ipam.php routes/netboot.php routes/scans.php
php tests/database_migrations.php
DATABASE_PATH=/tmp/fenping-sqlite-test.sqlite3 php tests/sqlite.php
DATABASE_PATH=/tmp/fenping-scan-test.sqlite3 php tests/scan_storage.php
```

After implementation work, run `./restart.sh` as the final deployment check. Use `./restart.sh demo` only when the user explicitly requests replacing the active data with the synthetic screenshot environment.

If PHP or Node is unavailable on the host, run syntax checks inside the container/image.

## Gotchas

- Never commit `.env`, cookies, DB dumps, webhook URLs, or machine-specific private values.
- Do not add a Compose `build:` section. Release images are built and pushed only through `publish.sh`; the explicit `./restart.sh dev` path is limited to a current-platform local image.
- Keep `config.php` generic and environment-driven; do not hardcode local secrets or host-specific values in it.
- Do not revert unrelated dirty work.
- `data/` is live state.
- Keep `db.sql` idempotent.
- For every schema change, update `db.sql`, increment `DATABASE_SCHEMA_VERSION` and its final `PRAGMA user_version`, and add the next immutable `migrations/NNNN_description.sql` file.
- SQLite WAL plus `synchronous=NORMAL` is an intentional SSD-endurance tradeoff. Keep the busy timeout, foreign keys, and integrity checks enabled.
- Keep `DATABASE_PATH` inside the local `data/database` bind mount in production; SQLite must not be placed on a network filesystem.
- `/tmp` and `/run` are tmpfs; persistent state must remain under the documented bind mounts.
- API-triggered doas calls are restricted to exact `/usr/bin/php` CLI commands in `/etc/doas.conf`.
- Inventory commands enqueue scans; `inventory --work` is the lock-protected four-process queue coordinator.
- MAC vendor lookups must remain local. The image contains no OUI seed; boot and the monthly job refresh the complete public IEEE registries through `oui-refresh` instead of sending individual LAN MAC addresses to an external API.
- Lease imports must retain the `(hardware-ethernet, ip)` history and use the staging/upsert transaction in `dnsmasq.leases.php`; do not restore truncate-and-reinsert behavior.
- Automatic inventory discovery must honor each managed host's `scan_profile` and `scan_interval_hours`; manual API/CLI scans intentionally bypass cadence.
- dnsmasq generation happens through `php cli.php hosts`.
- Cron is inside the container; do not look for Ofelia.
- Avoid full `/24` inventory scans unless the user accepts LAN scan traffic.

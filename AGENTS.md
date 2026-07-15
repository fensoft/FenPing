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

- `./fenping.sh restart` normally pulls published images and starts the Compose project. `./fenping.sh dev` explicitly builds the current platform as the local `dev` tag.
- `fenping` uses host networking for DHCP/DNS/TFTP and the web UI.
- `fenping` owns the SQLite database at `/var/lib/fenping/database/fenping.sqlite3`, mounted from `data/database`.
- `boot` runs the blocking network doctor, initializes and checks SQLite, backfills service-change notifications, refreshes the IEEE vendor registry into persistent state and SQL, renders dnsmasq config, starts BusyBox cron, sends the restart notification, regenerates host files, starts PHP-FPM, and runs nginx in the foreground.
- Cron inside the container runs ping, hourly inventory discovery, the four-concurrent-scan queue worker, and lease import jobs.

Do not add a separate database service or split dnsmasq into another container unless the user explicitly asks.

## Important Files

- `docker-compose.yml`: single application service, mounts, capabilities, and logging limits.
- `Dockerfile`: Composer/PHPUnit, Vite, and Alpine runtime stages with nginx/PHP-FPM, SQLite, dnsmasq, cron, and networking tools.
- `fenping.sh`: start, destroy, restart, development, demo, rollback, and multi-architecture publishing commands.
- `demo/`: versioned synthetic screenshot database, netboot files, and backup metadata.
- `boot`: application-service bootstrap and database schema application.
- `composer.json`, `composer.lock`: PHP 8.4 requirements, PSR-4 autoloading, and locked test dependencies.
- `api.php`, `cli.php`, `public/api.php`: thin stable executable entrypoints.
- `src/Application.php`: typed composition root; only this layer wires concrete services.
- `src/Config/`, `src/Database/`, `src/Api/`, `src/Cli/`: configuration, SQLite ownership, HTTP routing/responses, and command dispatch.
- `src/Scan/`: profiles, XML codec, queue/snapshot repositories, results, port changes, and retention.
- `src/Inventory/`, `src/Host/`, `src/Status/`, `src/Netboot/`, `src/Ipam/`: application domain services.
- `src/Dhcp/`, `src/Backup/`, `src/Oui/`, `src/Ping/`, `src/Discord/`, `src/Health/`: operational services.
- `src/Backend/`: class-owned behavior modules composed by the injected `Backend`; production code contains no procedural compatibility layer.
- `db.sql`: canonical idempotent SQLite schema for new databases, tracked with `PRAGMA user_version`.
- `migrations/`: immutable sequential SQLite upgrades for existing nonzero schema versions.
- `frontend/App.vue`: Vue application shell and cross-page orchestration.
- `frontend/router.js`, `frontend/pages/`: Vue Router configuration and route-level inventory, IPAM, services, scans, notifications, host-detail, and netboot components.
- `frontend/components/`, `frontend/composables/`, `frontend/lib/`: shared UI, lifecycle, API, and formatting modules.
- `frontend/locales/*.json`: flat UTF-8 translation catalogs; `en.json` is canonical and every locale must retain identical keys and `{name}` placeholders.
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
docker compose run --rm --no-deps app php /opt/fenping/cli.php doctor [--json] # startup mode, with app stopped
docker exec fenping php /opt/fenping/cli.php doctor --runtime [--json]
docker exec fenping php /opt/fenping/cli.php database
docker exec fenping php /opt/fenping/cli.php ping [--network IPv4/24] [1-254|DEBUG]
docker exec fenping php /opt/fenping/cli.php hosts
docker exec fenping php /opt/fenping/cli.php inventory [--network IPv4/24] [--profile lightweight|standard|deep] [1-254|IPv4]
docker exec fenping php /opt/fenping/cli.php inventory --work
docker exec fenping php /opt/fenping/cli.php scan-port-backfill
docker exec fenping php /opt/fenping/cli.php oui-refresh
docker exec fenping php /opt/fenping/cli.php oui-sync
docker exec fenping php /opt/fenping/cli.php backup [backup.tgz]
docker exec fenping php /opt/fenping/cli.php restore <backup.tgz>
docker exec fenping php /opt/fenping/cli.php discord-restart
```

## API Shape

Routes are converted to typed `Route` objects and dispatched by `src/Api/ApiKernel.php`. Success responses are direct JSON with HTTP 200. Errors use 4xx/5xx plus:

```json
{ "error": "message" }
```

Guest mode is read-only. Mutating routes require authenticated session/body auth.

Host and netboot mutations that affect dnsmasq must go through `FenPing\Dhcp\MutationCoordinator` so database changes and generated configuration stay coordinated. Do not write DHCP fields without `FenPing\Dhcp\HostValidator`.

## Tests Before Commit

Use the applicable subset:

```bash
bash -n boot fenping.sh tests/test.sh
! ./fenping.sh publish 'invalid version!' # validation-only negative check
docker compose config --quiet
docker build --check .
docker build --target backend-test -t fenping-backend-test .
docker build --target frontend-test -t fenping-frontend-test .
docker build -t fenping-check .
composer validate --strict
composer dump-autoload --optimize --strict-psr
composer test
find src tests/Php -name '*.php' -type f -print0 | xargs -0 -n1 php -l
npm run build
npm test
npm run test:browser
```

After implementation work, run `./fenping.sh restart` as the final deployment check. Use `./fenping.sh demo` only when the user explicitly requests replacing the active data with the synthetic screenshot environment.

If PHP or Node is unavailable on the host, run syntax checks inside the container/image.

## Gotchas

- Never commit `.env`, cookies, DB dumps, webhook URLs, or machine-specific private values.
- Do not add a Compose `build:` section. Release images are built and pushed only through `./fenping.sh publish`; the explicit `./fenping.sh dev` path is limited to a current-platform local image.
- Keep `FenPing\Config\AppConfig` generic and environment-driven; do not hardcode local secrets or host-specific values.
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
- Keep locale catalogs aligned with `frontend/locales/README.md`; `npm test` enforces key and placeholder parity.

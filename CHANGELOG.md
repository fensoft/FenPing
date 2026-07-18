# Changelog

All notable FenPing changes are documented here. This file was reconstructed from the complete Git history, from the initial commit on 2019-02-20 through the `1.6` release on 2026-07-14.

Release dates use the corresponding Git tag dates. Application and Docker development now target version 1.8, while the forward-compatible portable backup contract remains version 1.6. The history contains one merge-only commit with no distinct feature to list separately.

## [Unreleased] - 1.8 development

Development after the 1.7 tag.

### Added

- Added named DNS override groups with text-file import, hosts-file IPv4 records, local CNAME aliases, enable/disable controls, transactional dnsmasq validation, live UI updates, and portable backup support.
- Added configurable daily and weekly Discord/Telegram reports for outages, new devices, IP conflicts, changed ports, and expiring certificates, with persisted idempotent scheduling and run status.
- Added authenticated, network-scoped CSV and JSON inventory exports for hosts, DHCP lease history, effective services, scan changes, and retained uptime history.

## [1.7] - 2026-07-16

Development after the 1.6 tag through 2026-07-16.

### Added

- Added cancellable queued and running scans with persisted live progress, timeout phases, and responsive frontend controls.
- Added a Playwright browser suite covering authentication, accessibility, inventory mutations, responsive and RTL layouts, live updates, and topology.
- Added rich metadata, tags, curated icons, scan cadence, and shared saved views for managed hosts and verified Docker containers.
- Added an observed network-topology workspace built from retained traceroute evidence, with filtering, focus, keyboard inspection, and responsive layout.
- Added the running FenPing version beside the application logo so deployed images identify their release.

### Changed

- Updated PHP and frontend dependencies and retained strict Composer, Node, and browser verification.
- Finished backend modularization by replacing the Backend trait facade and route adapter with native controllers, typed CLI commands, and focused services and repositories.
- Consolidated start, destroy, restart, development, demo, rollback, and publishing operations under `fenping.sh` subcommands; destroy preserves persistent data, and the non-executable `boot` source receives its runtime mode from the Dockerfile.

### Fixed

- Fixed effective service counts when recent partial scans inherit open ports from earlier deep results.
- Fixed the ping CLI conflict scan using an undeclared property after backend modularization.

## [1.6] - 2026-07-14

Development after the `1.5` tag through 2026-07-13.

### Added

- Added best-effort Nchan-backed live UI refresh with one scoped SSE stream per browser tab, post-commit API/CLI/cron/worker invalidations, reconnect reconciliation, and no Redis or extra container.
- Added optional real-time Docker network discovery with occupied-/24 mapping, event-stream updates, hourly reconciliation, and guest-accessible UI refresh.
- Added an every-startup network doctor that blocks all services until interface, subnet, on-link router, DHCP pool endpoints, required ports, persistent storage, SQLite WAL, and absence of competing DHCP servers are verified.
- Added an admin-only Doctor page and authenticated API with privileged runtime checks for FenPing-owned listeners, storage, routing, and competing DHCP servers.
- Added configured multi-network ping and inventory scanning with informational explicit-route detection, independent persistent round-robin scheduling, scan-only remote hosts, and an Inventory network selector that labels unrouted networks without disabling them.
- Added read-only category grouping for extra networks, including restored legacy category ranges and HTML-entity decoding for their labels.
- Added a single-file SQLite database at `data/database/fenping.sqlite3`, with automatic schema initialization and integrity checking at boot.
- Added SQLite concurrency safeguards: WAL journaling, `synchronous=NORMAL`, a 30-second busy timeout, foreign-key enforcement, memory-backed temporary storage, `BEGIN IMMEDIATE` writer coordination, and a deterministic `ipv4_num()` function.
- Added ordered, transactional SQLite migrations with strict version sequencing, automatic rollback, immutable numbered files, and upgrade-path tests.
- Added normalized, relational nmap snapshot storage for scan scopes, addresses, hostnames, ports and CPEs, closed-port summaries, OS matches and classes, NSE scripts and nodes, and traceroute hops.
- Added database-neutral version 1.6 JSON backups with manifests, portable table/column data, netboot metadata, forward-compatible 1.x restore behavior, integrity checks, explicit-ID restoration, and demo timestamp shifting.
- Added an offline FenPing 1.2 backup converter. It accepts `.sql`, `.sql.gz`, or `.sql.tgz` data plus an nmap archive containing one `IP.xml` per host, converts legacy leases and categories, imports each XML file as the latest deep scan, and writes a restore-compatible 1.6 archive without requiring MySQL or MariaDB.
- Added converter coverage for ports, services, CPEs, full scan scopes, hostnames, addresses, OS matches, NSE script trees, traceroute data, compressed SQL members, archive safety checks, and real-world restore validation.
- Added a dependency-closure tool that retains only the NSE libraries and data required by Nmap's default scripts.
- Added a shared Vue icon component so only the Tabler icons used by FenPing are bundled.
- Added category-wide expand/collapse controls and persisted category collapse state.
- Added compact inventory summaries showing device, online, and new-device totals.
- Added category bars with device and online counts.
- Added responsive inventory metadata that combines vendor, MAC address, activity, and services on narrow screens.
- Added persistent segmented tri-state Inventory filters for status, importance, and new-device state, including compatibility with saved checkbox-era preferences.
- Added ten browser-interface languages—English, Simplified Chinese, Spanish, French, Arabic, Brazilian Portuguese, Indonesian, Japanese, Russian, and German—with an extensible Auto/manual sidebar selector, automatic browser-language detection, persisted preferences, Arabic RTL layout, and English fallback text.

### Changed

- Inventory now hides unreserved hosts after they have remained Down longer than `INVENTORY_DOWN_RETENTION_DAYS` (7 days by default), without deleting their history.
- Replaced the legacy `NETWORK` prefix with required canonical `DHCP_NETWORK` and optional comma-separated `EXTRA_NETWORKS`; dnsmasq, IPAM, host/category mutations, and netboot assignments remain confined to the DHCP `/24`.
- Changed new managed-host scan defaults from Deep hourly to Standard daily while preserving every existing host's configured schedule.
- Changed unmanaged-device automatic scans from Deep hourly to Lightweight daily and staggered their first scans across deterministic UTC hour slots.
- Replaced the MariaDB Compose service with SQLite and returned the deployment to a single application container.
- Removed MariaDB credentials, socket sharing, client packages, server configuration, stored procedures, advisory locks, and MariaDB-specific SQL.
- Reimplemented ping-status batching, lease imports, scan queue claims, DHCP transactions, backup/restore, schema inspection, and sequence handling using native SQLite transactions and upserts.
- Replaced the Ubuntu/Apache runtime with Alpine Linux, nginx, and PHP-FPM.
- Replaced `sudo` with `doas`, GNU timeout usage with BusyBox timeout, and Bash-specific boot behavior with portable shell behavior where possible.
- Switched the runtime to explicit minimal packages and removed dependencies that were no longer needed after the Alpine/nginx/SQLite migration.
- Changed completed scan storage from retained XML blobs to normalized facts in SQLite. Compatibility XML is now rendered on demand.
- Changed scan deduplication to distinguish semantic result hashes from exact retained-content hashes.
- Changed the OUI workflow so the Docker image no longer embeds an IEEE vendor seed. The complete MA-L, MA-M, MA-S, and IAB registries are downloaded, validated, atomically cached, and synchronized at startup and monthly.
- Replaced cURL usage for Discord and OUI downloads with native HTTPS streams and explicit TLS/timeouts.
- Reduced Nmap data to the default script category, removed the bundled MAC-prefix database, and pruned unused NSE libraries and data. Inventory vendor lookup remains backed by FenPing's local IEEE database.
- Converted the logo to a 640-pixel lossless WebP asset and added PurgeCSS processing for unused Tabler styles.
- Changed application navigation and operational controls to a left sidebar, removing the former top bar.
- Moved refresh, theme, and authentication controls into the sidebar and kept their labels available at desktop width.
- Redesigned Inventory as a dense, fixed-layout list with small but shape-coded status indicators, a combined Device column, nearby IP addresses, activity text, service counts, hover actions, and approximately thirty visible devices on a typical desktop screen.
- Changed status indicators to combine color with check, cross, Wi-Fi, or question-mark icons for improved color-vision accessibility.
- Changed narrow Inventory layouts to a two-line responsive presentation without horizontal scrolling.
- Replaced boxed category expand/collapse buttons with compact carets while retaining full-row category activation.
- Standardized page-level refresh placement for Services, Notify, IPAM, and Scans.
- Changed normal `restart.sh` behavior to pull the configured published image; local builds are isolated to `./restart.sh dev`.
- Reduced frontend installation layers and Docker build context to improve build-cache reuse and final image size.

### Fixed

- Fixed startup with an intentionally omitted `DHCP_DEFAULT_ROUTER` by skipping router reachability and explicitly suppressing the dnsmasq router option.
- Fixed retained successful scans incorrectly marking scan-only extra-network hosts online when their latest ping state is Down.
- Fixed SQLite queue claims so concurrent coordinators cannot claim duplicate jobs or exceed four running scans.
- Fixed transactional DHCP coordination under SQLite by acquiring the database writer before applying validated dnsmasq candidates.
- Fixed database backup consistency without relying on MariaDB table locks.
- Fixed scan details and history after XML removal by reconstructing compatible responses from normalized tables.
- Fixed inventory width changes when every category is collapsed.
- Fixed page header movement caused by collapsed Inventory contents.
- Fixed compact scan/retry action sizing and empty guest action columns.
- Fixed category carets overlapping category names.
- Fixed legacy 1.2 category names retaining HTML entities after offline backup conversion.
- Fixed nginx temporary-directory ownership so large scan-detail responses and buffered uploads remain accessible to the `www-data` worker after restart.

### Security and hardening

- nginx serves only `/var/www/public`, rejects dotfiles, private extensions, backup/runtime paths, and direct PHP/runtime data access.
- Runtime code remains under `/opt/fenping`; persistent database, backup, state, dnsmasq, and netboot data remain outside the document root.
- SQLite restore validates foreign keys and database integrity before accepting converted or portable backups.
- Legacy archive conversion validates filenames, IPv4 ownership, archive sizes, duplicate results, XML structure, and safe output replacement.

### Upgrade notes

- Replace `NETWORK=x.y.z` with `DHCP_NETWORK=x.y.z.0/24`. Optionally configure `EXTRA_NETWORKS` with comma-separated `/24` CIDRs. Add host routes when needed for actual reachability; FenPing reports route status but does not create routes or disable unrouted selections.
- The first SQLite startup intentionally does not import the old MariaDB directory. `data/db` is left untouched for rollback or offline recovery.
- A version 1.6 JSON backup produced by the MariaDB-based application can be restored into SQLite.
- FenPing 1.2 SQL and nmap archives must first be converted with `tools/convert-v1.2-backup.py`.
- Pre-1.6 SQL dumps are not accepted directly by the normal restore command.
- The image no longer contains an OUI database, so initial startup requires access to the public IEEE registry unless a previously cached registry exists in persistent state.

## [1.5] - 2026-07-11

### Added

- Added versioned backup and restore commands for application data, scan history, and netboot files, together with appliance-focused operational documentation and `AGENTS.md` maintenance guidance.
- Added a reproducible synthetic demo database, demo netboot files, timestamp-shifted restores, automatic preservation of current state, and a screenshot gallery covering Inventory, Services, Notify, IPAM, Scans, Netboot, host details, and scan details.
- Added a four-worker background scan queue. Discovery remains short, scan requests return HTTP `202`, and queued scans are claimed and run with bounded concurrency.
- Added queued, running, completed, failed, and timeout scan metadata, durations, errors, and lock-protected queue coordination.
- Added Lightweight, Standard, and Deep scan profiles with profile-specific commands and timeout budgets.
- Added per-host scan profiles and cadence from disabled through 8,760 hours; manual scans continue to bypass cadence.
- Added database-backed scan snapshots so quick/partial scans do not overwrite richer full scans.
- Added effective scan merging: partial observations override matching ports while previous deep-only ports and richer OS/version data remain visible.
- Added selection of every 100% OS match, falling back to the single best match when no perfect result exists.
- Added service-change detection for new, removed, and version-changed services, including initial appearances, Discord notifications, a Notify section, and chronological backfill from retained scans.
- Added a Services route listing every current open service per computer from the latest effective scan, grouped by host and linking HTTP/HTTPS services in a new window.
- Added Device Onboarding and IPAM with DHCP-pool capacity/utilization, seven-day pending devices, reversible MAC approvals, approved offline devices, Inventory warning state, and a Reserve shortcut into the existing fixed-host workflow.
- Added transactional DHCP mutations: host/netboot changes are validated, rendered to candidate files, checked with `dnsmasq --test`, committed with the database, and rolled back or recovered on failure.
- Added local IEEE OUI resolution using the complete public registries, atomic state refresh, SQL synchronization, startup loading, monthly updates, negative behavior for locally administered addresses, and removal of synchronous per-MAC external lookups.
- Added transactional lease staging/upserts with preserved first-seen and last-seen history, inactive lease retention, proper timestamps, and atomic reader visibility.
- Added a Vue Router frontend split into route pages, shared modal components, API/formatting helpers, abortable loaders, a reactive clock, and page controllers.
- Added keyboard-accessible modals with focus trapping, Escape/backdrop close behavior, inert background content, and focus restoration.
- Added host detail routes for managed and unmanaged devices, including an IP-based public detail API.
- Added a persistent left navigation sidebar and later moved primary application navigation into it.
- Added labeled Inventory actions on desktop and accessible icon-only actions at narrow widths.
- Added a local `./restart.sh dev` workflow that builds the current platform as `FENPING_IMAGE:dev` without allowing Compose to pull over it.
- Added multi-platform Docker publishing for `linux/arm64`, `linux/amd64`, and `linux/arm/v7`, including automatic binfmt installation, reusable Buildx builder, version/latest tags, provenance, SBOM, and manifest inspection.
- Added FenPing logo/favicon branding to the sidebar and browser assets.

### Changed

- Split MariaDB into a dedicated `mariadb:11.8` Compose service with networking disabled, a shared Unix socket, a persistent database directory, health checks, and bounded logs.
- Changed normal restart behavior to pull a published Docker Hub image before replacing the running service, so missing releases do not stop the current appliance.
- Moved the web document root to a minimal `/var/www/public` containing only the built frontend, API entrypoint, and public assets; application PHP moved to `/opt/fenping`.
- Moved backups, scan state, netboot images, `.env`, and runtime data out of the document root and added explicit defense-in-depth web denial rules.
- Changed nmap deep scans to `-T3 -A -p- -sS` with a two-hour timeout and termination grace period.
- Changed scan tables to show Duration rather than Ended, limited duration formatting to two units, and rebalanced columns for timestamps and errors.
- Changed the notification view to include device name and MAC vendor.
- Changed quick scan display to merge the selected result with the previous full scan.
- Changed Inventory and host details so scan history lives under History, while hostname, OS, and ports appear directly on the detail page.
- Changed Inventory row navigation so the whole device row opens a modal detail view; separate Details and View Scan actions were removed.
- Changed edit, delete, add, create, rename, and scan actions to compact icon controls with tooltips and distinct colors.
- Changed Services to show host information only once at the start of each group.
- Changed Netboot tables to allocate more width to filenames and less to secondary columns.
- Changed the guest Netboot view to remove the misleading login prompt.
- Changed route loading to cancel stale requests and changed running durations to update without unrelated rerenders.
- Reduced persistent writes through longer database flush windows, memory-backed temporary/runtime files, disabled access/verbose logs, compressed log rotation, lazy PHP sessions, conditional dnsmasq file replacement, stable ping-history extension, and reduced OUI rewrites.
- Changed OUI refresh to use the local public registry rather than a legacy `vendors` cache table.

### Fixed

- Made `IFACE` mandatory at boot, documented it in the environment template, and bound dnsmasq explicitly to the selected DHCP/DNS/TFTP interface.
- Corrected network-prefix handling in CLI and system routes so scans use the configured network rather than a stale `192.168.0` default.
- Added and documented static appliance interface handling for host-network deployments.
- Fixed scan timestamps and durations to interpret server dates consistently in UTC and display them in the browser's local timezone.
- Fixed nmap processes that could run indefinitely by adding process-level timeouts and explicit timeout states.
- Fixed deleted DHCP reservations remaining active by regenerating dnsmasq configuration as part of the same transactional mutation.
- Added strict hostname, MAC, IP, router, DNS, and netboot validation before data can reach dnsmasq configuration.
- Fixed lease readers observing empty or partially imported tables.
- Fixed service-change notifications that appeared empty and preserved services learned by earlier full scans when newer scans were partial.
- Fixed netboot column sizing and guest image layout.
- Fixed database container health checks that attempted passwordless root access.
- Fixed Inventory action visibility for guest users and normalized scan/retry button dimensions.
- Fixed direct host detail navigation and unmanaged-device scan history.
- Fixed boot portability and replaced external scheduler/lock assumptions with internal CLI locks.

### Security

- Added validated netboot extension and content checks for EFI, iPXE/PXE, PXELINUX, Linux kernel, and iPXE script formats.
- Explicitly disabled PHP execution in the netboot directory.
- Prevented direct serving of existing private files from runtime directories under the former web root.
- Kept MariaDB inaccessible from the host and LAN by using only a shared Unix socket.

## [1.4] - 2026-07-08

### Added

- Replaced the server-rendered Smarty interface with a static Vue 3/Vite frontend styled with Tabler.
- Added JSON API modules for inventory, authentication, system status, hosts/categories, scans, history, and netboot.
- Added dark mode, refresh-on-load, collapsible categories, compact inventory presentation, router/repeater indicators, and improved host editing.
- Added dnsmasq-based DHCP, DNS, TFTP, generated host/option files, lease parsing, and `php cli.php hosts` configuration regeneration.
- Added a PHP nmap inventory scanner with automatic discovery and per-host quick scans.
- Added scan-result popups, scan metadata/history, host status, ports, duration/error display, and Inventory search/down/important/new filters.
- Added stability summaries with uptime percentage, transitions, longest outage, and current-state duration.
- Added `/api/health` with web, PHP, database, dnsmasq, cron, ping, and scan status.
- Added session login and read-only guest mode; mutating admin actions require authentication.
- Added a 24-hour Notify page for status transitions.
- Added netboot image upload, download, deletion, and per-host assignment through dnsmasq/TFTP.
- Added optional Discord webhook notifications for host changes and application restarts.
- Added a queued scan workflow and combined host detail views at the end of the release cycle.

### Changed

- Removed legacy Smarty templates, PHP-rendered pages, image controls, Composer runtime dependencies, and shell inventory scanning.
- Replaced ISC DHCP and BIND with dnsmasq.
- Refactored the monolithic API dispatcher into route modules.
- Changed scan history retention and cleanup, removed stale temporary scan files, and improved report rendering through the bundled nmap XSL.
- Moved Add Category into the Inventory table header.
- Reduced container capabilities and privileged runtime access.
- Refreshed documentation and replaced obsolete screenshots with current Inventory, Notify, Scan, and Netboot illustrations.

### Fixed

- Fixed sparse inventory rows and null status values in the Vue/API boundary.
- Fixed scan history cleanup and latest-result selection.
- Fixed stability calculations, intermittent-state presentation, and history row highlighting.
- Fixed host edit modal spacing and responsive layout.
- Fixed route dispatch consistency and API error responses.

## [1.3] - 2026-07-02

### Added

- Added a PHP CLI entrypoint, PDO database singleton, and native PHP ping/ARP scanner.
- Added prepared, batched status writes and normalized status-history handling.
- Added host-network container support and boot-time MariaDB initialization.
- Added automatic creation of DHCP lease/host files and resilient DHCP startup behavior.
- Added support for short and full IPv4 category boundaries.

### Changed

- Updated the runtime base to Ubuntu 26.04 and modern PHP-compatible dependencies.
- Updated Smarty to 4.5.7, registered modifiers explicitly, and guarded missing/null template values.
- Replaced the shell ping scanner with PHP raw-socket/system ping logic.
- Changed Inventory normalization to tolerate incomplete database and lease data without warnings.
- Optimized Composer installation and container boot behavior.

### Fixed

- Fixed DHCP regeneration when PID or lease files do not yet exist.
- Fixed lease imports by using parameterized inserts and tolerating missing source files.
- Fixed inconsistent ping status normalization and duplicate history behavior.
- Fixed category rendering across configured networks.

## [1.2] - 2024-02-10

The `1.2` tag includes the original 2019-2020 application, its Docker conversion, the 2023 release work, and a final missing nmap stylesheet added in 2024. The release was first marked in the repository on 2023-07-20 and the tag points to the 2024-02-10 follow-up.

### Initial application (2019)

- Created FenPing as a PHP/Smarty LAN inventory backed by MySQL/MariaDB.
- Added configured host/category management, shell-based ping status updates, network inventory scanning, and generated DHCP host files.
- Added the initial SQL schema, Composer dependencies, icons, and installation documentation.
- Added a JSON API and ISC DHCP lease parsing.
- Moved reusable database/inventory logic into shared PHP functions.
- Added database structure fixtures, a smoke-test script, an environment config template, and Apache test protections.
- Removed committed local configuration/dump data and improved installation/security guidance.

### Network and history improvements (2020)

- Fixed lease imports, unknown MAC-vendor handling, configured network selection, and private file access through Apache rules.
- Added configurable network interface handling and verbose operational errors.
- Added host router, DNS, web-interface, and repeater-related options.
- Added status history and an interactive history view.
- Suppressed short intermittent transitions while highlighting sustained outages.
- Replaced the simple nmap port check with a richer full scan and formatted report.
- Added create/edit/delete/category controls and a visible Add action.
- Added the first README screenshot gallery and expanded usage documentation.

### Containerization and release (2022-2024)

- Added the first Dockerfile, container boot script, service configuration, DHCP configuration, persistent restart helper, and Docker ignore rules.
- Added environment-driven container installation and restart behavior.
- Added generated DHCP configuration from environment values.
- Added database indexes to improve history performance and optimized shell ping scans.
- Cleaned the Docker image and updated Composer dependencies.
- Added browser favicon assets and header integration.
- Added syslog-ng for container logging and DHCP-related service visibility.
- Marked the 1.2 release in `restart.sh` and refreshed installation documentation.
- Added the missing bundled `res/xsl/nmap.xsl` report stylesheet before the final tag.

[Unreleased]: https://github.com/fensoft/FenPing/compare/1.7...HEAD
[1.7]: https://github.com/fensoft/FenPing/compare/1.6...1.7
[1.6]: https://github.com/fensoft/FenPing/compare/1.5...1.6
[1.5]: https://github.com/fensoft/FenPing/compare/1.4...1.5
[1.4]: https://github.com/fensoft/FenPing/compare/1.3...1.4
[1.3]: https://github.com/fensoft/FenPing/compare/1.2...1.3
[1.2]: https://github.com/fensoft/FenPing/commits/1.2

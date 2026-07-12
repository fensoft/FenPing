<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Backend\Backend;
use FenPing\Backup\BackupService;
use FenPing\Database\DatabaseManager;
use Throwable;


final class CliKernel
{
    /** @var array<string, Command> */
    private array $commands;

    public function __construct(
        private readonly Backend $backend,
        private readonly DatabaseManager $database,
        private readonly BackupService $backups,
    )
    {
        $this->commands = [
            'database' => new CallableCommand(fn(array $args): int => $this->database($args)),
            'ping' => new CallableCommand(fn(array $args): int => $this->backend->runLockedCliCommand(
                '/tmp/ping.lck',
                'ping scan',
                fn(): int => $this->backend->runPingCommand($args),
            )),
            'hosts' => new CallableCommand(fn(array $args): int => $this->backend->runHostsCommand($args)),
            'inventory' => new CallableCommand(fn(array $args): int => $this->inventory($args)),
            'scan-port-backfill' => new CallableCommand(fn(array $args): int => $args === []
                ? $this->backend->runScanPortBackfillCommand()
                : $this->usage()),
            'oui-refresh' => new CallableCommand(fn(array $args): int => $this->backend->runLockedCliCommand(
                '/tmp/oui-refresh.lck',
                'OUI refresh',
                fn(): int => $this->backend->runIeeeOuiRefreshCommand($args),
            )),
            'oui-sync' => new CallableCommand(fn(array $args): int => $this->backend->runIeeeOuiSyncCommand($args)),
            'dnsmasq-leases' => new CallableCommand(fn(array $args): int => $this->backend->runLockedCliCommand(
                '/tmp/dnsmasq-leases.lck',
                'dnsmasq lease import',
                fn(): int => $this->backend->runDnsmasqLeasesCommand($args),
            )),
            'discord-restart' => new CallableCommand(fn(array $args): int => $args === []
                ? $this->backend->runDiscordRestartCommand()
                : $this->usage()),
            'backup' => new CallableCommand(fn(array $args): int => $this->backups->backup($args)),
            'restore' => new CallableCommand(fn(array $args): int => $this->backups->restore($args)),
            'backup-verify' => new CallableCommand(fn(array $args): int => $this->backups->verify($args)),
            'backup-maintenance' => new CallableCommand(fn(array $args): int => $this->backups->maintenance($args)),
            'backup-restore-stage' => new CallableCommand(fn(array $args): int => $this->backups->restoreStage($args)),
        ];
    }

    public function run(array $argv): int
    {
        $name = (string) ($argv[1] ?? '');
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            return $this->usage();
        }
        return $command->run(array_slice($argv, 2));
    }

    private function database(array $arguments): int
    {
        if ($arguments !== []) {
            return $this->usage();
        }
        try {
            $this->database->initialize();
            $errors = $this->database->integrityErrors();
            if ($errors !== []) {
                throw new \RuntimeException('database integrity check failed: ' . implode('; ', $errors));
            }
            echo 'SQLite database ready: ' . $this->database->connection()
                ->query('PRAGMA database_list')->fetchColumn(2) . PHP_EOL;
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function inventory(array $arguments): int
    {
        if (($arguments[0] ?? '') === '--work') {
            return $this->backend->runLockedCliCommand(
                '/tmp/fenping-inventory-worker.lck',
                'inventory worker',
                fn(): int => $this->backend->runInventoryCommand($arguments),
            );
        }
        if (($arguments[0] ?? '') === '--run-job') {
            return $this->backend->runInventoryCommand($arguments);
        }
        return $this->backend->runLockedCliCommand(
            '/tmp/inventory-discovery.lck',
            'inventory scheduling',
            fn(): int => $this->backend->runInventoryCommand($arguments),
        );
    }

    private function usage(): int
    {
        fwrite(STDERR, "Usage: php cli.php ping [--network IPv4/24] [1-254|DEBUG]" . PHP_EOL);
        fwrite(STDERR, "       php cli.php database" . PHP_EOL);
        fwrite(STDERR, "       php cli.php hosts" . PHP_EOL);
        fwrite(STDERR, "       php cli.php inventory [--network IPv4/24] [--profile lightweight|standard|deep] [1-254|IPv4] (queue scans)" . PHP_EOL);
        fwrite(STDERR, "       php cli.php inventory --work" . PHP_EOL);
        fwrite(STDERR, "       php cli.php scan-port-backfill" . PHP_EOL);
        fwrite(STDERR, "       php cli.php oui-refresh" . PHP_EOL);
        fwrite(STDERR, "       php cli.php oui-sync" . PHP_EOL);
        fwrite(STDERR, "       php cli.php dnsmasq-leases" . PHP_EOL);
        fwrite(STDERR, "       php cli.php discord-restart" . PHP_EOL);
        fwrite(STDERR, "       php cli.php backup [backup.tgz]" . PHP_EOL);
        fwrite(STDERR, "       php cli.php backup-verify <backup.tgz>" . PHP_EOL);
        fwrite(STDERR, "       php cli.php backup-maintenance <daily|verify>" . PHP_EOL);
        fwrite(STDERR, "       php cli.php restore <backup.tgz>" . PHP_EOL);
        return 2;
    }
}

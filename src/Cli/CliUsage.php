<?php

declare(strict_types=1);

namespace FenPing\Cli;


final readonly class CliUsage
{
    public function write(): int
    {
        fwrite(STDERR, "Usage: php cli.php doctor [--runtime] [--json]" . PHP_EOL);
        fwrite(STDERR, "Usage: php cli.php docker-networks-refresh [--api]" . PHP_EOL);
        fwrite(STDERR, "Usage: php cli.php docker-networks-watch" . PHP_EOL);
        fwrite(STDERR, "Usage: php cli.php ping [--network IPv4/24] [1-254|DEBUG]" . PHP_EOL);
        fwrite(STDERR, "       php cli.php database|database-check" . PHP_EOL);
        fwrite(STDERR, "       php cli.php hosts" . PHP_EOL);
        fwrite(STDERR, "       php cli.php inventory [--network IPv4/24] [--profile lightweight|standard|deep] [1-254|IPv4] (queue scans)" . PHP_EOL);
        fwrite(STDERR, "       php cli.php inventory --work" . PHP_EOL);
        fwrite(STDERR, "       php cli.php scan-port-backfill" . PHP_EOL);
        fwrite(STDERR, "       php cli.php status-clean [retention-days] [max-events-per-ip]" . PHP_EOL);
        fwrite(STDERR, "       php cli.php oui-refresh" . PHP_EOL);
        fwrite(STDERR, "       php cli.php oui-sync" . PHP_EOL);
        fwrite(STDERR, "       php cli.php dnsmasq-leases" . PHP_EOL);
        fwrite(STDERR, "       php cli.php notify-restart" . PHP_EOL);
        fwrite(STDERR, "       php cli.php discord-restart" . PHP_EOL);
        fwrite(STDERR, "       php cli.php backup [backup.tgz]" . PHP_EOL);
        fwrite(STDERR, "       php cli.php backup-verify <backup.tgz>" . PHP_EOL);
        fwrite(STDERR, "       php cli.php backup-maintenance <daily|verify>" . PHP_EOL);
        fwrite(STDERR, "       php cli.php restore <backup.tgz>" . PHP_EOL);
        return 2;
    }
}

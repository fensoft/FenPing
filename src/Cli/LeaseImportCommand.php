<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Dhcp\LeaseImporter;
use Throwable;

final readonly class LeaseImportCommand implements Command
{
    public function __construct(private LeaseImporter $leases) {}
    public function run(array $arguments): int
    {
        if ($arguments !== []) { fwrite(STDERR, 'Usage: php cli.php dnsmasq-leases' . PHP_EOL); return 2; }
        try { $this->leases->import(); return 0; }
        catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); return 1; }
    }
}

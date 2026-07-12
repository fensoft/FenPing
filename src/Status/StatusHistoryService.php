<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;
use FenPing\Support\Clock;

final readonly class StatusHistoryService
{
    public function __construct(private Backend $backend, private DatabaseManager $database, private Clock $clock)
    {
    }

    public function response(string $ip): array { return $this->backend->get_history_response($ip); }
    public function history(string $ip, int $blipSeconds = 120): array { return $this->backend->get_history($ip, $blipSeconds); }
    public function summary(array $rows): array { return $this->backend->get_history_summary($rows); }
    public function mergeBlips(array $rows, int $seconds): array { return $this->backend->merge_history_blips($rows, $seconds); }
}

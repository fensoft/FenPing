<?php

declare(strict_types=1);

namespace FenPing\Ipam;

use FenPing\Network\Ipv4Network;

interface IpConflictScanner
{
    public function scan(Ipv4Network $network): array;
}

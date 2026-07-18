<?php

declare(strict_types=1);

namespace FenPing\Realtime;

enum LiveUpdateScope: string
{
    case Hosts = 'hosts';
    case Status = 'status';
    case Scans = 'scans';
    case Conflicts = 'conflicts';
    case Leases = 'leases';
    case Netboot = 'netboot';
    case Backups = 'backups';
    case Operations = 'operations';
    case Networks = 'networks';
    case Vendors = 'vendors';
    case Dns = 'dns';
    case All = 'all';
}

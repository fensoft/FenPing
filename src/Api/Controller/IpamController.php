<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Backend\Backend;

use FenPing\Dhcp\HostValidator;
use FenPing\Ipam\IpamService;

final readonly class IpamController implements Controller
{
    public function __construct(private Backend $backend, private IpamService $ipam, private HostValidator $validator, private RouteAdapter $adapter)
    {
    }

    public function routes(): array
    {
        return $this->adapter->adapt($this->backend->ipamApiRoutes());
    }
}

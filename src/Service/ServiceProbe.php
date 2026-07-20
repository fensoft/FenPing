<?php

declare(strict_types=1);

namespace FenPing\Service;

interface ServiceProbe
{
    public function check(array $service): ServiceProbeResult;
}

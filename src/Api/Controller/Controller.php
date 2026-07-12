<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\Route;

interface Controller
{
    /** @return list<Route> */
    public function routes(): array;
}

<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Topology\TopologyService;

final readonly class TopologyController implements Controller
{
    public function __construct(private TopologyService $topology)
    {
    }

    public function routes(): array
    {
        return [new Route('GET', '/topology', fn(Request $request, array $params): array => $this->topology->snapshot())];
    }
}

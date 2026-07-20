<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Inventory\BulkInventoryService;
use FenPing\Realtime\LiveUpdateScope;
use InvalidArgumentException;

final readonly class BulkInventoryController implements Controller
{
    public function __construct(private BulkInventoryService $bulk)
    {
    }

    public function routes(): array
    {
        return [
            new Route(
                'POST',
                '/inventory/bulk-actions',
                fn(Request $request, array $params): array => $this->execute($request),
                AuthPolicy::Session,
                [LiveUpdateScope::Hosts],
            ),
        ];
    }

    private function execute(Request $request): array
    {
        try {
            return $this->bulk->execute($request->body());
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }
    }
}

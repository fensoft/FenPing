<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Response;
use FenPing\Api\Route;
use FenPing\Export\InventoryExportService;
use FenPing\Network\NetworkManager;
use FenPing\Network\NetworkPolicyException;
use InvalidArgumentException;

final readonly class ExportController implements Controller
{
    public function __construct(private InventoryExportService $exports, private NetworkManager $networks) {}

    public function routes(): array
    {
        return [new Route(
            'GET',
            '/exports/{dataset}',
            fn(Request $request, array $params): Response => $this->download($params['dataset'], $request),
            AuthPolicy::Session,
        )];
    }

    private function download(string $dataset, Request $request): Response
    {
        $format = $request->query['format'] ?? 'csv';
        $network = $request->query['network'] ?? null;
        if (!is_string($format) || ($network !== null && !is_string($network))) {
            throw new HttpException(400, 'invalid export request');
        }
        try {
            return $this->exports->download($dataset, strtolower($format), $this->networks->forCidr($network, false));
        } catch (InvalidArgumentException|NetworkPolicyException $error) {
            throw new HttpException(400, $error->getMessage());
        }
    }
}

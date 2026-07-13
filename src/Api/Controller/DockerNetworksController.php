<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\HttpException;
use FenPing\Api\RequestContext;
use FenPing\Api\Route;
use FenPing\Docker\DockerNetworkRefreshGateway;
use RuntimeException;

final readonly class DockerNetworksController implements Controller
{
    public function __construct(private DockerNetworkRefreshGateway $refresh)
    {
    }

    public function routes(): array
    {
        return [
            new Route('POST', '/networks/refresh', function (array $params): array {
                if ($_GET !== [] || (RequestContext::body() ?? []) !== []) {
                    throw new HttpException(400, 'Docker network refresh accepts no parameters');
                }
                try {
                    return $this->refresh->refresh();
                } catch (RuntimeException) {
                    throw new HttpException(503, 'Docker network refresh unavailable; using the last successful snapshot');
                }
            }),
        ];
    }
}

<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Audit\AuditLogService;

final readonly class AuditController implements Controller
{
    public function __construct(private AuditLogService $audit)
    {
    }

    public function routes(): array
    {
        return [
            new Route(
                'GET',
                '/audit',
                fn(Request $request, array $params): array => $this->audit->page($request->query),
                AuthPolicy::Session,
            ),
        ];
    }
}

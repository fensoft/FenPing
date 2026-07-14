<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Doctor\DoctorReportProvider;
use RuntimeException;

final readonly class DoctorController implements Controller
{
    public function __construct(private DoctorReportProvider $doctor)
    {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/doctor', function (Request $request, array $params): array {
                try {
                    return $this->doctor->runtimeReport();
                } catch (RuntimeException $error) {
                    throw new HttpException(503, 'doctor unavailable: ' . $error->getMessage());
                }
            }, AuthPolicy::Session),
        ];
    }
}

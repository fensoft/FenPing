<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\FileResponse;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Backup\BackupService;
use InvalidArgumentException;
use RuntimeException;

final readonly class BackupController implements Controller
{
    public function __construct(private BackupService $backups) {}

    public function routes(): array
    {
        return [
            new Route('GET', '/backups', fn(Request $request, array $params): array => $this->backups->apiList(), AuthPolicy::Session),
            new Route('GET', '/backups/{filename}/file', function (Request $request, array $params): FileResponse {
                try {
                    $path = $this->backups->downloadPath((string) $params['filename']);
                } catch (InvalidArgumentException $error) {
                    throw new HttpException(400, $error->getMessage());
                } catch (RuntimeException $error) {
                    throw new HttpException(404, $error->getMessage());
                }
                return new FileResponse($path, basename($path), 'application/gzip');
            }, AuthPolicy::Session),
        ];
    }
}

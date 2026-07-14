<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\FileResponse;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Netboot\NetbootImageService;
use FenPing\Realtime\LiveUpdateScope;
use OutOfBoundsException;
use RuntimeException;

final readonly class NetbootController implements Controller
{
    public function __construct(
        private NetbootImageService $netboot,
        private MutationCoordinator $mutations,
    ) {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/netboot/images', fn(Request $request, array $params): array => ['images' => $this->netboot->all()]),
            new Route(
                'POST',
                '/netboot/images',
                fn(Request $request, array $params): array => $this->create($request),
                AuthPolicy::Session,
                [LiveUpdateScope::Netboot],
            ),
            new Route('GET', '/netboot/images/{id:int}', fn(Request $request, array $params): array => $this->find($params['id'])),
            new Route('GET', '/netboot/images/{id:int}/file', fn(Request $request, array $params): FileResponse => $this->file($params['id'])),
            new Route(
                'DELETE',
                '/netboot/images/{id:int}',
                fn(Request $request, array $params): array => $this->delete($params['id']),
                AuthPolicy::Session,
                [LiveUpdateScope::Netboot, LiveUpdateScope::Hosts],
            ),
        ];
    }

    private function find(int $id): array
    {
        try {
            return $this->netboot->withHostCount($id);
        } catch (OutOfBoundsException $error) {
            throw new HttpException(404, $error->getMessage());
        }
    }

    private function file(int $id): FileResponse
    {
        $image = $this->netboot->find($id);
        if ($image === false) {
            throw new HttpException(404, 'netboot image not found');
        }
        $path = $this->netboot->path($image);
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException(404, 'netboot file not found');
        }
        return new FileResponse($path, basename((string) ($image['original_name'] ?: $image['filename'])));
    }

    private function create(Request $request): array
    {
        try {
            return $this->netboot->create($request->files['file'] ?? [], (string) ($request->post['name'] ?? ''));
        } catch (RuntimeException $error) {
            throw new HttpException(400, $error->getMessage());
        }
    }

    private function delete(int $id): array
    {
        try {
            $change = $this->mutations->commit(fn(): array => $this->netboot->delete($id));
        } catch (OutOfBoundsException $error) {
            throw new HttpException(404, $error->getMessage());
        }
        $this->netboot->deleteFile($change['result']);
        return ['deleted' => true, 'log' => $change['log']];
    }
}

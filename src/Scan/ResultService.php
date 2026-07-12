<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Backend\Backend;

final readonly class ResultService
{
    public function __construct(private Backend $backend,
        private ScanJobRepository $jobs,
        private SnapshotRepository $snapshots,
        private ProfileCatalog $profiles,
    ) {
    }

    public function forHost(string $ip, ?int $id = null): array
    {
        return $this->backend->scanJsonResponse($ip, $id);
    }

    public function mergePartialWithDeep(array $partial, array $deep, array $deepMetadata): array
    {
        return $this->backend->scanMergePartialWithDeep($partial, $deep, $deepMetadata);
    }

    public function xmlUrl(string $ip, ?int $id = null): string
    {
        return $this->backend->scanXmlUrl($ip, $id);
    }
}

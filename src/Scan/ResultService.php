<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Api\HttpException;
use FenPing\Api\Response;
use FenPing\Config\AppConfig;
use FenPing\Vendor\VendorLookup;

final readonly class ResultService
{
    public function __construct(
        private AppConfig $config,
        private ScanResultStore $results,
        private ScanJobRepository $jobs,
        private SnapshotRepository $snapshots,
        private ProfileCatalog $profiles,
        private XmlCodec $codec,
        private VendorLookup $vendors,
    ) {
    }

    public function forHost(string $ip, ?int $id = null): array
    {
        $metadata = $id === null ? $this->jobs->bestResult($ip) : $this->jobs->byId($ip, $id);
        if ($id !== null && $metadata === null) {
            throw new HttpException(404, 'scan not found');
        }

        $scan = $this->snapshots->read($ip, $metadata);
        $deepMetadata = $metadata !== null
            && $this->profiles->isPartial((string) ($metadata['mode'] ?? ''))
            ? $this->jobs->previousResult($ip, 'deep', (int) $metadata['id'])
            : null;
        if ($scan === null && $deepMetadata === null) {
            throw new HttpException(404, 'scan not found');
        }

        $scan ??= [
            'ip' => $ip,
            'args' => '',
            'started' => '',
            'status' => $metadata['status'] ?? '',
            'uptime' => '',
            'duration' => $metadata['duration'] ?? null,
            'ports_count' => 0,
            'addresses' => [],
            'hostnames' => [],
            'os' => [],
            'ports' => [],
            'metadata' => $metadata,
            'xml' => null,
        ];

        if ($deepMetadata !== null) {
            $deepScan = $this->snapshots->read($ip, $deepMetadata);
            if ($deepScan !== null) {
                $scan = $this->mergePartialWithDeep($scan, $deepScan, $deepMetadata);
            }
        }
        if ($metadata === null) {
            $scan['metadata'] = null;
        }
        return $scan;
    }

    public function mergePartialWithDeep(array $partial, array $deep, array $deepMetadata): array
    {
        return $this->results->scanMergePartialWithDeep($partial, $deep, $deepMetadata);
    }

    public function xmlUrl(string $ip, ?int $id = null): string
    {
        return $this->codec->url($ip, $id);
    }

    public function services(): array
    {
        $services = [];
        $hosts = [];
        foreach ($this->jobs->latestUsableByIp() as $metadata) {
            $scan = $this->forHost($metadata['ip'], $metadata['id']);
            $hostServices = 0;
            $vendor = $this->vendors->forMac((string) $metadata['mac']);
            foreach ($scan['ports'] ?? [] as $port) {
                if (strtolower((string) ($port['state'] ?? '')) !== 'open') {
                    continue;
                }
                $source = (string) ($port['source'] ?? $metadata['mode']);
                $sourceMetadata = $source === 'deep' && !empty($scan['merged_with'])
                    ? $scan['merged_with']
                    : $metadata;
                $services[] = [
                    'host_id' => $metadata['host_id'],
                    'name' => $metadata['name'],
                    'ip' => $metadata['ip'],
                    'mac' => $metadata['mac'],
                    'vendor' => $vendor,
                    'scan_id' => $metadata['id'],
                    'scan_mode' => $metadata['mode'],
                    'scan_date' => $sourceMetadata['date_end'] ?? $sourceMetadata['date_begin'],
                    'merged' => !empty($scan['merged']),
                    'protocol' => strtolower((string) ($port['protocol'] ?? '')),
                    'port' => (int) ($port['port'] ?? 0),
                    'service' => (string) ($port['service'] ?? ''),
                    'version' => (string) ($port['details'] ?? ''),
                    'tunnel' => (string) ($port['tunnel'] ?? ''),
                    'source' => $source,
                ];
                $hostServices++;
            }
            $hosts[] = [
                'host_id' => $metadata['host_id'],
                'name' => $metadata['name'],
                'ip' => $metadata['ip'],
                'services' => $hostServices,
            ];
        }

        return [
            'network' => $this->config->network,
            'summary' => ['hosts' => count($hosts), 'services' => count($services)],
            'hosts' => $hosts,
            'services' => $services,
        ];
    }

    public function xml(string $ip, ?int $id = null): Response
    {
        $metadata = $id === null ? $this->jobs->bestResult($ip) : $this->jobs->byId($ip, $id);
        if ($id !== null && $metadata === null) {
            throw new HttpException(404, 'scan not found');
        }
        $scan = $this->snapshots->read($ip, $metadata);
        if ($scan === null) {
            throw new HttpException(404, 'scan not found');
        }
        return new Response(
            200,
            ['Content-Type' => 'application/xml; charset=utf-8'],
            $this->codec->render($scan),
        );
    }
}

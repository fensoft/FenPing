<?php

declare(strict_types=1);

namespace FenPing\Scan;

use FenPing\Backend\Backend;

final class XmlCodec
{
    public function __construct(private readonly Backend $backend) {}

    public function parse(string $xml, ?array $metadata = null): array
    {
        return $this->backend->scanParseXml($xml, $metadata);
    }

    public function render(array $scan): string
    {
        return $this->backend->scanRenderXml($scan);
    }

    public function normalize(string $xml): string
    {
        return $this->backend->scanNormalizeXml($xml);
    }

    public function semanticHash(array $scan): string
    {
        return $this->backend->scanResultHash($scan);
    }

    public function contentHash(array $scan): string
    {
        return $this->backend->scanContentHash($scan);
    }

    public function parseScriptNodes(string $xml): array
    {
        return $this->backend->scanParseScriptNodes($xml);
    }
}

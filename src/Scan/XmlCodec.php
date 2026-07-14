<?php

declare(strict_types=1);

namespace FenPing\Scan;

final readonly class XmlCodec
{
    private const XSL_FROM = 'file:///usr/bin/../share/nmap/';
    private const XSL_LEGACY = '../res/xsl/';
    private const XSL_TO = '/res/xsl/';

    public function __construct(
        private ScanXmlParser $parser,
        private ScanXmlRenderer $renderer,
        private ScanResultHasher $hasher,
    ) {
    }

    public function parse(string $xml, ?array $metadata = null): array
    {
        return $this->parser->parse($xml, $metadata);
    }

    public function render(array $scan): string
    {
        return $this->renderer->render($scan);
    }

    public function normalize(string $xml): string
    {
        return str_replace(
            ['href="' . self::XSL_LEGACY, 'href="' . self::XSL_FROM],
            ['href="' . self::XSL_TO, 'href="' . self::XSL_TO],
            $xml,
        );
    }

    public function semanticHash(array $scan): string
    {
        return $this->hasher->semanticHash($scan);
    }

    public function contentHash(array $scan): string
    {
        return $this->hasher->contentHash($scan);
    }

    public function parseScriptNodes(string $xml): array
    {
        return $this->parser->parseScriptNodes($xml);
    }

    public function selectOsMatches(array $matches): array
    {
        return $this->parser->scanSelectOsMatches($matches);
    }

    public function url(string $ip, ?int $id = null): string
    {
        return $id === null
            ? '/api/scans/' . rawurlencode($ip) . '.xml'
            : '/api/scans/' . rawurlencode($ip) . '/' . $id . '.xml';
    }
}

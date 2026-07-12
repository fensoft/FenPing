<?php

declare(strict_types=1);

namespace FenPing\Api;

final class FileResponse extends Response
{
    public function __construct(
        private readonly string $path,
        string $downloadName,
        string $contentType = 'application/octet-stream',
    ) {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($downloadName)) ?: 'download.bin';
        parent::__construct(200, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode(basename($downloadName)),
            'Content-Length' => (string) filesize($path),
        ], '');
    }

    public function emit(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        readfile($this->path);
        exit;
    }
}

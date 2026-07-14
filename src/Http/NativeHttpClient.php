<?php

declare(strict_types=1);

namespace FenPing\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final readonly class NativeHttpClient implements HttpClient
{
    public function __construct(private ?HttpTransport $httpTransport = null)
    {
    }

    public function request(string $url, array $options = []): HttpResult
    {
        $result = $this->rawRequest($url, $options);
        return new HttpResult($result["status"], $result["headers"], $result["body"]);
    }

private function rawRequest(string $url, array $options = array()): array {
  $method = strtoupper((string)($options['method'] ?? 'GET'));
  if (!preg_match('/^[A-Z]+$/', $method))
    throw new InvalidArgumentException('invalid HTTPS request method');

  $headers = $options['headers'] ?? array();
  if (!is_array($headers))
    throw new InvalidArgumentException('invalid HTTPS request headers');

  $body = array_key_exists('body', $options) ? (string)$options['body'] : null;
  $timeout = max(1.0, (float)($options['timeout'] ?? 10));
  $maxRedirects = max(0, (int)($options['max_redirects'] ?? 0));
  $maxBytes = max(1, (int)($options['max_bytes'] ?? 1024 * 1024));
  $currentUrl = $url;
  if ($this->httpTransport !== null) {
    $this->fenpingHttpValidateUrl($url);
    return $this->fenpingHttpTransportResponse($this->httpTransport->request($url, array(
      'method' => $method,
      'headers' => $headers,
      'body' => $body,
      'timeout' => $timeout,
      'max_redirects' => $maxRedirects,
      'max_bytes' => $maxBytes
    )));
  }


  for ($redirects = 0; ; $redirects++) {
    $this->fenpingHttpValidateUrl($currentUrl);
    $response = $this->fenpingHttpRequestOnce($currentUrl, $method, $headers, $body, $timeout, $maxBytes);
    $location = $this->fenpingHttpResponseHeader($response['headers'], 'location');
    if (!in_array($response['status'], array(301, 302, 303, 307, 308), true) || $location === null)
      return $this->fenpingHttpTransportResponse($response);
    if ($redirects >= $maxRedirects)
      throw new RuntimeException('HTTPS request exceeded its redirect limit');

    $currentUrl = $this->fenpingHttpRedirectUrl($currentUrl, $location);
    if ($response['status'] === 303 || (($response['status'] === 301 || $response['status'] === 302) && $method === 'POST')) {
      $method = 'GET';
      $body = null;
      unset($headers['Content-Type'], $headers['content-type']);
    }
  }
}

private function fenpingHttpTransportResponse(array $response): array {
  $status = $response['status'] ?? null;
  $headers = $response['headers'] ?? null;
  $body = $response['body'] ?? null;
  if (!is_int($status) || $status < 100 || $status > 599 || !is_array($headers) || !is_string($body))
    throw new RuntimeException('HTTPS transport returned an invalid response');
  return array(
    'status' => $status,
    'headers' => $headers,
    'body' => $body
  );
}

private function fenpingHttpRequestOnce(string $url, string $method, array $headers, ?string $body, float $timeout, int $maxBytes): array {
  $headerLines = array();
  foreach ($headers as $name => $value) {
    if (!is_string($name) || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name))
      throw new InvalidArgumentException('invalid HTTPS request header name');
    $value = (string)$value;
    if (str_contains($value, "\r") || str_contains($value, "\n"))
      throw new InvalidArgumentException('invalid HTTPS request header value');
    $headerLines[] = $name . ': ' . $value;
  }
  $headerLines[] = 'Connection: close';

  $httpOptions = array(
    'method' => $method,
    'header' => implode("\r\n", $headerLines),
    'ignore_errors' => true,
    'follow_location' => 0,
    'protocol_version' => 1.1,
    'timeout' => $timeout
  );
  if ($body !== null)
    $httpOptions['content'] = $body;

  $context = stream_context_create(array(
    'http' => $httpOptions,
    'ssl' => array(
      'verify_peer' => true,
      'verify_peer_name' => true,
      'allow_self_signed' => false,
      'SNI_enabled' => true,
      'cafile' => '/etc/ssl/certs/ca-certificates.crt'
    )
  ));

  $stream = @fopen($url, 'rb', false, $context);
  if ($stream === false)
    throw new RuntimeException('HTTPS connection failed');

  try {
    $metadata = stream_get_meta_data($stream);
    $responseHeaders = $metadata['wrapper_data'] ?? array();
    if (is_string($responseHeaders))
      $responseHeaders = array($responseHeaders);
    $status = $this->fenpingHttpResponseStatus($responseHeaders);
    if ($status === 0)
      throw new RuntimeException('HTTPS server returned an invalid response');

    $contents = '';
    while (!feof($stream)) {
      $chunk = fread($stream, 16384);
      if ($chunk === false)
        throw new RuntimeException('failed to read HTTPS response');
      if ($chunk === '') {
        $metadata = stream_get_meta_data($stream);
        if (!empty($metadata['timed_out']))
          throw new RuntimeException('HTTPS request timed out');
        if (!feof($stream))
          throw new RuntimeException('HTTPS response ended unexpectedly');
        break;
      }
      $contents .= $chunk;
      if (strlen($contents) > $maxBytes)
        throw new RuntimeException('HTTPS response exceeded its size limit');
    }

    $metadata = stream_get_meta_data($stream);
    if (!empty($metadata['timed_out']))
      throw new RuntimeException('HTTPS request timed out');
    return array('status' => $status, 'headers' => $responseHeaders, 'body' => $contents);
  } finally {
    fclose($stream);
  }
}

private function fenpingHttpValidateUrl(string $url): void {
  $parts = parse_url($url);
  if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === ''
      || isset($parts['user']) || isset($parts['pass']))
    throw new InvalidArgumentException('only credential-free HTTPS URLs are supported');
}

private function fenpingHttpResponseStatus(array $headers): int {
  $status = 0;
  foreach ($headers as $header) {
    if (preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/i', (string)$header, $matches))
      $status = (int)$matches[1];
  }
  return $status;
}

private function fenpingHttpResponseHeader(array $headers, string $name): ?string {
  $value = null;
  $prefix = strtolower($name) . ':';
  foreach ($headers as $header) {
    $header = (string)$header;
    if (str_starts_with(strtolower($header), $prefix))
      $value = trim(substr($header, strlen($prefix)));
  }
  return $value === null || $value === '' ? null : $value;
}

private function fenpingHttpRedirectUrl(string $baseUrl, string $location): string {
  $location = trim($location);
  if ($location === '')
    throw new RuntimeException('HTTPS server returned an invalid redirect');
  if (parse_url($location, PHP_URL_SCHEME) !== null)
    return $location;
  if (str_starts_with($location, '//'))
    return 'https:' . $location;

  $base = parse_url($baseUrl);
  if (!is_array($base) || ($base['host'] ?? '') === '')
    throw new RuntimeException('HTTPS server returned an invalid redirect');
  $host = (string)$base['host'];
  if (str_contains($host, ':'))
    $host = '[' . $host . ']';
  $authority = 'https://' . $host . (isset($base['port']) ? ':' . (int)$base['port'] : '');
  if (str_starts_with($location, '/'))
    return $authority . $location;

  $basePath = (string)($base['path'] ?? '/');
  if (str_starts_with($location, '?'))
    return $authority . $basePath . $location;
  $directory = str_contains($basePath, '/') ? substr($basePath, 0, strrpos($basePath, '/') + 1) : '/';
  return $authority . $directory . $location;
}
}

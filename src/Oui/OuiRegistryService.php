<?php

declare(strict_types=1);

namespace FenPing\Oui;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Http\HttpClient;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final readonly class OuiRegistryService
{
    public function __construct(
        private AppConfig $config,
        private DatabaseManager $database,
        private HttpClient $http,
    ) {
    }

    public function refresh(array $arguments): int { return $this->runIeeeOuiRefreshCommand($arguments); }
    public function synchronize(array $arguments): int { return $this->runIeeeOuiSyncCommand($arguments); }
    public function normalizeMac(string $mac): string { return $this->ieeeOuiNormalizeMac($mac); }

public const IEEE_OUI_USER_AGENT = 'FenPing IEEE OUI updater';

public function ieeeOuiRegistrySources(): array {
  return array(
    array('registry' => 'MA-L', 'length' => 6, 'url' => 'https://standards-oui.ieee.org/oui/oui.csv'),
    array('registry' => 'MA-M', 'length' => 7, 'url' => 'https://standards-oui.ieee.org/oui28/mam.csv'),
    array('registry' => 'MA-S', 'length' => 9, 'url' => 'https://standards-oui.ieee.org/oui36/oui36.csv'),
    array('registry' => 'IAB', 'length' => 9, 'url' => 'https://standards-oui.ieee.org/iab/iab.csv')
  );
}

public function ieeeOuiRuntimePath(): string {
  return $this->config->dataDir . '/state/ieee-oui.json';
}

public function runIeeeOuiRefreshCommand(array $args): int {
  if (count($args) !== 0) {
    fwrite(STDERR, "Usage: php cli.php oui-refresh" . PHP_EOL);
    return 2;
  }

  try {
    $result = $this->ieeeOuiRefresh($this->ieeeOuiRuntimePath());
    $message = "IEEE OUI registry updated: {$result['assignments']} assignments from {$result['registries']} registries";
    $sync = $this->ieeeOuiSyncDatabase($this->database->connection());
    $message .= $sync['changed']
      ? "; {$sync['assignments']} loaded into SQL"
      : '; SQL already current';
    echo $message . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

public function runIeeeOuiSyncCommand(array $args): int {
  if (count($args) !== 0) {
    fwrite(STDERR, "Usage: php cli.php oui-sync" . PHP_EOL);
    return 2;
  }

  try {
    $sync = $this->ieeeOuiSyncDatabase($this->database->connection());
    if ($sync['changed'])
      echo "IEEE OUI SQL registry loaded: {$sync['assignments']} assignments" . PHP_EOL;
    else
      echo "IEEE OUI SQL registry already current: {$sync['assignments']} assignments" . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

public function ieeeOuiRefresh(string $target): array {
  $assignments = array('6' => array(), '7' => array(), '9' => array());
  $registries = 0;

  foreach ($this->ieeeOuiRegistrySources() as $source) {
    $csv = $this->ieeeOuiDownload($source['url']);
    $rows = $this->ieeeOuiParseCsv($csv, $source['registry'], $source['length']);
    if (count($rows) < 1000)
      throw new RuntimeException("IEEE {$source['registry']} registry is unexpectedly small");
    foreach ($rows as $prefix => $vendor) {
      $length = (string)$source['length'];
      if (!isset($assignments[$length][$prefix]))
        $assignments[$length][$prefix] = $vendor;
    }
    $registries++;
  }

  foreach ($assignments as &$prefixes)
    ksort($prefixes);
  unset($prefixes);

  $count = array_sum(array_map('count', $assignments));
  $payload = json_encode(array(
    'source' => 'IEEE Registration Authority public listings',
    'updated_at' => gmdate(DATE_ATOM),
    'assignments' => $assignments
  ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  $this->ieeeOuiWriteAtomic($target, $payload . PHP_EOL);
  return array('assignments' => $count, 'registries' => $registries);
}

public function ieeeOuiDownload(string $url): string {
  try {
    $response = $this->http->request($url, array(
      'headers' => array('User-Agent' => self::IEEE_OUI_USER_AGENT),
      'timeout' => 45,
      'max_redirects' => 3,
      'max_bytes' => 64 * 1024 * 1024
    ));
  } catch (Throwable $error) {
    throw new RuntimeException('failed to download IEEE OUI registry: ' . $error->getMessage(), 0, $error);
  }
  if ($response->status < 200 || $response->status >= 300)
    throw new RuntimeException('failed to download IEEE OUI registry: HTTP ' . $response->status);
  if (strlen($response->body) < 10000)
    throw new RuntimeException('downloaded IEEE OUI registry is unexpectedly small');
  return $response->body;
}

public function ieeeOuiParseCsv(string $csv, string $expectedRegistry, int $prefixLength): array {
  $stream = fopen('php://temp', 'w+b');
  if ($stream === false)
    throw new RuntimeException('failed to parse IEEE OUI registry');

  try {
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', '');
    if ($header === false)
      throw new RuntimeException("IEEE $expectedRegistry registry has no header");
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($header[0] ?? ''));
    if (($header[0] ?? '') !== 'Registry' || ($header[1] ?? '') !== 'Assignment' || ($header[2] ?? '') !== 'Organization Name')
      throw new RuntimeException("IEEE $expectedRegistry registry header is invalid");

    $assignments = array();
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
      if (count($row) < 3)
        continue;
      $registry = strtoupper(trim((string)$row[0]));
      $prefix = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$row[1]));
      $vendor = trim((string)$row[2]);
      if ($registry !== $expectedRegistry || strlen($prefix) !== $prefixLength || !ctype_xdigit($prefix) || $vendor === '')
        continue;
      $assignments[$prefix] = $vendor;
    }
    return $assignments;
  } finally {
    fclose($stream);
  }
}

public function ieeeOuiWriteAtomic(string $target, string $contents): void {
  $dir = dirname($target);
  if (!is_dir($dir) && !mkdir($dir, 0755, true))
    throw new RuntimeException("failed to create IEEE OUI cache directory: $dir");
  $tmp = tempnam($dir, '.ieee-oui-');
  if ($tmp === false)
    throw new RuntimeException('failed to create IEEE OUI cache file');

  try {
    if (file_put_contents($tmp, $contents, LOCK_EX) === false)
      throw new RuntimeException('failed to write IEEE OUI cache');
    chmod($tmp, 0644);
    if (!rename($tmp, $target))
      throw new RuntimeException('failed to replace IEEE OUI cache');
  } finally {
    if (is_file($tmp))
      @unlink($tmp);
  }
}

public function ieeeOuiSyncDatabase(PDO $db): array {
  $assignments = $this->ieeeOuiAssignments();
  $count = array_sum(array_map('count', $assignments));
  if ($count < 1000)
    throw new RuntimeException('no valid IEEE OUI registry is available for SQL import');

  $sourceHash = $this->ieeeOuiAssignmentsHash($assignments);
  $databaseState = $this->ieeeOuiDatabaseState($db);
  if ($databaseState['count'] === $count && hash_equals($sourceHash, $databaseState['hash']))
    return array('assignments' => $count, 'changed' => false);

  $this->database->beginImmediate();
  try {
    $db->exec('DELETE FROM oui_vendors');
    $batch = array();
    foreach ($assignments as $length => $prefixes) {
      foreach ($prefixes as $prefix => $vendor) {
        $batch[] = array((int)$length, $prefix, $vendor);
        if (count($batch) >= 500) {
          $this->ieeeOuiInsertBatch($db, $batch);
          $batch = array();
        }
      }
    }
    if (count($batch) > 0)
      $this->ieeeOuiInsertBatch($db, $batch);
    $this->database->commit();
  } catch (Throwable $e) {
    $this->database->rollback();
    throw $e;
  }
  return array('assignments' => $count, 'changed' => true);
}

public function ieeeOuiAssignmentsHash(array $assignments): string {
  $context = hash_init('sha256');
  foreach (array('6', '7', '9') as $length) {
    $prefixes = $assignments[$length] ?? array();
    ksort($prefixes, SORT_STRING);
    foreach ($prefixes as $prefix => $vendor)
      hash_update($context, $length . "\0" . $prefix . "\0" . $vendor . "\n");
  }
  return hash_final($context);
}

public function ieeeOuiDatabaseState(PDO $db): array {
  $context = hash_init('sha256');
  $count = 0;
  $stmt = $db->query('SELECT prefix_length, prefix, vendor FROM oui_vendors ORDER BY prefix_length, prefix');
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    hash_update($context, $row['prefix_length'] . "\0" . $row['prefix'] . "\0" . $row['vendor'] . "\n");
    $count++;
  }
  return array('count' => $count, 'hash' => hash_final($context));
}

public function ieeeOuiInsertBatch(PDO $db, array $rows): void {
  $values = implode(',', array_fill(0, count($rows), '(?, ?, ?)'));
  $params = array();
  foreach ($rows as $row) {
    $params[] = $row[0];
    $params[] = $row[1];
    $params[] = $row[2];
  }
  $stmt = $db->prepare("INSERT INTO oui_vendors (prefix_length, prefix, vendor) VALUES $values");
  $stmt->execute($params);
}

public function ieeeOuiDatabaseVendor(PDO $db, string $normalizedMac): ?string {
  $stmt = $db->prepare("
    SELECT vendor
    FROM oui_vendors
    WHERE (prefix_length=9 AND prefix=:prefix9)
       OR (prefix_length=7 AND prefix=:prefix7)
       OR (prefix_length=6 AND prefix=:prefix6)
    ORDER BY prefix_length DESC
    LIMIT 1
  ");
  $stmt->execute(array(
    'prefix9' => substr($normalizedMac, 0, 9),
    'prefix7' => substr($normalizedMac, 0, 7),
    'prefix6' => substr($normalizedMac, 0, 6)
  ));
  $vendor = $stmt->fetchColumn();
  return $vendor === false ? null : (string)$vendor;
}

public function ieeeOuiVendor(string $mac): string {
  static $cache = array();

  $normalized = $this->ieeeOuiNormalizeMac($mac);
  if ($normalized === '')
    return '';
  if (array_key_exists($normalized, $cache))
    return $cache[$normalized];

  $firstOctet = hexdec(substr($normalized, 0, 2));
  if (($firstOctet & 0x02) !== 0)
    return $cache[$normalized] = '';

  $assignments = $this->ieeeOuiAssignments();
  foreach (array(9, 7, 6) as $length) {
    $prefix = substr($normalized, 0, $length);
    if (isset($assignments[(string)$length][$prefix]))
      return $cache[$normalized] = $assignments[(string)$length][$prefix];
  }
  return $cache[$normalized] = '';
}

public function ieeeOuiNormalizeMac(string $mac): string {
  $normalized = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', trim($mac)));
  return strlen($normalized) === 12 && ctype_xdigit($normalized) ? $normalized : '';
}

public function ieeeOuiAssignments(): array {
  static $assignments = null;
  if ($assignments !== null)
    return $assignments;

  $loaded = $this->ieeeOuiLoad($this->ieeeOuiRuntimePath());
  if ($loaded !== null)
    return $assignments = $loaded;
  return $assignments = array('6' => array(), '7' => array(), '9' => array());
}

public function ieeeOuiLoad(string $path): ?array {
  if (!is_file($path) || !is_readable($path))
    return null;
  $contents = file_get_contents($path);
  if ($contents === false)
    return null;

  try {
    $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    return null;
  }
  $assignments = $data['assignments'] ?? null;
  if (!is_array($assignments) || !is_array($assignments['6'] ?? null) || !is_array($assignments['7'] ?? null) || !is_array($assignments['9'] ?? null))
    return null;
  if (count($assignments['6']) + count($assignments['7']) + count($assignments['9']) < 1000)
    return null;
  return $assignments;
}
}

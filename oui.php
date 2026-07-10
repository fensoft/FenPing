<?php

const IEEE_OUI_SEED_PATH = '/usr/share/fenping/ieee-oui.json';
const IEEE_OUI_USER_AGENT = 'FenPing IEEE OUI updater';

function ieeeOuiRegistrySources(): array {
  return array(
    array('registry' => 'MA-L', 'length' => 6, 'url' => 'https://standards-oui.ieee.org/oui/oui.csv'),
    array('registry' => 'MA-M', 'length' => 7, 'url' => 'https://standards-oui.ieee.org/oui28/mam.csv'),
    array('registry' => 'MA-S', 'length' => 9, 'url' => 'https://standards-oui.ieee.org/oui36/oui36.csv'),
    array('registry' => 'IAB', 'length' => 9, 'url' => 'https://standards-oui.ieee.org/iab/iab.csv')
  );
}

function ieeeOuiRuntimePath(): string {
  return FENPING_DATA_DIR . '/state/ieee-oui.json';
}

function runIeeeOuiRefreshCommand(array $args): int {
  if (count($args) > 1 || (count($args) === 1 && $args[0] !== '--seed')) {
    fwrite(STDERR, "Usage: php cli.php oui-refresh [--seed]" . PHP_EOL);
    return 2;
  }

  $target = ($args[0] ?? '') === '--seed' ? IEEE_OUI_SEED_PATH : ieeeOuiRuntimePath();
  try {
    $result = ieeeOuiRefresh($target);
    $message = "IEEE OUI registry updated: {$result['assignments']} assignments from {$result['registries']} registries";
    if ($target !== IEEE_OUI_SEED_PATH) {
      $loaded = ieeeOuiSyncDatabase(db());
      $message .= "; $loaded loaded into SQL";
    }
    echo $message . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

function runIeeeOuiSyncCommand(array $args): int {
  if (count($args) !== 0) {
    fwrite(STDERR, "Usage: php cli.php oui-sync" . PHP_EOL);
    return 2;
  }

  try {
    $loaded = ieeeOuiSyncDatabase(db());
    echo "IEEE OUI SQL registry loaded: $loaded assignments" . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

function ieeeOuiRefresh(string $target): array {
  $assignments = array('6' => array(), '7' => array(), '9' => array());
  $registries = 0;

  foreach (ieeeOuiRegistrySources() as $source) {
    $csv = ieeeOuiDownload($source['url']);
    $rows = ieeeOuiParseCsv($csv, $source['registry'], $source['length']);
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
  ieeeOuiWriteAtomic($target, $payload . PHP_EOL);
  return array('assignments' => $count, 'registries' => $registries);
}

function ieeeOuiDownload(string $url): string {
  $handle = curl_init($url);
  if ($handle === false)
    throw new RuntimeException('failed to initialize IEEE OUI download');

  try {
    curl_setopt_array($handle, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 45,
      CURLOPT_FAILONERROR => true,
      CURLOPT_USERAGENT => IEEE_OUI_USER_AGENT,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS
    ));
    $contents = curl_exec($handle);
    if ($contents === false)
      throw new RuntimeException('failed to download IEEE OUI registry: ' . curl_error($handle));
    if (strlen($contents) < 10000)
      throw new RuntimeException('downloaded IEEE OUI registry is unexpectedly small');
    return $contents;
  } finally {
    curl_close($handle);
  }
}

function ieeeOuiParseCsv(string $csv, string $expectedRegistry, int $prefixLength): array {
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

function ieeeOuiWriteAtomic(string $target, string $contents): void {
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

function ieeeOuiSyncDatabase(PDO $db): int {
  $assignments = ieeeOuiAssignments();
  $count = array_sum(array_map('count', $assignments));
  if ($count < 1000)
    throw new RuntimeException('no valid IEEE OUI registry is available for SQL import');

  $db->beginTransaction();
  try {
    $db->exec('DELETE FROM oui_vendors');
    $batch = array();
    foreach ($assignments as $length => $prefixes) {
      foreach ($prefixes as $prefix => $vendor) {
        $batch[] = array((int)$length, $prefix, $vendor);
        if (count($batch) >= 500) {
          ieeeOuiInsertBatch($db, $batch);
          $batch = array();
        }
      }
    }
    if (count($batch) > 0)
      ieeeOuiInsertBatch($db, $batch);
    $db->commit();
  } catch (Throwable $e) {
    if ($db->inTransaction())
      $db->rollBack();
    throw $e;
  }
  return $count;
}

function ieeeOuiInsertBatch(PDO $db, array $rows): void {
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

function ieeeOuiDatabaseVendor(PDO $db, string $normalizedMac): ?string {
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

function ieeeOuiVendor(string $mac): string {
  static $cache = array();

  $normalized = ieeeOuiNormalizeMac($mac);
  if ($normalized === '')
    return '';
  if (array_key_exists($normalized, $cache))
    return $cache[$normalized];

  $firstOctet = hexdec(substr($normalized, 0, 2));
  if (($firstOctet & 0x02) !== 0)
    return $cache[$normalized] = '';

  $assignments = ieeeOuiAssignments();
  foreach (array(9, 7, 6) as $length) {
    $prefix = substr($normalized, 0, $length);
    if (isset($assignments[(string)$length][$prefix]))
      return $cache[$normalized] = $assignments[(string)$length][$prefix];
  }
  return $cache[$normalized] = '';
}

function ieeeOuiNormalizeMac(string $mac): string {
  $normalized = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', trim($mac)));
  return strlen($normalized) === 12 && ctype_xdigit($normalized) ? $normalized : '';
}

function ieeeOuiAssignments(): array {
  static $assignments = null;
  if ($assignments !== null)
    return $assignments;

  foreach (array(ieeeOuiRuntimePath(), IEEE_OUI_SEED_PATH) as $path) {
    $loaded = ieeeOuiLoad($path);
    if ($loaded !== null)
      return $assignments = $loaded;
  }
  return $assignments = array('6' => array(), '7' => array(), '9' => array());
}

function ieeeOuiLoad(string $path): ?array {
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

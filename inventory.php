<?php

function runInventoryCommand(array $args): int {
  try {
    $options = inventoryOptions($args);
    $targets = inventoryTargets($options['args']);
    ensureInventoryDir();

    if (count($targets) === 0) {
      echo "discovered 0 hosts" . PHP_EOL;
      echo "saved 0 scans" . PHP_EOL;
      return 0;
    }

    if (count($options['args']) === 0)
      echo "discovered " . count($targets) . " hosts" . PHP_EOL;
    else
      echo "scanning " . $targets[0] . ($options['quick'] ? " quick" : "") . PHP_EOL;

    $saved = 0;
    foreach ($targets as $ip) {
      $result = inventoryScan($ip, $options['quick']);
      if ($result['saved']) {
        $saved++;
        echo "$ip saved" . PHP_EOL;
      } else {
        echo "$ip skipped" . PHP_EOL;
      }
    }

    echo "saved $saved scans" . PHP_EOL;
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  }
}

function inventoryOptions(array $args): array {
  $quick = false;
  $remaining = array();

  foreach ($args as $arg) {
    if ($arg === '--quick' || $arg === '-q') {
      $quick = true;
      continue;
    }
    if ($arg === 'quick') {
      $quick = true;
      continue;
    }
    $remaining[] = $arg;
  }

  return array(
    'quick' => $quick,
    'args' => $remaining
  );
}

function inventoryTargets(array $args): array {
  global $network;

  if (count($args) > 1)
    throw new InvalidArgumentException(inventoryUsage());

  $target = $args[0] ?? '';
  if ($target !== '') {
    if (ctype_digit($target)) {
      $octet = intval($target);
      if ($octet < 1 || $octet > 254)
        throw new InvalidArgumentException(inventoryUsage());
      return array($network . '.' . $octet);
    }

    if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      throw new InvalidArgumentException(inventoryUsage());

    return array($target);
  }

  return inventoryDiscover($network . '.1-254');
}

function inventoryDiscover(string $range): array {
  $output = inventoryExec(array('nmap', '-n', '-sn', '-T3', '-oG', '-', $range));
  $hosts = array();

  foreach ($output as $line) {
    if (preg_match('/^Host:\s+(\d{1,3}(?:\.\d{1,3}){3})\s+.*Status:\s+Up\b/', $line, $matches))
      $hosts[] = $matches[1];
  }

  return array_values(array_unique($hosts));
}

function inventoryScan(string $ip, bool $quick = false): array {
  $mode = $quick ? 'quick' : 'deep';
  $scanId = scanMetadataStart($ip, $mode);
  $tmp = tempnam(sys_get_temp_dir(), 'fenping-nmap-');
  if ($tmp === false) {
    scanMetadataFailed($scanId, 'failed to create temporary nmap file');
    throw new RuntimeException('failed to create temporary nmap file');
  }

  try {
    $command = $quick
      ? array('nmap', $ip, '-T4', '-F', '-sS', '-v', '-oX', $tmp)
      : array('nmap', $ip, '-T2', '-A', '-p-', '-sS', '-v', '-oX', $tmp);

    inventoryExec($command, true);
    $xml = file_get_contents($tmp);
    if ($xml === false)
      throw new RuntimeException("failed to read nmap result for $ip");

    $xml = scanNormalizeXml($xml);
    $scan = scanParseXml($xml, array('ip' => $ip));
    $status = $scan['status'] ?: 'unknown';
    $xmlHash = scanXmlHash($xml, $ip);
    $saved = $status === 'up';
    $xmlPath = null;

    if ($saved) {
      $xmlPath = inventorySaveXml($ip, $scanId, $xml);
    }

    scanMetadataComplete($scanId, $status, count($scan['ports']), $scan['duration'], $xmlPath, $xmlHash);
    scanPruneHistory($ip);
    return array(
      'saved' => $saved,
      'status' => $status
    );
  } catch (Throwable $e) {
    scanMetadataFailed($scanId, $e->getMessage());
    scanPruneHistory($ip);
    throw $e;
  } finally {
    @unlink($tmp);
  }
}

function inventorySaveXml(string $ip, int $scanId, string $xml): string {
  $historyDir = SCAN_DIR . '/history/' . $ip;
  if (!is_dir($historyDir) && !mkdir($historyDir, 0755, true))
    throw new RuntimeException("failed to create scan history directory for $ip");

  $historyPath = scanXmlPath($ip, $scanId);
  inventoryWriteXmlFile($historyPath, $xml);
  inventoryWriteXmlFile(scanXmlPath($ip), $xml);
  return $historyPath;
}

function inventoryWriteXmlFile(string $target, string $xml): void {
  $dir = dirname($target);
  $tmp = tempnam($dir, basename($target) . '.');
  if ($tmp === false)
    throw new RuntimeException("failed to create temporary scan file");

  if (file_put_contents($tmp, $xml) === false) {
    @unlink($tmp);
    throw new RuntimeException("failed to write scan file");
  }

  chmod($tmp, 0644);
  if (!rename($tmp, $target)) {
    @unlink($tmp);
    throw new RuntimeException("failed to save scan file");
  }
}

function ensureInventoryDir(): void {
  if (!is_dir(SCAN_DIR) && !mkdir(SCAN_DIR, 0755, true))
    throw new RuntimeException('failed to create nmap directory');
}

function inventoryExec(array $command, bool $quiet = false): array {
  $line = implode(' ', array_map('escapeshellarg', $command));
  if ($quiet)
    $line .= ' >/dev/null 2>/dev/null';
  else
    $line .= ' 2>&1';

  $output = array();
  $code = 0;
  exec($line, $output, $code);
  if ($code !== 0)
    throw new RuntimeException(trim(implode(PHP_EOL, $output)) ?: "command failed: " . implode(' ', $command));

  return $output;
}

function inventoryUsage(): string {
  return "Usage: php cli.php inventory [--quick] [1-254|IPv4]";
}

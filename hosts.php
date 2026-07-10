<?php

const DNSMASQ_CONF = '/etc/dnsmasq.d/fenping.conf';
const DNSMASQ_DHCP_HOSTS = '/etc/dnsmasq.d/fenping.dhcp-hosts';
const DNSMASQ_DHCP_OPTS = '/etc/dnsmasq.d/fenping.dhcp-opts';
const DNSMASQ_HOSTS = '/etc/dnsmasq.d/fenping.hosts';
const DNSMASQ_PID = '/var/run/dnsmasq.pid';
const DNSMASQ_RELOAD_SIGNAL = 1;
const DNSMASQ_UPDATE_LOCK = '/tmp/fenping-dnsmasq-update.lock';

class DhcpHostNotFoundException extends RuntimeException {}
class DhcpHostInputException extends InvalidArgumentException {}

function runHostsCommand(array $args = array()): int {
  $lock = null;

  try {
    ensureDnsmasqDirs();

    if ($args === array('--apply-pending')) {
      applyDnsmasqCandidate(dnsmasqPendingDir());
      echo "pending dnsmasq files applied" . PHP_EOL;
      return 0;
    }

    if ($args === array('--sync-locked')) {
      syncDnsmasqFromDatabase();
      return 0;
    }

    if (count($args) !== 0) {
      fwrite(STDERR, "Usage: php cli.php hosts" . PHP_EOL);
      return 2;
    }

    $lock = acquireDnsmasqUpdateLock();
    syncDnsmasqFromDatabase();
    return 0;
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  } finally {
    releaseDnsmasqUpdateLock($lock);
  }
}

function syncDnsmasqFromDatabase(): void {
  $candidateDir = createDnsmasqCandidateDir();

  try {
    prepareDnsmasqCandidate(buildDnsmasqFiles(), $candidateDir);
    applyDnsmasqCandidate($candidateDir);
    echo "dnsmasq files written" . PHP_EOL;
  } finally {
    removeDnsmasqCandidateDir($candidateDir);
  }
}

function ensureDnsmasqDirs(): void {
  foreach (array('/etc/dnsmasq.d', '/var/lib/misc') as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true))
      throw new RuntimeException("failed to create $dir");
  }
}

function validateDhcpHostCreate($ip, $mac): array {
  return array(
    'ip' => normalizeDhcpIp($ip, true),
    'mac' => normalizeDhcpMac($mac, true)
  );
}

function validateDhcpHostEdit($ip, $mac, $name, $router, $dns): array {
  return array(
    'ip' => normalizeDhcpIp($ip, true),
    'mac' => normalizeDhcpMac($mac, true),
    'name' => normalizeDhcpHostname($name, false),
    'router' => normalizeDhcpRouter($router),
    'dns' => normalizeDhcpDnsServers($dns)
  );
}

function normalizeDhcpIp($value, bool $required): ?string {
  $ip = dhcpScalarText($value, 'ip');
  if ($ip === '') {
    if ($required)
      throw new InvalidArgumentException('ip is required');
    return null;
  }

  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
    throw new InvalidArgumentException('invalid ip');
  return $ip;
}

function normalizeDhcpMac($value, bool $required): string {
  $mac = strtolower(str_replace('-', ':', dhcpScalarText($value, 'mac')));
  if ($mac === '') {
    if ($required)
      throw new InvalidArgumentException('mac is required');
    return '';
  }

  if (preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac) !== 1)
    throw new InvalidArgumentException('invalid mac; expected six hexadecimal octets');
  return $mac;
}

function normalizeDhcpHostname($value, bool $required): string {
  $name = dhcpScalarText($value, 'name');
  if ($name === '') {
    if ($required)
      throw new InvalidArgumentException('host name is required');
    return '';
  }

  if (strlen($name) > 50 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $name) !== 1)
    throw new InvalidArgumentException('invalid host name; use a single DNS label containing letters, numbers, or hyphens');
  return $name;
}

function normalizeDhcpRouter($value): ?string {
  $router = dhcpScalarText($value, 'router');
  if ($router === '')
    return null;
  if (!ctype_digit($router) || (int)$router < 1 || (int)$router > 254)
    throw new InvalidArgumentException('invalid router; expected a host number from 1 to 254');
  return (string)(int)$router;
}

function normalizeDhcpDnsServers($value): ?string {
  $dns = dhcpScalarText($value, 'dns');
  if ($dns === '')
    return null;

  $servers = preg_split('/[\s,;]+/', $dns, -1, PREG_SPLIT_NO_EMPTY);
  if ($servers === false || count($servers) === 0)
    throw new InvalidArgumentException('invalid dns servers');

  $normalized = array();
  foreach ($servers as $server) {
    if (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
      throw new InvalidArgumentException("invalid dns server: $server");
    $normalized[$server] = true;
  }
  return implode(' ', array_keys($normalized));
}

function normalizeDhcpBootFilename($value): string {
  $filename = dhcpScalarText($value, 'netboot filename');
  if ($filename === '')
    return '';
  if (basename($filename) !== $filename || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1)
    throw new InvalidArgumentException('invalid netboot filename');
  return $filename;
}

function dhcpScalarText($value, string $field): string {
  if ($value === null)
    return '';
  if (!is_scalar($value))
    throw new InvalidArgumentException("invalid $field");
  return trim((string)$value);
}

function buildDnsmasqFiles(): array {
  global $network, $myself;

  $applianceIp = normalizeDhcpIp($myself ?? '', true);
  $dhcpHosts = array();
  $dhcpOptions = array();
  $dnsHosts = array($applianceIp . ' lan fenping fenping.lan');

  $stmt = db()->query("
    SELECT
      ips.name AS name,
      IFNULL(ips.mac, '') AS mac,
      ips.ip AS ip,
      IFNULL(ips.router, '') AS router,
      IFNULL(ips.dns, '') AS dns,
      IFNULL(ni.filename, '') AS netboot_filename
    FROM ips
    LEFT JOIN netboot_images ni ON ni.id=ips.netboot_image_id
    WHERE ips.ip IS NOT NULL
  ");

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $name = normalizeDhcpHostname($row['name'] ?? '', false);
    $mac = normalizeDhcpMac($row['mac'] ?? '', false);
    $ip = normalizeDhcpIp($row['ip'] ?? '', true);
    $router = normalizeDhcpRouter($row['router'] ?? '');
    $dns = normalizeDhcpDnsServers($row['dns'] ?? '');
    $netboot = normalizeDhcpBootFilename($row['netboot_filename'] ?? '');
    $tag = hostTag($ip);

    if ($name !== '')
      $dnsHosts[] = $ip . ' ' . dnsNames($name);

    if ($mac !== '') {
      $reservation = array($mac, "set:$tag", $ip);
      if ($name !== '')
        $reservation[] = $name;
      $reservation[] = 'infinite';
      $dhcpHosts[] = implode(',', $reservation);
    }

    if ($router !== null) {
      $routerIp = normalizeDhcpIp(($network ?? '') . '.' . $router, true);
      $dhcpOptions[] = "tag:$tag,option:router,$routerIp";
    }

    if ($dns !== null)
      $dhcpOptions[] = "tag:$tag,option:dns-server," . str_replace(' ', ',', $dns);

    if ($netboot !== '') {
      $dhcpOptions[] = "tag:$tag,option:tftp-server,$applianceIp";
      $dhcpOptions[] = "tag:$tag,option:bootfile-name,$netboot";
    }
  }

  for ($i = 1; $i <= 255; $i++) {
    $ip = normalizeDhcpIp(($network ?? '') . '.' . $i, true);
    $dnsHosts[] = "$ip _$i _$i.lan @$i @$i.lan ip$i ip$i.lan";
  }

  return array(
    'dhcpHosts' => lines($dhcpHosts),
    'dhcpOptions' => lines($dhcpOptions),
    'dnsHosts' => lines($dnsHosts)
  );
}

function hostTag(string $value): string {
  return preg_replace('/[^A-Za-z0-9]/', '-', $value);
}

function dnsNames(string $name): string {
  return "$name $name.lan";
}

function lines(array $lines): string {
  if (count($lines) === 0)
    return '';
  return implode(PHP_EOL, $lines) . PHP_EOL;
}

function dnsmasqGeneratedFiles(): array {
  return array(
    'dhcpHosts' => array('candidate' => 'fenping.dhcp-hosts', 'live' => DNSMASQ_DHCP_HOSTS),
    'dhcpOptions' => array('candidate' => 'fenping.dhcp-opts', 'live' => DNSMASQ_DHCP_OPTS),
    'dnsHosts' => array('candidate' => 'fenping.hosts', 'live' => DNSMASQ_HOSTS)
  );
}

function dnsmasqPendingDir(): string {
  return '/run/fenping/dnsmasq-pending';
}

function prepareDnsmasqCandidate(array $files, string $dir): void {
  ensureDnsmasqCandidateDir($dir);
  clearDnsmasqCandidateDir($dir);
  validateGeneratedDnsmasqFiles($files);

  foreach (dnsmasqGeneratedFiles() as $key => $paths)
    writeGeneratedFile($dir . '/' . $paths['candidate'], $files[$key]);

  writeDnsmasqCandidateConfig($dir);
  testDnsmasqCandidate($dir);
}

function applyDnsmasqCandidate(string $dir): void {
  $files = readDnsmasqCandidate($dir);
  validateGeneratedDnsmasqFiles($files);
  writeDnsmasqCandidateConfig($dir);
  testDnsmasqCandidate($dir);

  $previous = array();
  foreach (dnsmasqGeneratedFiles() as $key => $paths)
    $previous[$key] = readLiveGeneratedFile($paths['live']);

  try {
    foreach (dnsmasqGeneratedFiles() as $key => $paths)
      writeGeneratedFile($paths['live'], $files[$key]);

    runCommand(array('dnsmasq', '--test', '--conf-file=' . DNSMASQ_CONF), false);
    reloadDnsmasq();
  } catch (Throwable $applyError) {
    try {
      foreach (dnsmasqGeneratedFiles() as $key => $paths)
        restoreLiveGeneratedFile($paths['live'], $previous[$key]);
      runCommand(array('dnsmasq', '--test', '--conf-file=' . DNSMASQ_CONF), false);
      reloadDnsmasq();
    } catch (Throwable $restoreError) {
      throw new RuntimeException(
        'dnsmasq apply failed: ' . $applyError->getMessage() . '; previous configuration restore failed: ' . $restoreError->getMessage(),
        0,
        $applyError
      );
    }
    throw new RuntimeException('dnsmasq apply failed; previous configuration restored: ' . $applyError->getMessage(), 0, $applyError);
  }
}

function validateGeneratedDnsmasqFiles(array $files): void {
  foreach (array('dhcpHosts', 'dhcpOptions', 'dnsHosts') as $key) {
    if (!array_key_exists($key, $files) || !is_string($files[$key]))
      throw new RuntimeException("missing generated dnsmasq data: $key");
    if (strlen($files[$key]) > 4 * 1024 * 1024 || strpos($files[$key], "\0") !== false)
      throw new RuntimeException("invalid generated dnsmasq data: $key");
  }

  foreach (generatedDnsmasqLines($files['dhcpHosts']) as $line) {
    $parts = explode(',', $line);
    $count = count($parts);
    $nameAndLeaseValid = ($count === 4 && $parts[3] === 'infinite')
      || ($count === 5 && normalizeDhcpHostname($parts[3], true) === $parts[3] && $parts[4] === 'infinite');
    if (($count !== 4 && $count !== 5) || normalizeDhcpMac($parts[0], true) !== $parts[0]
      || preg_match('/^set:[A-Za-z0-9-]+$/', $parts[1]) !== 1
      || normalizeDhcpIp($parts[2], true) !== $parts[2]
      || !$nameAndLeaseValid)
      throw new RuntimeException('invalid generated DHCP reservation');
  }

  foreach (generatedDnsmasqLines($files['dhcpOptions']) as $line)
    validateGeneratedDhcpOption($line);

  foreach (generatedDnsmasqLines($files['dnsHosts']) as $line) {
    $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false || count($parts) < 2 || normalizeDhcpIp(array_shift($parts), true) === null)
      throw new RuntimeException('invalid generated DNS host');
    foreach ($parts as $name) {
      if (preg_match('/^[A-Za-z0-9_@-]+(?:\.[A-Za-z0-9_@-]+)*$/', $name) !== 1)
        throw new RuntimeException('invalid generated DNS name');
    }
  }
}

function validateGeneratedDhcpOption(string $line): void {
  if (preg_match('/^tag:[A-Za-z0-9-]+,option:(router|tftp-server),(.+)$/', $line, $matches) === 1) {
    normalizeDhcpIp($matches[2], true);
    return;
  }

  if (preg_match('/^tag:[A-Za-z0-9-]+,option:dns-server,(.+)$/', $line, $matches) === 1) {
    normalizeDhcpDnsServers($matches[1]);
    return;
  }

  if (preg_match('/^tag:[A-Za-z0-9-]+,option:bootfile-name,(.+)$/', $line, $matches) === 1) {
    normalizeDhcpBootFilename($matches[1]);
    return;
  }

  throw new RuntimeException('invalid generated DHCP option');
}

function generatedDnsmasqLines(string $contents): array {
  if ($contents === '')
    return array();
  $lines = preg_split('/\r?\n/', rtrim($contents, "\r\n"));
  if ($lines === false || in_array('', $lines, true))
    throw new RuntimeException('invalid blank line in generated dnsmasq data');
  return $lines;
}

function writeDnsmasqCandidateConfig(string $dir): void {
  $conf = @file_get_contents(DNSMASQ_CONF);
  if ($conf === false)
    throw new RuntimeException('failed to read dnsmasq configuration');

  foreach (dnsmasqGeneratedFiles() as $paths) {
    if (strpos($conf, $paths['live']) === false)
      throw new RuntimeException("dnsmasq configuration does not reference {$paths['live']}");
    $conf = str_replace($paths['live'], $dir . '/' . $paths['candidate'], $conf);
  }
  writeGeneratedFile($dir . '/fenping.conf', $conf);
}

function testDnsmasqCandidate(string $dir): void {
  runCommand(array('dnsmasq', '--test', '--conf-file=' . $dir . '/fenping.conf'), false);
}

function readDnsmasqCandidate(string $dir): array {
  $files = array();
  foreach (dnsmasqGeneratedFiles() as $key => $paths) {
    $path = $dir . '/' . $paths['candidate'];
    $contents = @file_get_contents($path);
    if ($contents === false)
      throw new RuntimeException("failed to read pending dnsmasq file: {$paths['candidate']}");
    $files[$key] = $contents;
  }
  return $files;
}

function ensureDnsmasqCandidateDir(string $dir): void {
  if (!is_dir($dir) && !mkdir($dir, 0700, true))
    throw new RuntimeException('failed to create dnsmasq candidate directory');
  if (is_link($dir) || !is_writable($dir))
    throw new RuntimeException('dnsmasq candidate directory is not writable');
}

function clearDnsmasqCandidateDir(string $dir): void {
  foreach (array('fenping.dhcp-hosts', 'fenping.dhcp-opts', 'fenping.hosts', 'fenping.conf') as $name)
    @unlink($dir . '/' . $name);
}

function createDnsmasqCandidateDir(): string {
  $dir = sys_get_temp_dir() . '/fenping-dnsmasq-' . bin2hex(random_bytes(8));
  ensureDnsmasqCandidateDir($dir);
  return $dir;
}

function removeDnsmasqCandidateDir(string $dir): void {
  clearDnsmasqCandidateDir($dir);
  @rmdir($dir);
}

function readLiveGeneratedFile(string $path): array {
  if (!file_exists($path))
    return array('exists' => false, 'contents' => '');
  $contents = @file_get_contents($path);
  if ($contents === false)
    throw new RuntimeException("failed to read $path");
  return array('exists' => true, 'contents' => $contents);
}

function restoreLiveGeneratedFile(string $path, array $snapshot): void {
  if (!$snapshot['exists']) {
    @unlink($path);
    return;
  }
  writeGeneratedFile($path, $snapshot['contents']);
}

function writeGeneratedFile(string $path, string $contents): void {
  if (is_file($path)) {
    $current = @file_get_contents($path);
    if ($current !== false && $current === $contents)
      return;
  }

  $dir = dirname($path);
  $tmp = tempnam($dir, basename($path) . '.');
  if ($tmp === false)
    throw new RuntimeException("failed to create temporary file for $path");

  if (file_put_contents($tmp, $contents) === false) {
    @unlink($tmp);
    throw new RuntimeException("failed to write $path");
  }

  chmod($tmp, 0644);
  if (!rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException("failed to replace $path");
  }
}

function acquireDnsmasqUpdateLock() {
  $lock = @fopen(DNSMASQ_UPDATE_LOCK, 'c');
  if ($lock === false)
    throw new RuntimeException('failed to open dnsmasq update lock');
  @chmod(DNSMASQ_UPDATE_LOCK, 0666);
  if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    throw new RuntimeException('failed to acquire dnsmasq update lock');
  }
  return $lock;
}

function releaseDnsmasqUpdateLock($lock): void {
  if (!is_resource($lock))
    return;
  flock($lock, LOCK_UN);
  fclose($lock);
}

function dnsmasqPid(): ?int {
  if (!is_readable(DNSMASQ_PID))
    return null;

  $pid = intval(trim((string)file_get_contents(DNSMASQ_PID)));
  return $pid > 0 ? $pid : null;
}

function dnsmasqRunning(): bool {
  $pid = dnsmasqPid();
  if ($pid === null)
    return false;

  if (function_exists('posix_kill'))
    return posix_kill($pid, 0);

  exec('kill -0 ' . escapeshellarg((string)$pid) . ' 2>/dev/null', $output, $code);
  return $code === 0;
}

function reloadDnsmasq(): void {
  if ((getenv('DNSMASQ_RELOAD_MODE') ?: 'local') === 'none') {
    echo "dnsmasq reload delegated" . PHP_EOL;
    return;
  }

  if (!dnsmasqRunning()) {
    @unlink(DNSMASQ_PID);
    runCommand(array('dnsmasq', '--test', '--conf-file=' . DNSMASQ_CONF));
    runCommand(array('dnsmasq', '--conf-file=' . DNSMASQ_CONF));
    echo "dnsmasq started" . PHP_EOL;
    return;
  }

  $pid = dnsmasqPid();
  if ($pid === null)
    throw new RuntimeException('dnsmasq pid disappeared');

  if (function_exists('posix_kill')) {
    if (!posix_kill($pid, DNSMASQ_RELOAD_SIGNAL))
      throw new RuntimeException('failed to reload dnsmasq');
  } else {
    runCommand(array('kill', '-HUP', (string)$pid));
  }

  echo "dnsmasq reloaded" . PHP_EOL;
}

function runCommand(array $command, bool $printOutput = true): string {
  $line = implode(' ', array_map('escapeshellarg', $command));
  $output = array();
  $code = 0;
  exec($line . ' 2>&1', $output, $code);

  if ($printOutput) {
    foreach ($output as $row)
      echo $row . PHP_EOL;
  }

  $text = trim(implode(PHP_EOL, $output));
  if ($code !== 0)
    throw new RuntimeException($text ?: "command failed: $line");
  return $text;
}

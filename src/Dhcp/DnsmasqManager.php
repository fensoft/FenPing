<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use RuntimeException;
use Throwable;

final readonly class DnsmasqManager
{
    public const DNSMASQ_CONF = '/etc/dnsmasq.d/fenping.conf';
    public const DNSMASQ_DHCP_HOSTS = '/etc/dnsmasq.d/fenping.dhcp-hosts';
    public const DNSMASQ_DHCP_OPTS = '/etc/dnsmasq.d/fenping.dhcp-opts';
    public const DNSMASQ_HOSTS = '/etc/dnsmasq.d/fenping.hosts';
    public const DNSMASQ_DNS_OVERRIDES = '/etc/dnsmasq.d/fenping.dns-overrides';
    public const DNSMASQ_PID = '/var/run/dnsmasq.pid';
    public const DNSMASQ_RELOAD_SIGNAL = 1;
    public const DNSMASQ_UPDATE_LOCK = '/tmp/fenping-dnsmasq-update.lock';

    public function __construct(private HostValidator $validator)
    {
    }

public function dnsmasqGeneratedFiles(): array {
  return array(
    'dhcpHosts' => array('candidate' => 'fenping.dhcp-hosts', 'live' => self::DNSMASQ_DHCP_HOSTS),
    'dhcpOptions' => array('candidate' => 'fenping.dhcp-opts', 'live' => self::DNSMASQ_DHCP_OPTS),
    'dnsHosts' => array('candidate' => 'fenping.hosts', 'live' => self::DNSMASQ_HOSTS),
    'customDns' => array('candidate' => 'fenping.dns-overrides', 'live' => self::DNSMASQ_DNS_OVERRIDES),
  );
}

public function dnsmasqPendingDir(): string {
  return '/run/fenping/dnsmasq-pending';
}

public function prepareDnsmasqCandidate(array $files, string $dir): void {
  $this->ensureDnsmasqCandidateDir($dir);
  $this->clearDnsmasqCandidateDir($dir);
  $this->validateGeneratedDnsmasqFiles($files);

  foreach ($this->dnsmasqGeneratedFiles() as $key => $paths)
    $this->writeGeneratedFile($dir . '/' . $paths['candidate'], $files[$key]);

  $this->writeDnsmasqCandidateConfig($dir);
  $this->testDnsmasqCandidate($dir);
}

public function applyDnsmasqCandidate(string $dir): void {
  $files = $this->readDnsmasqCandidate($dir);
  $this->validateGeneratedDnsmasqFiles($files);
  $this->writeDnsmasqCandidateConfig($dir);
  $this->testDnsmasqCandidate($dir);

  $previous = array();
  foreach ($this->dnsmasqGeneratedFiles() as $key => $paths)
    $previous[$key] = $this->readLiveGeneratedFile($paths['live']);

  try {
    foreach ($this->dnsmasqGeneratedFiles() as $key => $paths)
      $this->writeGeneratedFile($paths['live'], $files[$key]);

    $this->runCommand(array('dnsmasq', '--test', '--conf-file=' . self::DNSMASQ_CONF), false);
    $this->reloadDnsmasq();
  } catch (Throwable $applyError) {
    try {
      foreach ($this->dnsmasqGeneratedFiles() as $key => $paths)
        $this->restoreLiveGeneratedFile($paths['live'], $previous[$key]);
      $this->runCommand(array('dnsmasq', '--test', '--conf-file=' . self::DNSMASQ_CONF), false);
      $this->reloadDnsmasq();
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

public function validateGeneratedDnsmasqFiles(array $files): void {
  foreach (array('dhcpHosts', 'dhcpOptions', 'dnsHosts', 'customDns') as $key) {
    if (!array_key_exists($key, $files) || !is_string($files[$key]))
      throw new RuntimeException("missing generated dnsmasq data: $key");
    if (strlen($files[$key]) > 4 * 1024 * 1024 || strpos($files[$key], "\0") !== false)
      throw new RuntimeException("invalid generated dnsmasq data: $key");
  }

  foreach ($this->generatedDnsmasqLines($files['dhcpHosts']) as $line) {
    $parts = explode(',', $line);
    $count = count($parts);
    $nameAndLeaseValid = ($count === 4 && $parts[3] === 'infinite')
      || ($count === 5 && $this->validator->hostname($parts[3], true) === $parts[3] && $parts[4] === 'infinite');
    if (($count !== 4 && $count !== 5) || $this->validator->mac($parts[0], true) !== $parts[0]
      || preg_match('/^set:[A-Za-z0-9-]+$/', $parts[1]) !== 1
      || $this->validator->ip($parts[2], true) !== $parts[2]
      || !$nameAndLeaseValid)
      throw new RuntimeException('invalid generated DHCP reservation');
  }

  foreach ($this->generatedDnsmasqLines($files['dhcpOptions']) as $line)
    $this->validateGeneratedDhcpOption($line);

  foreach ($this->generatedDnsmasqLines($files['dnsHosts']) as $line) {
    $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false || count($parts) < 2 || $this->validator->ip(array_shift($parts), true) === null)
      throw new RuntimeException('invalid generated DNS host');
    foreach ($parts as $name) {
      if (preg_match('/^[A-Za-z0-9_@-]+(?:\.[A-Za-z0-9_@-]+)*$/', $name) !== 1)
        throw new RuntimeException('invalid generated DNS name');
    }
  }

  foreach ($this->generatedDnsmasqLines($files['customDns']) as $line) {
    if (preg_match('/^host-record=([a-z0-9.-]+(?:,[a-z0-9.-]+)*),(\d{1,3}(?:\.\d{1,3}){3})$/', $line, $matches) === 1) {
      $this->validator->ip($matches[2], true);
      foreach (explode(',', $matches[1]) as $name)
        $this->validateDnsOverrideName($name);
      continue;
    }
    if (preg_match('/^cname=([a-z0-9.-]+),([a-z0-9.-]+)$/', $line, $matches) === 1) {
      $this->validateDnsOverrideName($matches[1]);
      $this->validateDnsOverrideName($matches[2]);
      continue;
    }
    throw new RuntimeException('invalid generated DNS override');
  }
}

private function validateDnsOverrideName(string $name): void {
  if (strlen($name) > 253)
    throw new RuntimeException('invalid generated DNS override name');
  foreach (explode('.', $name) as $label) {
    if (strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1)
      throw new RuntimeException('invalid generated DNS override name');
  }
}

public function validateGeneratedDhcpOption(string $line): void {
  if (preg_match('/^tag:[A-Za-z0-9-]+,option:(router|tftp-server),(.+)$/', $line, $matches) === 1) {
    $this->validator->ip($matches[2], true);
    return;
  }

  if (preg_match('/^tag:[A-Za-z0-9-]+,option:dns-server,(.+)$/', $line, $matches) === 1) {
    $this->validator->dnsServers($matches[1]);
    return;
  }

  if (preg_match('/^tag:[A-Za-z0-9-]+,option:bootfile-name,(.+)$/', $line, $matches) === 1) {
    $this->validator->bootFilename($matches[1]);
    return;
  }

  throw new RuntimeException('invalid generated DHCP option');
}

public function generatedDnsmasqLines(string $contents): array {
  if ($contents === '')
    return array();
  $lines = preg_split('/\r?\n/', rtrim($contents, "\r\n"));
  if ($lines === false || in_array('', $lines, true))
    throw new RuntimeException('invalid blank line in generated dnsmasq data');
  return $lines;
}

public function writeDnsmasqCandidateConfig(string $dir): void {
  $conf = @file_get_contents(self::DNSMASQ_CONF);
  if ($conf === false)
    throw new RuntimeException('failed to read dnsmasq configuration');

  foreach ($this->dnsmasqGeneratedFiles() as $paths) {
    if (strpos($conf, $paths['live']) === false)
      throw new RuntimeException("dnsmasq configuration does not reference {$paths['live']}");
    $conf = str_replace($paths['live'], $dir . '/' . $paths['candidate'], $conf);
  }
  $this->writeGeneratedFile($dir . '/fenping.conf', $conf);
}

public function testDnsmasqCandidate(string $dir): void {
  $this->runCommand(array('dnsmasq', '--test', '--conf-file=' . $dir . '/fenping.conf'), false);
}

public function readDnsmasqCandidate(string $dir): array {
  $files = array();
  foreach ($this->dnsmasqGeneratedFiles() as $key => $paths) {
    $path = $dir . '/' . $paths['candidate'];
    $contents = @file_get_contents($path);
    if ($contents === false)
      throw new RuntimeException("failed to read pending dnsmasq file: {$paths['candidate']}");
    $files[$key] = $contents;
  }
  return $files;
}

public function ensureDnsmasqCandidateDir(string $dir): void {
  if (!is_dir($dir) && !mkdir($dir, 0700, true))
    throw new RuntimeException('failed to create dnsmasq candidate directory');
  if (is_link($dir) || !is_writable($dir))
    throw new RuntimeException('dnsmasq candidate directory is not writable');
}

public function clearDnsmasqCandidateDir(string $dir): void {
  foreach (array('fenping.dhcp-hosts', 'fenping.dhcp-opts', 'fenping.hosts', 'fenping.dns-overrides', 'fenping.conf') as $name)
    @unlink($dir . '/' . $name);
}

public function createDnsmasqCandidateDir(): string {
  $dir = sys_get_temp_dir() . '/fenping-dnsmasq-' . bin2hex(random_bytes(8));
  $this->ensureDnsmasqCandidateDir($dir);
  return $dir;
}

public function removeDnsmasqCandidateDir(string $dir): void {
  $this->clearDnsmasqCandidateDir($dir);
  @rmdir($dir);
}

public function readLiveGeneratedFile(string $path): array {
  if (!file_exists($path))
    return array('exists' => false, 'contents' => '');
  $contents = @file_get_contents($path);
  if ($contents === false)
    throw new RuntimeException("failed to read $path");
  return array('exists' => true, 'contents' => $contents);
}

public function restoreLiveGeneratedFile(string $path, array $snapshot): void {
  if (!$snapshot['exists']) {
    @unlink($path);
    return;
  }
  $this->writeGeneratedFile($path, $snapshot['contents']);
}

public function writeGeneratedFile(string $path, string $contents): void {
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

public function acquireDnsmasqUpdateLock() {
  $lock = @fopen(self::DNSMASQ_UPDATE_LOCK, 'c');
  if ($lock === false)
    throw new RuntimeException('failed to open dnsmasq update lock');
  @chmod(self::DNSMASQ_UPDATE_LOCK, 0666);
  if (!flock($lock, LOCK_EX)) {
    fclose($lock);
    throw new RuntimeException('failed to acquire dnsmasq update lock');
  }
  return $lock;
}

public function releaseDnsmasqUpdateLock($lock): void {
  if (!is_resource($lock))
    return;
  flock($lock, LOCK_UN);
  fclose($lock);
}

public function dnsmasqPid(): ?int {
  if (!is_readable(self::DNSMASQ_PID))
    return null;

  $pid = intval(trim((string)file_get_contents(self::DNSMASQ_PID)));
  return $pid > 0 ? $pid : null;
}

public function dnsmasqRunning(): bool {
  $pid = $this->dnsmasqPid();
  if ($pid === null)
    return false;

  if (function_exists('posix_kill'))
    return posix_kill($pid, 0);

  exec('kill -0 ' . escapeshellarg((string)$pid) . ' 2>/dev/null', $output, $code);
  return $code === 0;
}

public function reloadDnsmasq(): void {
  if ((getenv('DNSMASQ_RELOAD_MODE') ?: 'local') === 'none') {
    echo "dnsmasq reload delegated" . PHP_EOL;
    return;
  }

  if (!$this->dnsmasqRunning()) {
    @unlink(self::DNSMASQ_PID);
    $this->runCommand(array('dnsmasq', '--test', '--conf-file=' . self::DNSMASQ_CONF));
    $this->runCommand(array('dnsmasq', '--conf-file=' . self::DNSMASQ_CONF));
    echo "dnsmasq started" . PHP_EOL;
    return;
  }

  $pid = $this->dnsmasqPid();
  if ($pid === null)
    throw new RuntimeException('dnsmasq pid disappeared');

  if (function_exists('posix_kill')) {
    if (!posix_kill($pid, self::DNSMASQ_RELOAD_SIGNAL))
      throw new RuntimeException('failed to reload dnsmasq');
  } else {
    $this->runCommand(array('kill', '-HUP', (string)$pid));
  }

  echo "dnsmasq reloaded" . PHP_EOL;
}

public function runCommand(array $command, bool $printOutput = true): string {
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
}

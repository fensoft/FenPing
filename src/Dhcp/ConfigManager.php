<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use FenPing\Config\AppConfig;
use FenPing\Health\OperationTracker;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ConfigManager
{
    public function __construct(
        private AppConfig $config,
        private ConfigRenderer $renderer,
        private DnsmasqManager $dnsmasq,
        private OperationTracker $operations,
    ) {
    }

public function runHostsCommand(array $args = array()): int {
  $lock = null;
  $track = $args === array();
  if ($track)
    $this->operations->started('dnsmasq_generation');

  try {
    $this->ensureDnsmasqDirs();

    if ($args === array('--apply-pending')) {
      $this->dnsmasq->applyDnsmasqCandidate($this->dnsmasq->dnsmasqPendingDir());
      echo "pending dnsmasq files applied" . PHP_EOL;
      return 0;
    }

    if ($args === array('--sync-locked')) {
      $this->syncDnsmasqFromDatabase();
      return 0;
    }

    if (count($args) !== 0) {
      fwrite(STDERR, "Usage: php cli.php hosts" . PHP_EOL);
      return 2;
    }

    $lock = $this->dnsmasq->acquireDnsmasqUpdateLock();
    $this->syncDnsmasqFromDatabase();
    if ($track)
      $this->operations->succeeded('dnsmasq_generation');
    return 0;
  } catch (Throwable $e) {
    if ($track)
      $this->operations->failed('dnsmasq_generation', $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    return 1;
  } finally {
    $this->dnsmasq->releaseDnsmasqUpdateLock($lock);
  }
}

public function syncDnsmasqFromDatabase(): void {
  $candidateDir = $this->dnsmasq->createDnsmasqCandidateDir();

  try {
    $this->dnsmasq->prepareDnsmasqCandidate($this->renderer->buildDnsmasqFiles(), $candidateDir);
    $this->dnsmasq->applyDnsmasqCandidate($candidateDir);
    echo "dnsmasq files written" . PHP_EOL;
  } finally {
    $this->dnsmasq->removeDnsmasqCandidateDir($candidateDir);
  }
}

public function ensureDnsmasqDirs(): void {
  foreach (array('/etc/dnsmasq.d', '/var/lib/misc') as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true))
      throw new RuntimeException("failed to create $dir");
  }
}

public function run(array $arguments = array()): int { return $this->runHostsCommand($arguments); }

public function runPrivileged(string $mode): string {
  if (!in_array($mode, array('', '--apply-pending', '--sync-locked'), true))
    throw new InvalidArgumentException('invalid DHCP apply mode');
  $command = '/usr/bin/doas /usr/bin/php ' . escapeshellarg($this->config->projectDir . '/cli.php') . ' hosts';
  if ($mode !== '')
    $command .= ' ' . escapeshellarg($mode);
  $output = array();
  $code = 0;
  exec($command . ' 2>&1', $output, $code);
  if ($code !== 0)
    throw new RuntimeException(trim(implode("\n", $output)) ?: 'DHCP apply failed');
  return implode("\n", $output);
}

public function render(): array { return $this->renderer->buildDnsmasqFiles(); }

public function synchronize(): void { $this->syncDnsmasqFromDatabase(); }
}

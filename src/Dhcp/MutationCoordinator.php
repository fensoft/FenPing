<?php

declare(strict_types=1);

namespace FenPing\Dhcp;

use RuntimeException;
use Throwable;

use FenPing\Database\DatabaseManager;
use FenPing\Health\OperationTracker;

final readonly class MutationCoordinator
{
    public function __construct(
        private DatabaseManager $database,
        private ConfigManager $config,
        private DnsmasqManager $dnsmasq,
        private OperationTracker $operations,
    ) {
    }

public function commitDhcpMutation(callable $mutation): array {
  $lock = $this->dnsmasq->acquireDnsmasqUpdateLock();
  $database = $this->database->connection();
  $candidateDir = $this->dnsmasq->dnsmasqPendingDir();
  $applied = false;
  $configuring = false;

  try {
    if ($database->inTransaction())
      throw new RuntimeException('database transaction already active');

    $this->dnsmasq->clearDnsmasqCandidateDir($candidateDir);
    $this->database->beginImmediate();
    $result = $mutation();
    $configuring = true;
    $this->dnsmasq->prepareDnsmasqCandidate($this->config->render(), $candidateDir);
    $log = $this->config->runPrivileged('--apply-pending');
    $applied = true;
    $this->database->commit();
    $this->operations->started('dnsmasq_generation');
    $this->operations->succeeded('dnsmasq_generation');

    return array('result' => $result, 'log' => $log);
  } catch (Throwable $error) {
    $recoveryErrors = array();

    if ($database->inTransaction()) {
      try {
        $this->database->rollback();
      } catch (Throwable $rollbackError) {
        $recoveryErrors[] = 'database rollback failed: ' . $rollbackError->getMessage();
      }
    }

    if ($applied) {
      try {
        $this->config->runPrivileged('--sync-locked');
      } catch (Throwable $syncError) {
        $recoveryErrors[] = 'dnsmasq recovery failed: ' . $syncError->getMessage();
      }
    }

    if ($configuring) {
      $this->operations->started('dnsmasq_generation');
      $this->operations->failed('dnsmasq_generation', $error->getMessage());
    }
    if (count($recoveryErrors) !== 0) {
      throw new RuntimeException(
        'DHCP update failed: ' . $error->getMessage() . '; ' . implode('; ', $recoveryErrors),
        0,
        $error
      );
    }
    throw $error;
  } finally {
    $this->dnsmasq->clearDnsmasqCandidateDir($candidateDir);
    $this->dnsmasq->releaseDnsmasqUpdateLock($lock);
  }
}

public function commit(callable $mutation): array { return $this->commitDhcpMutation($mutation); }
}

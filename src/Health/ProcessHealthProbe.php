<?php

declare(strict_types=1);

namespace FenPing\Health;

final readonly class ProcessHealthProbe
{
public function healthProcess(string $name, ?string $pidFile = null): array {
  $pid = $this->healthPidFromFile($pidFile);
  if ($pid !== null && $this->healthPidRunning($pid) && $this->healthPidMatches($pid, $name)) {
    return array(
      'running' => true,
      'pid' => $pid
    );
  }

  $pids = $this->healthPidsByName($name);
  return array(
    'running' => count($pids) > 0,
    'pid' => $pids[0] ?? $pid
  );
}

public function healthPidFromFile(?string $pidFile): ?int {
  if ($pidFile === null || !is_readable($pidFile))
    return null;

  $pid = (int)trim((string)file_get_contents($pidFile));
  return $pid > 0 ? $pid : null;
}

public function healthPidRunning(int $pid): bool {
  return is_dir('/proc/' . $pid);
}

public function healthPidMatches(int $pid, string $name): bool {
  $path = '/proc/' . $pid;
  $comm = $this->healthReadProcFile($path . '/comm');
  $cmdline = str_replace("\0", ' ', $this->healthReadProcFile($path . '/cmdline'));
  $command = basename(strtok($cmdline, ' ') ?: '');
  return $comm === $name || $command === $name;
}

public function healthPidsByName(string $name): array {
  $pids = array();
  foreach (glob('/proc/[0-9]*') ?: array() as $path) {
    $pid = (int)basename($path);
    if ($pid <= 0)
      continue;

    if ($this->healthPidMatches($pid, $name))
      $pids[] = $pid;
  }

  sort($pids);
  return $pids;
}

public function healthReadProcFile(string $path): string {
  if (!is_readable($path))
    return '';

  $contents = file_get_contents($path);
  return $contents === false ? '' : trim($contents);
}

}

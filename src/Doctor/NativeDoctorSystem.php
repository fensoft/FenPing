<?php

declare(strict_types=1);

namespace FenPing\Doctor;

use FenPing\Config\AppConfig;
use FenPing\Process\ProcessRunner;
use RuntimeException;
use Socket;
use Throwable;

final readonly class NativeDoctorSystem implements DoctorSystem
{
    public function __construct(private ProcessRunner $processes)
    {
    }

    public function interfaceExists(string $interface): bool
    {
        return $interface !== '' && basename($interface) === $interface && is_dir('/sys/class/net/' . $interface);
    }

    public function interfaceUp(string $interface): bool
    {
        $path = '/sys/class/net/' . basename($interface) . '/flags';
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }
        return (hexdec(trim($contents)) & 1) === 1;
    }

    public function bindError(string $protocol, string $address, int $port, ?string $interface = null): ?string
    {
        $type = $protocol === 'tcp' ? SOCK_STREAM : SOCK_DGRAM;
        $socket = @socket_create(AF_INET, $type, $protocol === 'tcp' ? SOL_TCP : SOL_UDP);
        if (!$socket instanceof Socket) {
            return 'socket creation failed';
        }
        try {
            if ($protocol === 'tcp'
                && !@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
                return 'could not enable safe TCP address reuse';
            }
            if ($interface !== null && (!defined('SO_BINDTODEVICE')
                || !@socket_set_option($socket, SOL_SOCKET, SO_BINDTODEVICE, $interface))) {
                return 'could not bind the probe to interface ' . $interface;
            }
            if (!@socket_bind($socket, $address, $port)) {
                return socket_strerror(socket_last_error($socket));
            }
            if ($protocol === 'tcp' && !@socket_listen($socket, 1)) {
                return socket_strerror(socket_last_error($socket));
            }
            return null;
        } finally {
            socket_close($socket);
        }
    }

    public function listenerError(
        string $protocol,
        string $address,
        int $port,
        ?string $interface,
        string $expectedProcess,
    ): ?string {
        try {
            $result = $this->processes->run([
                'ss', '-H', '-4', $protocol === 'tcp' ? '-lntp' : '-lnup',
            ]);
        } catch (Throwable $error) {
            return $error->getMessage();
        }
        if (!$result->successful()) {
            return trim($result->stderr) ?: 'failed to inspect listeners';
        }

        $owners = [];
        $ownerHidden = false;
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if (preg_match('/^\S+\s+\d+\s+\d+\s+(\S+):(\d+)\s+/', $line, $matches) !== 1
                || (int) $matches[2] !== $port) {
                continue;
            }
            $local = trim($matches[1], '[]');
            $localAddress = explode('%', $local, 2)[0];
            if ($address !== '0.0.0.0' && !in_array($localAddress, [$address, '0.0.0.0'], true)) {
                continue;
            }
            if ($interface !== null && str_contains($local, '%') && !str_ends_with($local, '%' . $interface)) {
                continue;
            }
            $owner = preg_match('/\(\("([^"]+)"/', $line, $ownerMatch) === 1 ? $ownerMatch[1] : '';
            if ($owner === $expectedProcess) {
                return null;
            }
            if ($owner === '') {
                $ownerHidden = true;
            } else {
                $owners[$owner] = true;
            }
        }
        if ($owners !== []) {
            return 'owned by ' . implode(', ', array_keys($owners)) . " instead of $expectedProcess";
        }
        if ($ownerHidden) {
            try {
                $process = $this->processes->run(['pidof', $expectedProcess]);
                return $process->successful() && trim($process->stdout) !== ''
                    ? null
                    : "$expectedProcess process is not running";
            } catch (Throwable $error) {
                return $error->getMessage();
            }
        }
        return "$expectedProcess is not listening";
    }

    public function storageErrors(AppConfig $config): array
    {
        $errors = [];
        $databaseDir = dirname($config->databasePath);
        foreach ([
            'backup directory' => $config->backupDir(),
            'state directory' => $config->stateDir(),
            'dnsmasq configuration directory' => '/etc/dnsmasq.d',
            'dnsmasq lease directory' => '/var/lib/misc',
        ] as $label => $path) {
            if (($error = $this->atomicWriteError($path)) !== null) {
                $errors[] = "$label: $error";
            }
        }

        foreach ([
            'database directory' => $databaseDir,
            'netboot directory' => $config->netbootDir(),
        ] as $label => $path) {
            if (($error = $this->atomicWriteAsWwwDataError($path)) !== null) {
                $errors[] = "$label: $error";
            }
        }
        if (is_file($config->databasePath) && !$this->writableAsWwwData($config->databasePath)) {
            $errors[] = 'database file is not writable by the application worker';
        }
        if (($error = $this->sqliteWalError($databaseDir)) !== null) {
            $errors[] = 'database WAL probe: ' . $error;
        }
        if (is_file('/var/lib/misc/dnsmasq.leases') && !is_writable('/var/lib/misc/dnsmasq.leases')) {
            $errors[] = 'dnsmasq lease file is not writable';
        }
        return $errors;
    }

    private function atomicWriteError(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return 'directory does not exist';
        }
        $source = $directory . '/.fenping-doctor-' . bin2hex(random_bytes(8));
        $target = $source . '.renamed';
        try {
            if (@file_put_contents($source, "doctor\n", LOCK_EX) === false) {
                return 'create/write failed';
            }
            if (!@rename($source, $target)) {
                return 'atomic rename failed';
            }
            return null;
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    private function atomicWriteAsWwwDataError(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return 'directory does not exist';
        }
        $source = $directory . '/.fenping-doctor-' . bin2hex(random_bytes(8));
        $target = $source . '.renamed';
        $script = 'set -eu; trap ' . escapeshellarg('rm -f ' . escapeshellarg($source) . ' ' . escapeshellarg($target))
            . ' EXIT; printf "doctor\\n" > ' . escapeshellarg($source)
            . '; mv ' . escapeshellarg($source) . ' ' . escapeshellarg($target);
        try {
            $result = $this->processes->run(['su', 'www-data', '-s', '/bin/sh', '-c', $script]);
            return $result->successful() ? null : (trim($result->stderr) ?: 'application-worker write failed');
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }

    private function writableAsWwwData(string $path): bool
    {
        try {
            return $this->processes->run([
                'su', 'www-data', '-s', '/bin/sh', '-c', 'test -w ' . escapeshellarg($path),
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function sqliteWalError(string $directory): ?string
    {
        $path = $directory . '/.fenping-doctor-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $code = '$path=' . var_export($path, true) . '; try {'
            . '$db=new PDO("sqlite:".$path);'
            . '$mode=strtolower((string)$db->query("PRAGMA journal_mode=WAL")->fetchColumn());'
            . 'if($mode!=="wal"){throw new RuntimeException("journal mode is ".$mode);}'
            . '$db->exec("CREATE TABLE probe (id INTEGER PRIMARY KEY)");'
            . '} finally {unset($db);@unlink($path);@unlink($path."-wal");@unlink($path."-shm");}';
        $script = 'exec php -r ' . escapeshellarg($code);
        try {
            $result = $this->processes->run(['su', 'www-data', '-s', '/bin/sh', '-c', $script]);
            return $result->successful() ? null : (trim($result->stderr) ?: 'SQLite WAL creation failed');
        } catch (Throwable $error) {
            return $error->getMessage();
        }
    }
}

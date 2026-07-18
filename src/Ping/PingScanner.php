<?php

declare(strict_types=1);

namespace FenPing\Ping;

use FenPing\Config\AppConfig;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use OutOfBoundsException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use FenPing\Realtime\LiveUpdateScope;

final readonly class PingScanner implements PingScannerGateway
{
    public function __construct(private AppConfig $config)
    {
    }

public function scan(array $ips, array $localIps = []): array {
  $interface = $this->config->interface;
  $hosts = array();
  $localMac = $this->localMac($interface);
  $icmpUp = $this->pingSweep(array_values($ips), $interface, $localIps);
  $arpTable = $this->readArpTable($interface);

  foreach ($ips as $ip)
    $hosts[] = $this->pingHost($ip, $interface, $localIps, $localMac, $arpTable, $icmpUp);

  return $hosts;
}

private function pingHost($ip, $interface, $localIps = array(), $localMac = null, $arpTable = null, $icmpUp = null) {
  $mac = "";

  if (in_array($ip, $localIps, true)) {
    $host = array("ip" => $ip, "mac" => $localMac === null ? $this->localMac($interface) : $localMac, "status" => "Up");
    return $host;
  }

  if ($icmpUp === null)
    $icmpUp = $this->pingSweep(array($ip), $interface, array());
  if ($arpTable === null)
    $arpTable = $this->readArpTable($interface);

  $status = !empty($icmpUp[$ip]) ? "Up" : "Down";
  $mac = $arpTable[$ip] ?? "";

  if ($status === "Down" && $mac !== "") {
    $status = "arp";
    if (!$this->arpingReplies($ip, $interface)) {
      $status = "arp-down";
    } elseif ($this->singlePing($ip, $interface)) {
      $status = "Up";
    }
  }

  $host = array("ip" => $ip, "mac" => strtolower($mac), "status" => $status);
  return $host;
}

private function localMac($interface) {
  if ($interface === "")
    return "";
  $path = "/sys/class/net/" . basename($interface) . "/address";
  if (!is_readable($path))
    return "";
  return trim((string)file_get_contents($path));
}

private function readArpTable($preferredInterface) {
  if (!is_readable('/proc/net/arp'))
    return array();

  $rows = file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $arp = array();
  foreach (array_slice($rows, 1) as $row) {
    $parts = preg_split('/\s+/', trim($row));
    if (count($parts) < 6)
      continue;

    list($ip, $_type, $flags, $mac, $_mask, $device) = $parts;
    if (strtolower($flags) !== '0x2')
      continue;
    if ($mac === '00:00:00:00:00:00')
      continue;
    if ($preferredInterface !== '' && $device !== $preferredInterface && isset($arp[$ip]))
      continue;
    if ($preferredInterface === '' || $device === $preferredInterface || !isset($arp[$ip]))
      $arp[$ip] = strtolower($mac);
  }
  return $arp;
}

private function pingSweep($ips, $interface, $skipIps) {
  $result = array();
  foreach ($skipIps as $ip) {
    if ($ip !== "")
      $result[$ip] = true;
  }

  $socket = $this->createIcmpSocket();
  if ($socket === false) {
    foreach ($ips as $ip) {
      if (!isset($result[$ip]))
        $result[$ip] = $this->systemPing($ip, $interface);
    }
    return $result;
  }

  $id = getmypid() & 0xffff;
  $sequenceToIp = array();
  $sequence = 1;
  foreach ($ips as $ip) {
    if (isset($result[$ip]))
      continue;
    $sequenceToIp[$sequence] = $ip;
    $packet = $this->icmpPacket($id, $sequence);
    @socket_sendto($socket, $packet, strlen($packet), 0, $ip, 0);
    $sequence++;
  }

  $deadline = microtime(true) + 1.5;
  while (microtime(true) < $deadline) {
    $read = array($socket);
    $write = null;
    $except = null;
    $remaining = max(0, $deadline - microtime(true));
    $seconds = (int)$remaining;
    $microseconds = (int)(($remaining - $seconds) * 1000000);
    $selected = @socket_select($read, $write, $except, $seconds, $microseconds);
    if ($selected === false || $selected === 0)
      break;

    $buffer = '';
    $from = '';
    $port = 0;
    if (@socket_recvfrom($socket, $buffer, 65535, 0, $from, $port) === false)
      continue;

    $reply = $this->parseIcmpReply($buffer);
    if ($reply === null || $reply["id"] !== $id)
      continue;
    if (isset($sequenceToIp[$reply["sequence"]]))
      $result[$sequenceToIp[$reply["sequence"]]] = true;
  }

  socket_close($socket);
  return $result;
}

private function singlePing($ip, $interface) {
  $result = $this->pingSweep(array($ip), $interface, array());
  return !empty($result[$ip]);
}

private function createIcmpSocket() {
  if (!extension_loaded('sockets'))
    return false;
  $protocol = getprotobyname('icmp');
  $socket = @socket_create(AF_INET, SOCK_RAW, $protocol);
  if ($socket === false)
    return false;
  @socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 1, "usec" => 0));
  return $socket;
}

private function icmpPacket($id, $sequence) {
  $payload = "FenPing" . pack('N', time());
  $header = pack('C2n3', 8, 0, 0, $id, $sequence);
  $checksum = $this->checksum($header . $payload);
  return pack('C2', 8, 0) . $checksum . pack('n2', $id, $sequence) . $payload;
}

private function checksum($data) {
  $sum = 0;
  $length = strlen($data);
  for ($i = 0; $i < $length; $i += 2) {
    $word = ord($data[$i]) << 8;
    if ($i + 1 < $length)
      $word += ord($data[$i + 1]);
    $sum += $word;
  }
  while ($sum >> 16)
    $sum = ($sum & 0xffff) + ($sum >> 16);
  return pack('n', ~$sum & 0xffff);
}

private function parseIcmpReply($buffer) {
  if (strlen($buffer) < 28)
    return null;
  $ipHeaderLength = (ord($buffer[0]) & 0x0f) * 4;
  if (strlen($buffer) < $ipHeaderLength + 8)
    return null;
  $type = ord($buffer[$ipHeaderLength]);
  if ($type !== 0)
    return null;
  $parts = unpack('nid/nsequence', substr($buffer, $ipHeaderLength + 4, 4));
  return array("id" => $parts["id"], "sequence" => $parts["sequence"]);
}

private function arpingReplies($ip, $interface) {
  if ($interface === "")
    return false;
  $cmd = "arping -I " . escapeshellarg($interface) . " -c 2 -w 1 " . escapeshellarg($ip) . " >/dev/null 2>&1";
  exec($cmd, $output, $code);
  return $code === 0;
}

private function systemPing($ip, $interface) {
  $cmd = "ping -c 1 -W 1 ";
  if ($interface !== "")
    $cmd .= "-I " . escapeshellarg($interface) . " ";
  $cmd .= escapeshellarg($ip) . " >/dev/null 2>&1";
  exec($cmd, $output, $code);
  return $code === 0;
}
}

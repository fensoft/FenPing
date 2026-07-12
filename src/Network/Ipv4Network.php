<?php

declare(strict_types=1);

namespace FenPing\Network;

use InvalidArgumentException;

final readonly class Ipv4Network
{
    private function __construct(public string $cidr, public string $address, public int $prefixLength)
    {
    }

    public static function from24(string $value, string $label = 'network'): self
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/24$/', $value, $matches)
            || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("invalid $label; expected a canonical IPv4 /24 CIDR");
        }
        $parts = explode('.', $matches[1]);
        $canonical = implode('.', array_map(static fn(string $part): int => (int) $part, $parts));
        if ($parts[3] !== '0' || $canonical !== $matches[1]) {
            throw new InvalidArgumentException("invalid $label; expected a canonical IPv4 /24 CIDR ending in .0/24");
        }
        return new self($matches[1] . '/24', $matches[1], 24);
    }

    public function prefix(): string
    {
        return substr($this->address, 0, strrpos($this->address, '.'));
    }

    public function contains(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        return (ip2long($ip) & 0xffffff00) === (ip2long($this->address) & 0xffffff00);
    }

    public function host(int $octet): string
    {
        if ($octet < 1 || $octet > 254) throw new InvalidArgumentException('host octet must be from 1 to 254');
        return $this->prefix() . '.' . $octet;
    }

    public function discoveryRange(): string
    {
        return $this->prefix() . '.1-254';
    }
}

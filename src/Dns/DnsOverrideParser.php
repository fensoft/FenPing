<?php

declare(strict_types=1);

namespace FenPing\Dns;

use InvalidArgumentException;

final readonly class DnsOverrideParser
{
    /** @return list<array{type:string, ip?:string, names?:list<string>, name?:string, target?:string, line:int}> */
    public function parse(string $contents, string $groupName = 'DNS group'): array
    {
        if (strlen($contents) > 512 * 1024) {
            throw new InvalidArgumentException("$groupName is larger than 512 KiB");
        }

        $records = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = trim((string) preg_replace('/\s+#.*$/', '', trim($rawLine)));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (strcasecmp($parts[0] ?? '', 'CNAME') === 0) {
                if (count($parts) !== 3) {
                    throw $this->lineError($groupName, $lineNumber, 'CNAME lines must be: CNAME alias target');
                }
                $records[] = [
                    'type' => 'cname',
                    'name' => $this->name($parts[1], $groupName, $lineNumber),
                    'target' => $this->name($parts[2], $groupName, $lineNumber),
                    'line' => $lineNumber,
                ];
                continue;
            }

            if (count($parts) < 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw $this->lineError($groupName, $lineNumber, 'use: IPv4 name [name ...] or CNAME alias target');
            }
            $names = [];
            foreach (array_slice($parts, 1) as $value) {
                $name = $this->name($value, $groupName, $lineNumber);
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            $records[] = ['type' => 'host', 'ip' => $parts[0], 'names' => $names, 'line' => $lineNumber];
        }

        if (count($records) > 10000) {
            throw new InvalidArgumentException("$groupName contains more than 10,000 records");
        }
        return $records;
    }

    /**
     * @param list<array{id:int, name:string, enabled:bool|int, contents:string}> $groups
     * @param list<string> $baseNames
     * @return array{config:string, owned_names:list<string>, record_counts:array<int, int>}
     */
    public function compile(array $groups, array $baseNames): array
    {
        $records = [];
        $owned = [];
        $counts = [];
        foreach ($groups as $group) {
            if (!(bool) $group['enabled']) {
                continue;
            }
            $parsed = $this->parse($group['contents'], $group['name']);
            $counts[(int) $group['id']] = count($parsed);
            foreach ($parsed as $record) {
                $record['group'] = $group['name'];
                $records[] = $record;
                $recordNames = $record['type'] === 'host' ? $record['names'] : [$record['name']];
                foreach ($recordNames as $name) {
                    if (isset($owned[$name])) {
                        throw new InvalidArgumentException(
                            "DNS name $name is defined more than once ({$owned[$name]} and {$group['name']} line {$record['line']})",
                        );
                    }
                    $owned[$name] = "{$group['name']} line {$record['line']}";
                }
            }
        }

        $known = [];
        foreach ($baseNames as $name) {
            $normalized = strtolower(rtrim($name, '.'));
            if ($normalized !== '' && !isset($owned[$normalized])) {
                $known[$normalized] = true;
            }
        }
        $aliases = [];
        foreach ($records as $record) {
            if ($record['type'] === 'host') {
                foreach ($record['names'] as $name) {
                    $known[$name] = true;
                }
            } else {
                $aliases[$record['name']] = $record;
            }
        }
        foreach ($aliases as $name => $record) {
            $this->validateAliasTarget($name, $record['target'], $aliases, $known, $record['group'], $record['line']);
        }

        $lines = [];
        foreach ($records as $record) {
            $lines[] = $record['type'] === 'host'
                ? 'host-record=' . implode(',', [...$record['names'], $record['ip']])
                : "cname={$record['name']},{$record['target']}";
        }
        return [
            'config' => $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL,
            'owned_names' => array_keys($owned),
            'record_counts' => $counts,
        ];
    }

    /** @param array<string, array> $aliases @param array<string, bool> $known */
    private function validateAliasTarget(
        string $origin,
        string $target,
        array $aliases,
        array $known,
        string $group,
        int $line,
        array $visited = [],
    ): void {
        if (isset($known[$target])) {
            return;
        }
        if ($target === $origin || isset($visited[$target])) {
            throw $this->lineError($group, $line, "CNAME cycle involving $target");
        }
        if (!isset($aliases[$target])) {
            throw $this->lineError(
                $group,
                $line,
                "CNAME target $target is not a local FenPing or enabled custom record",
            );
        }
        $visited[$target] = true;
        $next = $aliases[$target];
        $this->validateAliasTarget($origin, $next['target'], $aliases, $known, $group, $line, $visited);
    }

    private function name(string $value, string $group, int $line): string
    {
        $name = strtolower(rtrim(trim($value), '.'));
        if ($name === '' || strlen($name) > 253) {
            throw $this->lineError($group, $line, 'invalid DNS name');
        }
        foreach (explode('.', $name) as $label) {
            if (strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                throw $this->lineError($group, $line, "invalid DNS name: $value");
            }
        }
        return $name;
    }

    private function lineError(string $group, int $line, string $message): InvalidArgumentException
    {
        return new InvalidArgumentException("$group line $line: $message");
    }
}

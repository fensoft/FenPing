<?php

declare(strict_types=1);

namespace FenPing\Scan;

final class ProgressParser
{
    public static function parse(string $line): ?array
    {
        $line = trim($line);
        $isMilestone = false;
        if (preg_match('/^(.+?)\s+Timing:\s+About\s+([0-9]+(?:\.[0-9]+)?)%\s+done/i', $line, $matches)) {
            $label = $matches[1];
            $phasePercent = (float) $matches[2];
        } elseif (preg_match('/^NSE:\s+Script\s+(scanning\b.*|Post-scanning\.?)$/i', $line, $matches)) {
            $label = 'script';
            $phasePercent = str_starts_with(strtolower($matches[1]), 'post') ? 100.0 : 0.0;
            $isMilestone = true;
        } elseif (preg_match('/^(Initiating|Completed)\s+(.+?)(?:\s+at\s+\d{1,2}:\d{2}.*)?$/i', $line, $matches)) {
            $label = $matches[2];
            if (strtolower(trim($label)) === 'nse') {
                return null;
            }
            $phasePercent = strtolower($matches[1]) === 'completed' ? 100.0 : 0.0;
            $isMilestone = true;
        } else {
            return null;
        }

        $label = strtolower(trim($label));
        $phase = match (true) {
            str_contains($label, 'ping'), str_contains($label, 'host discovery') => 'host_discovery',
            str_contains($label, 'service') => 'service_detection',
            str_contains($label, 'script'), str_contains($label, 'nse') => 'script_scan',
            str_contains($label, 'os ') || str_starts_with($label, 'os') => 'os_detection',
            str_contains($label, 'trace') => 'traceroute',
            str_contains($label, 'scan') || str_contains($label, 'port') => 'port_scan',
            default => 'running',
        };
        if ($isMilestone && $phase === 'running') {
            return null;
        }

        return [
            'phase' => $phase,
            'phase_percent' => max(0.0, min(100.0, $phasePercent)),
        ];
    }

    public static function overall(string $profile, string $phase, float $phasePercent, int $previous = 0): int
    {
        $ranges = $profile === 'quick' || $profile === 'lightweight'
            ? [
                'host_discovery' => [1, 5],
                'port_scan' => [5, 99],
                'finalizing' => [99, 99],
            ]
            : [
                'host_discovery' => [1, 5],
                'port_scan' => [5, 55],
                'service_detection' => [55, 75],
                'script_scan' => [75, 90],
                'os_detection' => [90, 96],
                'traceroute' => [96, 99],
                'finalizing' => [99, 99],
            ];

        if (!isset($ranges[$phase])) {
            return max(0, min(99, $previous));
        }
        [$start, $end] = $ranges[$phase];
        $estimated = (int) floor($start + ($end - $start) * max(0.0, min(100.0, $phasePercent)) / 100);
        return max($previous, min(99, $estimated));
    }
}

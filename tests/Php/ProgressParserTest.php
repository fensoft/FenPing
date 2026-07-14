<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Scan\ProgressParser;
use PHPUnit\Framework\TestCase;

final class ProgressParserTest extends TestCase
{
    public function testRepresentativeNmapTimingLinesUseStablePhases(): void
    {
        self::assertSame(
            ['phase' => 'host_discovery', 'phase_percent' => 25.0],
            ProgressParser::parse('Ping Scan Timing: About 25.00% done; ETC: 12:00'),
        );
        self::assertSame(
            ['phase' => 'port_scan', 'phase_percent' => 50.5],
            ProgressParser::parse('SYN Stealth Scan Timing: About 50.50% done; ETC: 12:01'),
        );
        self::assertSame(
            ['phase' => 'service_detection', 'phase_percent' => 10.0],
            ProgressParser::parse('Service scan Timing: About 10% done'),
        );
        self::assertSame(
            ['phase' => 'script_scan', 'phase_percent' => 90.0],
            ProgressParser::parse('NSE Timing: About 90.00% done'),
        );
        self::assertSame(
            ['phase' => 'os_detection', 'phase_percent' => 40.0],
            ProgressParser::parse('OS Scan Timing: About 40.00% done'),
        );
        self::assertSame(
            ['phase' => 'traceroute', 'phase_percent' => 75.0],
            ProgressParser::parse('Traceroute Timing: About 75.00% done'),
        );
        self::assertSame(
            ['phase' => 'running', 'phase_percent' => 12.0],
            ProgressParser::parse('Future Nmap Task Timing: About 12.00% done'),
        );
        self::assertNull(ProgressParser::parse('malformed status output'));
    }

    public function testLifecycleMilestonesAdvanceStandardScansWithoutTimingPercentages(): void
    {
        $milestones = [
            ['Completed SYN Stealth Scan at 02:00, 0.02s elapsed (1000 total ports)', 'port_scan', 100.0, 55],
            ['Initiating Service scan at 02:00', 'service_detection', 0.0, 55],
            ['Completed Service scan at 02:00, 6.03s elapsed (4 services on 1 host)', 'service_detection', 100.0, 75],
            ['Initiating OS detection (try #1) against localhost (127.0.0.1)', 'os_detection', 0.0, 90],
            ['NSE: Script scanning 127.0.0.1.', 'script_scan', 0.0, 90],
            ['NSE: Script Post-scanning.', 'script_scan', 100.0, 90],
            ['Initiating Traceroute at 02:01', 'traceroute', 0.0, 96],
            ['Completed Traceroute at 02:01, 0.01s elapsed', 'traceroute', 100.0, 99],
        ];

        $progress = 1;
        foreach ($milestones as [$line, $phase, $phasePercent, $expectedProgress]) {
            $parsed = ProgressParser::parse($line);
            self::assertSame($phase, $parsed['phase']);
            self::assertSame($phasePercent, $parsed['phase_percent']);
            $progress = ProgressParser::overall('standard', $parsed['phase'], $parsed['phase_percent'], $progress);
            self::assertSame($expectedProgress, $progress);
        }

        self::assertNull(ProgressParser::parse('NSE: Script Pre-scanning.'));
        self::assertNull(ProgressParser::parse('Initiating NSE at 02:00'));
        self::assertNull(ProgressParser::parse('Initiating Future Nmap Task at 02:00'));
    }

    public function testOverallMappingIsProfileAwareAndMonotonic(): void
    {
        self::assertSame(3, ProgressParser::overall('standard', 'host_discovery', 50));
        self::assertSame(30, ProgressParser::overall('standard', 'port_scan', 50, 3));
        self::assertSame(65, ProgressParser::overall('standard', 'service_detection', 50, 30));
        self::assertSame(65, ProgressParser::overall('standard', 'host_discovery', 100, 65));
        self::assertSame(93, ProgressParser::overall('deep', 'os_detection', 50, 65));
        self::assertSame(93, ProgressParser::overall('deep', 'running', 99, 93));
        self::assertSame(52, ProgressParser::overall('lightweight', 'port_scan', 50, 1));
        self::assertSame(99, ProgressParser::overall('lightweight', 'port_scan', 100, 1));
    }
}

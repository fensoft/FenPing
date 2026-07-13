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

<?php

declare(strict_types=1);

namespace FenPing\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ArchitectureTest extends TestCase
{
    public function testProductionModulesStayFocused(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = count(file($file->getPathname(), FILE_IGNORE_NEW_LINES));
            self::assertLessThanOrEqual(400, $lines, $file->getPathname() . ' exceeds 400 lines');
        }
    }

    public function testProductionFilesDeclareOnePrimaryTypeAndNoFreeFunctions(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertSame(
                1,
                preg_match_all('/^(?:(?:abstract|final|readonly)\\s+)*(?:class|interface|trait|enum)\\s+/m', $source),
                $file->getPathname() . ' must declare exactly one primary type',
            );
            self::assertDoesNotMatchRegularExpression(
                '/^function\\s+/m',
                $source,
                $file->getPathname() . ' contains a namespace-level function',
            );
        }
    }

    public function testLegacyRootModulesWereRemoved(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['scans.php', 'functions.php', 'database.php', 'hosts.php', 'inventory.php', 'backup.php', 'auth.php', 'discord.php', 'health.php', 'http.php', 'ipam.php', 'oui.php', 'ping.php', 'dnsmasq.leases.php'] as $file) {
            self::assertFileDoesNotExist($root . '/' . $file);
        }
        self::assertDirectoryDoesNotExist($root . '/routes');
        self::assertDirectoryDoesNotExist($root . '/src/Legacy');
    }

    public function testLegacyBackendFacadeAndRouteAdapterAreAbsent(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertDirectoryDoesNotExist($root . '/src/Backend');
        self::assertFileDoesNotExist($root . '/src/Api/Controller/RouteAdapter.php');

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            self::assertStringNotContainsString('FenPing\\Backend', $source, $file->getPathname());
            self::assertStringNotContainsString('RouteAdapter', $source, $file->getPathname());
        }
    }
}

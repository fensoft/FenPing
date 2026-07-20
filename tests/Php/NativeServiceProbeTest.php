<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use FenPing\Service\NativeServiceProbe;
use FenPing\Service\SshConnector;
use PHPUnit\Framework\TestCase;

final class NativeServiceProbeTest extends TestCase
{
    public function testHttpsAcceptsAnyFinalTwoHundredResponseWithThreeRedirects(): void
    {
        $processes = new ProbeProcessRunner(new ProcessResult(0, '204'));
        $probe = new NativeServiceProbe($processes, new ProbeSshConnector());
        $result = $probe->check(['type' => 'https', 'target' => 'https://example.test/health']);

        self::assertTrue($result->healthy);
        self::assertSame('HTTP 204', $result->detail);
        self::assertContains('3', $processes->command);
        self::assertContains('10', $processes->command);
        self::assertContains('=https', $processes->command);
    }

    public function testHttpsReportsNonTwoHundredResponsesAsUnhealthy(): void
    {
        $probe = new NativeServiceProbe(new ProbeProcessRunner(new ProcessResult(0, '503')), new ProbeSshConnector());
        $result = $probe->check(['type' => 'https', 'target' => 'https://example.test/health']);
        self::assertFalse($result->healthy);
        self::assertSame('HTTP 503', $result->detail);
    }

    public function testSshRequiresTheConnectorBannerContract(): void
    {
        $probe = new NativeServiceProbe(new ProbeProcessRunner(), new ProbeSshConnector('SSH-2.0-OpenSSH_9.9'));
        $result = $probe->check(['type' => 'ssh', 'target' => 'ssh.example.test', 'port' => 2222]);
        self::assertTrue($result->healthy);
        self::assertSame('SSH-2.0-OpenSSH_9.9', $result->detail);
    }

    public function testProxyRequiresAValidPublicIpAndUsesCredentialFreeCurlArguments(): void
    {
        $processes = new ProbeProcessRunner(new ProcessResult(0, "8.8.8.8\n"));
        $probe = new NativeServiceProbe($processes, new ProbeSshConnector());
        $result = $probe->check(['type' => 'proxy', 'target' => 'proxy.example.test', 'port' => 3128]);
        self::assertTrue($result->healthy);
        self::assertSame('8.8.8.8', $result->observedIp);
        self::assertContains('http://proxy.example.test:3128', $processes->command);
        self::assertContains('https://ifconfig.me/ip', $processes->command);

        $invalid = new NativeServiceProbe(new ProbeProcessRunner(new ProcessResult(0, "192.168.1.10\n")), new ProbeSshConnector());
        self::assertFalse($invalid->check(['type' => 'proxy', 'target' => 'proxy.example.test', 'port' => 3128])->healthy);
    }

    public function testSocks5ProxyResolvesThroughTheProxyAndRequiresAPublicExitIp(): void
    {
        $processes = new ProbeProcessRunner(new ProcessResult(0, "2001:4860:4860::8888\n"));
        $probe = new NativeServiceProbe($processes, new ProbeSshConnector());
        $result = $probe->check(['type' => 'socks5', 'target' => 'socks.example.test', 'port' => 1080]);

        self::assertTrue($result->healthy);
        self::assertSame('2001:4860:4860::8888', $result->observedIp);
        self::assertContains('socks5h://socks.example.test:1080', $processes->command);
        self::assertContains('https://ifconfig.me/ip', $processes->command);
    }
}

final class ProbeProcessRunner implements ProcessRunner
{
    public array $command = [];

    public function __construct(private ?ProcessResult $result = null)
    {
    }

    public function run(array $command, array $environment = [], ?string $stdinFile = null, ?string $stdoutFile = null): ProcessResult
    {
        $this->command = $command;
        return $this->result ?? new ProcessResult(1, '', 'not configured');
    }
}

final readonly class ProbeSshConnector implements SshConnector
{
    public function __construct(private string $banner = 'SSH-2.0-test')
    {
    }

    public function banner(string $host, int $port, float $timeoutSeconds): string
    {
        return $this->banner;
    }
}

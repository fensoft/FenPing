<?php

declare(strict_types=1);

namespace FenPing\Tests;

use FenPing\Api\AuthPolicy;
use FenPing\Api\Controller\DoctorController;
use FenPing\Api\Request;
use FenPing\Doctor\DoctorReportProvider;
use FenPing\Doctor\ProcessDoctorReportProvider;
use FenPing\Process\ProcessResult;
use FenPing\Process\ProcessRunner;
use RuntimeException;

final class DoctorApiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ($this->app()->auth()->isAuthenticated()) {
            $this->app()->auth()->logout();
        }
    }

    public function testDoctorApiRequiresAnAdminSession(): void
    {
        $response = $this->app()->api()->handle($this->request('GET', '/api/doctor'));

        self::assertSame(403, $response->status);
        self::assertSame(['error' => 'login required'], json_decode($response->body, true));
    }

    public function testControllerExposesAReadOnlySessionRoute(): void
    {
        $provider = new DoctorApiTestProvider();
        $route = (new DoctorController($provider))->routes()[0];

        self::assertSame('GET', $route->method);
        self::assertSame('/doctor', $route->pattern);
        self::assertSame(AuthPolicy::Session, $route->auth);
        self::assertSame($provider->report, ($route->handler)([]));
    }

    public function testProviderReturnsFailedReportsFromTheExactPrivilegedCommand(): void
    {
        $processes = new DoctorApiTestProcessRunner(new ProcessResult(
            1,
            '{"status":"failed","checked_at":"2026-07-13T12:00:00+00:00","checks":[]}',
        ));
        $report = (new ProcessDoctorReportProvider($this->app()->config(), $processes))->runtimeReport();

        self::assertSame('failed', $report['status']);
        self::assertSame([
            '/usr/bin/doas',
            '/usr/bin/php',
            $this->app()->config()->projectDir . '/cli.php',
            'doctor',
            '--runtime',
            '--json',
        ], $processes->command);
    }

    public function testProviderRejectsCommandAndJsonFailures(): void
    {
        $failed = new ProcessDoctorReportProvider(
            $this->app()->config(),
            new DoctorApiTestProcessRunner(new ProcessResult(2, '', 'not permitted')),
        );
        $invalid = new ProcessDoctorReportProvider(
            $this->app()->config(),
            new DoctorApiTestProcessRunner(new ProcessResult(0, 'not json')),
        );

        try {
            $invalid->runtimeReport();
            self::fail('invalid JSON was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('doctor returned invalid JSON', $error->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $failed->runtimeReport();
    }

    private function request(string $method, string $uri): Request
    {
        return new Request($method, $uri, [], [], [], ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri], []);
    }
}

final class DoctorApiTestProvider implements DoctorReportProvider
{
    public array $report = [
        'status' => 'ok',
        'checked_at' => '2026-07-13T12:00:00+00:00',
        'checks' => [],
    ];

    public function runtimeReport(): array
    {
        return $this->report;
    }
}

final class DoctorApiTestProcessRunner implements ProcessRunner
{
    public array $command = [];

    public function __construct(private ProcessResult $result)
    {
    }

    public function run(
        array $command,
        array $environment = [],
        ?string $stdinFile = null,
        ?string $stdoutFile = null,
    ): ProcessResult {
        $this->command = $command;
        return $this->result;
    }
}

<?php

declare(strict_types=1);

namespace FenPing;

use FenPing\Api\ApiKernel;
use FenPing\Api\Controller\BackupController;
use FenPing\Api\Controller\DoctorController;
use FenPing\Api\Controller\AuthController;
use FenPing\Api\Controller\HostController;
use FenPing\Api\Controller\IpamController;
use FenPing\Api\Controller\NetbootController;
use FenPing\Api\Controller\RouteAdapter;
use FenPing\Api\Controller\ScanController;
use FenPing\Api\Controller\SystemController;
use FenPing\Auth\AuthService;
use FenPing\Backend\Backend;
use FenPing\Backup\BackupService;
use FenPing\Cli\CliKernel;
use FenPing\Cli\DoctorCommand;
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Dhcp\ConfigManager;
use FenPing\Dhcp\HostValidator;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Doctor\DoctorService;
use FenPing\Doctor\NativeDoctorSystem;
use FenPing\Doctor\ProcessDoctorReportProvider;
use FenPing\Health\HealthService;
use FenPing\Host\CategoryRepository;
use FenPing\Host\HostRepository;
use FenPing\Inventory\InventoryService;
use FenPing\Ipam\IpConflictDetector;
use FenPing\Ipam\IpConflictRepository;
use FenPing\Ipam\IpamService;
use FenPing\Netboot\NetbootImageService;
use FenPing\Ping\PingScanner;
use FenPing\Process\NativeProcessRunner;
use FenPing\Process\ProcessRunner;
use FenPing\Scan\PortChangeService;
use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ResultService;
use FenPing\Scan\RetentionService;
use FenPing\Scan\ScanJobRepository;
use FenPing\Scan\SnapshotRepository;
use FenPing\Scan\XmlCodec;
use FenPing\Status\NotificationService;
use FenPing\Status\StatusHistoryService;
use FenPing\Support\SystemClock;
use FenPing\Vendor\VendorLookup;

final class Application
{
    private readonly DatabaseManager $database;
    private readonly Backend $backend;
    private readonly IpConflictRepository $ipConflicts;
    private readonly IpConflictDetector $ipConflictDetector;
    private readonly AuthService $auth;
    private readonly ProfileCatalog $profiles;
    private readonly ScanJobRepository $scanJobs;
    private readonly SnapshotRepository $snapshots;
    private readonly VendorLookup $vendors;
    private readonly InventoryService $inventory;
    private readonly HostRepository $hosts;
    private readonly CategoryRepository $categories;
    private readonly StatusHistoryService $history;
    private readonly NotificationService $notifications;
    private readonly NetbootImageService $netboot;
    private readonly IpamService $ipam;
    private readonly BackupService $backups;
    private readonly DoctorService $doctor;
    private readonly ProcessRunner $processes;

    private function __construct(private readonly AppConfig $config)
    {
        $this->database = new DatabaseManager($config);

        $clock = new SystemClock();
        $this->auth = new AuthService($config);
        $this->ipConflicts = new IpConflictRepository($this->database);
        $this->processes = new NativeProcessRunner();
        $this->ipConflictDetector = new IpConflictDetector($config, $this->processes, $this->ipConflicts, $clock);
        $this->backend = new Backend($config, $this->database, $this->ipConflicts, $this->ipConflictDetector);
        $this->profiles = new ProfileCatalog();
        $this->scanJobs = new ScanJobRepository($this->backend, $this->database);
        $this->snapshots = new SnapshotRepository($this->backend, $this->database);
        $this->vendors = new VendorLookup($this->backend, $config, $this->database);
        $this->inventory = new InventoryService($this->backend, $config, $this->database, $this->scanJobs, $clock);
        $this->hosts = new HostRepository($this->backend, $this->database);
        $this->categories = new CategoryRepository($this->backend, $config, $this->database);
        $this->history = new StatusHistoryService($this->backend, $this->database, $clock);
        $this->notifications = new NotificationService($this->backend, $this->database, $this->vendors);
        $this->netboot = new NetbootImageService($this->backend, $config, $this->database);
        $this->ipam = new IpamService($this->backend, $config, $this->database, $this->vendors);
        $this->backups = new BackupService($this->backend, $config, $this->database);
        $this->doctor = new DoctorService(
            $config,
            $this->processes,
            new NativeDoctorSystem($this->processes),
            $clock,
        );
    }

    public static function fromEnvironment(string $projectDir): self
    {
        return new self(AppConfig::fromEnvironment($projectDir));
    }

    public static function forConfig(AppConfig $config): self
    {
        return new self($config);
    }

    public function api(): ApiKernel
    {
        $adapter = new RouteAdapter();
        $configManager = new ConfigManager($this->backend, $this->config, $this->database);
        $mutations = new MutationCoordinator($this->backend, $this->database, $configManager);
        $validator = new HostValidator($this->backend);
        $results = $this->scanResults();
        $clock = new SystemClock();

        return new ApiKernel($this->auth, [
            new DoctorController(new ProcessDoctorReportProvider($this->config, $this->processes)),
            new AuthController($this->backend, $this->auth, $adapter),
            new SystemController(
                $this->backend,
                new HealthService($this->backend, $this->database, $clock),
                $this->inventory,
                $this->notifications,
                new PingScanner($this->backend, $this->config, $this->database),
                $adapter,
            ),
            new HostController(
                $this->backend,
                $this->hosts,
                $this->categories,
                $this->history,
                $this->scanJobs,
                $results,
                $this->netboot,
                $validator,
                $mutations,
                $adapter,
            ),
            new IpamController($this->backend, $this->ipam, $validator, $adapter),
            new NetbootController($this->backend, $this->netboot, $mutations, $adapter),
            new BackupController($this->backups),
            new ScanController($this->backend, $this->scanJobs, $this->profiles, $results, $this->vendors, $adapter),
        ]);
    }
    public function cli(): CliKernel
    {
        return new CliKernel($this->backend, $this->database, $this->backups, new DoctorCommand($this->doctor));
    }
    public function config(): AppConfig { return $this->config; }
    public function database(): DatabaseManager { return $this->database; }
    public function backend(): Backend { return $this->backend; }
    public function auth(): AuthService { return $this->auth; }
    public function profiles(): ProfileCatalog { return $this->profiles; }
    public function scanJobs(): ScanJobRepository { return $this->scanJobs; }
    public function snapshots(): SnapshotRepository { return $this->snapshots; }
    public function scanResults(): ResultService { return new ResultService($this->backend, $this->scanJobs, $this->snapshots, $this->profiles); }
    public function scanPortChanges(): PortChangeService { return new PortChangeService($this->backend, $this->database); }
    public function scanRetention(): RetentionService { return new RetentionService($this->backend, $this->database); }
    public function scanXml(): XmlCodec { return new XmlCodec($this->backend); }
    public function vendors(): VendorLookup { return $this->vendors; }
    public function inventory(): InventoryService { return $this->inventory; }
    public function hosts(): HostRepository { return $this->hosts; }
    public function categories(): CategoryRepository { return $this->categories; }
    public function history(): StatusHistoryService { return $this->history; }
    public function notifications(): NotificationService { return $this->notifications; }
    public function netboot(): NetbootImageService { return $this->netboot; }
    public function ipam(): IpamService { return $this->ipam; }
    public function backups(): BackupService { return $this->backups; }
    public function ipConflicts(): IpConflictRepository { return $this->ipConflicts; }
    public function ipConflictDetector(): IpConflictDetector { return $this->ipConflictDetector; }
}

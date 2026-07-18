<?php

declare(strict_types=1);

namespace FenPing;

use FenPing\Api\ApiKernel;
use FenPing\Api\Controller\{AuthController, BackupController, DnsOverrideController, DoctorController, DockerNetworksController, ExportController, HostController, IpamController, NetbootController, ScanController, SystemController, TopologyController};
use FenPing\Auth\AuthService;
use FenPing\Backup\{BackupService, BackupArchiveService, BackupArchiveTools, BackupDatabaseDocument, BackupFilesystem, BackupManager, BackupTableCatalog};
use FenPing\Discord\DiscordNotifier;
use FenPing\Discord\DiscordPayloadBuilder;
use FenPing\Cli\{CliKernel, CliUsage, BackupCommand, CallableCommand, DatabaseCommand, DiscordRestartCommand, HostsCommand, InventoryCommand, LeaseImportCommand, LockingCommand, NotificationRestartCommand, NoArgumentsCommand, OuiCommand, PingCommand, PublishingCommand, ScanPortBackfillCommand, ScheduledReportCommand, StatusHistoryCleanCommand, TrackedCommand, DoctorCommand, DockerNetworksRefreshCommand, DockerNetworksWatchCommand};
use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Dhcp\ConfigManager;
use FenPing\Dhcp\ConfigRenderer;
use FenPing\Dhcp\DnsmasqManager;
use FenPing\Dhcp\LeaseImporter;
use FenPing\Dhcp\HostValidator;
use FenPing\Dhcp\MutationCoordinator;
use FenPing\Dns\DnsOverrideGroupService;
use FenPing\Dns\DnsOverrideParser;
use FenPing\Doctor\DoctorService;
use FenPing\Doctor\NativeDoctorSystem;
use FenPing\Doctor\ProcessDoctorReportProvider;
use FenPing\Docker\DockerEngineClient;
use FenPing\Docker\DockerNetworkCache;
use FenPing\Docker\DockerNetworkParser;
use FenPing\Docker\DockerNetworkRefreshService;
use FenPing\Docker\DockerNetworkWatcher;
use FenPing\Http\HttpClient;
use FenPing\Http\HttpTransport;
use FenPing\Http\NativeHttpClient;
use FenPing\Export\InventoryExportService;
use FenPing\Docker\PrivilegedDockerNetworkRefreshGateway;
use FenPing\Health\HealthService;
use FenPing\Health\DatabaseHealthProbe;
use FenPing\Health\ProcessHealthProbe;
use FenPing\Health\OperationTracker;
use FenPing\Host\CategoryRepository;
use FenPing\Host\DiscoveredHostMetadataService;
use FenPing\Host\HostMetadataNormalizer;
use FenPing\Host\HostMetadataRepository;
use FenPing\Host\HostService;
use FenPing\Host\HostRepository;
use FenPing\Inventory\InventoryReadService;
use FenPing\Inventory\InventoryRowNormalizer;
use FenPing\Inventory\InventoryScanner;
use FenPing\Inventory\InventoryScheduler;
use FenPing\Inventory\InventoryService;
use FenPing\Inventory\SavedInventoryFilterRepository;
use FenPing\Inventory\PrivilegedInventoryWorkerLauncher;
use FenPing\Ipam\IpConflictDetector;
use FenPing\Ipam\IpConflictRepository;
use FenPing\Ipam\IpConflictService;
use FenPing\Ipam\IpamService;
use FenPing\Netboot\NetbootImageService;
use FenPing\Oui\OuiRegistryService;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Ping\PingRepository;
use FenPing\Ping\PingScanner;
use FenPing\Ping\PrivilegedPingRefreshGateway;
use FenPing\Process\NativeProcessRunner;
use FenPing\Process\ProcessRunner;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\LiveUpdateScope;
use FenPing\Realtime\NchanLiveUpdatePublisher;
use FenPing\Report\ScheduledReportFormatter;
use FenPing\Report\ScheduledReportQueryRepository;
use FenPing\Report\ScheduledReportService;
use FenPing\Report\ScheduledReportSettingsRepository;
use FenPing\Scan\PortChangeService;
use FenPing\Scan\PortKnowledgeMerger;
use FenPing\Scan\ProfileCatalog;
use FenPing\Scan\ResultService;
use FenPing\Scan\RetentionService;
use FenPing\Scan\ScanJobRepository;
use FenPing\Scan\ScanResultHasher;
use FenPing\Scan\ScanControlStore;
use FenPing\Scan\ScanPolicyService;
use FenPing\Scan\ScanResultStore;
use FenPing\Scan\ScanXmlParser;
use FenPing\Scan\ScanXmlRenderer;
use FenPing\Scan\SnapshotRepository;
use FenPing\Scan\XmlCodec;
use FenPing\Status\NotificationService;
use FenPing\Status\NotificationQueryRepository;
use FenPing\Status\NotificationRuleRepository;
use FenPing\Status\TelegramApiClient;
use FenPing\Status\TelegramChatRepository;
use FenPing\Status\TelegramNotifier;
use FenPing\Status\StatusHistoryService;
use FenPing\Status\StatusHistoryCleaner;
use FenPing\Support\SystemClock;
use FenPing\Topology\TopologyRepository;
use FenPing\Topology\TopologyService;
use FenPing\Vendor\VendorLookup;

final class Application
{
    private readonly DatabaseManager $database;
    private readonly HttpClient $http;
    private readonly OuiRegistryService $oui;
    private readonly PingRepository $pingRepository;
    private readonly PingScanner $pingScanner;
    private readonly OperationTracker $operations;
    private readonly HostValidator $hostValidator;
    private readonly ConfigManager $dhcpConfig;
    private readonly MutationCoordinator $dhcpMutations;
    private readonly DnsOverrideGroupService $dnsOverrideGroups;
    private readonly LeaseImporter $leaseImporter;
    private readonly NetworkManager $networks;
    private readonly IpConflictRepository $ipConflicts;
    private readonly IpConflictService $ipConflictService;
    private readonly IpConflictDetector $ipConflictDetector;
    private readonly AuthService $auth;
    private readonly ProfileCatalog $profiles;
    private readonly XmlCodec $scanCodec;
    private readonly ScanPolicyService $scanPolicy;
    private readonly ScanControlStore $scanControl;
    private readonly ScanResultStore $scanResultStore;
    private readonly PortChangeService $portChanges;
    private readonly ScanJobRepository $scanJobs;
    private readonly SnapshotRepository $snapshots;
    private readonly VendorLookup $vendors;
    private readonly InventoryScanner $inventoryScanner;
    private readonly InventoryScheduler $inventoryScheduler;
    private readonly InventoryService $inventory;
    private readonly HostRepository $hosts;
    private readonly HostMetadataNormalizer $hostMetadataNormalizer;
    private readonly HostMetadataRepository $hostMetadata;
    private readonly DiscoveredHostMetadataService $discoveredHostMetadata;
    private readonly CategoryRepository $categories;
    private readonly StatusHistoryService $history;
    private readonly StatusHistoryCleaner $historyCleaner;
    private readonly NotificationService $notifications;
    private readonly ScheduledReportService $scheduledReports;
    private readonly NotificationRuleRepository $notificationRules;
    private readonly DiscordNotifier $discord;
    private readonly TelegramChatRepository $telegramChats;
    private readonly TelegramNotifier $telegram;
    private readonly NetbootImageService $netboot;
    private readonly IpamService $ipam;
    private readonly BackupService $backups;
    private readonly BackupManager $backupManager;
    private readonly BackupArchiveTools $backupTools;
    private readonly BackupArchiveService $backupArchives;
    private readonly BackupDatabaseDocument $backupDocuments;
    private readonly BackupTableCatalog $backupTables;
    private readonly DoctorService $doctor;
    private readonly HealthService $health;
    private readonly TopologyService $topology;
    private readonly ProcessRunner $processes;
    private readonly DockerNetworkCache $dockerNetworks;
    private readonly DockerNetworkRefreshService $dockerNetworkRefresh;
    private readonly DockerNetworkWatcher $dockerNetworkWatcher;
    private readonly LiveUpdatePublisher $liveUpdates;

    private function __construct(private readonly AppConfig $config, ?LiveUpdatePublisher $liveUpdates = null, ?HttpTransport $httpTransport = null)
    {
        $this->liveUpdates = $liveUpdates ?? new NchanLiveUpdatePublisher();
        $this->database = new DatabaseManager($config);
        $this->http = new NativeHttpClient($httpTransport);

        $clock = new SystemClock();
        $this->auth = new AuthService($config);
        $this->ipConflicts = new IpConflictRepository($this->database, $this->liveUpdates);
        $this->processes = new NativeProcessRunner();
        $routes = new RouteDetector($this->processes);
        $this->networks = new NetworkManager($config, $routes);
        $dockerSocket = getenv('DOCKER_SOCKET');
        $dockerSource = new DockerEngineClient(
            $this->processes,
            new DockerNetworkParser(),
            $dockerSocket === false ? '' : trim($dockerSocket),
        );
        $this->dockerNetworks = new DockerNetworkCache(DockerNetworkCache::pathFromEnvironment());
        $dockerCache = $this->dockerNetworks;
        $this->dockerNetworkRefresh = new DockerNetworkRefreshService(
            $dockerSource,
            $dockerCache,
            liveUpdates: $this->liveUpdates,
        );
        $this->dockerNetworkWatcher = new DockerNetworkWatcher($dockerSource, $this->dockerNetworkRefresh);
        $this->ipConflictDetector = new IpConflictDetector($config, $this->processes, $routes, $this->ipConflicts, $clock);
        $this->operations = new OperationTracker($this->database, $clock, $this->liveUpdates);
        $this->hostValidator = new HostValidator($this->networks);
        $dnsmasq = new DnsmasqManager($this->hostValidator);
        $dnsOverrideParser = new DnsOverrideParser();
        $this->dnsOverrideGroups = new DnsOverrideGroupService($this->database, $dnsOverrideParser);
        $renderer = new ConfigRenderer($config, $this->database, $this->hostValidator, $dnsOverrideParser);
        $this->dhcpConfig = new ConfigManager($config, $renderer, $dnsmasq, $this->operations);
        $this->dhcpMutations = new MutationCoordinator($this->database, $this->dhcpConfig, $dnsmasq, $this->operations);
        $this->leaseImporter = new LeaseImporter($this->database, $this->liveUpdates);
        $this->pingRepository = new PingRepository($this->database, $this->liveUpdates);
        $this->pingScanner = new PingScanner($config);
        $this->profiles = new ProfileCatalog();
        $this->scanCodec = new XmlCodec(new ScanXmlParser(), new ScanXmlRenderer(), new ScanResultHasher());
        $portKnowledge = new PortKnowledgeMerger();
        $this->scanPolicy = new ScanPolicyService($config, $this->database, $this->networks, $this->scanCodec);
        $this->scanControl = new ScanControlStore($this->database, $this->liveUpdates, $this->scanPolicy);
        $this->scanResultStore = new ScanResultStore($this->database, $this->profiles, $this->scanPolicy, $this->scanCodec, $portKnowledge);
        $this->snapshots = new SnapshotRepository($this->database, $this->scanCodec, $this->scanResultStore);
        $this->portChanges = new PortChangeService($this->database, $this->scanPolicy, $this->snapshots, $portKnowledge);
        $this->scanJobs = new ScanJobRepository($config, $this->database, $this->liveUpdates, $this->profiles, $this->scanPolicy, $this->scanControl, $this->scanResultStore, $this->snapshots, $this->portChanges);
        $this->oui = new OuiRegistryService($config, $this->database, $this->http);
        $this->vendors = new VendorLookup($this->database, $this->oui);
        $this->ipConflictService = new IpConflictService($config, $this->ipConflicts, $this->networks, $this->vendors);
        $this->hostMetadataNormalizer = new HostMetadataNormalizer($this->profiles);
        $this->hostMetadata = new HostMetadataRepository($this->database, $dockerCache, $this->hostMetadataNormalizer);
        $this->hosts = new HostRepository($this->database, $this->hostMetadata);
        $this->discoveredHostMetadata = new DiscoveredHostMetadataService($config, $this->database, $this->networks, $this->hosts, $this->hostMetadata, $this->hostMetadataNormalizer);
        $this->categories = new CategoryRepository($config, $this->database, $this->networks);
        $this->history = new StatusHistoryService($this->database, $clock);
        $this->historyCleaner = new StatusHistoryCleaner($this->database);
        $this->notificationRules = new NotificationRuleRepository($this->database);
        $notificationPayloads = new DiscordPayloadBuilder($config);
        $this->discord = new DiscordNotifier($config, $this->database, $this->http, $this->operations, $this->notificationRules, $notificationPayloads);
        $telegramApi = new TelegramApiClient($config, $this->http);
        $this->telegramChats = new TelegramChatRepository($config, $this->database, $telegramApi);
        $this->telegram = new TelegramNotifier($config, $this->notificationRules, $this->telegramChats, $telegramApi, $this->operations, $notificationPayloads);
        $notificationQueries = new NotificationQueryRepository($config, $this->database, $this->history, $this->vendors, $this->ipConflictService);
        $reportSettings = new ScheduledReportSettingsRepository($this->database);
        $this->scheduledReports = new ScheduledReportService(
            $this->database,
            $reportSettings,
            new ScheduledReportQueryRepository($this->database),
            new ScheduledReportFormatter(),
            $this->discord,
            $this->telegram,
            $this->telegramChats,
            $clock,
            $this->liveUpdates,
        );
        $this->notifications = new NotificationService($config, $notificationQueries, $this->notificationRules, $this->discord, $this->telegram, $this->telegramChats, $this->scheduledReports);
        $retention = new RetentionService($this->database, $this->portChanges);
        $this->inventoryScanner = new InventoryScanner($this->profiles, $this->scanJobs, $this->scanCodec, $retention, $this->notifications);
        $this->inventoryScheduler = new InventoryScheduler($config, $this->database, $dockerCache, $this->hostMetadata, $this->inventoryScanner, $this->scanJobs, $this->profiles, $this->networks, $this->operations, $this->liveUpdates);
        $inventoryRows = new InventoryRowNormalizer($config, $dockerCache, $this->hostMetadata);
        $inventoryRead = new InventoryReadService($config, $this->database, $this->networks, $dockerCache, $this->hostMetadata, $this->hosts, $this->discoveredHostMetadata, $inventoryRows, $this->vendors, $this->history, $this->scanPolicy, $this->scanCodec);
        $savedFilters = new SavedInventoryFilterRepository($this->database, $this->hostMetadata, $this->hostMetadataNormalizer);
        $this->inventory = new InventoryService($inventoryRead, $inventoryRows, $this->hostMetadata, $savedFilters, $this->inventoryScheduler);
        $this->topology = new TopologyService($config, $this->networks, $this->inventory, new TopologyRepository($this->database));
        $this->netboot = new NetbootImageService($config, $this->database);
        $this->ipam = new IpamService($config, $this->database, $this->vendors, $this->networks, $this->ipConflictService);
        $backupFilesystem = new BackupFilesystem($config);
        $this->backupTools = new BackupArchiveTools($this->dhcpConfig);
        $this->backupTables = new BackupTableCatalog();
        $this->backupDocuments = new BackupDatabaseDocument($config, $this->database, $this->backupTables, $backupFilesystem, $this->backupTools);
        $this->backupArchives = new BackupArchiveService($config, $this->database, $backupFilesystem, $this->backupTools, $this->backupDocuments, $this->backupTables);
        $this->backupManager = new BackupManager($config, $this->database, $this->backupArchives, $backupFilesystem, $this->backupTools);
        $this->backups = new BackupService($this->backupManager, $this->backupTools, $this->operations, $this->liveUpdates);
        $this->health = new HealthService($this->config, new DatabaseHealthProbe($this->database), new ProcessHealthProbe(), $this->ipam, $this->ipConflictService, $this->inventory, $this->notifications, $this->database, $this->operations, $clock);
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

    public static function forConfig(AppConfig $config, ?LiveUpdatePublisher $liveUpdates = null, ?HttpTransport $httpTransport = null): self
    {
        return new self($config, $liveUpdates, $httpTransport);
    }

    public function api(): ApiKernel
    {
        $configManager = $this->dhcpConfig;
        $mutations = $this->dhcpMutations;
        $validator = $this->hostValidator;
        $hostService = new HostService($this->config, $this->database, $this->networks, $this->hosts, $this->hostMetadata, $this->hostMetadataNormalizer, $this->discoveredHostMetadata, $validator, $mutations, $this->inventory, $this->history, $this->scanJobs, $this->netboot, $this->vendors);
        $results = $this->scanResults();
        $exports = new InventoryExportService($this->database, $this->inventory, $results);
        $clock = new SystemClock();

        return new ApiKernel($this->auth, [
            new DoctorController(new ProcessDoctorReportProvider($this->config, $this->processes)),
            new DockerNetworksController(new PrivilegedDockerNetworkRefreshGateway($this->processes, $this->config->projectDir)),
            new DnsOverrideController($this->dnsOverrideGroups, $mutations),
            new AuthController($this->auth),
            new SystemController(
                $this->config,
                $this->networks,
                $this->health,
                $this->inventory,
                $this->notifications,
                new PrivilegedPingRefreshGateway($this->config),
            ),
            new ExportController($exports, $this->networks),
            new HostController($hostService, $this->categories, $this->history),
            new IpamController($this->ipam, $validator),
            new NetbootController($this->netboot, $mutations),
            new BackupController($this->backups),
            new ScanController($this->scanJobs, $this->profiles, $results, $this->networks, new PrivilegedInventoryWorkerLauncher($this->config)),
            new TopologyController($this->topology),
        ], $this->liveUpdates);
    }
    public function cli(): CliKernel
    {
        $usage = new CliUsage();
        $database = new NoArgumentsCommand(
            new TrackedCommand(new DatabaseCommand($this->database), $this->operations, "database_integrity"),
            $usage,
        );
        $ping = new LockingCommand(
            new TrackedCommand(new PingCommand($this->config, $this->networks, $this->pingScanner, $this->pingRepository, $this->notifications, $this->discord, $this->ipConflictDetector, $this->ipConflictService), $this->operations, 'ping'),
            '/tmp/ping.lck', 'ping scan',
        );
        $inventoryRaw = new CallableCommand(fn(array $arguments): int => $this->inventory->run($arguments));
        $inventory = new InventoryCommand(
            new LockingCommand($inventoryRaw, '/tmp/inventory-discovery.lck', 'inventory scheduling'),
            new LockingCommand($inventoryRaw, '/tmp/fenping-inventory-worker.lck', 'inventory worker'),
            $inventoryRaw,
        );
        $ouiRefresh = new PublishingCommand(
            new LockingCommand(new TrackedCommand(new OuiCommand($this->oui, true), $this->operations, 'oui_update'), '/tmp/oui-refresh.lck', 'OUI refresh'),
            $this->liveUpdates, [LiveUpdateScope::Vendors],
        );
        $ouiSync = new PublishingCommand(new OuiCommand($this->oui, false), $this->liveUpdates, [LiveUpdateScope::Vendors]);
        $leases = new LockingCommand(new TrackedCommand(new LeaseImportCommand($this->leaseImporter), $this->operations, 'lease_import'), '/tmp/dnsmasq-leases.lck', 'dnsmasq lease import');

        return new CliKernel([
            'doctor' => new DoctorCommand($this->doctor),
            'docker-networks-refresh' => new DockerNetworksRefreshCommand($this->dockerNetworkRefresh),
            'docker-networks-watch' => new DockerNetworksWatchCommand($this->dockerNetworkWatcher),
            'database' => $database, 'database-check' => $database, 'ping' => $ping,
            'hosts' => new HostsCommand($this->dhcpConfig), 'inventory' => $inventory,
            "scan-port-backfill" => new NoArgumentsCommand(new ScanPortBackfillCommand($this->portChanges, $this->liveUpdates), $usage),
            'status-clean' => new LockingCommand(
                new TrackedCommand(new PublishingCommand(new StatusHistoryCleanCommand($this->config, $this->historyCleaner), $this->liveUpdates, [LiveUpdateScope::Status]), $this->operations, 'status_history_cleanup'),
                '/tmp/fenping-status-clean.lck', 'status history cleanup'),
            'oui-refresh' => $ouiRefresh, 'oui-sync' => $ouiSync, 'dnsmasq-leases' => $leases,
            "notify-restart" => new NoArgumentsCommand(new NotificationRestartCommand($this->notifications), $usage),
            "scheduled-report" => new ScheduledReportCommand($this->scheduledReports, $usage),
            "discord-restart" => new NoArgumentsCommand(new DiscordRestartCommand($this->discord), $usage),
            'backup' => new BackupCommand($this->backups, 'backup'),
            'restore' => new BackupCommand($this->backups, 'restore'),
            'backup-verify' => new BackupCommand($this->backups, 'verify'),
            'backup-maintenance' => new BackupCommand($this->backups, 'maintenance'),
            "backup-restore-stage" => new BackupCommand($this->backups, "restore-stage"),
        ], $usage);
    }
    public function config(): AppConfig { return $this->config; }
    public function database(): DatabaseManager { return $this->database; }
    public function operations(): OperationTracker { return $this->operations; }
    public function pingRepository(): PingRepository { return $this->pingRepository; }
    public function dockerNetworks(): DockerNetworkCache { return $this->dockerNetworks; }
    public function hostMetadata(): HostMetadataRepository { return $this->hostMetadata; }
    public function hostMetadataNormalizer(): HostMetadataNormalizer { return $this->hostMetadataNormalizer; }
    public function dhcpConfig(): ConfigManager { return $this->dhcpConfig; }
    public function dhcpMutations(): MutationCoordinator { return $this->dhcpMutations; }
    public function dnsOverrideGroups(): DnsOverrideGroupService { return $this->dnsOverrideGroups; }
    public function inventoryScanner(): InventoryScanner { return $this->inventoryScanner; }
    public function discord(): DiscordNotifier { return $this->discord; }
    public function notificationRules(): NotificationRuleRepository { return $this->notificationRules; }
    public function telegramChats(): TelegramChatRepository { return $this->telegramChats; }
    public function telegram(): TelegramNotifier { return $this->telegram; }
    public function ipConflictService(): IpConflictService { return $this->ipConflictService; }
    public function health(): HealthService { return $this->health; }
    public function auth(): AuthService { return $this->auth; }
    public function profiles(): ProfileCatalog { return $this->profiles; }
    public function scanJobs(): ScanJobRepository { return $this->scanJobs; }
    public function snapshots(): SnapshotRepository { return $this->snapshots; }
    public function scanResults(): ResultService { return new ResultService($this->config, $this->scanResultStore, $this->scanJobs, $this->snapshots, $this->profiles, $this->scanCodec, $this->vendors); }
    public function scanPortChanges(): PortChangeService { return $this->portChanges; }
    public function scanRetention(): RetentionService { return new RetentionService($this->database, $this->portChanges); }
    public function scanXml(): XmlCodec { return $this->scanCodec; }
    public function vendors(): VendorLookup { return $this->vendors; }
    public function inventory(): InventoryService { return $this->inventory; }
    public function topology(): TopologyService { return $this->topology; }
    public function hosts(): HostRepository { return $this->hosts; }
    public function categories(): CategoryRepository { return $this->categories; }
    public function history(): StatusHistoryService { return $this->history; }
    public function notifications(): NotificationService { return $this->notifications; }
    public function scheduledReports(): ScheduledReportService { return $this->scheduledReports; }
    public function netboot(): NetbootImageService { return $this->netboot; }
    public function ipam(): IpamService { return $this->ipam; }
    public function backups(): BackupService { return $this->backups; }
    public function backupManager(): BackupManager { return $this->backupManager; }
    public function backupArchives(): BackupArchiveService { return $this->backupArchives; }
    public function backupDocuments(): BackupDatabaseDocument { return $this->backupDocuments; }
    public function backupTools(): BackupArchiveTools { return $this->backupTools; }
    public function backupTables(): BackupTableCatalog { return $this->backupTables; }
    public function ipConflicts(): IpConflictRepository { return $this->ipConflicts; }
    public function ipConflictDetector(): IpConflictDetector { return $this->ipConflictDetector; }
}

<?php

declare(strict_types=1);

namespace FenPing\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Process\NativeProcessRunner;

final class Backend
{
    public readonly NetworkManager $networks;
    use ApiBehavior;
    use AuthBehavior;
    use BackupArchiveServiceBehavior;
    use BackupArchiveValidationBehavior;
    use BackupDatabaseDocumentBehavior;
    use BackupFilesystemBehavior;
    use CliBehavior;
    use CoreHostsBehavior;
    use CoreInventoryBehavior;
    use CoreNetbootBehavior;
    use CoreNotificationsBehavior;
    use CoreStatusHistoryBehavior;
    use DatabaseBehavior;
    use DhcpLeaseImporterBehavior;
    use DhcpManagerBehavior;
    use DhcpRendererBehavior;
    use DiscordBehavior;
    use HealthBehavior;
    use HttpBehavior;
    use InventoryScannerBehavior;
    use InventorySchedulerBehavior;
    use IpamBehavior;
    use OuiBehavior;
    use PingBehavior;
    use RoutesAuthBehavior;
    use RoutesHostsBehavior;
    use RoutesIpamBehavior;
    use RoutesNetbootBehavior;
    use RoutesScansBehavior;
    use RoutesSystemBehavior;
    use ScanJobQueueBehavior;
    use ScanPortChangesBehavior;
    use ScanProfileAndHashBehavior;
    use ScanResultsBehavior;
    use ScanRetentionBehavior;
    use ScanSnapshotStoreBehavior;
    use ScanXmlParserBehavior;
    use ScanXmlRendererBehavior;

    public function __construct(
        public readonly AppConfig $config,
        public readonly DatabaseManager $database,
        ?NetworkManager $networks = null,
    ) {
        $this->networks = $networks ?? new NetworkManager($config, new RouteDetector(new NativeProcessRunner()));
    }
}

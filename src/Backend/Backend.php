<?php

declare(strict_types=1);

namespace FenPing\Backend;

use FenPing\Config\AppConfig;
use FenPing\Database\DatabaseManager;
use FenPing\Http\HttpTransport;
use FenPing\Health\OperationTracker;
use FenPing\Ipam\IpConflictDetector;
use FenPing\Ipam\IpConflictRepository;
use FenPing\Network\NetworkManager;
use FenPing\Network\RouteDetector;
use FenPing\Process\NativeProcessRunner;
use FenPing\Realtime\LiveUpdatePublisher;
use FenPing\Realtime\NullLiveUpdatePublisher;

final class Backend
{
    public readonly NetworkManager $networks;
    public readonly LiveUpdatePublisher $liveUpdates;
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
    use DiscordPayloadBehavior;
    use HealthBehavior;
    use HttpBehavior;
    use InventoryScannerBehavior;
    use InventorySchedulerBehavior;
    use IpConflictBehavior;
    use IpamBehavior;
    use NotificationDeliveryBehavior;
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
    use TelegramChatDiscoveryBehavior;

    public function __construct(
        public readonly AppConfig $config,
        public readonly DatabaseManager $database,
        public readonly IpConflictRepository $ipConflicts,
        public readonly IpConflictDetector $ipConflictDetector,
        public readonly OperationTracker $operations,
        ?NetworkManager $networks = null,
        ?LiveUpdatePublisher $liveUpdates = null,
        public readonly ?HttpTransport $httpTransport = null,
    ) {
        $this->networks = $networks ?? new NetworkManager($config, new RouteDetector(new NativeProcessRunner()));
        $this->liveUpdates = $liveUpdates ?? new NullLiveUpdatePublisher();
    }
}

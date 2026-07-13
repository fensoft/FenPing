<?php

declare(strict_types=1);

namespace FenPing\Status;

use FenPing\Backend\Backend;

use FenPing\Database\DatabaseManager;
use FenPing\Vendor\VendorLookup;

final readonly class NotificationService
{
    public function __construct(private Backend $backend, private DatabaseManager $database, private VendorLookup $vendors)
    {
    }

    public function recent(int $hours = 24): array { return $this->backend->get_notify($hours); }
    public function portChanges(int $hours = 24): array { return $this->backend->get_port_notify($hours); }
    public function delivery(): array { return $this->backend->notificationDelivery(); }
    public function updateRules(array $rules): array
    {
        $this->backend->notificationRulesUpdate($rules);
        return $this->backend->notificationDelivery();
    }
}

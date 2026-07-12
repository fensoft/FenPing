<?php

declare(strict_types=1);

namespace FenPing\Network;

use InvalidArgumentException;

final class NetworkPolicyException extends InvalidArgumentException
{
    public function __construct(public readonly int $httpStatus, string $message) { parent::__construct($message); }
}

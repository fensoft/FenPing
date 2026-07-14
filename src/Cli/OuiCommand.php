<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Oui\OuiRegistryService;

final readonly class OuiCommand implements Command
{
    public function __construct(private OuiRegistryService $oui, private bool $refresh) {}
    public function run(array $arguments): int { return $this->refresh ? $this->oui->refresh($arguments) : $this->oui->synchronize($arguments); }
}

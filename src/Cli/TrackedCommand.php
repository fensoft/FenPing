<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Health\OperationTracker;
use Throwable;

final readonly class TrackedCommand implements Command
{
    public function __construct(private Command $command, private OperationTracker $operations, private string $operation) {}

    public function run(array $arguments): int
    {
        $this->operations->started($this->operation);
        try {
            $code = $this->command->run($arguments);
            if ($code === 0) $this->operations->succeeded($this->operation);
            else $this->operations->failed($this->operation, "command exited with code $code");
            return $code;
        } catch (Throwable $error) {
            $this->operations->failed($this->operation, $error->getMessage());
            throw $error;
        }
    }
}

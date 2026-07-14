<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class DatabaseCommand implements Command
{
    public function __construct(private DatabaseManager $database) {}
    public function run(array $arguments): int
    {
        try {
            $this->database->initialize();
            $errors = $this->database->integrityErrors();
            if ($errors !== []) throw new RuntimeException('database integrity check failed: ' . implode('; ', $errors));
            echo 'SQLite database ready: ' . $this->database->connection()->query('PRAGMA database_list')->fetchColumn(2) . PHP_EOL;
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}

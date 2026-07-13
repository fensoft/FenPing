<?php

declare(strict_types=1);

namespace FenPing\Cli;

use FenPing\Doctor\DoctorService;
use FenPing\Doctor\DoctorMode;
use JsonException;

final readonly class DoctorCommand implements Command
{
    public function __construct(private DoctorService $doctor)
    {
    }

    public function run(array $arguments): int
    {
        if (array_diff($arguments, ['--runtime', '--json']) !== []
            || count($arguments) !== count(array_unique($arguments))) {
            fwrite(STDERR, "Usage: php cli.php doctor [--runtime] [--json]" . PHP_EOL);
            return 2;
        }
        $report = $this->doctor->inspect(
            in_array('--runtime', $arguments, true) ? DoctorMode::Runtime : DoctorMode::Startup,
        );
        if (in_array('--json', $arguments, true)) {
            try {
                echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            } catch (JsonException $error) {
                fwrite(STDERR, $error->getMessage() . PHP_EOL);
                return 1;
            }
        } else {
            echo 'FenPing startup doctor' . PHP_EOL;
            foreach ($report->checks as $check) {
                echo ($check->passed ? 'PASS' : 'FAIL') . " {$check->id}: {$check->message}" . PHP_EOL;
                if (!$check->passed && $check->remediation !== '') {
                    echo "     {$check->remediation}" . PHP_EOL;
                }
            }
            echo $report->passed() ? 'Doctor passed.' . PHP_EOL : 'Doctor failed; FenPing services will not start.' . PHP_EOL;
        }
        return $report->passed() ? 0 : 1;
    }
}

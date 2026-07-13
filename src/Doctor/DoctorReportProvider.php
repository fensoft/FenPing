<?php

declare(strict_types=1);

namespace FenPing\Doctor;

interface DoctorReportProvider
{
    public function runtimeReport(): array;
}

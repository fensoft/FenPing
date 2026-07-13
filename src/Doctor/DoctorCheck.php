<?php

declare(strict_types=1);

namespace FenPing\Doctor;

use JsonSerializable;

final readonly class DoctorCheck implements JsonSerializable
{
    public function __construct(
        public string $id,
        public bool $passed,
        public string $message,
        public string $remediation = '',
    ) {
    }

    public function jsonSerialize(): array
    {
        $result = [
            'id' => $this->id,
            'status' => $this->passed ? 'pass' : 'fail',
            'message' => $this->message,
        ];
        if ($this->remediation !== '') {
            $result['remediation'] = $this->remediation;
        }
        return $result;
    }
}

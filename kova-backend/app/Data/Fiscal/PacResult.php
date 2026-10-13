<?php

namespace App\Data\Fiscal;

final readonly class PacResult
{
    public function __construct(
        public bool $successful,
        public ?string $code = null,
        public ?string $message = null,
        public ?string $uuid = null,
        public ?string $xml = null,
        public array $data = [],
    ) {}
}

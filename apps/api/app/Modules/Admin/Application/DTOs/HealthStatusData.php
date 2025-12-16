<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\DTOs;

use Spatie\LaravelData\Data;

final class HealthStatusData extends Data
{
    /**
     * @param array<string, array<string, mixed>> $checks
     */
    public function __construct(
        public readonly bool $healthy,
        public readonly array $checks,
        public readonly string $timestamp,
    ) {}
}

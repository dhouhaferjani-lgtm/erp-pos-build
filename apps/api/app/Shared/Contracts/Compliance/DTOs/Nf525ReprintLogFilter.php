<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Filter inputs for the reprint-log query. Pre-validated by the controller.
 */
final readonly class Nf525ReprintLogFilter
{
    public function __construct(
        public string $companyId,
        public ?string $terminalId,
        /** YYYY-MM-DD or null. */
        public ?string $fromDate,
        /** YYYY-MM-DD or null. */
        public ?string $toDate,
        public int $perPage,
    ) {}
}

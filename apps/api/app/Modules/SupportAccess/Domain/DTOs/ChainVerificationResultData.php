<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\DTOs;

use Spatie\LaravelData\Data;

final class ChainVerificationResultData extends Data
{
    public function __construct(
        public bool $valid,
        public ?int $failed_sequence,
        public ?string $error,
    ) {}
}

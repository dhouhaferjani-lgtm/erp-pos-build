<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\PaymentInstrument;

final readonly class MaturityLegResult
{
    public function __construct(
        public PaymentInstrument $instrument,
        public string $portfolioAccountId,
    ) {}
}

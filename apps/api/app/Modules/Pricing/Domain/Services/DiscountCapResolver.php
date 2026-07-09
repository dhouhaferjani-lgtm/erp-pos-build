<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Services;

use App\Shared\Domain\CurrencyScale;
use App\Shared\DTOs\DiscountPolicySubject;

final class DiscountCapResolver
{
    public function resolve(DiscountPolicySubject $subject): string
    {
        return CurrencyScale::bcround($subject->effectiveMaxDiscountPercent() ?? '100.00', 2);
    }
}

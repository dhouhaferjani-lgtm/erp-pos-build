<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\DTOs;

use App\Shared\Domain\Enums\VarianceDirection;

final readonly class VarianceAmount
{
    public function __construct(
        /** @var numeric-string */
        public string $amount,
        public string $currencyCode,
    ) {}

    public function direction(): VarianceDirection
    {
        return VarianceDirection::fromSignedAmount($this->amount);
    }

    public function abs(): string
    {
        return ltrim($this->amount, '-');
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', 4) === 0;
    }
}

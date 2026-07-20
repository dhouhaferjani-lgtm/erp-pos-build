<?php

declare(strict_types=1);

namespace App\Modules\Demo\Presentation\Resources;

use App\Shared\Domain\QuantityScale;

final class CleanPresentationScaleRound
{
    public function format(string $value, int $decimals): string
    {
        return QuantityScale::round($value, $decimals, QuantityScale::HALF_UP);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Demo\Presentation\Resources;

use App\Shared\Domain\QuantityScale;

final class PresentationScaleRound
{
    public function format(string $value): string
    {
        return QuantityScale::round($value, QuantityScale::SCALE, QuantityScale::HALF_UP);
    }
}

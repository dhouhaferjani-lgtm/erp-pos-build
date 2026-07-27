<?php

declare(strict_types=1);

namespace App\Modules\Demo\Application\Services;

use App\Shared\Domain\QuantityScale;

final class ApplicationScaleRound
{
    public function format(string $value): string
    {
        return QuantityScale::round($value, QuantityScale::SCALE, QuantityScale::HALF_UP);
    }
}

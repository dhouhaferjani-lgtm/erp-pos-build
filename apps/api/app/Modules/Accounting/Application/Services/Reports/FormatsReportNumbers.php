<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

trait FormatsReportNumbers
{
    private function decimalString(string|int|float|null $value, int $scale = 2): string
    {
        $number = number_format((float) ($value ?? 0), $scale, '.', '');

        return rtrim(rtrim($number, '0'), '.') ?: '0';
    }
}

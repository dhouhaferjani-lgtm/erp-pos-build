<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryData;
use App\Modules\Accounting\Application\DTOs\Reports\SalesSummaryDeltaData;
use Tests\TestCase;

final class SalesSummaryDataTest extends TestCase
{
    public function test_it_exposes_summary_fields_as_strings(): void
    {
        $dto = new SalesSummaryData(
            currencyCode: 'EUR',
            grossSales: '1500.00',
            returnsAmount: '50.00',
            netSales: '1450.00',
            salesCount: 12,
            returnsCount: 1,
            itemsSold: '34.0000',
            averageBasket: '125.00',
            delta: new SalesSummaryDeltaData('200.00', '15.38', '11.54', 3, '33.33'),
        );

        $array = $dto->toArray();

        $this->assertSame('EUR', $array['currencyCode']);
        $this->assertSame('1500.00', $array['grossSales']);
        $this->assertSame(12, $array['salesCount']);
        // O-28: the net-of-returns trend rides alongside the gross one.
        $this->assertSame('11.54', $array['delta']['netSalesPct']);
        $this->assertNull((new SalesSummaryDeltaData('0.00', null, null, 0, null))->grossSalesPct);
        $this->assertNull((new SalesSummaryDeltaData('0.00', null, null, 0, null))->netSalesPct);
    }
}

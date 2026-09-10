<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class TransferInTransitReadersUseRemainderTest extends TestCase
{
    private const OLD_READER = "\$query->where('stock_transfers.status', TransferStatus::InTransit->value)->sum('stock_transfer_lines.quantity');";

    public function test_the_three_readers_use_remainder_sql_and_carrying_statuses(): void
    {
        foreach (['LocationStockQueryService', 'StockMatrixQueryService', 'WeightedAverageCostService'] as $name) {
            $source = file_get_contents(__DIR__.'/../../app/Modules/Inventory/Application/Services/'.$name.'.php');
            self::assertTrue($this->usesRemainder($source), $name.' must use carrying statuses and remainder quantities.');
        }
    }

    public function test_the_detector_rejects_the_liveness_fixture(): void
    {
        self::assertTrue($this->usesRemainder(file_get_contents(__DIR__.'/../../app/Modules/Inventory/Application/Services/LocationStockQueryService.php')), 'The real reader must be migrated before the negative control is meaningful.');
        self::assertFalse($this->usesRemainder(self::OLD_READER));
    }

    private function usesRemainder(string $source): bool
    {
        return ! str_contains($source, 'TransferStatus::InTransit') && str_contains($source, 'REMAINDER_SQL') && str_contains($source, 'CARRYING_STATUSES');
    }
}

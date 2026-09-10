<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class TransferInTransitReadersUseRemainderTest extends TestCase
{
    private const OLD_READER = "\$query->where('stock_transfers.status', TransferStatus::InTransit->value)->sum('stock_transfer_lines.quantity');";

    public function test_the_three_readers_use_remainder_sql_and_carrying_statuses(): void
    {
        foreach (['LocationStockQueryService' => 2, 'StockMatrixQueryService' => 1, 'WeightedAverageCostService' => 1] as $name => $sites) {
            $source = file_get_contents(__DIR__.'/../../app/Modules/Inventory/Application/Services/'.$name.'.php');
            self::assertTrue($this->usesRemainder($source, $sites), $name.' must use carrying statuses and remainder quantities.');
        }
    }

    public function test_the_detector_rejects_the_liveness_fixture(): void
    {
        self::assertTrue($this->usesRemainder(file_get_contents(__DIR__.'/../../app/Modules/Inventory/Application/Services/LocationStockQueryService.php'), 2), 'The real reader must be migrated before the negative control is meaningful.');
        self::assertFalse($this->usesRemainder(self::OLD_READER));
    }

    public function test_each_location_reader_site_is_required(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Modules/Inventory/Application/Services/LocationStockQueryService.php');
        foreach (['StockTransferLine::REMAINDER_SQL', 'StockTransfer::CARRYING_STATUSES'] as $required) {
            foreach ([strpos($source, $required), strrpos($source, $required)] as $offset) {
                self::assertFalse($this->usesRemainder(substr_replace($source, 'UNMIGRATED_SITE', $offset, strlen($required)), 2));
            }
        }
    }

    private function usesRemainder(string $source, int $sites = 1): bool
    {
        return ! str_contains($source, 'TransferStatus::InTransit') && substr_count($source, 'StockTransferLine::REMAINDER_SQL') === $sites && substr_count($source, 'StockTransfer::CARRYING_STATUSES') === $sites;
    }
}

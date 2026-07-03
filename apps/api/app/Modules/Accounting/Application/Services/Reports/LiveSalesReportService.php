<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services\Reports;

use App\Modules\Accounting\Application\DTOs\Reports\LiveSaleReceiptData;
use App\Modules\Accounting\Application\DTOs\Reports\LiveSalesData;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live owner-dashboard feed: the most recent POS sale receipts across the
 * owner's locations plus the count of currently-open shifts per location.
 *
 * Reads the same pos_receipts / pos_shifts projections as the other owner
 * report services (SalesReportService, CashRegisterReportService).
 */
final class LiveSalesReportService
{
    private const RECEIPT_LIMIT = 10;

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     */
    public function report(array $companyIds, array $locationIds): LiveSalesData
    {
        $generatedAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        if ($companyIds === [] || $locationIds === []) {
            return new LiveSalesData(
                recent_receipts: [],
                open_shifts_by_location: [],
                generated_at: $generatedAt,
            );
        }

        return new LiveSalesData(
            recent_receipts: $this->recentReceipts($companyIds, $locationIds),
            open_shifts_by_location: $this->openShiftsByLocation($companyIds, $locationIds),
            generated_at: $generatedAt,
        );
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return list<LiveSaleReceiptData>
     */
    private function recentReceipts(array $companyIds, array $locationIds): array
    {
        $rows = DB::table('pos_receipts')
            ->join('locations', 'locations.id', '=', 'pos_receipts.location_id')
            ->whereIn('pos_receipts.company_id', $companyIds)
            ->whereIn('pos_receipts.location_id', $locationIds)
            ->where('pos_receipts.is_voided', false)
            ->where('pos_receipts.training_flag', false)
            // Live feed shows SALES only, consistent with the other
            // owner-dashboard breakdowns (returns are a separate metric).
            ->where('pos_receipts.receipt_type', ReceiptType::Sale->value)
            ->orderByDesc('pos_receipts.posted_at')
            ->limit(self::RECEIPT_LIMIT)
            ->selectRaw('pos_receipts.id as id')
            ->selectRaw('pos_receipts.posted_at as posted_at')
            ->selectRaw('pos_receipts.location_id as location_id')
            ->selectRaw('locations.name as location_name')
            // Select the stored decimal as text so no float ever touches the amount.
            ->selectRaw('CAST(pos_receipts.total AS TEXT) as total')
            ->selectRaw('pos_receipts.currency as currency')
            ->selectRaw('pos_receipts.receipt_number as receipt_number')
            ->selectRaw('(SELECT COUNT(*) FROM pos_receipt_lines WHERE pos_receipt_lines.receipt_id = pos_receipts.id) as items_count')
            ->get();

        return array_values($rows->map(fn (object $row): LiveSaleReceiptData => new LiveSaleReceiptData(
            id: (string) $row->id,
            posted_at: CarbonImmutable::parse((string) $row->posted_at, 'UTC')->format('Y-m-d\TH:i:s\Z'),
            location_id: (string) $row->location_id,
            location_name: (string) $row->location_name,
            total: (string) $row->total,
            currency: (string) $row->currency,
            items_count: (int) $row->items_count,
            receipt_number: (string) $row->receipt_number,
        ))->all());
    }

    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $locationIds
     * @return array<string, int>
     */
    private function openShiftsByLocation(array $companyIds, array $locationIds): array
    {
        $rows = DB::table('pos_shifts')
            ->join('pos_terminals', 'pos_terminals.id', '=', 'pos_shifts.terminal_id')
            ->whereIn('pos_terminals.company_id', $companyIds)
            ->whereIn('pos_terminals.location_id', $locationIds)
            ->where('pos_shifts.status', ShiftStatus::Open->value)
            ->groupBy('pos_terminals.location_id')
            ->selectRaw('pos_terminals.location_id as location_id')
            ->selectRaw('COUNT(*) as open_count')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->location_id] = (int) $row->open_count;
        }

        return $counts;
    }
}

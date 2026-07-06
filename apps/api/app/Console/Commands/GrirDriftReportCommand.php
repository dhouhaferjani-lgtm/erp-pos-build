<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Domain\Enums\GoodsReceiptStatus;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * @cross-tenant-by-design Single tenant connection per invocation; fleet-wide operation is an external operator loop.
 */
final class GrirDriftReportCommand extends Command
{
    protected $signature = 'procurement:grir-drift {--tenant= : Optional tenant UUID filter on the current connection}';

    protected $description = 'Report posted goods receipt paid movements missing GR-IR journal entries';

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        $query = DB::table('goods_receipt_lines')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            // INNER join is safe while goods_receipts.purchase_order_id is NOT NULL (v1);
            // if Phase 2 relaxes it, switch to leftJoin + company-currency fallback or
            // PO-less receipts silently drop out of drift detection.
            ->join('documents', 'documents.id', '=', 'goods_receipts.purchase_order_id')
            ->leftJoin('journal_entries', function (JoinClause $join): void {
                $join->on('journal_entries.source_id', '=', 'goods_receipt_lines.movement_id')
                    ->where('journal_entries.source_type', '=', 'goods_receipt');
            })
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->whereNotNull('goods_receipt_lines.movement_id')
            ->whereNull('journal_entries.id')
            ->select([
                'goods_receipts.receipt_number',
                'goods_receipt_lines.movement_id',
                'goods_receipt_lines.landed_unit_cost',
                'goods_receipt_lines.received_qty',
                'documents.currency',
            ])
            ->orderBy('goods_receipts.receipt_number')
            ->orderBy('goods_receipt_lines.movement_id');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('goods_receipts.tenant_id', $tenantId);
        }

        // Mirror GeneralLedgerService::createGoodsReceiptGrIrEntry exactly: the GL
        // early-returns (creates NO entry) when bcround(unitCost × qty) <= 0 at the
        // currency scale — so zero/sub-scale-amount PAID movements are NOT drift.
        $rows = $query->get()->filter(function (object $row): bool {
            $unitCost = (string) ($row->landed_unit_cost ?? '0');
            $receivedQty = (string) ($row->received_qty ?? '0');

            if (! is_numeric($unitCost) || ! is_numeric($receivedQty)) {
                return true;
            }

            $scale = $this->scaleResolver->getScale((string) ($row->currency ?? 'TND'));
            $amount = CurrencyScale::bcround(bcmul($unitCost, $receivedQty, $scale + 2), $scale);

            return bccomp($amount, '0', $scale) > 0;
        });

        if ($rows->isEmpty()) {
            $this->info('GR-IR drift: none');

            return self::SUCCESS;
        }

        $this->error(sprintf('GR-IR drift detected: %d movement(s)', $rows->count()));

        foreach ($rows->groupBy('receipt_number') as $receiptNumber => $receiptRows) {
            $movementIds = $receiptRows
                ->pluck('movement_id')
                ->map(static fn (mixed $movementId): string => (string) $movementId)
                ->implode(', ');

            $this->line(sprintf('%s: %s', (string) $receiptNumber, $movementIds));
        }

        return self::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One transfer line, reconciled.
 *
 * `variance` is `received − sent` at 4 dp and is NEGATIVE for a shortfall; it is
 * a derived presentation value, never a stored column.
 */
#[TypeScript]
final class TransferReconciliationLineData extends Data
{
    /**
     * @param  list<TransferReconciliationLotData>  $lots
     * @param  list<TransferDiscrepancyReason>  $discrepancy_reasons
     */
    public function __construct(
        public string $transfer_line_id,
        public string $product_id,
        public string $product_name,
        public string $product_sku,
        public ?string $variant_id,
        public int $unit_decimal_places,
        public bool $is_lot_tracked,
        public string $sent,
        public string $received,
        public string $damaged,
        public string $written_off,
        public string $returned,
        public string $remaining,
        public string $variance,
        #[LiteralTypeScriptType('Array<App.Modules.Inventory.Domain.Enums.TransferDiscrepancyReason>')]
        public array $discrepancy_reasons,
        public array $lots,
    ) {}
}

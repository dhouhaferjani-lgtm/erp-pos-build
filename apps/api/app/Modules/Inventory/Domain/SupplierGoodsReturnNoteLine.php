<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product line of a supplier goods-return note (DPA lane V8).
 *
 * `kind` is the whole reason this table exists: both kinds of return line now
 * live on one document and differ only by their costing rule
 * ({@see SupplierGoodsReturnLineKind}). `movement_id` is the Issue movement the
 * line produced on confirm; `cost_adjustment_movement_id` is populated for BONUS
 * lines only and points at the quantity-neutral movement that un-dilutes the WAC.
 *
 * `wac_undilution_applied` / `wac_undilution_forgone` are the BONUS lines' audit
 * pair: how much of the value the exiting units carried was actually capitalized
 * back onto the survivors, and how much could not be (because it had already left
 * through COGS with units issued since the bonus receipt, or because no survivor
 * remains). They always sum to `quantity x unit_cost`.
 *
 * `applied` is CONSUMED BY GL: `SupplierCreditNotePostingService` sums it across
 * the note's bonus lines and passes it to
 * `createSupplierCreditNoteEntryWithBonusReturn`, which books the compensating
 * `Dr Inventory / Cr PurchaseExpenses` pair that keeps GL reconciled with the
 * sub-ledger (stock-GL gate P1-1). It is no longer a deferred figure.
 *
 * `forgone` is NOT safe to read as a P&L figure on a multi-price history — it is
 * measured against the receipt-line ceiling, not the paid blend, and under-reports
 * there. See the PRECONDITION block in SupplierGoodsReturnNoteService.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $supplier_goods_return_note_id
 * @property string $po_line_id
 * @property string|null $goods_receipt_id
 * @property string|null $goods_receipt_line_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property SupplierGoodsReturnLineKind $kind
 * @property numeric-string $quantity
 * @property numeric-string|null $unit_cost
 * @property numeric-string|null $unit_cost_ceiling
 * @property string|null $location_id
 * @property string|null $movement_id
 * @property string|null $cost_adjustment_movement_id
 * @property numeric-string|null $wac_undilution_applied
 * @property numeric-string|null $wac_undilution_forgone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupplierGoodsReturnNote $note
 */
class SupplierGoodsReturnNoteLine extends Model
{
    use HasUuids;

    protected $table = 'supplier_goods_return_note_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'supplier_goods_return_note_id',
        'po_line_id',
        'goods_receipt_id',
        'goods_receipt_line_id',
        'product_id',
        'variant_id',
        'kind',
        'quantity',
        'unit_cost',
        'unit_cost_ceiling',
        'location_id',
        'movement_id',
        'cost_adjustment_movement_id',
        'wac_undilution_applied',
        'wac_undilution_forgone',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SupplierGoodsReturnLineKind::class,
            // Quantity at the canonical 4-dp stock scale; cost at the internal
            // COST_SCALE=6 the WAC engine persists at.
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'unit_cost_ceiling' => 'decimal:6',
            'wac_undilution_applied' => 'decimal:6',
            'wac_undilution_forgone' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<SupplierGoodsReturnNote, $this>
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(SupplierGoodsReturnNote::class, 'supplier_goods_return_note_id');
    }
}

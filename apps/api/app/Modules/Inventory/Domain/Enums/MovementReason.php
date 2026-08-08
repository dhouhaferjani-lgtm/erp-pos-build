<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum MovementReason: string
{
    // Stock IN
    case GoodsReceipt = 'goods_receipt';
    case CustomerReturn = 'customer_return';
    case AdjustmentPositive = 'adjustment_positive';
    case TransferIn = 'transfer_in';
    case ProductionOutput = 'production_output';
    case OpeningBalance = 'opening_balance';

    // Stock OUT
    case Delivery = 'delivery';
    case SupplierReturn = 'supplier_return';
    case AdjustmentNegative = 'adjustment_negative';
    // Bidirectional: a count correction can be positive or negative. Its actual
    // direction is on the movement row (quantity_before -> quantity_after), so use
    // StockMovement::directionForRow() rather than getMovementType() for it.
    case CountCorrection = 'count_correction';
    case TransferOut = 'transfer_out';
    case Damage = 'damage';
    case Expiry = 'expiry';
    case WriteOff = 'write_off';
    case Consumption = 'consumption';
    case POSSale = 'pos_sale';
    case POSReturn = 'pos_return';

    /**
     * The SIGN INVARIANT's single origin (DPA V7 / D6a).
     *
     * `'in'` means a manual adjustment line carrying this reason must have a
     * POSITIVE `delta_quantity`, `'out'` a negative one. Both runtime assertions
     * — the FormRequest closure and StockAdjustmentDocumentService — derive from
     * this method rather than restating a literal list. The migration's CHECK
     * freezes the two lists as a point-in-time snapshot (a migration cannot
     * safely derive from a mutable Domain constant under `tenants:migrate`);
     * tests/Unit/Inventory/AdjustmentReasonSignPartitionTest.php asserts the two
     * stay in agreement so an enum change fails CI instead of forking the schema.
     *
     * NOTE for CountCorrection: bidirectional, so use
     * StockMovement::directionForRow() for it — it is deliberately not part of
     * the manual vocabulary.
     */
    public function getMovementType(): string
    {
        return match ($this) {
            self::GoodsReceipt,
            self::CustomerReturn,
            self::AdjustmentPositive,
            self::TransferIn,
            self::ProductionOutput,
            self::OpeningBalance,
            self::POSReturn => 'in',
            default => 'out',
        };
    }

    public function affectsCOGS(): bool
    {
        return match ($this) {
            self::Delivery => true,
            self::CustomerReturn => true,
            self::Damage => true,
            self::Expiry => true,
            self::WriteOff => true,
            self::POSSale => true,
            self::POSReturn => true,
            default => false,
        };
    }

    public function requiresGLEntry(): bool
    {
        return match ($this) {
            self::GoodsReceipt => true,
            self::Delivery => true,
            self::CustomerReturn => true,
            self::SupplierReturn => true,
            self::Damage => true,
            self::Expiry => true,
            self::WriteOff => true,
            self::AdjustmentPositive => true,
            self::AdjustmentNegative => true,
            self::CountCorrection => true,
            self::POSSale => true,
            self::POSReturn => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GoodsReceipt => 'Goods Receipt',
            self::CustomerReturn => 'Customer Return',
            self::AdjustmentPositive => 'Stock Adjustment (In)',
            self::TransferIn => 'Transfer In',
            self::ProductionOutput => 'Production Output',
            self::OpeningBalance => 'Opening Balance',
            self::Delivery => 'Delivery to Customer',
            self::SupplierReturn => 'Return to Supplier',
            self::AdjustmentNegative => 'Stock Adjustment (Out)',
            self::CountCorrection => 'Count Correction',
            self::TransferOut => 'Transfer Out',
            self::Damage => 'Damaged Stock',
            self::Expiry => 'Expired Stock',
            self::WriteOff => 'Stock Write-Off',
            self::Consumption => 'Internal Consumption',
            self::POSSale => 'POS Sale',
            self::POSReturn => 'POS Return',
        };
    }

    /**
     * Reasons selectable on a `stock_adjustments` document LINE (DPA V7).
     *
     * The vocabulary of the manual stock-correction document — the only manual
     * stock writer after V7 deleted the four raw `POST /stock-movements/*`
     * endpoints. Excludes document/POS-driven reasons (set by their own flows)
     * and, deliberately, four more:
     *
     * - `CountCorrection` — reserved for the counting flow, and bidirectional,
     *   so it has no sign invariant this document could enforce.
     * - `OpeningBalance` (D7) — selecting it here produced a WRONG opening
     *   balance: this path writes MovementType::Adjustment with no WAC basis, no
     *   enter-once guard and no GL leg. A real opening balance is
     *   MovementType::Opening via OpeningBalancePostingService, reachable from
     *   the product editor's opening_qty / opening_unit_cost fields.
     * - `Expiry` (D7a) — an expiry is always lot-identified, and
     *   BatchWriteOffService already posts the Dr COGS / Cr Inventory leg this
     *   document does not (GL is deferred to G1). Routing it here would LOSE a
     *   journal entry that exists today.
     * - `Consumption` (D7b) — `requiresGLEntry()` and `affectsCOGS()` are both
     *   false via the `default` arms, so offering it would be a silent value leak
     *   the moment G1 lands. Internal consumption is represented by
     *   `AdjustmentNegative` in v1 (an inventory-adjustment leg, no COGS leg) —
     *   NEVER by `WriteOff`, whose `affectsCOGS()` is true and would produce a
     *   WRONG COGS rather than a missing one.
     *
     * @return list<self>
     */
    public static function manualAdjustmentCases(): array
    {
        return [
            self::AdjustmentPositive,
            self::AdjustmentNegative,
            self::Damage,
            self::WriteOff,
        ];
    }

    /**
     * @return list<string>
     */
    public static function manualAdjustmentValues(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::manualAdjustmentCases());
    }
}

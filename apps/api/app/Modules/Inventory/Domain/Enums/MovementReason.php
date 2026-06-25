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
     * Reasons selectable in the manual stock-adjustment screen. Excludes
     * document/POS-driven reasons (set by their own flows) and CountCorrection
     * (reserved for the counting flow).
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
            self::OpeningBalance,
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

<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Receipt VAT Detail Entity
 *
 * Represents VAT breakdown for a single tax rate on a receipt.
 * Required for NF525 compliance - feeds into vat_breakdown_hash.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string|null $tax_category
 * @property numeric-string $tax_rate VAT rate percentage (e.g., 19.00)
 * @property numeric-string $net_amount Total net amount (HT) for this rate
 * @property numeric-string $vat_amount Total VAT amount for this rate
 * @property numeric-string $gross_amount Total gross amount (TTC) = net + VAT
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Receipt $receipt
 */
class ReceiptVatDetail extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_receipt_vat_details';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_id',
        'tax_category',
        'tax_rate',
        'net_amount',
        'vat_amount',
        'gross_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * Get effective VAT rate as decimal
     */
    public function getVatRateDecimal(): string
    {
        return bcdiv($this->tax_rate, '100', 4);
    }

    /**
     * Verify VAT calculation is correct
     */
    public function verifyCalculation(): bool
    {
        // Verify gross = net + vat
        $calculatedGross = bcadd($this->net_amount, $this->vat_amount, 2);
        if ($calculatedGross !== $this->gross_amount) {
            return false;
        }

        // Verify vat = net * rate
        $expectedVat = bcmul($this->net_amount, $this->getVatRateDecimal(), 2);
        if ($expectedVat !== $this->vat_amount) {
            return false;
        }

        return true;
    }

    /**
     * Get formatted VAT detail line for receipt printing
     */
    public function getDisplayLine(): string
    {
        return sprintf(
            'TVA %s%%: %s (Base: %s)',
            $this->tax_rate,
            $this->vat_amount,
            $this->net_amount
        );
    }
}

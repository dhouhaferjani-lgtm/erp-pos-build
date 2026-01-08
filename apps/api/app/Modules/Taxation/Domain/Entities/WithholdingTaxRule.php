<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Company\Domain\Company;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Withholding Tax Rule
 *
 * Defines the conditions and rate for automatic withholding tax calculation.
 * Rules can be global (country-level) or company-specific overrides.
 *
 * @property string $id
 * @property string $country_code
 * @property string|null $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property TransactionType|null $transaction_type
 * @property PartnerTaxStatus|null $partner_tax_status
 * @property numeric-string|null $min_amount
 * @property numeric-string $rate
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $company
 */
class WithholdingTaxRule extends Model
{
    use HasUuids;

    protected $fillable = [
        'country_code',
        'company_id',
        'code',
        'name',
        'description',
        'transaction_type',
        'partner_tax_status',
        'min_amount',
        'rate',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'partner_tax_status' => PartnerTaxStatus::class,
            'min_amount' => 'decimal:3',
            'rate' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Check if this rule is global (not company-specific).
     */
    public function isGlobal(): bool
    {
        return $this->company_id === null;
    }

    /**
     * Check if this rule is effective on a given date.
     */
    public function isEffectiveOn(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to && $date->gt($this->effective_to)) {
            return false;
        }

        return true;
    }

    /**
     * Check if this rule applies to given conditions.
     *
     * @param  numeric-string  $amount
     */
    public function appliesTo(
        PartnerTaxStatus $partnerStatus,
        string $amount,
        ?TransactionType $transactionType,
        Carbon $date
    ): bool {
        // Check if rule is effective on date
        if (! $this->isEffectiveOn($date)) {
            return false;
        }

        // Check partner tax status match (null means applies to all)
        if ($this->partner_tax_status && $this->partner_tax_status !== $partnerStatus) {
            return false;
        }

        // Check transaction type match (null means applies to all)
        if ($this->transaction_type && $this->transaction_type !== $transactionType) {
            return false;
        }

        // Check minimum amount threshold
        if ($this->min_amount && bccomp($amount, $this->min_amount, 3) < 0) {
            return false;
        }

        return true;
    }

    /**
     * Get the withholding rate as a percentage (e.g., 0.05 becomes 5).
     */
    public function getRateAsPercentage(): float
    {
        return (float) bcmul($this->rate, '100', 2);
    }

    /**
     * Get display name for the rule.
     */
    public function getDisplayName(): string
    {
        $scope = $this->isGlobal() ? $this->country_code : 'Custom';

        return "[{$scope}] {$this->name}";
    }
}

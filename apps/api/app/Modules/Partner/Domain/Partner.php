<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property PartnerType $type
 * @property numeric-string $receivable_balance
 * @property numeric-string $credit_balance
 * @property numeric-string $payable_balance
 * @property \Illuminate\Support\Carbon|null $balance_updated_at
 * @property string|null $code
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $country_code
 * @property string|null $vat_number
 * @property PartnerTaxStatus $tax_status
 * @property string|null $tax_exemption_reason
 * @property string|null $tax_exemption_certificate_media_id
 * @property \Illuminate\Support\Carbon|null $tax_exemption_valid_until
 * @property bool $withholding_exempt
 * @property string|null $withholding_exemption_reason
 * @property string|null $withholding_exemption_certificate_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read string $net_balance
 */
class Partner extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'type',
        'receivable_balance',
        'credit_balance',
        'payable_balance',
        'balance_updated_at',
        'code',
        'email',
        'phone',
        'country_code',
        'vat_number',
        'tax_status',
        'tax_exemption_reason',
        'tax_exemption_certificate_media_id',
        'tax_exemption_valid_until',
        'withholding_exempt',
        'withholding_exemption_reason',
        'withholding_exemption_certificate_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PartnerType::class,
            'tax_status' => PartnerTaxStatus::class,
            'tax_exemption_valid_until' => 'date',
            'withholding_exempt' => 'boolean',
            'receivable_balance' => 'decimal:4',
            'credit_balance' => 'decimal:4',
            'payable_balance' => 'decimal:4',
            'balance_updated_at' => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \Database\Factories\PartnerFactory
    {
        return \Database\Factories\PartnerFactory::new();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isCustomer(): bool
    {
        return $this->type === PartnerType::Customer || $this->type === PartnerType::Both;
    }

    public function isSupplier(): bool
    {
        return $this->type === PartnerType::Supplier || $this->type === PartnerType::Both;
    }

    public function getDisplayName(): string
    {
        return $this->name;
    }

    /**
     * Scope a query to only include customers.
     *
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->whereIn('type', [PartnerType::Customer, PartnerType::Both]);
    }

    /**
     * Scope a query to only include suppliers.
     *
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->whereIn('type', [PartnerType::Supplier, PartnerType::Both]);
    }

    /**
     * Scope a query to only include partners for a specific tenant.
     *
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include partners for a specific company.
     *
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Get the net balance for this partner.
     * Positive = they owe us (receivable) or we owe them (payable).
     */
    public function getNetBalanceAttribute(): string
    {
        if ($this->isCustomer()) {
            // Customer: receivable minus any credit they have
            return bcsub($this->receivable_balance ?? '0', $this->credit_balance ?? '0', 4);
        }

        // Supplier: what we owe them
        return $this->payable_balance ?? '0';
    }

    /**
     * Check if partner has outstanding balance.
     */
    public function hasOutstandingBalance(): bool
    {
        return bccomp($this->net_balance, '0', 4) !== 0;
    }

    /**
     * Check if balance cache is stale (older than threshold).
     */
    public function isBalanceStale(int $minutes = 60): bool
    {
        if ($this->balance_updated_at === null) {
            return true;
        }

        return $this->balance_updated_at->diffInMinutes(now()) > $minutes;
    }

    /**
     * Check if partner has a valid tax exemption
     */
    public function hasValidTaxExemption(): bool
    {
        if ($this->tax_status !== PartnerTaxStatus::EXEMPT) {
            return false;
        }

        if (! $this->tax_exemption_certificate_media_id) {
            return false;
        }

        if ($this->tax_exemption_valid_until && $this->tax_exemption_valid_until->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get tax exemption warnings
     *
     * @return array<int, array<string, string>>
     */
    public function getTaxExemptionWarnings(): array
    {
        $warnings = [];

        if ($this->tax_status !== PartnerTaxStatus::EXEMPT) {
            return $warnings;
        }

        if (! $this->tax_exemption_certificate_media_id) {
            $warnings[] = [
                'type' => 'missing_certificate',
                'message' => 'No exemption certificate on file',
                'severity' => 'error',
            ];
        }

        if ($this->tax_exemption_valid_until) {
            if ($this->tax_exemption_valid_until->isPast()) {
                $warnings[] = [
                    'type' => 'expired_certificate',
                    'message' => 'Exemption certificate expired on '.$this->tax_exemption_valid_until->format('Y-m-d'),
                    'severity' => 'error',
                ];
            } elseif ($this->tax_exemption_valid_until->diffInDays(now()) <= 30) {
                $warnings[] = [
                    'type' => 'expiring_soon',
                    'message' => 'Exemption certificate expires on '.$this->tax_exemption_valid_until->format('Y-m-d'),
                    'severity' => 'warning',
                ];
            }
        }

        return $warnings;
    }
}

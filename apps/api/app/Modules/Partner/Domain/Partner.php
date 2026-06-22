<?php

declare(strict_types=1);

namespace App\Modules\Partner\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Contact\Domain\PartyContact;
use App\Modules\Partner\Domain\Enums\ConsolidationFrequency;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Enums\PaymentTerms;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property PartnerType $type
 * @property CustomerCategory|null $customer_category
 * @property string|null $company_legal_name
 * @property string|null $business_registration_number
 * @property PaymentTerms|null $payment_terms
 * @property int|null $payment_terms_days
 * @property numeric-string|null $credit_limit
 * @property numeric-string|null $discount_percentage
 * @property bool $invoice_consolidation
 * @property ConsolidationFrequency|null $consolidation_frequency
 * @property numeric-string $receivable_balance
 * @property numeric-string $credit_balance
 * @property numeric-string $payable_balance
 * @property Carbon|null $balance_updated_at
 * @property string|null $code
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $country_code
 * @property string|null $vat_number
 * @property PartnerTaxStatus $tax_status
 * @property string|null $tax_exemption_reason
 * @property string|null $tax_exemption_certificate_media_id
 * @property Carbon|null $tax_exemption_valid_until
 * @property bool $withholding_exempt
 * @property string|null $withholding_exemption_reason
 * @property string|null $withholding_exemption_certificate_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $street_address
 * @property string|null $street_address_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country
 * @property bool $is_active
 * @property CustomerAccountStatus $account_status
 * @property int $account_status_version
 * @property Carbon|null $account_status_changed_at
 * @property string|null $account_status_changed_by
 * @property string|null $account_status_reason
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read string $net_balance
 */
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'account_status' => 'active',
        'account_status_version' => 1,
    ];

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'type',
        'customer_category',
        'company_legal_name',
        'business_registration_number',
        'payment_terms',
        'payment_terms_days',
        'credit_limit',
        'discount_percentage',
        'invoice_consolidation',
        'consolidation_frequency',
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
        'street_address',
        'street_address_2',
        'city',
        'state',
        'postal_code',
        'country',
        'is_active',
        'account_status',
        'account_status_version',
        'account_status_changed_at',
        'account_status_changed_by',
        'account_status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PartnerType::class,
            'customer_category' => CustomerCategory::class,
            'payment_terms' => PaymentTerms::class,
            'consolidation_frequency' => ConsolidationFrequency::class,
            'invoice_consolidation' => 'boolean',
            'credit_limit' => 'decimal:3',
            'discount_percentage' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'tax_status' => PartnerTaxStatus::class,
            'tax_exemption_valid_until' => 'date',
            'withholding_exempt' => 'boolean',
            'receivable_balance' => 'decimal:3',
            'credit_balance' => 'decimal:3',
            'payable_balance' => 'decimal:3',
            'balance_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'account_status' => CustomerAccountStatus::class,
            'account_status_version' => 'integer',
            'account_status_changed_at' => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PartnerFactory
    {
        return PartnerFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(static function (Partner $partner): void {
            self::assertNonNegativeCachedLiabilityMagnitude('credit_balance', $partner->credit_balance);
            self::assertNonNegativeCachedLiabilityMagnitude('payable_balance', $partner->payable_balance);
        });
    }

    private static function assertNonNegativeCachedLiabilityMagnitude(string $attribute, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (str_starts_with(ltrim($value), '-')) {
            throw new \InvalidArgumentException("{$attribute} must be a non-negative magnitude.");
        }
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

    /**
     * Check if this partner is a B2B (business) customer.
     */
    public function isB2B(): bool
    {
        return $this->customer_category === CustomerCategory::Business;
    }

    /**
     * Check if this partner has an active (non-null, positive) credit limit.
     */
    public function hasActiveCreditLimit(): bool
    {
        if ($this->credit_limit === null) {
            return false;
        }

        return bccomp($this->credit_limit, '0', 3) > 0;
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
        $customerPosition = bcsub($this->receivable_balance ?? '0', $this->credit_balance ?? '0', 3);

        if ($this->type === PartnerType::Both) {
            return bcsub($customerPosition, $this->payable_balance ?? '0', 3);
        }

        if ($this->type === PartnerType::Customer) {
            return $customerPosition;
        }

        // Supplier: what we owe them
        return $this->payable_balance ?? '0';
    }

    /**
     * Check if partner has outstanding balance.
     */
    public function hasOutstandingBalance(): bool
    {
        /** @var numeric-string $netBalance */
        $netBalance = $this->net_balance;

        return bccomp($netBalance, '0', 4) !== 0;
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
            } elseif ($this->tax_exemption_valid_until->diffInDays(now(), absolute: true) <= 30) {
                $warnings[] = [
                    'type' => 'expiring_soon',
                    'message' => 'Exemption certificate expires on '.$this->tax_exemption_valid_until->format('Y-m-d'),
                    'severity' => 'warning',
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return HasMany<PartyContact, $this>
     */
    public function partyContacts(): HasMany
    {
        return $this->hasMany(PartyContact::class, 'party_id');
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'party_contacts', 'party_id', 'contact_id')
            ->withPivot(['job_title', 'department', 'is_primary', 'is_invoice_contact', 'is_delivery_contact', 'start_date', 'end_date'])
            ->withTimestamps();
    }

    /**
     * Get the primary contact for this partner.
     */
    public function primaryContact(): ?Contact
    {
        return $this->contacts()->wherePivot('is_primary', true)->first();
    }
}

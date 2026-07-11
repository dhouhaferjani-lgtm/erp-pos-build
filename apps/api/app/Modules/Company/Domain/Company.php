<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain;

use App\Models\Country;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Enums\PriceEntryMode;
use App\Modules\Company\Domain\Enums\VerificationStatus;
use App\Modules\Company\Domain\Enums\VerificationTier;
use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Company\Domain\Events\CompanyUpdated;
use App\Modules\Company\Domain\ValueObjects\ReservationSettings;
use App\Modules\Document\Domain\Document;
use App\Modules\SmartPrompts\Domain\Enums\SmartPromptsVariant;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company model - represents a legal entity.
 *
 * IMPORTANT: Company is where business data is scoped.
 * A tenant (account holder) can have multiple companies.
 * Each company has its own tax_id, country_code, compliance profile.
 *
 * @property string $id UUID of the company
 * @property string $tenant_id UUID of the owning tenant (account)
 * @property string $name Display name
 * @property string|null $legal_name Legal/registered name
 * @property string|null $code Internal company code
 * @property string $country_code ISO 3166-1 alpha-2 country code (CRITICAL!)
 * @property string|null $tax_id VAT/Tax identification number
 * @property string|null $registration_number Company registration number
 * @property string|null $vat_number VAT number (may differ from tax_id)
 * @property array<string, mixed> $legal_identifiers Country-specific identifiers
 * @property string|null $email Contact email
 * @property string|null $phone Contact phone
 * @property string|null $website Website URL
 * @property string|null $address_street Street address line 1
 * @property string|null $address_street_2 Street address line 2
 * @property string|null $address_city City
 * @property string|null $address_state State/Province
 * @property string|null $address_postal_code Postal/ZIP code
 * @property string|null $logo_path Path to logo file
 * @property string $primary_color Brand primary color (hex)
 * @property string $currency ISO 4217 currency code
 * @property string $locale Locale identifier
 * @property string $timezone Timezone identifier
 * @property string $date_format Date display format
 * @property int $fiscal_year_start_month Month fiscal year starts (1-12)
 * @property Carbon|null $fiscal_year_validated_at When fiscal year was validated
 * @property string|null $fiscal_year_validated_by UUID of user who validated fiscal year
 * @property Carbon|null $first_transaction_posted_at When first transaction was posted (locks fiscal year)
 * @property string|null $first_transaction_document_id UUID of first posted document
 * @property string $invoice_prefix Prefix for invoice numbers
 * @property int $invoice_next_number Next invoice number
 * @property string $quote_prefix Prefix for quote numbers
 * @property int $quote_next_number Next quote number
 * @property string $sales_order_prefix Prefix for sales order numbers
 * @property int $sales_order_next_number Next sales order number
 * @property string $purchase_order_prefix Prefix for purchase order numbers
 * @property int $purchase_order_next_number Next purchase order number
 * @property string $delivery_note_prefix Prefix for delivery note numbers
 * @property int $delivery_note_next_number Next delivery note number
 * @property string $receipt_prefix Prefix for receipt numbers
 * @property int $receipt_next_number Next receipt number
 * @property bool $auto_print_receipts Whether to auto-print receipts after transaction
 * @property string|null $receipt_logo Path to receipt logo (can differ from main logo)
 * @property string|null $receipt_footer Custom footer text for receipts
 * @property string|null $receipt_header Custom header text below logo
 * @property bool $receipt_show_vat_breakdown Whether to show VAT breakdown on receipts
 * @property bool $receipt_show_fiscal_info Whether to show fiscal information on receipts
 * @property bool $receipt_show_payment_details Whether to show payment details on receipts
 * @property bool $receipt_show_customer Whether to show customer name on receipts
 * @property string|null $receipt_thank_you Custom thank-you message for receipts
 * @property bool $line_designation_override_enabled Whether document line designations can differ from product names
 * @property VerificationTier $verification_tier Verification tier
 * @property VerificationStatus $verification_status Verification status
 * @property Carbon|null $verification_submitted_at When verification was submitted
 * @property Carbon|null $verified_at When verification completed
 * @property string|null $verified_by UUID of verifier
 * @property string|null $verification_notes Verification notes
 * @property string|null $compliance_profile Compliance profile identifier
 * @property PosStockPolicy $pos_stock_policy POS over-stock enforcement behavior (spec §4.2)
 * @property string|null $parent_company_id UUID of parent company (for chains)
 * @property bool $is_headquarters Whether this is headquarters
 * @property CompanyTaxStatus $tax_status Tax registration status
 * @property string $default_tax_rate Company-level fallback tax rate (decimal 5,2 stored as string)
 * @property string|null $default_tax_configuration_id FK to the company's default TaxConfiguration
 * @property CompanyStatus $status Company status
 * @property Carbon|null $closed_at When company was closed
 * @property string $inventory_costing_method Inventory costing method (weighted_average)
 * @property string $default_target_margin Default target margin percentage
 * @property string $default_minimum_margin Default minimum margin percentage
 * @property bool $allow_below_cost_sales Whether below-cost sales are allowed
 * @property string|null $default_max_discount_percent Company-level discount cap percentage
 * @property DiscountFloorMode $discount_floor_mode Phase-1 B2B discount-floor enforcement mode
 * @property PriceEntryMode $price_entry_mode Company product price entry/display preference
 * @property bool $allow_cross_location_stock_view Whether the POS may show stock levels from all locations
 * @property string|null $fiscal_chain_seed Unique 256-bit seed for fiscal hash chain genesis
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company|null $parentCompany
 * @property-read Collection<int, Company> $childCompanies
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * Bootstrap the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            // Auto-generate fiscal chain seed if not provided
            if ($company->fiscal_chain_seed === null) {
                $company->fiscal_chain_seed = bin2hex(random_bytes(32));
            }
        });

        static::created(function (Company $company): void {
            $userId = auth()->id() ?? 'system';

            event(new CompanyCreated(
                companyId: $company->id,
                tenantId: $company->tenant_id,
                name: $company->name,
                countryCode: $company->country_code,
                currency: $company->currency,
                fiscalYearStartMonth: $company->fiscal_year_start_month,
                createdBy: (string) $userId,
                createdAt: $company->created_at->toIso8601String(),
            ));
        });

        static::updated(function (Company $company): void {
            // Only dispatch if there are actual changes
            if (empty($company->getDirty())) {
                return;
            }

            $changes = [];
            foreach ($company->getDirty() as $key => $newValue) {
                $changes[$key] = [
                    'old' => $company->getOriginal($key),
                    'new' => $newValue,
                ];
            }

            $userId = auth()->id() ?? 'system';

            event(new CompanyUpdated(
                companyId: $company->id,
                tenantId: $company->tenant_id,
                userId: (string) $userId,
                changes: $changes,
                attributes: $company->attributesToArray(),
                updatedAt: now()->toIso8601String(),
            ));
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /**
     * The table associated with the model.
     */
    protected $table = 'companies';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'legal_name',
        'code',
        'country_code',
        'tax_id',
        'registration_number',
        'vat_number',
        'legal_identifiers',
        'email',
        'phone',
        'website',
        'address_street',
        'address_street_2',
        'address_city',
        'address_state',
        'address_postal_code',
        'logo_path',
        'primary_color',
        'currency',
        'locale',
        'timezone',
        'date_format',
        'fiscal_year_start_month',
        'default_tax_rate',
        'default_tax_configuration_id',
        'tax_status',
        'fiscal_year_validated_at',
        'fiscal_year_validated_by',
        'first_transaction_posted_at',
        'first_transaction_document_id',
        'invoice_prefix',
        'invoice_next_number',
        'quote_prefix',
        'quote_next_number',
        'sales_order_prefix',
        'sales_order_next_number',
        'purchase_order_prefix',
        'purchase_order_next_number',
        'delivery_note_prefix',
        'delivery_note_next_number',
        'receipt_prefix',
        'receipt_next_number',
        'auto_print_receipts',
        'receipt_logo',
        'receipt_footer',
        'receipt_header',
        'receipt_show_vat_breakdown',
        'receipt_show_fiscal_info',
        'receipt_show_payment_details',
        'receipt_show_customer',
        'receipt_thank_you',
        'line_designation_override_enabled',
        'verification_tier',
        'verification_status',
        'verification_submitted_at',
        'verified_at',
        'verified_by',
        'verification_notes',
        'compliance_profile',
        'pos_stock_policy',
        'parent_company_id',
        'is_headquarters',
        'status',
        'closed_at',
        'inventory_costing_method',
        'default_target_margin',
        'default_minimum_margin',
        'allow_below_cost_sales',
        'default_max_discount_percent',
        'discount_floor_mode',
        'price_entry_mode',
        'payment_tolerance_enabled',
        'payment_tolerance_percentage',
        'max_payment_tolerance_amount',
        'fiscal_chain_seed',
        'reservation_settings',
        'smart_prompts_enabled',
        'smart_prompts_variant',
        'allow_cross_location_stock_view',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_identifiers' => 'array',
            'is_headquarters' => 'boolean',
            'fiscal_year_start_month' => 'integer',
            'invoice_next_number' => 'integer',
            'quote_next_number' => 'integer',
            'sales_order_next_number' => 'integer',
            'purchase_order_next_number' => 'integer',
            'delivery_note_next_number' => 'integer',
            'receipt_next_number' => 'integer',
            'auto_print_receipts' => 'boolean',
            'receipt_show_vat_breakdown' => 'boolean',
            'receipt_show_fiscal_info' => 'boolean',
            'receipt_show_payment_details' => 'boolean',
            'receipt_show_customer' => 'boolean',
            'line_designation_override_enabled' => 'boolean',
            'verification_tier' => VerificationTier::class,
            'verification_status' => VerificationStatus::class,
            'verification_submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
            'fiscal_year_validated_at' => 'datetime',
            'first_transaction_posted_at' => 'datetime',
            'status' => CompanyStatus::class,
            'tax_status' => CompanyTaxStatus::class,
            'allow_below_cost_sales' => 'boolean',
            'default_max_discount_percent' => 'decimal:2',
            'discount_floor_mode' => DiscountFloorMode::class,
            'price_entry_mode' => PriceEntryMode::class,
            'payment_tolerance_enabled' => 'boolean',
            'payment_tolerance_percentage' => 'string',
            'max_payment_tolerance_amount' => 'string',
            'reservation_settings' => 'array',
            'smart_prompts_enabled' => 'boolean',
            'smart_prompts_variant' => SmartPromptsVariant::class,
            'pos_stock_policy' => PosStockPolicy::class,
            'allow_cross_location_stock_view' => 'boolean',
        ];
    }

    /**
     * Get the tenant (account holder) that owns this company.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the country for this company.
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }

    /**
     * Get the parent company (for chains/franchises).
     *
     * @return BelongsTo<Company, $this>
     */
    public function parentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_company_id');
    }

    /**
     * Get child companies (branches).
     *
     * @return HasMany<Company, $this>
     */
    public function childCompanies(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_company_id');
    }

    /**
     * Get all locations for this company.
     *
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Get all user memberships for this company.
     *
     * @return HasMany<UserCompanyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompanyMembership::class);
    }

    /**
     * Get all documents for this company.
     *
     * @return HasMany<CompanyDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }

    /**
     * Get all hash chain entries for this company.
     *
     * @return HasMany<CompanyHashChain, $this>
     */
    public function hashChains(): HasMany
    {
        return $this->hasMany(CompanyHashChain::class);
    }

    /**
     * Get all sequences for this company.
     *
     * @return HasMany<CompanySequence, $this>
     */
    public function sequences(): HasMany
    {
        return $this->hasMany(CompanySequence::class);
    }

    /**
     * Get all fiscal years for this company.
     *
     * @return HasMany<FiscalYear, $this>
     */
    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    /**
     * Get all fiscal periods for this company.
     *
     * @return HasMany<FiscalPeriod, $this>
     */
    public function fiscalPeriods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }

    /**
     * Check if the company is active.
     */
    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    /**
     * Check if the company is verified.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === VerificationStatus::Verified;
    }

    /**
     * Get formatted full address.
     */
    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address_street,
            $this->address_street_2,
            $this->address_city,
            $this->address_state,
            $this->address_postal_code,
        ]);

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }

    /**
     * Check if fiscal year has been validated by the user.
     */
    public function isFiscalYearValidated(): bool
    {
        return $this->fiscal_year_validated_at !== null;
    }

    /**
     * Check if fiscal year is permanently locked (first transaction posted).
     */
    public function hasFiscalYearLocked(): bool
    {
        return $this->first_transaction_posted_at !== null;
    }

    /**
     * Check if the fiscal year start month can be changed.
     * Can only change if no transaction has been posted yet.
     */
    public function canChangeFiscalYear(): bool
    {
        return ! $this->hasFiscalYearLocked();
    }

    /**
     * Check if transactions can be posted.
     * Transactions can only be posted after fiscal year is validated.
     */
    public function canPostTransactions(): bool
    {
        return $this->isFiscalYearValidated();
    }

    /**
     * Check if company has any posted fiscal documents.
     * Posted fiscal documents are immutable and prevent tax status changes.
     */
    public function hasPostedFiscalDocuments(): bool
    {
        return Document::query()
            ->where('company_id', $this->id)
            ->whereIn('type', ['invoice', 'credit_note'])
            ->where('status', 'posted')
            ->exists();
    }

    /**
     * Check if company can change tax status.
     * Tax status changes are only allowed before any fiscal documents are posted.
     */
    public function canChangeTaxStatus(): bool
    {
        return ! $this->hasPostedFiscalDocuments();
    }

    /**
     * Get reservation settings with defaults.
     */
    public function getReservationSettings(): ReservationSettings
    {
        if ($this->reservation_settings === null) {
            return new ReservationSettings;
        }

        /** @var array<string, mixed> $settings */
        $settings = $this->reservation_settings;

        return ReservationSettings::fromArray($settings);
    }

    /**
     * Check if company can recover VAT on purchases.
     */
    public function canRecoverVAT(): bool
    {
        return $this->tax_status === CompanyTaxStatus::REGISTERED;
    }
}

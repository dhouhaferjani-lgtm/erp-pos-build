<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain;

use App\Enums\Vertical;
use App\Models\TenantSubscription;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use Carbon\Carbon;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant model for AutoERP multi-tenancy.
 *
 * IMPORTANT: Tenant = Account/Person (subscription holder), NOT a company.
 * Company-specific data (tax_id, legal_name, etc.) lives in the Company model.
 *
 * @property string $id UUID of the tenant
 * @property string $name Account/Display name
 * @property string|null $first_name Personal first name
 * @property string|null $last_name Personal last name
 * @property string $preferred_locale Preferred UI locale (default: fr)
 * @property string|null $full_name Computed full name (accessor)
 * @property string|null $legal_name Legal/registered business name (deprecated - use Company)
 * @property string $slug URL-friendly identifier
 * @property TenantStatus $status Tenant lifecycle status
 * @property SubscriptionPlan $plan Subscription plan
 * @property string|null $tax_id VAT/Tax identification number (deprecated - use Company)
 * @property string|null $registration_number Company registration number (deprecated - use Company)
 * @property array<string, mixed> $address Address object (deprecated - use Company)
 * @property string|null $phone Contact phone number
 * @property string|null $email Contact email
 * @property string|null $website Website URL
 * @property string|null $logo_path Path to logo file
 * @property string $primary_color Brand primary color (hex)
 * @property string|null $country_code ISO 3166-1 alpha-2 country code (deprecated - use Company)
 * @property string|null $currency_code ISO 4217 currency code (deprecated - use Company)
 * @property string $timezone Timezone identifier
 * @property string $date_format Date display format
 * @property string $locale Default locale
 * @property array<string, mixed> $settings Tenant-specific settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $subscription_ends_at
 * @property Vertical $vertical Business vertical (mechanic, pharmacy, etc.)
 * @property array<int, string>|null $enabled_extras Enabled optional modules
 * @property string|null $signup_source Signup attribution source
 * @property array<string, mixed>|null $signup_tracking Signup tracking metadata
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'preferred_locale',
        'legal_name',
        'slug',
        'status',
        'plan',
        'is_demo',
        'tax_id',
        'registration_number',
        'address',
        'phone',
        'email',
        'website',
        'logo_path',
        'primary_color',
        'country_code',
        'currency_code',
        'timezone',
        'date_format',
        'locale',
        'settings',
        'trial_ends_at',
        'subscription_ends_at',
        'vertical',
        'enabled_extras',
        'signup_source',
        'signup_tracking',
        'is_sensitive',
        'support_access_starts_at',
        'support_access_expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'plan' => SubscriptionPlan::class,
            'is_demo' => 'boolean',
            'vertical' => Vertical::class,
            'address' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'enabled_extras' => 'array',
            'signup_tracking' => 'array',
            'is_sensitive' => 'boolean',
            'support_access_starts_at' => 'datetime',
            'support_access_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the custom columns for the tenants table.
     * These are stored in the data column by default in stancl/tenancy,
     * but we define them as actual columns for better querying.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'first_name',
            'last_name',
            'preferred_locale',
            'legal_name',
            'slug',
            'status',
            'plan',
            'tax_id',
            'registration_number',
            'address',
            'phone',
            'email',
            'website',
            'logo_path',
            'primary_color',
            'country_code',
            'currency_code',
            'timezone',
            'date_format',
            'locale',
            'settings',
            'trial_ends_at',
            'subscription_ends_at',
            'vertical',
            'enabled_extras',
            'signup_source',
            'signup_tracking',
            'is_sensitive',
            'support_access_starts_at',
            'support_access_expires_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Check if the tenant is active and can access the system.
     */
    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * Check if the tenant is in trial period.
     */
    public function isInTrial(): bool
    {
        if ($this->plan !== SubscriptionPlan::Trial) {
            return false;
        }

        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if the tenant has a valid subscription.
     */
    public function hasValidSubscription(): bool
    {
        if ($this->isInTrial()) {
            return true;
        }

        return $this->subscription_ends_at !== null && $this->subscription_ends_at->isFuture();
    }

    /**
     * Get the full name of the tenant account holder.
     */
    public function getFullNameAttribute(): ?string
    {
        if ($this->first_name === null && $this->last_name === null) {
            return null;
        }

        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    /**
     * Get all companies owned by this tenant (account).
     *
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Get the tenant's active subscription.
     *
     * @return HasOne<TenantSubscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    /**
     * The physical database name for this tenant under database-per-tenant.
     *
     * Delegates to Stancl's DatabaseConfig (tenancy.database.prefix + tenant key
     * + suffix) — the exact name CreateDatabase / MigrateDatabase / tenancy()->
     * initialize() use. Pre-flip this returned a schema name ('tenant_'.$slug);
     * that schema-era value no longer matches the created database and broke the
     * provisioning existence check (login fail-closed) in DB-per-tenant mode.
     */
    public function getDatabaseName(): string
    {
        $name = $this->database()->getName();

        if ($name === null) {
            throw new \RuntimeException(
                "Tenant [{$this->getKey()}] has no resolvable database name; tenancy database config is missing.",
            );
        }

        return $name;
    }
}

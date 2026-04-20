<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property SystemAccountPurpose|null $system_purpose
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_system
 * @property numeric-string $balance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Account|null $parent
 * @property-read Collection<int, Account> $children
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static Builder<static> withPurpose(SystemAccountPurpose $purpose)
 * @method static Builder<static> active()
 * @method static Builder<static> ofType(AccountType $type)
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'accounts';

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'system_purpose',
        'description',
        'is_active',
        'is_system',
        'balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'system_purpose' => SystemAccountPurpose::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_system' => false,
        'balance' => '0.00',
    ];

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

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    /**
     * Check if account is a system account (cannot be modified)
     */
    public function isSystemAccount(): bool
    {
        return $this->is_system;
    }

    /**
     * Check if account is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if this account type increases with debits
     */
    public function increasesWithDebit(): bool
    {
        return $this->type->increasesWithDebit();
    }

    /**
     * Check if this account type increases with credits
     */
    public function increasesWithCredit(): bool
    {
        return $this->type->increasesWithCredit();
    }

    /**
     * Get the normal balance direction
     */
    public function getNormalBalance(): string
    {
        return $this->type->getNormalBalance();
    }

    /**
     * Scope to filter accounts by tenant
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter active accounts
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter accounts by type
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, AccountType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter accounts by company
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter accounts by system purpose
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithPurpose(Builder $query, SystemAccountPurpose $purpose): Builder
    {
        return $query->where('system_purpose', $purpose->value);
    }

    /**
     * Find an account by its system purpose for a given company.
     *
     * This is the preferred way to look up system accounts instead of
     * hardcoding account codes which vary by country.
     */
    public static function findByPurpose(string $companyId, SystemAccountPurpose $purpose): ?self
    {
        return static::forCompany($companyId)
            ->withPurpose($purpose)
            ->first();
    }

    /**
     * Find an account by its system purpose or throw an exception.
     *
     * Use this in GL operations where the account MUST exist.
     *
     * @throws RuntimeException When no account with the given purpose exists
     */
    public static function findByPurposeOrFail(string $companyId, SystemAccountPurpose $purpose): self
    {
        $account = static::findByPurpose($companyId, $purpose);

        if ($account === null) {
            throw new RuntimeException(
                "Missing GL account: no account with system purpose '{$purpose->value}' ({$purpose->label()}) exists for this company. ".
                'This account is required to process transactions. '.
                "Go to Settings → Chart of Accounts and assign the '{$purpose->label()}' purpose to the appropriate account, ".
                'or contact support if the chart of accounts was not properly initialized during registration.'
            );
        }

        return $account;
    }
}

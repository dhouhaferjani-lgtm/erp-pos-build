<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use Database\Factories\PaymentRepositoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Payment repository for storing cash, checks, and other instruments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property RepositoryType $type
 * @property bool $allow_negative Whether an outflow may take this repository's balance below zero (W-5b Option B). Type-derived at creation when not explicitly set: bank_account defaults true, every other type defaults false (the column default). Enforced by TreasuryMovementService::record().
 * @property string|null $bank_id
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $iban
 * @property string|null $bic
 * @property numeric-string $balance Cached repository balance; port-managed (Task 22), NOT fillable — only TreasuryMovementService may write it (a pgsql trigger forbids direct writes).
 * @property Carbon|null $last_reconciled_at
 * @property numeric-string|null $last_reconciled_balance
 * @property string|null $location_id
 * @property string|null $responsible_user_id
 * @property string|null $account_id
 * @property string|null $gl_account_id
 * @property bool $is_active
 * @property string $currency ISO 4217 currency code (char(3)); port-managed, not fillable.
 * @property Carbon|null $frozen_at
 * @property string|null $frozen_reason
 * @property int $next_movement_ordinal Monotonic per-repository ordinal for spine movements; port-managed, not fillable.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Account|null $glAccount
 * @property-read User|null $responsibleUser
 * @property-read Location|null $location
 * @property-read Bank|null $bank
 */
class PaymentRepository extends Model
{
    /** @use HasFactory<PaymentRepositoryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_repositories';

    /**
     * A new repository always opens at a zero balance — money enters ONLY via
     * the movement port thereafter. `balance` is port-managed and NOT fillable
     * (Task 22), so a plain create() cannot set it; this model-level default
     * gives every fresh instance an in-memory '0' (and persists 0 on the INSERT,
     * which the direct-balance-write trigger permits — it guards UPDATEs only).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'balance' => 0,
    ];

    protected static function newFactory(): PaymentRepositoryFactory
    {
        return PaymentRepositoryFactory::new();
    }

    protected static function booted(): void
    {
        // `currency` is port-managed (not fillable) and NOT NULL (spine columns
        // migration, MED-11), so a plain create() — e.g. PaymentRepositoryController
        // ::store() — never sets it and would insert null. Default it here from the
        // owning company (single-currency today) so EVERY repository satisfies the
        // movement port's currency invariant, mirroring the factory and the
        // migration backfill. `next_movement_ordinal` already defaults to 0 at the
        // DB level. This is set only on creation; the port owns it thereafter.
        static::creating(function (PaymentRepository $repository): void {
            // Read via getAttribute (mixed) — the @property PHPDoc types these as
            // always-string (their post-creation truth), but at creation currency is
            // not yet set and may be null.
            $currency = $repository->getAttribute('currency');
            $companyId = $repository->getAttribute('company_id');
            if ($currency === null && is_string($companyId)) {
                $company = Company::query()->find($companyId);
                if (! $company instanceof Company) {
                    // Fail LOUDLY rather than silently minting a TND repository: the
                    // movement port's currency invariant demands the repository's real
                    // owning-company currency, and a wrong-currency default would corrupt
                    // every downstream movement. An unresolvable company is a caller bug.
                    throw new \DomainException(
                        "Cannot default payment repository currency: company {$companyId} could not be resolved."
                    );
                }
                $repository->currency = $company->currency;
            }

            // W-5b Option B: allow_negative defaults from RepositoryType — a
            // bank account may run an authorised overdraft (allow_negative =
            // true); every other type (cash_register/safe/virtual) is a till
            // that cannot hold negative cash and is left to the column
            // default (false). Only derive when the caller has not already
            // supplied an explicit value — mirrors the currency default
            // above, never overriding an explicit choice.
            if ($repository->getAttribute('allow_negative') === null) {
                $type = $repository->getAttribute('type');
                $typeEnum = $type instanceof RepositoryType
                    ? $type
                    : (is_string($type) ? RepositoryType::tryFrom($type) : null);
                if ($typeEnum === RepositoryType::BankAccount) {
                    $repository->allow_negative = true;
                }
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'type',
        'allow_negative',
        'bank_id',
        'bank_name',
        'account_number',
        'iban',
        'bic',
        'last_reconciled_at',
        'last_reconciled_balance',
        'location_id',
        'responsible_user_id',
        'account_id',
        'gl_account_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RepositoryType::class,
            'allow_negative' => 'boolean',
            'balance' => 'decimal:3',
            'last_reconciled_balance' => 'decimal:3',
            'last_reconciled_at' => 'datetime',
            'is_active' => 'boolean',
            'frozen_at' => 'immutable_datetime',
            'next_movement_ordinal' => 'integer',
        ];
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

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Scope to filter by tenant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter active repositories only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, RepositoryType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use Database\Factories\PaymentRepositoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment repository for storing cash, checks, and other instruments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property RepositoryType $type
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $iban
 * @property string|null $bic
 * @property numeric-string $balance
 * @property \Illuminate\Support\Carbon|null $last_reconciled_at
 * @property numeric-string|null $last_reconciled_balance
 * @property string|null $location_id
 * @property string|null $responsible_user_id
 * @property string|null $account_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read User|null $responsibleUser
 */
class PaymentRepository extends Model
{
    /** @use HasFactory<PaymentRepositoryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_repositories';

    protected static function newFactory(): PaymentRepositoryFactory
    {
        return PaymentRepositoryFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'type',
        'bank_name',
        'account_number',
        'iban',
        'bic',
        'balance',
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
            'balance' => 'decimal:3',
            'last_reconciled_balance' => 'decimal:3',
            'last_reconciled_at' => 'datetime',
            'is_active' => 'boolean',
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

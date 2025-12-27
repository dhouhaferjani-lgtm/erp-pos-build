<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Expense category for organizing business expenses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property string|null $description
 * @property string|null $parent_id
 * @property string|null $account_id
 * @property int $sort_order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read ExpenseCategory|null $parent
 * @property-read Collection<int, ExpenseCategory> $children
 * @property-read Account|null $account
 */
class ExpenseCategory extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'expense_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'description',
        'parent_id',
        'account_id',
        'sort_order',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    /**
     * @return HasMany<ExpenseCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Scope a query to only include active categories.
     *
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include root categories (no parent).
     *
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope a query to only include categories for a specific company.
     *
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope a query to only include categories for a specific tenant.
     *
     * @param  Builder<ExpenseCategory>  $query
     * @return Builder<ExpenseCategory>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}

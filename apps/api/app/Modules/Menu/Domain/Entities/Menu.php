<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Entities;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_active
 * @property string|null $active_from
 * @property string|null $active_until
 * @property string|null $start_date
 * @property string|null $end_date
 * @property array<int>|null $available_days
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Collection<int, MenuCategory> $categories
 */
class Menu extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'description',
        'is_default',
        'is_active',
        'active_from',
        'active_until',
        'start_date',
        'end_date',
        'available_days',
        'display_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'display_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'available_days' => 'array',
            'display_order' => 'integer',
        ];
    }

    // -- Relations --

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
     * @return HasMany<MenuCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class)->orderBy('display_order');
    }

    // -- Scopes --

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeNonDefault(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }
}

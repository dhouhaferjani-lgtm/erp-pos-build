<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\SelectionType;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property SelectionType $selection_type
 * @property int $min_selections
 * @property int $max_selections
 * @property bool $is_required
 * @property bool $is_active
 * @property int $display_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Modifier> $modifiers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CompositeItem> $compositeItems
 */
class ModifierGroup extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_required' => false,
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'selection_type',
        'min_selections',
        'max_selections',
        'is_required',
        'is_active',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection_type' => SelectionType::class,
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
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
     * @return HasMany<Modifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('display_order');
    }

    /**
     * @return BelongsToMany<CompositeItem, $this>
     */
    public function compositeItems(): BelongsToMany
    {
        return $this->belongsToMany(CompositeItem::class, 'composite_item_modifier_groups')
            ->withPivot('display_order')
            ->withTimestamps();
    }

    // -- Scopes --

    /**
     * @param  Builder<ModifierGroup>  $query
     * @return Builder<ModifierGroup>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ModifierGroup>  $query
     * @return Builder<ModifierGroup>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<ModifierGroup>  $query
     * @return Builder<ModifierGroup>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}

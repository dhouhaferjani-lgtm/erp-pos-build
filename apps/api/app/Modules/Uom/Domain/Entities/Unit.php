<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Entities;

use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $category_id
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property string $conversion_factor
 * @property int $decimal_places
 * @property RoundingMethod $rounding_method
 * @property bool $is_base_unit
 * @property bool $is_system
 * @property bool $is_active
 * @property-read UnitCategory $category
 */
class Unit extends Model
{
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'category_id',
        'code',
        'name',
        'symbol',
        'conversion_factor',
        'decimal_places',
        'rounding_method',
        'is_base_unit',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'string',
            'decimal_places' => 'integer',
            'rounding_method' => RoundingMethod::class,
            'is_base_unit' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UnitCategory::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory(Builder $query, string $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $modifier_group_id
 * @property string $code
 * @property string $name
 * @property string $price_adjustment
 * @property ComponentType|null $component_type
 * @property string|null $component_id
 * @property string|null $component_quantity
 * @property string|null $component_unit_id
 * @property bool $is_default
 * @property bool $is_active
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ModifierGroup $group
 * @property-read Unit|null $componentUnit
 */
class Modifier extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'price_adjustment' => '0.0000',
        'is_default' => false,
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'modifier_group_id',
        'code',
        'name',
        'price_adjustment',
        'component_type',
        'component_id',
        'component_quantity',
        'component_unit_id',
        'is_default',
        'is_active',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function componentUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'component_unit_id');
    }

    // -- Domain Methods --

    public function hasInventoryImpact(): bool
    {
        return $this->component_type !== null && $this->component_id !== null;
    }
}

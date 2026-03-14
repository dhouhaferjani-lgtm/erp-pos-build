<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $recipe_id
 * @property ComponentType $component_type
 * @property string $component_id
 * @property string $quantity
 * @property string|null $unit_id
 * @property bool $is_optional
 * @property bool $is_scalable
 * @property string $wastage_percent
 * @property string|null $unit_cost
 * @property string|null $line_cost
 * @property int $display_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Recipe $recipe
 * @property-read \App\Modules\Product\Domain\Product|null $product
 * @property-read CompositeItem|null $compositeItemComponent
 * @property-read \App\Modules\Product\Domain\Product|CompositeItem|null $component
 * @property-read Unit|null $unit
 */
class RecipeLine extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'component_type' => 'product',
        'is_optional' => false,
        'is_scalable' => true,
        'wastage_percent' => '0.00',
        'display_order' => 0,
    ];

    protected $fillable = [
        'recipe_id',
        'component_type',
        'component_id',
        'quantity',
        'unit_id',
        'is_optional',
        'is_scalable',
        'wastage_percent',
        'unit_cost',
        'line_cost',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'is_optional' => 'boolean',
            'is_scalable' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<\App\Modules\Product\Domain\Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Domain\Product::class, 'component_id');
    }

    /**
     * @return BelongsTo<CompositeItem, $this>
     */
    public function compositeItemComponent(): BelongsTo
    {
        return $this->belongsTo(CompositeItem::class, 'component_id');
    }

    /**
     * Resolves the component based on component_type.
     */
    public function getComponentAttribute(): \App\Modules\Product\Domain\Product|CompositeItem|null
    {
        if ($this->component_type === ComponentType::CompositeItem) {
            return $this->compositeItemComponent;
        }

        return $this->product;
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}

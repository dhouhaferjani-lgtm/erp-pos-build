<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Uom\Domain\Entities\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $composite_item_id
 * @property int $version
 * @property string|null $version_name
 * @property bool $is_active
 * @property string $yield_quantity
 * @property string|null $yield_unit_id
 * @property string|null $calculated_cost
 * @property int|null $prep_time_minutes
 * @property int|null $cook_time_minutes
 * @property int|null $total_time_minutes
 * @property string|null $instructions
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CompositeItem $compositeItem
 * @property-read Unit|null $yieldUnit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeLine> $lines
 */
class Recipe extends Model
{
    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
        'yield_quantity' => '1.0000',
    ];

    protected $fillable = [
        'composite_item_id',
        'version',
        'version_name',
        'is_active',
        'yield_quantity',
        'yield_unit_id',
        'calculated_cost',
        'prep_time_minutes',
        'cook_time_minutes',
        'instructions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'prep_time_minutes' => 'integer',
            'cook_time_minutes' => 'integer',
            'total_time_minutes' => 'integer',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<CompositeItem, $this>
     */
    public function compositeItem(): BelongsTo
    {
        return $this->belongsTo(CompositeItem::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function yieldUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'yield_unit_id');
    }

    /**
     * @return HasMany<RecipeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class)->orderBy('display_order');
    }

    // -- Scopes --

    /**
     * @param  Builder<Recipe>  $query
     * @return Builder<Recipe>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // -- Domain Methods --

    public function getTotalTime(): int
    {
        return ($this->prep_time_minutes ?? 0) + ($this->cook_time_minutes ?? 0);
    }
}

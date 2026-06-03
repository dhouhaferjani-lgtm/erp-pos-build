<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Models\User;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $counting_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property string $location_id
 * @property numeric-string $theoretical_qty
 * @property numeric-string|null $count_1_qty
 * @property Carbon|null $count_1_at
 * @property string|null $count_1_notes
 * @property numeric-string|null $count_2_qty
 * @property Carbon|null $count_2_at
 * @property string|null $count_2_notes
 * @property numeric-string|null $count_3_qty
 * @property Carbon|null $count_3_at
 * @property string|null $count_3_notes
 * @property numeric-string|null $final_qty
 * @property ItemResolutionMethod $resolution_method
 * @property string|null $resolution_notes
 * @property string|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property bool $is_flagged
 * @property string|null $flag_reason
 * @property bool $is_unexpected_item
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read InventoryCounting $counting
 * @property-read Product $product
 * @property-read Location $location
 * @property-read User|null $resolvedBy
 */
class InventoryCountingItem extends Model
{
    use HasUuids;

    protected $table = 'inventory_counting_items';

    protected $fillable = [
        'counting_id',
        'product_id',
        'variant_id',
        'location_id',
        'theoretical_qty',
        'count_1_qty',
        'count_1_at',
        'count_1_notes',
        'count_2_qty',
        'count_2_at',
        'count_2_notes',
        'count_3_qty',
        'count_3_at',
        'count_3_notes',
        'final_qty',
        'resolution_method',
        'resolution_notes',
        'resolved_by_user_id',
        'resolved_at',
        'is_flagged',
        'flag_reason',
        'is_unexpected_item',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolution_method' => ItemResolutionMethod::class,
            'theoretical_qty' => 'decimal:4',
            'count_1_qty' => 'decimal:4',
            'count_2_qty' => 'decimal:4',
            'count_3_qty' => 'decimal:4',
            'final_qty' => 'decimal:4',
            'count_1_at' => 'datetime',
            'count_2_at' => 'datetime',
            'count_3_at' => 'datetime',
            'resolved_at' => 'datetime',
            'is_flagged' => 'boolean',
            'is_unexpected_item' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<InventoryCounting, $this>
     */
    public function counting(): BelongsTo
    {
        return $this->belongsTo(InventoryCounting::class, 'counting_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Scope to filter flagged items.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFlagged(Builder $query): Builder
    {
        return $query->where('is_flagged', true);
    }

    /**
     * Scope to filter items needing resolution.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsResolution(Builder $query): Builder
    {
        return $query->where('resolution_method', ItemResolutionMethod::Pending);
    }

    /**
     * Scope to filter items needing third count.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsThirdCount(Builder $query): Builder
    {
        return $query->whereNotNull('count_1_qty')
            ->whereNotNull('count_2_qty')
            ->whereNull('count_3_qty')
            ->whereRaw('count_1_qty != count_2_qty');
    }

    /**
     * Get the variance between final and theoretical quantity.
     */
    public function getVariance(): ?float
    {
        if ($this->final_qty === null) {
            return null;
        }

        return (float) $this->final_qty - (float) $this->theoretical_qty;
    }

    /**
     * Get the variance percentage.
     */
    public function getVariancePercentage(): ?float
    {
        if ($this->final_qty === null || (float) $this->theoretical_qty === 0.0) {
            return null;
        }

        $variance = $this->getVariance();
        if ($variance === null) {
            return null;
        }

        return round(($variance / (float) $this->theoretical_qty) * 100, 2);
    }

    /**
     * Check if item has count for a specific phase.
     */
    public function hasCountForPhase(int $phase): bool
    {
        $column = "count_{$phase}_qty";

        return $this->$column !== null;
    }

    /**
     * Submit a count for this item.
     */
    /**
     * @param  numeric-string  $quantity  Canonical numeric string (quantity scale 4, e.g. '1.2345')
     */
    public function submitCount(int $phase, string $quantity, ?string $notes = null): void
    {
        $qtyColumn = "count_{$phase}_qty";
        $atColumn = "count_{$phase}_at";
        $notesColumn = "count_{$phase}_notes";

        $this->$qtyColumn = $quantity;
        $this->$atColumn = now();
        $this->$notesColumn = $notes;
        $this->save();
    }

    /**
     * Check if counters agree on quantity.
     */
    public function countersAgree(): bool
    {
        if ($this->count_1_qty === null || $this->count_2_qty === null) {
            return false;
        }

        return $this->floatsEqual((float) $this->count_1_qty, (float) $this->count_2_qty);
    }

    /**
     * Check if all counts match theoretical.
     */
    public function allCountsMatchTheoretical(): bool
    {
        if ($this->count_1_qty === null) {
            return false;
        }

        $theoretical = (float) $this->theoretical_qty;

        if (! $this->floatsEqual((float) $this->count_1_qty, $theoretical)) {
            return false;
        }

        if ($this->count_2_qty !== null && ! $this->floatsEqual((float) $this->count_2_qty, $theoretical)) {
            return false;
        }

        if ($this->count_3_qty !== null && ! $this->floatsEqual((float) $this->count_3_qty, $theoretical)) {
            return false;
        }

        return true;
    }

    /**
     * Compare floats with epsilon tolerance.
     */
    private function floatsEqual(float $a, float $b, float $epsilon = 0.0001): bool
    {
        return abs($a - $b) < $epsilon;
    }
}

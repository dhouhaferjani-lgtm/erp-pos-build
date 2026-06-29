<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string|null $period
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ParapharmacyProductMetadata> $products
 * @property-read ProductRoutinePivot $pivot
 */
class Routine extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'period',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Products included in this routine.
     *
     * @return BelongsToMany<ParapharmacyProductMetadata, $this, ProductRoutinePivot>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            ParapharmacyProductMetadata::class,
            'product_routine',
            'routine_id',
            'product_id'
        )
            ->using(ProductRoutinePivot::class)
            ->withPivot(['step_order', 'step_label'])
            ->orderByPivot('step_order');
    }
}

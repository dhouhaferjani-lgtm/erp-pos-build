<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Product\Domain\Enums\EquivalenceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $equivalent_product_id
 * @property EquivalenceType $equivalence_type
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductEquivalent extends Pivot
{
    use HasUuids;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var string
     */
    protected $table = 'product_equivalents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'product_id',
        'equivalent_product_id',
        'equivalence_type',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'equivalence_type' => EquivalenceType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->product_id === $model->equivalent_product_id) {
                throw new \InvalidArgumentException('A product cannot be its own equivalent.');
            }
        });
    }
}

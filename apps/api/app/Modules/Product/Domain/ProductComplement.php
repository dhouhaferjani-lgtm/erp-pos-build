<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $complement_product_id
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductComplement extends Pivot
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
    protected $table = 'product_complements';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'product_id',
        'complement_product_id',
        'reason',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->product_id === $model->complement_product_id) {
                throw new \InvalidArgumentException('A product cannot be its own complement.');
            }
        });
    }
}

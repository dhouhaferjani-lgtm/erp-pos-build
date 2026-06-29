<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Shared\Domain\Enums\SkinType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property SkinType $skin_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
class ProductSkinSuitability extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'product_skin_suitability';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'product_id',
        'skin_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skin_type' => SkinType::class,
        ];
    }

    /**
     * Get the product that owns this skin suitability entry.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

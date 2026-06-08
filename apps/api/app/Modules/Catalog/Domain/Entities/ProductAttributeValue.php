<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use Database\Factories\Catalog\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $attribute_id
 * @property string $code
 * @property string $label
 * @property string|null $hex_color
 * @property string|null $image_url
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'product_attribute_values';

    protected $fillable = [
        'tenant_id',
        'attribute_id',
        'code',
        'label',
        'hex_color',
        'image_url',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    protected static function newFactory(): ProductAttributeValueFactory
    {
        return ProductAttributeValueFactory::new();
    }
}

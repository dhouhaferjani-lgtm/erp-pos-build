<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use Database\Factories\Catalog\ProductVariantAttributeValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $variant_id
 * @property string $attribute_id
 * @property string $attribute_value_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant $variant
 * @property-read ProductAttribute $attribute
 * @property-read ProductAttributeValue $attributeValue
 */
class ProductVariantAttributeValue extends Model
{
    /** @use HasFactory<ProductVariantAttributeValueFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'product_variant_attribute_values';

    protected $fillable = [
        'variant_id',
        'attribute_id',
        'attribute_value_id',
    ];

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    /** @return BelongsTo<ProductAttributeValue, $this> */
    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeValue::class, 'attribute_value_id');
    }

    protected static function newFactory(): ProductVariantAttributeValueFactory
    {
        return ProductVariantAttributeValueFactory::new();
    }
}

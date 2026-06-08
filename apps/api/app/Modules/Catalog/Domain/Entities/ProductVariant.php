<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string $variant_code
 * @property string $sku
 * @property string|null $barcode
 * @property string $name_suffix
 * @property bool $is_default
 * @property bool $is_active
 * @property int $display_order
 * @property string|null $price_override Decimal string — do NOT cast to float
 * @property string|null $cost_override Decimal string — do NOT cast to float
 * @property string|null $image_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Product $product
 * @property-read Company $company
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'product_variants';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'variant_code',
        'sku',
        'barcode',
        'name_suffix',
        'is_default',
        'is_active',
        'display_order',
        'price_override',
        'cost_override',
        'image_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
            // price_override and cost_override intentionally left uncast —
            // monetary decimal strings must not be converted to float (precision rule).
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}

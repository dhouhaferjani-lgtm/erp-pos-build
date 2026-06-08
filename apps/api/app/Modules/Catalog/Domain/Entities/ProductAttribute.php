<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Database\Factories\Catalog\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property AttributeDataType $data_type
 * @property bool $is_variant_axis
 * @property int $display_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class ProductAttribute extends Model
{
    /** @use HasFactory<ProductAttributeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'product_attributes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_variant_axis' => false,
        'display_order' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'data_type',
        'is_variant_axis',
        'display_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_type' => AttributeDataType::class,
            'is_variant_axis' => 'boolean',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ProductAttributeFactory
    {
        return ProductAttributeFactory::new();
    }
}

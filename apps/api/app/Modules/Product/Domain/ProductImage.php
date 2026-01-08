<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $filename
 * @property string $original_filename
 * @property string $storage_path
 * @property string $storage_disk
 * @property string $mime_type
 * @property int $file_size
 * @property int|null $width
 * @property int|null $height
 * @property int $sort_order
 * @property bool $is_primary
 * @property string|null $thumbnail_path
 * @property string|null $uploaded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Product $product
 * @property-read Tenant $tenant
 * @property-read User|null $uploader
 */
class ProductImage extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'filename',
        'original_filename',
        'storage_path',
        'storage_disk',
        'mime_type',
        'file_size',
        'width',
        'height',
        'sort_order',
        'is_primary',
        'thumbnail_path',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Scope a query to only include images for a specific product.
     *
     * @param  Builder<ProductImage>  $query
     * @return Builder<ProductImage>
     */
    public function scopeForProduct(Builder $query, string $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope a query to order images by sort_order then created_at.
     *
     * @param  Builder<ProductImage>  $query
     * @return Builder<ProductImage>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Scope a query to only include primary images.
     *
     * @param  Builder<ProductImage>  $query
     * @return Builder<ProductImage>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Check if this is an image file (vs other file types in the future).
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if this image can be accessed publicly based on product e-commerce status.
     */
    public function canBeAccessedPublicly(): bool
    {
        return $this->product->is_active_for_ecommerce ?? false;
    }
}

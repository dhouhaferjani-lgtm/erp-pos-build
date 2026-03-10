<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Product\Domain\Enums\AutomotiveArticleStatus;
use App\Modules\Product\Domain\Enums\BrandQualityTier;
use App\Modules\Product\Domain\Enums\PlatformLinkStatus;
use Database\Factories\AutomotiveProductMetadataFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $product_id
 * @property string|null $platform_article_id
 * @property PlatformLinkStatus $platform_link_status
 * @property string|null $article_number
 * @property string|null $supplier_brand
 * @property string|null $product_group_name
 * @property BrandQualityTier|null $brand_quality_tier
 * @property AutomotiveArticleStatus $article_status
 * @property int $confidence_score
 * @property string $data_source
 * @property string|null $weight_kg
 * @property array<string, mixed>|null $dimensions
 * @property string|null $superseded_by_product_id
 * @property bool $is_universal_fit
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $platform_synced_at
 * @property int|null $tire_width
 * @property int|null $tire_aspect_ratio
 * @property int|null $tire_rim_diameter
 * @property string|null $tire_speed_rating
 * @property int|null $tire_load_index
 * @property string|null $tire_season
 * @property string|null $glass_type
 * @property string|null $glass_tinting
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Product $product
 * @property-read Product|null $supersededByProduct
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AutomotiveProductCrossReference> $crossReferences
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AutomotiveProductVehicle> $vehicles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AutomotiveProductCriterion> $criteria
 */
class AutomotiveProductMetadata extends Model
{
    /** @use HasFactory<AutomotiveProductMetadataFactory> */
    use HasFactory;

    use HasUuids;

    protected static function newFactory(): AutomotiveProductMetadataFactory
    {
        return AutomotiveProductMetadataFactory::new();
    }

    /**
     * @var string
     */
    protected $table = 'automotive_product_metadata';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'platform_article_id',
        'platform_link_status',
        'article_number',
        'supplier_brand',
        'product_group_name',
        'brand_quality_tier',
        'article_status',
        'confidence_score',
        'data_source',
        'weight_kg',
        'dimensions',
        'superseded_by_product_id',
        'is_universal_fit',
        'notes',
        'platform_synced_at',
        'tire_width',
        'tire_aspect_ratio',
        'tire_rim_diameter',
        'tire_speed_rating',
        'tire_load_index',
        'tire_season',
        'glass_type',
        'glass_tinting',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'platform_link_status' => 'unlinked',
        'article_status' => 'active',
        'confidence_score' => 0,
        'data_source' => 'manual',
        'is_universal_fit' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform_link_status' => PlatformLinkStatus::class,
            'brand_quality_tier' => BrandQualityTier::class,
            'article_status' => AutomotiveArticleStatus::class,
            'confidence_score' => 'integer',
            'dimensions' => 'array',
            'is_universal_fit' => 'boolean',
            'platform_synced_at' => 'datetime',
            'tire_width' => 'integer',
            'tire_aspect_ratio' => 'integer',
            'tire_rim_diameter' => 'integer',
            'tire_load_index' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function supersededByProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'superseded_by_product_id');
    }

    /**
     * @return HasMany<AutomotiveProductCrossReference, $this>
     */
    public function crossReferences(): HasMany
    {
        return $this->hasMany(AutomotiveProductCrossReference::class, 'automotive_metadata_id');
    }

    /**
     * @return HasMany<AutomotiveProductVehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(AutomotiveProductVehicle::class, 'automotive_metadata_id');
    }

    /**
     * @return HasMany<AutomotiveProductCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(AutomotiveProductCriterion::class, 'automotive_metadata_id')
            ->orderBy('sort_order');
    }
}

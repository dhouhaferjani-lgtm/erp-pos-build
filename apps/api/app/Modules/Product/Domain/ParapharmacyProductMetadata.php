<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use Database\Factories\ParapharmacyProductMetadataFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $product_id
 * @property ParapharmacyCategory $category
 * @property DosageForm|null $dosage_form
 * @property string|null $usage_instructions
 * @property string|null $warnings
 * @property string|null $contraindications
 * @property int|null $minimum_age
 * @property AgeRestriction|null $age_restriction
 * @property bool $requires_consultation
 * @property string|null $regulatory_code
 * @property string|null $storage_requirements
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read Collection<int, Ingredient> $ingredients
 * @property-read Collection<int, Certification> $certifications
 * @property-read Collection<int, HealthClaim> $healthClaims
 * @property-read Collection<int, KeyComponent> $keyComponents
 * @property-read Collection<int, ProductSkinSuitability> $skinSuitabilities
 */
class ParapharmacyProductMetadata extends Model
{
    /** @use HasFactory<ParapharmacyProductMetadataFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ParapharmacyProductMetadataFactory
    {
        return ParapharmacyProductMetadataFactory::new();
    }

    /**
     * @var string
     */
    protected $table = 'parapharmacy_product_metadata';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'category',
        'dosage_form',
        'usage_instructions',
        'warnings',
        'contraindications',
        'minimum_age',
        'age_restriction',
        'requires_consultation',
        'regulatory_code',
        'storage_requirements',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'requires_consultation' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ParapharmacyCategory::class,
            'dosage_form' => DosageForm::class,
            'age_restriction' => AgeRestriction::class,
            'requires_consultation' => 'boolean',
            'minimum_age' => 'integer',
        ];
    }

    /**
     * Get the product that owns this metadata.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get all ingredients for this product.
     *
     * @return BelongsToMany<Ingredient, $this>
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(
            Ingredient::class,
            'product_ingredient',
            'product_id',
            'ingredient_id',
            'product_id'
        )
            ->withPivot(['concentration', 'concentration_numeric', 'concentration_unit', 'order', 'notes'])
            ->withTimestamps()
            ->orderByPivot('order');
    }

    /**
     * Get all certifications for this product.
     *
     * @return BelongsToMany<Certification, $this>
     */
    public function certifications(): BelongsToMany
    {
        return $this->belongsToMany(
            Certification::class,
            'certification_product',
            'product_id',
            'certification_id',
            'product_id'
        )
            ->withPivot(['certification_code', 'issued_date', 'expiry_date', 'verification_url', 'notes'])
            ->withTimestamps();
    }

    /**
     * Get all health claims for this product.
     *
     * @return BelongsToMany<HealthClaim, $this>
     */
    public function healthClaims(): BelongsToMany
    {
        return $this->belongsToMany(
            HealthClaim::class,
            'health_claim_product',
            'product_id',
            'health_claim_id',
            'product_id'
        )
            ->withPivot(['display_order'])
            ->withTimestamps()
            ->orderByPivot('display_order');
    }

    /**
     * Get all key components for this product.
     *
     * @return BelongsToMany<KeyComponent, $this>
     */
    public function keyComponents(): BelongsToMany
    {
        return $this->belongsToMany(
            KeyComponent::class,
            'key_component_product',
            'product_id',
            'component_id',
            'product_id'
        )
            ->withPivot(['order'])
            ->withTimestamps()
            ->orderByPivot('order');
    }

    /**
     * Get all skin suitability entries for this product.
     *
     * @return HasMany<ProductSkinSuitability, $this>
     */
    public function skinSuitabilities(): HasMany
    {
        return $this->hasMany(ProductSkinSuitability::class, 'product_id', 'product_id');
    }
}

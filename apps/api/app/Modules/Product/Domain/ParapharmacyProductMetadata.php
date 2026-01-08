<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use Database\Factories\ParapharmacyProductMetadataFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $product_id
 * @property ParapharmacyCategory $category
 * @property DosageForm|null $dosage_form
 * @property array<int, array{name: string, concentration?: string}>|null $active_ingredients
 * @property array<int, string>|null $key_components
 * @property string|null $usage_instructions
 * @property string|null $warnings
 * @property string|null $contraindications
 * @property int|null $minimum_age
 * @property AgeRestriction|null $age_restriction
 * @property bool $requires_consultation
 * @property string|null $regulatory_code
 * @property array<int, string>|null $health_claims
 * @property array<int, array{type: string, code: string}>|null $certifications
 * @property string|null $storage_requirements
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Product $product
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
        'active_ingredients',
        'key_components',
        'usage_instructions',
        'warnings',
        'contraindications',
        'minimum_age',
        'age_restriction',
        'requires_consultation',
        'regulatory_code',
        'health_claims',
        'certifications',
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
            'active_ingredients' => 'array',
            'key_components' => 'array',
            'health_claims' => 'array',
            'certifications' => 'array',
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
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Shared\Domain\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $claim_type
 * @property string $slug
 * @property string $regulatory_status
 * @property string|null $efsa_reference
 * @property string|null $fda_reference
 * @property array|null $country_restrictions
 * @property bool $requires_disclaimer
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $claim (from translations - accessed via translate() method)
 * @property string|null $disclaimer_text (from translations - accessed via translate() method)
 */
class HealthClaim extends Model
{
    use HasTranslations;
    use HasUuids;

    protected $fillable = [
        'claim_type',
        'slug',
        'regulatory_status',
        'efsa_reference',
        'fda_reference',
        'country_restrictions',
        'requires_disclaimer',
    ];

    protected function casts(): array
    {
        return [
            'country_restrictions' => 'array',
            'requires_disclaimer' => 'boolean',
        ];
    }

    /**
     * Get all translations for this health claim.
     *
     * @return HasMany<HealthClaimTranslation>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(HealthClaimTranslation::class);
    }

    /**
     * Accessor for claim attribute (auto-translated).
     */
    public function getClaimAttribute(): ?string
    {
        return $this->getTranslatedAttribute('claim');
    }

    /**
     * Accessor for disclaimer_text attribute (auto-translated).
     */
    public function getDisclaimerTextAttribute(): ?string
    {
        return $this->getTranslatedAttribute('disclaimer_text');
    }

    /**
     * Products with this health claim.
     *
     * @return BelongsToMany<ParapharmacyProductMetadata>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            ParapharmacyProductMetadata::class,
            'health_claim_product',
            'health_claim_id',
            'product_id'
        )
            ->withPivot(['display_order'])
            ->withTimestamps()
            ->orderByPivot('display_order');
    }
}

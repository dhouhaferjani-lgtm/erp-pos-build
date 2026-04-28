<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Shared\Domain\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $type
 * @property string $slug
 * @property string|null $certifying_body
 * @property string|null $logo_url
 * @property string|null $verification_url
 * @property bool $is_active
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $name (from translations - auto-translated via HasTranslations trait)
 * @property string|null $description (from translations - auto-translated via HasTranslations trait)
 */
class Certification extends Model
{
    use HasTranslations;
    use HasUuids;

    protected $fillable = [
        'type',
        'slug',
        'certifying_body',
        'logo_url',
        'verification_url',
        'is_active',
        'display_order',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * Get all translations for this certification.
     *
     * @return HasMany<CertificationTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CertificationTranslation::class);
    }

    /**
     * Accessor for description attribute (auto-translated).
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->getTranslatedAttribute('description');
    }

    /**
     * Products with this certification.
     *
     * @return BelongsToMany<ParapharmacyProductMetadata, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            ParapharmacyProductMetadata::class,
            'certification_product',
            'certification_id',
            'product_id'
        )
            ->withPivot(['certification_code', 'issued_date', 'expiry_date', 'verification_url', 'notes'])
            ->withTimestamps();
    }
}

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
 * @property string $slug
 * @property bool $is_allergen
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $name (from translations - auto-translated via HasTranslations trait)
 * @property string|null $description (from translations - auto-translated via HasTranslations trait)
 */
class KeyComponent extends Model
{
    use HasTranslations;
    use HasUuids;

    protected $table = 'product_key_components';

    protected $fillable = [
        'slug',
        'is_allergen',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'is_allergen' => 'boolean',
        ];
    }

    /**
     * Get all translations for this key component.
     *
     * @return HasMany<KeyComponentTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(KeyComponentTranslation::class, 'component_id');
    }

    /**
     * Accessor for description attribute (auto-translated).
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->getTranslatedAttribute('description');
    }

    /**
     * Products using this key component.
     *
     * @return BelongsToMany<ParapharmacyProductMetadata, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            ParapharmacyProductMetadata::class,
            'key_component_product',
            'component_id',
            'product_id'
        )
            ->withPivot(['order'])
            ->withTimestamps()
            ->orderByPivot('order');
    }
}

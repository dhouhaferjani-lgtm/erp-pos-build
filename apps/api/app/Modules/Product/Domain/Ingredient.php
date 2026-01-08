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
 * @property string $slug
 * @property string|null $cas_number
 * @property bool $is_allergen
 * @property string|null $allergen_code
 * @property string|null $regulatory_status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $name (from translations - auto-translated via HasTranslations trait)
 * @property string|null $description (from translations - auto-translated via HasTranslations trait)
 */
class Ingredient extends Model
{
    use HasTranslations;
    use HasUuids;

    protected $fillable = [
        'slug',
        'cas_number',
        'is_allergen',
        'allergen_code',
        'regulatory_status',
        'notes',
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
     * Get all translations for this ingredient.
     *
     * @return HasMany<IngredientTranslation>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(IngredientTranslation::class);
    }

    /**
     * Accessor for description attribute (auto-translated).
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->getTranslatedAttribute('description');
    }

    /**
     * Products using this ingredient.
     *
     * @return BelongsToMany<ParapharmacyProductMetadata>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            ParapharmacyProductMetadata::class,
            'product_ingredient',
            'ingredient_id',
            'product_id'
        )
            ->withPivot(['concentration', 'concentration_numeric', 'concentration_unit', 'order', 'notes'])
            ->withTimestamps()
            ->orderByPivot('order');
    }
}

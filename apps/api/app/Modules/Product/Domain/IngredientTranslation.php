<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ingredient_id
 * @property string $locale
 * @property string $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class IngredientTranslation extends Model
{
    use HasUuids;

    protected $fillable = [
        'ingredient_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * Get the ingredient that owns this translation.
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $component_id
 * @property string $locale
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KeyComponentTranslation extends Model
{
    use HasUuids;

    protected $fillable = [
        'component_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * Get the key component that owns this translation.
     *
     * @return BelongsTo<KeyComponent, $this>
     */
    public function keyComponent(): BelongsTo
    {
        return $this->belongsTo(KeyComponent::class, 'component_id');
    }
}

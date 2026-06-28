<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $canonical_brand_id
 * @property string|null $logo_media_id
 * @property string|null $website_url
 * @property string|null $country_of_origin
 * @property string|null $description
 * @property bool $is_active
 */
final class Brand extends Model
{
    use HasUuids;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'canonical_brand_id',
        'logo_media_id',
        'website_url',
        'country_of_origin',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Deterministic, normalized slug for a brand name.
     * Used by the seeder (Task 13) and enrichment upsert (Task 5).
     */
    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}

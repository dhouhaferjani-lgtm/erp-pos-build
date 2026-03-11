<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Entities;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $menu_id
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property int $display_order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Menu $menu
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CompositeItem> $compositeItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
 */
class MenuCategory extends Model
{
    use HasUuids;

    protected $table = 'menu_categories';

    protected $fillable = [
        'menu_id',
        'name',
        'description',
        'icon',
        'display_order',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'display_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsToMany<CompositeItem, $this, MenuCategoryItem, 'pivot'>
     */
    public function compositeItems(): BelongsToMany
    {
        return $this->belongsToMany(CompositeItem::class, 'menu_category_items')
            ->using(MenuCategoryItem::class)
            ->withPivot(['id', 'override_price', 'display_order', 'is_available'])
            ->withTimestamps()
            ->orderByPivot('display_order');
    }

    /**
     * @return BelongsToMany<Product, $this, MenuCategoryItem, 'pivot'>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'menu_category_items')
            ->using(MenuCategoryItem::class)
            ->withPivot(['id', 'override_price', 'display_order', 'is_available'])
            ->withTimestamps()
            ->orderByPivot('display_order');
    }

    // -- Scopes --

    /**
     * @param  Builder<MenuCategory>  $query
     * @return Builder<MenuCategory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

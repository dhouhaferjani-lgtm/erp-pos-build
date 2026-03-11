<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $menu_category_id
 * @property string|null $composite_item_id
 * @property string|null $product_id
 * @property string|null $override_price
 * @property int $display_order
 * @property bool $is_available
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MenuCategoryItem extends Pivot
{
    use HasUuids;

    protected $table = 'menu_category_items';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'menu_category_id',
        'composite_item_id',
        'product_id',
        'override_price',
        'display_order',
        'is_available',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}

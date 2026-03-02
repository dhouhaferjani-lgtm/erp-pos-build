<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $menu_category_id
 * @property string $composite_item_id
 * @property string|null $override_price
 * @property int $display_order
 * @property bool $is_available
 */
class MenuCategoryItem extends Pivot
{
    use HasUuids;

    protected $table = 'menu_category_items';

    public $incrementing = false;

    protected $keyType = 'string';
}

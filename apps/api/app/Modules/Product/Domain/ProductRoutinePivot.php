<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $routine_id
 * @property string $product_id
 * @property int $step_order
 * @property string $step_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductRoutinePivot extends Pivot
{
    use HasUuids;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
        ];
    }
}

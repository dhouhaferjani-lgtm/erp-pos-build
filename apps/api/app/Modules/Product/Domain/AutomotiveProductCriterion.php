<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $automotive_metadata_id
 * @property string $criteria_key
 * @property string $criteria_label
 * @property string $value
 * @property string|null $unit
 * @property int $sort_order
 * @property string|null $platform_criteria_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read AutomotiveProductMetadata $metadata
 */
class AutomotiveProductCriterion extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'automotive_product_criteria';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'automotive_metadata_id',
        'criteria_key',
        'criteria_label',
        'value',
        'unit',
        'sort_order',
        'platform_criteria_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AutomotiveProductMetadata, $this>
     */
    public function metadata(): BelongsTo
    {
        return $this->belongsTo(AutomotiveProductMetadata::class, 'automotive_metadata_id');
    }
}

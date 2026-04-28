<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Product\Domain\Enums\CrossReferenceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $automotive_metadata_id
 * @property CrossReferenceType $reference_type
 * @property string $reference_number
 * @property string|null $manufacturer_name
 * @property string|null $platform_cross_ref_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AutomotiveProductMetadata $metadata
 */
class AutomotiveProductCrossReference extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'automotive_product_cross_references';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'automotive_metadata_id',
        'reference_type',
        'reference_number',
        'manufacturer_name',
        'platform_cross_ref_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_type' => CrossReferenceType::class,
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

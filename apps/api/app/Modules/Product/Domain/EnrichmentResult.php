<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\Enums\EnrichmentResultOrigin;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string $tracking_id
 * @property int $version
 * @property EnrichmentResultOrigin $origin
 * @property EnrichmentReviewStatus $status
 * @property EnrichedProductData $enriched_data
 * @property string $enrichment_quality
 * @property string|null $assigned_barcode
 * @property Carbon|null $reviewed_at
 * @property string|null $reviewed_by
 * @property array<string, bool>|null $accepted_fields
 * @property string|null $rejection_reason
 * @property string|null $rejection_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read User|null $reviewer
 */
final class EnrichmentResult extends Model
{
    use HasUuids;

    protected $table = 'enrichment_results';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'tracking_id',
        'version',
        'origin',
        'status',
        'enriched_data',
        'enrichment_quality',
        'assigned_barcode',
        'reviewed_at',
        'reviewed_by',
        'accepted_fields',
        'rejection_reason',
        'rejection_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'origin' => EnrichmentResultOrigin::class,
            'status' => EnrichmentReviewStatus::class,
            'enriched_data' => EnrichedProductData::class,
            'accepted_fields' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain;

use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property numeric-string|null $requested_qty
 * @property string|null $note
 * @property int $request_count
 * @property ReplenishmentStatus $status
 * @property ReplenishmentChannel $source_channel
 * @property string $requested_by_user_id
 * @property Carbon $first_requested_at
 * @property Carbon $last_requested_at
 * @property string|null $client_request_uuid
 * @property string|null $sourcing_document_id
 * @property ReplenishmentFulfillmentType|null $fulfillment_type
 * @property string|null $fulfillment_id
 * @property string|null $processed_by_user_id
 * @property Carbon|null $processed_at
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $location_name
 * @property string $product_name
 * @property string|null $variant_name
 */
final class ReplenishmentRequest extends Model
{
    use HasUuids;

    protected $table = 'replenishment_requests';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'product_id',
        'variant_id',
        'requested_qty',
        'note',
        'request_count',
        'status',
        'source_channel',
        'requested_by_user_id',
        'first_requested_at',
        'last_requested_at',
        'client_request_uuid',
        'sourcing_document_id',
        'fulfillment_type',
        'fulfillment_id',
        'processed_by_user_id',
        'processed_at',
        'rejection_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReplenishmentStatus::class,
            'source_channel' => ReplenishmentChannel::class,
            'fulfillment_type' => ReplenishmentFulfillmentType::class,
            'requested_qty' => 'decimal:4',
            'first_requested_at' => 'immutable_datetime',
            'last_requested_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<ReplenishmentRequest> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('replenishment_requests.status', [
            ReplenishmentStatus::Pending,
            ReplenishmentStatus::InProgress,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property numeric-string $freight_uncapitalized
 * @property string|null $closed_by_user_id
 * @property Carbon|null $closed_at
 * @property TransferCloseDisposition|null $close_disposition
 * @property TransferDiscrepancyReason|null $close_reason
 * @property string|null $close_note
 * @property-read User|null $closedBy
 * @property-read Collection<int, StockTransferReceipt> $receipts
 * @property string $tenant_id
 * @property string $company_id
 * @property string $transfer_number
 * @property TransferType $transfer_type
 * @property TransferStatus $status
 * @property string $source_location_id
 * @property string $destination_location_id
 * @property string|null $notes
 * @property numeric-string $transfer_cost
 * @property string|null $transfer_cost_label
 * @property TransferCostDistribution $transfer_cost_distribution
 * @property string|null $idempotency_key
 * @property string $initiated_by_user_id
 * @property string|null $completed_by_user_id
 * @property string|null $cancelled_by_user_id
 * @property Carbon|null $initiated_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $sourceLocation
 * @property-read Location $destinationLocation
 * @property-read User $initiatedBy
 * @property-read User|null $completedBy
 * @property-read User|null $cancelledBy
 * @property-read Collection<int, StockTransferLine> $lines
 */
class StockTransfer extends Model
{
    use HasUuids;

    protected $table = 'stock_transfers';

    protected $fillable = [
        'freight_uncapitalized',
        'closed_by_user_id',
        'closed_at',
        'close_disposition',
        'close_reason',
        'close_note',

        'tenant_id',
        'company_id',
        'transfer_number',
        'transfer_type',
        'status',
        'source_location_id',
        'destination_location_id',
        'notes',
        'transfer_cost',
        'transfer_cost_label',
        'transfer_cost_distribution',
        'idempotency_key',
        'initiated_by_user_id',
        'completed_by_user_id',
        'cancelled_by_user_id',
        'initiated_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'freight_uncapitalized' => 'decimal:4',
            'closed_at' => 'datetime',
            'close_disposition' => TransferCloseDisposition::class,
            'close_reason' => TransferDiscrepancyReason::class,

            'transfer_type' => TransferType::class,
            'status' => TransferStatus::class,
            'transfer_cost_distribution' => TransferCostDistribution::class,
            'transfer_cost' => 'decimal:4',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, TransferStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /** @var list<string> */
    public const array CARRYING_STATUSES = [TransferStatus::InTransit->value, TransferStatus::PartiallyReceived->value];

    /**
     * @param  Builder<StockTransfer>  $query
     * @return Builder<StockTransfer>
     */
    public function scopeCarryingInTransit(Builder $query): Builder
    {
        return $query->whereIn('stock_transfers.status', self::CARRYING_STATUSES);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @return HasMany<StockTransferReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'transfer_id')->orderBy('sequence');
    }
}

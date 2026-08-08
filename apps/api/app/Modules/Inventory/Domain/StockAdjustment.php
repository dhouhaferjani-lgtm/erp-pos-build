<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A manual stock-correction document (DPA V7).
 *
 * ONE document per (location, moment); `reason_code` lives on the LINE so a
 * mixed-direction reconciliation is representable (D6). Posting writes one
 * `stock_movements` row per line through StockAdjustmentService::adjustByDelta()
 * and back-links it on the line.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $adjustment_number
 * @property StockAdjustmentStatus $status
 * @property string|null $note
 * @property string $location_id
 * @property Carbon $occurred_at
 * @property string|null $idempotency_key
 * @property string $created_by_user_id
 * @property string|null $posted_by_user_id
 * @property string|null $cancelled_by_user_id
 * @property string|null $stale_acknowledged_by_user_id
 * @property string|null $reservations_ignored_by_user_id
 * @property Carbon|null $posted_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $stale_acknowledged_at
 * @property Carbon|null $reservations_ignored_at
 * @property string|null $cancellation_reason
 * @property string|null $corrects_adjustment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 * @property-read User $createdBy
 * @property-read User|null $postedBy
 * @property-read User|null $cancelledBy
 * @property-read StockAdjustment|null $correctsAdjustment
 * @property-read StockAdjustment|null $correction
 * @property-read Collection<int, StockAdjustmentLine> $lines
 */
class StockAdjustment extends Model
{
    use HasUuids;

    protected $table = 'stock_adjustments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'adjustment_number',
        'status',
        'note',
        'location_id',
        'occurred_at',
        'idempotency_key',
        'created_by_user_id',
        'posted_by_user_id',
        'cancelled_by_user_id',
        'stale_acknowledged_by_user_id',
        'reservations_ignored_by_user_id',
        'posted_at',
        'cancelled_at',
        'stale_acknowledged_at',
        'reservations_ignored_at',
        'cancellation_reason',
        'corrects_adjustment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockAdjustmentStatus::class,
            'occurred_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'stale_acknowledged_at' => 'datetime',
            'reservations_ignored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /** @return BelongsTo<StockAdjustment, $this> */
    public function correctsAdjustment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_adjustment_id');
    }

    /**
     * The INVERSE of corrects_adjustment_id — "the correction that was issued
     * against me". §3 stores only one side; F4's `canCorrect` and
     * ADJUSTMENT_ALREADY_CORRECTED's `details.correction_id` both consume the
     * other. At most one by construction: the partial unique
     * `stock_adjustments_corrects_unique` (T7 / re-review N-6).
     *
     * @return HasOne<StockAdjustment, $this>
     */
    public function correction(): HasOne
    {
        // The LIVE correction. A cancelled contra is not one: the API allows
        // re-correcting after a cancellation, so surfacing the abandoned document
        // as `correction_id` would leave the UI's `canCorrect` false and dead-end
        // the operator where the server would have said yes.
        return $this->hasOne(self::class, 'corrects_adjustment_id')
            ->where('status', '!=', StockAdjustmentStatus::Cancelled->value);
    }

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id');
    }
}

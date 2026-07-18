<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Physical payment instrument (check, voucher, etc.).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $payment_method_id
 * @property string $reference
 * @property string|null $partner_id
 * @property string|null $drawer_name
 * @property numeric-string $amount
 * @property string $currency
 * @property Carbon $received_date
 * @property Carbon|null $maturity_date
 * @property Carbon|null $expiry_date
 * @property InstrumentStatus $status
 * @property InstrumentDirection $direction
 * @property InstrumentKind|null $kind
 * @property InstrumentOrigin $origin
 * @property string|null $repository_id
 * @property string|null $location_id
 * @property string|null $bank_name
 * @property string|null $bank_branch
 * @property string|null $bank_account
 * @property string|null $bank_id
 * @property string|null $idempotency_key
 * @property string|null $remittance_id
 * @property bool $needs_details
 * @property Carbon|null $deposited_at
 * @property string|null $deposited_to_id
 * @property Carbon|null $cleared_at
 * @property Carbon|null $bounced_at
 * @property string|null $bounce_reason
 * @property DishonorRouting|null $dishonor_routing
 * @property string|null $payment_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read Partner|null $partner
 * @property-read PaymentRepository|null $repository
 * @property-read PaymentRepository|null $depositedTo
 * @property-read User|null $createdBy
 * @property-read Payment|null $payment
 * @property-read InstrumentRemittance|null $remittance
 */
class PaymentInstrument extends Model
{
    use HasUuids;

    protected $table = 'payment_instruments';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'payment_method_id',
        'reference',
        'partner_id',
        'drawer_name',
        'amount',
        'currency',
        'received_date',
        'maturity_date',
        'expiry_date',
        'status',
        'direction',
        'kind',
        'origin',
        'repository_id',
        'location_id',
        'bank_name',
        'bank_branch',
        'bank_account',
        'bank_id',
        'idempotency_key',
        'remittance_id',
        'needs_details',
        'deposited_at',
        'deposited_to_id',
        'cleared_at',
        'bounced_at',
        'bounce_reason',
        'dishonor_routing',
        'payment_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'received_date' => 'date',
            'maturity_date' => 'date',
            'expiry_date' => 'date',
            'status' => InstrumentStatus::class,
            'direction' => InstrumentDirection::class,
            'kind' => InstrumentKind::class,
            'origin' => InstrumentOrigin::class,
            'needs_details' => 'boolean',
            'dishonor_routing' => DishonorRouting::class,
            'deposited_at' => 'datetime',
            'cleared_at' => 'datetime',
            'bounced_at' => 'datetime',
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
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class);
    }

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function depositedTo(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'deposited_to_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<InstrumentRemittance, $this>
     */
    public function remittance(): BelongsTo
    {
        return $this->belongsTo(InstrumentRemittance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to filter by tenant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, InstrumentStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by partner.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPartner(Builder $query, string $partnerId): Builder
    {
        return $query->where('partner_id', $partnerId);
    }

    /**
     * Scope to get instruments pending (not terminal).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            InstrumentStatus::Cleared,
            InstrumentStatus::Expired,
            InstrumentStatus::Cancelled,
            InstrumentStatus::Collected,
        ]);
    }

    /**
     * Instruments whose value remains in the active portfolio lifecycle.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingPortfolio(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InstrumentStatus::Received,
            InstrumentStatus::Deposited,
            InstrumentStatus::Bounced,
        ]);
    }

    /**
     * Scope to get instruments maturing on a specific date.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMaturingOn(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereDate('maturity_date', $date);
    }

    /**
     * Scope to filter by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}

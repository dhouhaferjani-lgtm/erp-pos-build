<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Payment record for receivables and payables.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $partner_id
 * @property string|null $payment_method_id
 * @property string|null $instrument_id
 * @property string|null $repository_id
 * @property string|null $location_id
 * @property numeric-string $amount
 * @property string $currency
 * @property Carbon $payment_date
 * @property PaymentStatus $status
 * @property PaymentType $payment_type
 * @property PaymentOrigin|null $origin Phase 1 §13 — which surface authored this payment (pos / web_admin / mobile / api / unknown_legacy)
 * @property string|null $fiscal_event_id Phase 1 §7.5 — UUID FK → fiscal_events.id when this Payment was projected from a SALE_RECEIPT fiscal event by TreasuryReceiptBridge (Task 22); NULL for legacy / non-fiscal payments
 * @property string|null $original_payment_id UUID of the original Payment this refund was split from (Task 19)
 * @property string|null $refund_request_id Idempotency key for refundReceiptPayments() calls (Task 19)
 * @property string|null $idempotency_key Client-supplied request-level idempotency key (Task 16b) — dedups payment/multipayment creation so a lost-response retry cannot mint a second payment (and second movement). Unique per (company_id, idempotency_key) when non-null; NULL on every payment whose caller did not supply a key.
 * @property string|null $authorized_by_user_id Manager/admin who authorised the override (Task 19 / spec §3.6)
 * @property string|null $policy_trigger Policy condition that triggered the override (Task 19 / spec §3.6)
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $journal_entry_id
 * @property bool $is_reconciled
 * @property Carbon|null $reconciled_at
 * @property Carbon|null $dishonored_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Partner|null $partner
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read PaymentInstrument|null $instrument
 * @property-read PaymentRepository|null $repository
 * @property-read User|null $createdBy
 * @property-read Collection<int, PaymentAllocation> $allocations
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'partner_id',
        'payment_method_id',
        'withholding_certificate_id',
        'instrument_id',
        'repository_id',
        'location_id',
        'amount',
        'currency',
        'payment_date',
        'status',
        'payment_type',
        'reference',
        'notes',
        'journal_entry_id',
        'is_reconciled',
        'reconciled_at',
        'dishonored_at',
        'created_by',
        // Refund audit columns (Task 19 — spec §3.6 / §4.3)
        'original_payment_id',
        'refund_request_id',
        'authorized_by_user_id',
        'policy_trigger',
        // Task 16b (spine Wave D, HIGH-7) — request-level idempotency key for
        // payment/multipayment creation.
        'idempotency_key',
        // Phase 1 §13 — fiscal-engine integration columns. `origin` tags
        // which surface authored the payment; `fiscal_event_id` is the
        // back-link to the authoritative `fiscal_events` row when this
        // Payment was projected by TreasuryReceiptBridge (Task 22). The
        // FK direction is payments → fiscal_events (module depends on
        // the engine; the engine has zero dependency on Treasury).
        'origin',
        'fiscal_event_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
            'payment_type' => PaymentType::class,
            'origin' => PaymentOrigin::class,
            'is_reconciled' => 'boolean',
            'reconciled_at' => 'datetime',
            'dishonored_at' => 'datetime',
            'exchange_rate_at_payment' => 'decimal:6',
            'fx_gain_loss_amount' => 'decimal:4',
            'discount_taken' => 'decimal:4',
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
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<PaymentInstrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(PaymentInstrument::class);
    }

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsTo<WithholdingCertificate, $this>
     */
    public function withholdingCertificate(): BelongsTo
    {
        return $this->belongsTo(WithholdingCertificate::class, 'withholding_certificate_id');
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
     * Scope to filter by status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, PaymentStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Get the total amount allocated to documents.
     */
    public function getAllocatedAmount(): string
    {
        /** @var numeric-string $total */
        $total = $this->allocations->sum('amount');

        return (string) $total;
    }

    /**
     * Get the unallocated amount.
     */
    public function getUnallocatedAmount(int $scale = 3): string
    {
        /** @var numeric-string $allocatedAmount */
        $allocatedAmount = $this->getAllocatedAmount();

        return bcsub($this->amount, $allocatedAmount, $scale);
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

    /**
     * Scope to filter by payment type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, PaymentType $type): Builder
    {
        return $query->where('payment_type', $type->value);
    }

    /**
     * Scope for incoming payments only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIncoming(Builder $query): Builder
    {
        return $query->whereIn('payment_type', [
            PaymentType::DocumentPayment->value,
            PaymentType::Advance->value,
        ]);
    }

    /**
     * Scope for outgoing payments only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->whereIn('payment_type', [
            PaymentType::Refund->value,
            PaymentType::SupplierPayment->value,
        ]);
    }

    /**
     * Check if this is an advance payment.
     */
    public function isAdvance(): bool
    {
        return $this->payment_type === PaymentType::Advance;
    }

    /**
     * Check if this is a refund.
     */
    public function isRefund(): bool
    {
        return $this->payment_type === PaymentType::Refund;
    }

    /**
     * Check if this is a supplier payment.
     */
    public function isSupplierPayment(): bool
    {
        return $this->payment_type === PaymentType::SupplierPayment;
    }

    /**
     * Create a new factory instance for the model.
     */
    /** @return PaymentFactory */
    protected static function newFactory(): Factory
    {
        return PaymentFactory::new();
    }
}

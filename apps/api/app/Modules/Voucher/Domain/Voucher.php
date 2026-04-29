<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherKind;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Voucher entity — non-taxable liability instrument (EU Directive 2016/1065 MPV).
 *
 * Vouchers are the canonical redeemable-instrument layer across all sources:
 * refund-issued, exchange-surplus, goodwill, loyalty-credit (Phase 1.5+),
 * gift-card-purchase (Phase 2+), promotional (Phase 2+).
 *
 * The ledger (VoucherLedger) is the source of truth for balance + status.
 * current_balance and status are projections, updated as ledger events arrive.
 *
 * Phase 1 voucher domain is single-terminal: redeemable_at_terminal_id is set
 * at issuance to issued_at_terminal_id and enforced at redemption time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property numeric-string $initial_balance
 * @property numeric-string $current_balance
 * @property string $currency ISO 4217
 * @property VoucherStatus $status
 * @property RedemptionMode $redemption_mode
 * @property VoucherKind $voucher_kind
 * @property VoucherSource $source
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property string|null $partner_id Current holder
 * @property string|null $issued_to_partner_id Identified-at-issuance partner
 * @property string|null $source_receipt_id FK to pos_receipts
 * @property string|null $source_loyalty_transaction_id Phase 1.5+
 * @property string|null $source_promotional_campaign_id Phase 2+
 * @property string $issued_by_user_id
 * @property string|null $issued_at_terminal_id
 * @property string|null $redeemable_at_terminal_id
 * @property string|null $notes
 * @property string|null $authorized_by_user_id
 * @property string|null $override_reason
 * @property string|null $policy_trigger
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Partner|null $partner
 * @property-read Partner|null $issuedToPartner
 * @property-read User $issuedBy
 * @property-read Terminal|null $issuedAtTerminal
 * @property-read Terminal|null $redeemableAtTerminal
 * @property-read Receipt|null $sourceReceipt
 * @property-read Collection<int, VoucherLedger> $ledger
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static VoucherFactory factory(int|null $count = null, array<string, mixed> $state = [])
 */
final class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'vouchers';

    protected static function newFactory(): VoucherFactory
    {
        return VoucherFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'initial_balance',
        'current_balance',
        'currency',
        'status',
        'redemption_mode',
        'voucher_kind',
        'source',
        'issued_at',
        'expires_at',
        'partner_id',
        'issued_to_partner_id',
        'source_receipt_id',
        'source_loyalty_transaction_id',
        'source_promotional_campaign_id',
        'issued_by_user_id',
        'issued_at_terminal_id',
        'redeemable_at_terminal_id',
        'notes',
        'authorized_by_user_id',
        'override_reason',
        'policy_trigger',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'redemption_mode' => RedemptionMode::class,
            'voucher_kind' => VoucherKind::class,
            'source' => VoucherSource::class,
            'initial_balance' => 'decimal:5',
            'current_balance' => 'decimal:5',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Current holder of the voucher.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /**
     * Partner identified at issuance (distinct from current holder for transferable cases).
     *
     * @return BelongsTo<Partner, $this>
     */
    public function issuedToPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'issued_to_partner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * Terminal that issued this voucher (null for back-office goodwill).
     *
     * @return BelongsTo<Terminal, $this>
     */
    public function issuedAtTerminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'issued_at_terminal_id');
    }

    /**
     * Terminal where redemption is allowed (Phase 1 = same as issued terminal).
     *
     * @return BelongsTo<Terminal, $this>
     */
    public function redeemableAtTerminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'redeemable_at_terminal_id');
    }

    /**
     * The credit note that birthed this voucher (nullable for goodwill).
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function sourceReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'source_receipt_id');
    }

    /**
     * Ledger events for this voucher ordered chronologically.
     *
     * @return HasMany<VoucherLedger, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(VoucherLedger::class)->orderBy('occurred_at');
    }

    // -------------------------------------------------------------------------
    // Domain logic
    // -------------------------------------------------------------------------

    /**
     * Check whether this voucher is currently redeemable at the given terminal.
     *
     * Enforces the Phase 1 single-terminal scope rule:
     *   redeemable_at_terminal_id must match $terminal->id.
     *
     * Returns false on any of: terminal mismatch, expired, voided, fully redeemed, zero balance.
     */
    public function isRedeemable(Terminal $terminal): bool
    {
        // Single-terminal guard (Phase 1)
        if ($this->redeemable_at_terminal_id !== $terminal->id) {
            return false;
        }

        // Expiry guard
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        // Status guard
        if (! in_array($this->status, [VoucherStatus::Issued, VoucherStatus::PartiallyRedeemed], true)) {
            return false;
        }

        // Balance guard
        if (bccomp($this->current_balance, '0', 5) <= 0) {
            return false;
        }

        return true;
    }
}

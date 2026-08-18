<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\VoucherLedger;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * POS Receipt Entity
 *
 * Represents an immutable POS transaction record (NF525 TICKET event).
 * Each receipt is hash-chained to the previous receipt in the terminal's sequence.
 *
 * CRITICAL: Receipts are IMMUTABLE after creation. Only void operation is allowed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property string $terminal_id
 * @property string $receipt_number Format: T001-C042-L01-POS03-2026-00000001
 * @property ReceiptType $receipt_type Sale or Return
 * @property string|null $original_receipt_id FK to original receipt (for returns)
 * @property ReturnReason|null $return_reason Reason for return
 * @property int $chain_sequence Sequential number in terminal's chain
 * @property int $receipt_year Year for filtering/reset logic
 * @property string $fiscal_hash SHA-256 hash of this receipt
 * @property string|null $previous_hash Previous receipt hash (NULL for first)
 * @property string $vat_breakdown_hash SHA-256 of VAT details
 * @property string $payment_methods_hash SHA-256 of payment methods
 * @property Carbon $posted_at Receipt creation time (fiscal timestamp)
 * @property string $cashier_id
 * @property string $cashier_name Snapshot for audit trail
 * @property numeric-string $subtotal Net amount before tax
 * @property numeric-string $tax_amount Total VAT/tax
 * @property numeric-string $discount_amount Transaction-level discount
 * @property string|null $discount_reason Reason for transaction discount
 * @property numeric-string $total Gross total ((subtotal - discount) + tax)
 * @property numeric-string|null $change_due Cash change returned to customer; NULL on legacy rows
 * @property numeric-string|null $tolerance_writeoff Amount written off to GL 658 for cash-sale tolerance. On v3+ rows this is ALWAYS written — canonical zero ('0.000') when no tolerance applied, never NULL. NULL means a v1/v2 legacy row (or a training receipt); it does NOT mean "no tolerance". Do not use `whereNotNull` as a "has tolerance" predicate on v3 data — compare with bccomp against zero.
 * @property numeric-string|null $cash_rounding_adjustment Signed cash-rounding adjustment (rounded − exact); NULL on v1/v2 rows
 * @property numeric-string|null $cash_rounding_denomination Denomination applied, as signed by the device; NULL on v1/v2 rows
 * @property string $currency
 * @property ConsumptionMode|null $consumption_mode SUR_PLACE, A_EMPORTER
 * @property string|null $customer_name
 * @property string|null $customer_identifier Loyalty number, phone, etc.
 * @property string|null $partner_id Optional FK to partners for customer queryability
 * @property string|null $contact_id
 * @property bool $is_voided
 * @property bool $is_training
 * @property Carbon|null $voided_at
 * @property string|null $voided_by
 * @property string|null $void_reason
 * @property string|null $void_receipt_id Reference to negative receipt
 * @property FiscalStatus $fiscal_status Lifecycle state: pending_seal, fiscalized, voided, etc.
 * @property Carbon|null $synced_at When terminal synced to server
 * @property string|null $sync_error Last sync error if any
 * @property array<string, mixed>|null $discount_breakdown JSONB audit snapshot of resolved discounts
 * @property string|null $notes
 * @property string|null $authorized_by_user_id UUID of the manager who approved the override
 * @property string|null $override_reason Human-readable reason for the manager override
 * @property bool|null $out_of_window TRUE when return window had expired at time of return
 * @property string|null $policy_trigger Machine-readable policy trigger key (e.g. "over_threshold")
 * @property string|null $refund_request_id Client-supplied idempotency UUID
 * @property string|null $exchange_group_id UUID shared by both halves of an exchange transaction (committed in v3 hash)
 * @property string|null $canonical_bytes Phase 1 §7.5 verbatim canonical encoding from the device (BYTEA on PG, BLOB on SQLite); NULL on rows pre-dating the projection-row rebuild
 * @property string|null $fiscal_event_id Phase 1 §7.5 UUID FK → fiscal_events.id — the projector idempotency anchor (Task 21); NULL on legacy rows
 * @property Carbon $created_at Server creation time
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 * @property-read Terminal $terminal
 * @property-read User $cashier
 * @property-read Partner|null $partner
 * @property-read Contact|null $contact
 * @property-read User|null $voidedBy
 * @property-read Receipt|null $voidReceipt
 * @property-read Receipt|null $originalReceipt
 * @property-read Collection<int, Receipt> $returnReceipts
 * @property-read Collection<int, ReceiptLine> $lines
 * @property-read Collection<int, ReceiptVatDetail> $vatDetails
 * @property-read Collection<int, ReceiptPayment> $payments
 * @property-read Collection<int, VoucherLedger> $voucherLedgerEntries
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forCashier(string $cashierId)
 * @method static Builder<static> notVoided()
 * @method static Builder<static> voided()
 * @method static Builder<static> production()
 * @method static Builder<static> notSynced()
 * @method static Builder<static> byYear(int $year)
 */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_receipts';

    protected static function newFactory(): ReceiptFactory
    {
        return ReceiptFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'terminal_id',
        'receipt_number',
        'receipt_type',
        'original_receipt_id',
        'return_reason',
        'chain_sequence',
        'receipt_year',
        'fiscal_hash',
        'previous_hash',
        'vat_breakdown_hash',
        'payment_methods_hash',
        'posted_at',
        'cashier_id',
        'cashier_name',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'discount_reason',
        'discount_authorized_by',
        'total',
        'change_due',
        'tolerance_writeoff',
        // Cash rounding Phase 1 (spec Rev 2.2 §4.5) — mirrored from the
        // signed v3 SALE_RECEIPT payload. `cash_rounding_adjustment` is
        // SIGNED and participates in the `pos_receipts_totals` CHECK;
        // `cash_rounding_denomination` records the denomination the device
        // actually applied so a later policy change is detectable.
        'cash_rounding_adjustment',
        'cash_rounding_denomination',
        'currency',
        'consumption_mode',
        'table_id',
        'fiscal_status',
        'customer_name',
        'customer_identifier',
        'partner_id',
        'contact_id',
        'is_voided',
        'is_training',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_receipt_id',
        'synced_at',
        'sync_error',
        'idempotency_key',
        'discount_breakdown',
        'notes',
        'authorized_by_user_id',
        'override_reason',
        'out_of_window',
        'policy_trigger',
        'refund_request_id',
        'exchange_group_id',
        // Phase 1 §7.5 — projection-row linkage to `fiscal_events`.
        // `canonical_bytes` carries the verbatim canonical encoding from the
        // device; `fiscal_event_id` is the UNIQUE FK to the authoritative
        // fiscal event and the idempotency anchor for
        // PosCoreReceiptProjection (Task 21). Both nullable for backward
        // compatibility with rows that pre-date the rebuild.
        'canonical_bytes',
        'fiscal_event_id',
        // Pass 2A.PHP.1 (synthesis v5 §3) — projector-consumed columns from
        // the 27-key canonical SALE_RECEIPT payload. `invoice_type_code` ∈
        // {SALE, REFUND, VOID, TRAINING}; `training_flag` is denormalized
        // from `invoice_type_code == 'TRAINING'` and the universal report-
        // filter path. Sealed-at-INSERT; the immutability trigger forbids
        // subsequent UPDATEs (they sit outside the void-whitelist by design).
        'invoice_type_code',
        'training_flag',
    ];

    public function getCanonicalBytesAttribute(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $meta = stream_get_meta_data($value);
            if ($meta['seekable'] === true) {
                rewind($value);
            }

            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_type' => ReceiptType::class,
            'return_reason' => ReturnReason::class,
            'chain_sequence' => 'integer',
            'receipt_year' => 'integer',
            'posted_at' => 'datetime',
            'subtotal' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'discount_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'change_due' => 'decimal:3',
            'tolerance_writeoff' => 'decimal:3',
            // Column is numeric(12,3) — same scale as the totals it balances.
            'cash_rounding_adjustment' => 'decimal:3',
            // Column is numeric(15,4), matching
            // `country_payment_settings.cash_rounding_denomination`. Cast to
            // decimal:4 (not the bare `string` used on CountryPaymentSettings)
            // so the value is normalised to 4dp on BOTH drivers — SQLite has
            // no numeric scale and would otherwise hand back '0.05' where PG
            // hands back '0.0500'. Comparisons against live policy are bccomp,
            // never string equality, so the 4dp normalisation is safe; the
            // authoritative signed bytes live in `canonical_bytes`.
            'cash_rounding_denomination' => 'decimal:4',
            'fiscal_status' => FiscalStatus::class,
            'consumption_mode' => ConsumptionMode::class,
            'is_voided' => 'boolean',
            // Snapshot of cashier max_discount_percent at transaction time — decimal(5,2)
            'discount_authorized_by' => 'decimal:2',
            'is_training' => 'boolean',
            'training_flag' => 'boolean',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
            'discount_breakdown' => 'array',
            'out_of_window' => 'boolean',
            // v3-refund-chain-integration spec §6.4: `sealed_hash_algorithm`
            // is a plain nullable string and needs no cast entry (Eloquent
            // already returns a bare string for an uncast attribute) — named
            // here explicitly so it is not silently omitted from this model
            // the way it was omitted from an earlier spec revision's
            // manifest. §3.6's `refund_policy_alerts` IS cast — JSONB,
            // decoded to a plain array, matching `discount_breakdown` above.
            'refund_policy_alerts' => 'array',
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
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'terminal_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function voidReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'void_receipt_id');
    }

    /**
     * The original receipt that this return receipt references.
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function originalReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'original_receipt_id');
    }

    /**
     * Return receipts that reference this receipt.
     *
     * @return HasMany<Receipt, $this>
     */
    public function returnReceipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'original_receipt_id');
    }

    /**
     * @return HasMany<ReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class, 'receipt_id')
            ->orderBy('line_number');
    }

    /**
     * @return HasMany<ReceiptVatDetail, $this>
     */
    public function vatDetails(): HasMany
    {
        return $this->hasMany(ReceiptVatDetail::class, 'receipt_id');
    }

    /**
     * @return HasMany<ReceiptPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ReceiptPayment::class, 'receipt_id');
    }

    /**
     * Voucher ledger entries linked to this receipt (issuance or redemption events).
     *
     * Used by V3ReceiptHashComputer to populate voucher_ledger_entries in the
     * canonical v3 hash payload (spec §5.0 / §5.1).
     *
     * @return HasMany<VoucherLedger, $this>
     */
    public function voucherLedgerEntries(): HasMany
    {
        return $this->hasMany(VoucherLedger::class, 'receipt_id')
            ->orderBy('voucher_id');
    }

    /**
     * Check if this is a return receipt
     */
    public function isReturn(): bool
    {
        return $this->receipt_type === ReceiptType::Return;
    }

    /**
     * Check if this is a sale receipt
     */
    public function isSale(): bool
    {
        return $this->receipt_type === ReceiptType::Sale;
    }

    /**
     * Check if receipt is fiscally sealed (always true after creation)
     */
    public function isSealed(): bool
    {
        return true; // fiscal_hash is always set after creation
    }

    /**
     * Check if receipt is voided
     */
    public function isVoided(): bool
    {
        return $this->is_voided;
    }

    /**
     * Check if receipt is editable (always false - receipts are immutable)
     */
    public function isEditable(): bool
    {
        return false;
    }

    /**
     * Check if receipt is deletable (always false - receipts are immutable)
     */
    public function isDeletable(): bool
    {
        return false;
    }

    /**
     * Check if receipt has been synced to server
     */
    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /**
     * Check if this is the first receipt in terminal chain
     */
    public function isFirstInChain(): bool
    {
        return $this->previous_hash === null;
    }

    /**
     * Get formatted receipt display (e.g., "T001-C042-L01-POS03-2026-00000001")
     */
    public function getDisplayNumber(): string
    {
        return $this->receipt_number;
    }

    /**
     * Scope to filter receipts by tenant
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter receipts by company
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter receipts by terminal
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter receipts by cashier
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCashier(Builder $query, string $cashierId): Builder
    {
        return $query->where('cashier_id', $cashierId);
    }

    /**
     * Scope to filter only non-voided receipts
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->where('is_voided', false);
    }

    /**
     * Scope to filter only voided receipts
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVoided(Builder $query): Builder
    {
        return $query->where('is_voided', true);
    }

    /**
     * Scope to filter only production (non-training) receipts
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProduction(Builder $query): Builder
    {
        return $query->where('is_training', false);
    }

    /**
     * Scope to filter receipts not yet synced
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotSynced(Builder $query): Builder
    {
        return $query->whereNull('synced_at');
    }

    /**
     * Scope to filter receipts by year
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByYear(Builder $query, int $year): Builder
    {
        return $query->where('receipt_year', $year);
    }
}

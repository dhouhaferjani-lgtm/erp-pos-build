<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\ReceiptType;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Database\Factories\ReceiptFactory;
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
 * @property string $currency
 * @property ConsumptionMode|null $consumption_mode SUR_PLACE, A_EMPORTER
 * @property string|null $customer_name
 * @property string|null $customer_identifier Loyalty number, phone, etc.
 * @property string|null $partner_id Optional FK to partners for customer queryability
 * @property string|null $contact_id
 * @property bool $is_voided
 * @property Carbon|null $voided_at
 * @property string|null $voided_by
 * @property string|null $void_reason
 * @property string|null $void_receipt_id Reference to negative receipt
 * @property Carbon|null $synced_at When terminal synced to server
 * @property string|null $sync_error Last sync error if any
 * @property array<string, mixed>|null $discount_breakdown JSONB audit snapshot of resolved discounts
 * @property string|null $notes
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
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forCashier(string $cashierId)
 * @method static Builder<static> notVoided()
 * @method static Builder<static> voided()
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
        'currency',
        'consumption_mode',
        'customer_name',
        'customer_identifier',
        'partner_id',
        'contact_id',
        'is_voided',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_receipt_id',
        'synced_at',
        'sync_error',
        'discount_breakdown',
        'notes',
    ];

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
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'consumption_mode' => ConsumptionMode::class,
            'is_voided' => 'boolean',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
            'discount_breakdown' => 'array',
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
        return $this->belongsTo(Terminal::class, 'terminal_id');
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
        return $this->fiscal_hash !== null;
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

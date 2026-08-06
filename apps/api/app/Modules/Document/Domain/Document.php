<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FacturXProfile;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PaymentStatus;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Expense\Domain\ExpenseMetadata;
use App\Modules\Income\Domain\IncomeMetadata;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Domain\Enums\SupplierCreditNoteReason;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Domain\CurrencyScale;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $location_id
 * @property string $partner_id
 * @property string|null $work_order_id
 * @property string|null $vehicle_id
 * @property DocumentType $type
 * @property FiscalCategory $fiscal_category
 * @property FiscalStatus $fiscal_status
 * @property DocumentStatus $status
 * @property string $document_number
 * @property Carbon $document_date
 * @property Carbon|null $due_date
 * @property Carbon|null $valid_until
 * @property string $currency
 * @property numeric-string|null $subtotal
 * @property numeric-string|null $discount_amount
 * @property numeric-string|null $tax_amount
 * @property numeric-string|null $line_tax_amount Line VAT total (excludes timbre), scale 3
 * @property numeric-string|null $stamp_duty_amount Timbre fiscal (non-recoverable), scale 3
 * @property numeric-string|null $total
 * @property numeric-string|null $balance_due
 * @property string|null $fiscal_hash
 * @property string|null $facturx_xml
 * @property FacturXProfile|null $facturx_profile
 * @property Carbon|null $facturx_generated_at
 * @property string|null $previous_hash
 * @property int|null $chain_sequence
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string|null $reference
 * @property bool $is_historical
 * @property string|null $external_document_number
 * @property Carbon|null $external_document_date
 * @property string|null $source_document_id
 * @property SupplierInvoiceMatchStatus|null $match_status
 * @property SupplierCreditNoteReason|null $supplier_credit_note_reason
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Partner $partner
 * @property-read array<string, mixed>|null $vehicle
 * @property-read Location|null $location
 * @property-read Document|null $sourceDocument
 * @property-read Collection<int, DocumentLine> $lines
 * @property-read Collection<int, DocumentAdditionalCost> $additionalCosts
 * @property-read Collection<int, PaymentAllocation> $allocations
 * @property-read Collection<int, CreditNoteAllocation> $creditNoteAllocations
 * @property-read Collection<int, CreditNoteAllocation> $creditsAgainstDocument
 * @property-read Collection<int, Document> $creditNotes
 * @property-read Collection<int, Document> $childDocuments
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> ofType(DocumentType $type)
 * @method static Builder<static> inStatus(DocumentStatus $status)
 * @method static Builder<static> creditNotes()
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'partner_id',
        'work_order_id',
        'type',
        'fiscal_category',
        'fiscal_status',
        'status',
        'document_number',
        'document_date',
        'due_date',
        'valid_until',
        'currency',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'line_tax_amount',
        'stamp_duty_amount',
        'total',
        'balance_due',
        'fiscal_hash',
        'facturx_xml',
        'facturx_profile',
        'facturx_generated_at',
        'previous_hash',
        'chain_sequence',
        'notes',
        'internal_notes',
        'reference',
        'is_historical',
        'external_document_number',
        'external_document_date',
        'source_document_id',
        'credit_note_reason',
        'confirmed_at',
        'confirmed_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'payload',
        'match_status',
        'supplier_credit_note_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'fiscal_category' => FiscalCategory::class,
            'fiscal_status' => FiscalStatus::class,
            'status' => DocumentStatus::class,
            'credit_note_reason' => CreditNoteReason::class,
            'document_date' => 'date',
            'due_date' => 'date',
            'valid_until' => 'date',
            'external_document_date' => 'date',
            'facturx_profile' => FacturXProfile::class,
            'facturx_generated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:3',
            'discount_amount' => 'decimal:3',
            'tax_amount' => 'decimal:3',
            'line_tax_amount' => 'decimal:3',
            'stamp_duty_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'balance_due' => 'decimal:3',
            'is_historical' => 'boolean',
            'payload' => 'array',
            'match_status' => SupplierInvoiceMatchStatus::class,
            'supplier_credit_note_reason' => SupplierCreditNoteReason::class,
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
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return HasOne<DocumentVehicleContext, $this>
     */
    public function vehicleContext(): HasOne
    {
        return $this->hasOne(DocumentVehicleContext::class);
    }

    /**
     * Get the associated vehicle (backward-compatible accessor)
     *
     * @return array<string, mixed>|null
     */
    public function getVehicleAttribute(): ?array
    {
        return $this->vehicleContext?->getVehicleSnapshot();
    }

    /**
     * Get the vehicle ID (backward-compatible accessor)
     */
    public function getVehicleIdAttribute(): ?string
    {
        return $this->vehicleContext?->vehicle_id;
    }

    /**
     * Get the vehicle display string
     */
    public function getVehicleDisplayString(): string
    {
        return $this->vehicleContext?->getVehicleDisplayString() ?? 'No vehicle';
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    /**
     * @return HasMany<DocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('line_number');
    }

    /**
     * @return HasMany<DocumentAdditionalCost, $this>
     */
    public function additionalCosts(): HasMany
    {
        return $this->hasMany(DocumentAdditionalCost::class);
    }

    /**
     * @return HasOne<ExpenseMetadata, $this>
     */
    public function expenseMetadata(): HasOne
    {
        return $this->hasOne(ExpenseMetadata::class);
    }

    /**
     * @return HasOne<IncomeMetadata, $this>
     */
    public function incomeMetadata(): HasOne
    {
        return $this->hasOne(IncomeMetadata::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'document_id');
    }

    /**
     * Get all credit note allocations for this document
     *
     * For invoices: credit notes allocated AGAINST this invoice (reduces balance)
     * For credit notes: allocations OF this credit note TO invoices
     *
     * @return HasMany<CreditNoteAllocation, $this>
     */
    public function creditNoteAllocations(): HasMany
    {
        // If this is an invoice, get credit notes allocated against it
        if ($this->type === DocumentType::Invoice) {
            return $this->hasMany(CreditNoteAllocation::class, 'invoice_id');
        }

        // If this is a credit note, get its allocations to invoices
        if ($this->type === DocumentType::CreditNote) {
            return $this->hasMany(CreditNoteAllocation::class, 'credit_note_id');
        }

        // Other document types have no credit note allocations
        return $this->hasMany(CreditNoteAllocation::class, 'invoice_id')->whereRaw('1 = 0');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Document::class, 'source_document_id')
            ->where('type', DocumentType::CreditNote);
    }

    /**
     * @return HasMany<WithholdingCertificate, $this>
     */
    public function withholdingCertificates(): HasMany
    {
        return $this->hasMany(WithholdingCertificate::class, 'document_id');
    }

    /**
     * Get all child documents derived from this document
     *
     * @return HasMany<Document, $this>
     */
    public function childDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'source_document_id');
    }

    /**
     * Supplier invoices can link to multiple POs through payload while keeping
     * source_document_id as the backward-compatible first PO column.
     *
     * @return Builder<static>
     */
    public function payloadLinkedSupplierInvoiceChildren(): Builder
    {
        return static::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('company_id', $this->company_id)
            ->where('id', '!=', $this->id)
            ->where('type', DocumentType::SupplierInvoice)
            ->whereJsonContains('payload->supplier_invoice->source_document_ids', $this->id);
    }

    /**
     * Get the full document chain (ancestors and descendants)
     *
     * Returns an array with:
     * - 'ancestors': Documents that led to this one (Quote → Order → Invoice)
     * - 'current': This document
     * - 'descendants': Documents derived from this one (DN, Credit Notes)
     *
     * @return array{ancestors: Collection<int, Document>, current: Document, siblings: Collection<int, Document>, descendants: Collection<int, Document>}
     */
    public function getDocumentChain(): array
    {
        // Get all ancestors by traversing up
        $ancestors = new Collection;
        $ancestorIds = [];
        $parent = $this->sourceDocument;
        while ($parent !== null && ! isset($ancestorIds[$parent->id])) {
            $ancestors->prepend($parent);
            $ancestorIds[$parent->id] = true;
            $parent = $parent->sourceDocument;
        }

        $sourceDocumentIds = $this->supplierInvoiceSourceDocumentIds();
        if ($sourceDocumentIds !== []) {
            $linkedSources = static::query()
                ->where('tenant_id', $this->tenant_id)
                ->where('company_id', $this->company_id)
                ->whereIn('id', $sourceDocumentIds)
                ->get()
                ->keyBy('id');

            foreach ($sourceDocumentIds as $sourceDocumentId) {
                if ($sourceDocumentId === $this->id || isset($ancestorIds[$sourceDocumentId])) {
                    continue;
                }

                /** @var Document|null $linkedSource */
                $linkedSource = $linkedSources->get($sourceDocumentId);
                if ($linkedSource === null) {
                    continue;
                }

                $ancestors->push($linkedSource);
                $ancestorIds[$sourceDocumentId] = true;
            }
        }

        // Get all descendants recursively
        $descendants = $this->getAllDescendants([$this->id => true]);

        // Get siblings - documents with the same source_document_id
        $siblings = new Collection;
        if ($this->source_document_id !== null) {
            $siblings = static::where('source_document_id', $this->source_document_id)
                ->where('id', '!=', $this->id)
                ->orderBy('created_at')
                ->get();
        }

        return [
            'ancestors' => $ancestors,
            'current' => $this,
            'siblings' => $siblings,
            'descendants' => $descendants,
        ];
    }

    /**
     * Recursively get all descendant documents
     *
     * @param  array<string, bool>  $visitedDocumentIds
     * @return Collection<int, Document>
     */
    protected function getAllDescendants(array $visitedDocumentIds = []): Collection
    {
        $descendants = new Collection;
        $visitedDocumentIds[$this->id] = true;

        $children = $this->childDocuments()
            ->orderBy('created_at')
            ->get()
            ->merge($this->payloadLinkedSupplierInvoiceChildren()->orderBy('created_at')->get())
            ->unique('id')
            ->values();

        foreach ($children as $child) {
            if (isset($visitedDocumentIds[$child->id])) {
                continue;
            }

            $visitedDocumentIds[$child->id] = true;
            $descendants->push($child);
            $childDescendants = $child->getAllDescendants($visitedDocumentIds);
            foreach ($childDescendants as $descendant) {
                if (isset($visitedDocumentIds[$descendant->id])) {
                    continue;
                }

                $visitedDocumentIds[$descendant->id] = true;
                $descendants->push($descendant);
            }
        }

        return $descendants;
    }

    /**
     * @return list<string>
     */
    private function supplierInvoiceSourceDocumentIds(): array
    {
        $sourceDocumentIds = $this->payload['supplier_invoice']['source_document_ids'] ?? [];
        if (! is_array($sourceDocumentIds)) {
            return [];
        }

        $ids = [];
        foreach ($sourceDocumentIds as $sourceDocumentId) {
            if (! is_string($sourceDocumentId) || isset($ids[$sourceDocumentId])) {
                continue;
            }

            $ids[$sourceDocumentId] = true;
        }

        return array_keys($ids);
    }

    /**
     * Check if document is in draft status
     */
    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    /**
     * Check if document is confirmed
     */
    public function isConfirmed(): bool
    {
        return $this->status === DocumentStatus::Confirmed;
    }

    /**
     * Check if document is posted
     */
    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::Posted;
    }

    /**
     * Check if document is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::Cancelled;
    }

    /**
     * Check if document is fiscally sealed (immutable core fields)
     */
    public function isSealed(): bool
    {
        return $this->fiscal_status === FiscalStatus::Sealed;
    }

    /**
     * Check if document is fiscally voided
     */
    public function isVoided(): bool
    {
        return $this->fiscal_status === FiscalStatus::Voided;
    }

    /**
     * Check if document is fiscally immutable (sealed or voided)
     */
    public function isFiscallyImmutable(): bool
    {
        return $this->fiscal_status->isImmutable();
    }

    /**
     * Check if document requires fiscal compliance
     */
    public function isFiscal(): bool
    {
        return $this->fiscal_category->isFiscal();
    }

    /**
     * Check if document can be edited
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable() && ! $this->isFiscallyImmutable();
    }

    /**
     * Check if document can be deleted
     */
    public function isDeletable(): bool
    {
        return $this->status->isDeletable();
    }

    /**
     * Check if document is historical (imported from another system)
     */
    public function isHistorical(): bool
    {
        return $this->is_historical ?? false;
    }

    /**
     * Scope to filter documents by tenant
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter documents by type
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, DocumentType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter documents by status
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInStatus(Builder $query, DocumentStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter documents by company
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter credit notes
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreditNotes(Builder $query): Builder
    {
        return $query->where('type', DocumentType::CreditNote);
    }

    /**
     * The SQL that reproduces the `balance_due` cache, correlated to `documents`.
     *
     * Copied deliberately from `update_document_balance_due()`
     * (`database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php`)
     * and from `DocumentCacheValidationService`, which already expresses the same
     * formula in raw SQL. Type-agnostic, exactly like the trigger: it always joins
     * `credit_note_allocations` on `invoice_id`, so a credit note's own outward
     * allocations never reduce its balance.
     */
    private const OUTSTANDING_BALANCE_SQL = '(COALESCE(documents.total, 0)'
        .' - COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE payment_allocations.document_id = documents.id), 0)'
        .' - COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE credit_note_allocations.invoice_id = documents.id), 0))';

    /**
     * @return HasMany<CreditNoteAllocation, $this>
     */
    public function creditsAgainstDocument(): HasMany
    {
        return $this->hasMany(CreditNoteAllocation::class, 'invoice_id');
    }

    /**
     * Bound a query to documents that MIGHT still be outstanding (W-6 D2).
     *
     * A deliberately coarse SQL pre-filter, not the verdict. `balance_due` is a
     * trigger cache that only ever fires on allocation DML, so filtering on the
     * column hides every posted-but-never-allocated document — the D2 defect. This
     * filters on the same arithmetic the trigger performs, so nothing outstanding
     * is excluded; callers must still take the exact amount from
     * {@see outstandingBalance()}, because SQLite evaluates this expression in
     * floating point and can leave a settled document a hair above zero.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereOutstanding(Builder $query): Builder
    {
        return $query->whereRaw(self::OUTSTANDING_BALANCE_SQL.' > 0');
    }

    /**
     * The outstanding amount computed from allocations — the SOURCE OF TRUTH.
     *
     * Mirrors {@see self::OUTSTANDING_BALANCE_SQL} in bcmath at the caller's
     * currency scale, so it never depends on the `balance_due` cache being warm.
     * Unlike {@see getOutstandingAmount()} it is type-agnostic, again like the
     * trigger, so it is usable for supplier invoices and purchase orders too.
     *
     * Eager-load `allocations` and `creditsAgainstDocument` before calling this in
     * a loop; otherwise it lazy-loads two relations per document.
     *
     * @return numeric-string
     */
    public function outstandingBalance(int $scale): string
    {
        // Intermediates run wider than the emission scale (CLAUDE.md rule 19);
        // the single rounding happens on the way out.
        $calculationScale = $scale + 4;

        /** @var numeric-string $outstanding */
        $outstanding = CurrencyScale::bcround((string) ($this->total ?? '0'), $calculationScale);

        foreach ($this->allocations as $allocation) {
            $outstanding = bcsub(
                $outstanding,
                CurrencyScale::bcround((string) $allocation->amount, $calculationScale),
                $calculationScale,
            );
        }

        foreach ($this->creditsAgainstDocument as $credit) {
            $outstanding = bcsub(
                $outstanding,
                CurrencyScale::bcround((string) $credit->amount, $calculationScale),
                $calculationScale,
            );
        }

        return CurrencyScale::bcround($outstanding, $scale);
    }

    /**
     * Get the delivery status for a sales order.
     *
     * Calculates whether the order is:
     * - NotDelivered: No line has any quantity delivered
     * - PartiallyDelivered: Some lines have partial or full deliveries
     * - FullyDelivered: All lines are fully delivered
     *
     * @throws \InvalidArgumentException If called on a non-sales-order document
     */
    public function getDeliveryStatus(): DeliveryStatus
    {
        if ($this->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Delivery status is only applicable to sales orders');
        }

        $lines = $this->lines;

        if ($lines->isEmpty()) {
            return DeliveryStatus::NotDelivered;
        }

        $totalLines = $lines->count();
        $fullyDeliveredLines = 0;
        $hasAnyDelivery = false;

        foreach ($lines as $line) {
            if ($line->hasDeliveries()) {
                $hasAnyDelivery = true;
            }

            if ($line->isFullyDelivered()) {
                $fullyDeliveredLines++;
            }
        }

        if ($fullyDeliveredLines === $totalLines) {
            return DeliveryStatus::FullyDelivered;
        }

        if ($hasAnyDelivery) {
            return DeliveryStatus::PartiallyDelivered;
        }

        return DeliveryStatus::NotDelivered;
    }

    /**
     * Get the outstanding amount for this document.
     *
     * This is the SOURCE OF TRUTH - computed from allocations.
     * The balance_due column is a CACHED value maintained by PostgreSQL trigger.
     *
     * Formula: Outstanding = Total - SUM(Payment Allocations) - SUM(Credit Note Allocations)
     *
     * @return numeric-string The outstanding amount (can be negative if overpaid)
     */
    public function getOutstandingAmount(int $scale = 3): string
    {
        $payableTypes = [
            DocumentType::Invoice,
            DocumentType::SalesOrder,
            DocumentType::PurchaseOrder,
        ];

        if (! in_array($this->type, $payableTypes, true)) {
            return '0';
        }

        $total = $this->total ?? '0';

        // Sum all payment allocations
        /** @var numeric-string $paid */
        $paid = (string) $this->allocations()->sum('amount');

        // Sum all credit note allocations
        /** @var numeric-string $credited */
        $credited = (string) $this->creditNoteAllocations()->sum('amount');

        // Calculate: Total - Paid - Credited
        $outstanding = bcsub(bcsub($total, $paid, $scale), $credited, $scale);

        return $outstanding;
    }

    /**
     * Get the payment status for this document.
     *
     * This is COMPUTED from the outstanding amount, not stored.
     * Uses getOutstandingAmount() as the source of truth.
     */
    public function getPaymentStatus(int $scale = 3): PaymentStatus
    {
        $payableTypes = [
            DocumentType::Invoice,
            DocumentType::SalesOrder,
            DocumentType::PurchaseOrder,
        ];

        if (! in_array($this->type, $payableTypes, true)) {
            return PaymentStatus::Unpaid;
        }

        $outstanding = $this->getOutstandingAmount($scale);
        $total = $this->total ?? '0';

        // Check if any payments are pending bank reconciliation
        $hasPendingPayments = $this->allocations()
            ->whereHas('payment', fn (Builder $q) => $q->whereRaw("status != 'reconciled'"))
            ->exists();

        return match (true) {
            // Overpaid: outstanding is negative
            bccomp($outstanding, '0', $scale) < 0 => PaymentStatus::Overpaid,

            // Paid: outstanding is zero
            bccomp($outstanding, '0', $scale) === 0 => PaymentStatus::Paid,

            // In Payment: no payments yet but some are pending reconciliation
            bccomp($outstanding, $total, $scale) === 0 && $hasPendingPayments => PaymentStatus::InPayment,

            // Unpaid: outstanding equals total (no payments)
            bccomp($outstanding, $total, $scale) === 0 => PaymentStatus::Unpaid,

            // Partially Paid: 0 < outstanding < total
            default => PaymentStatus::PartiallyPaid,
        };
    }

    /**
     * Get the fulfillment status for this sales document.
     *
     * Tracks delivery/shipment status based on delivery notes.
     * Only applicable to invoices and sales orders.
     */
    public function getFulfillmentStatus(): Enums\FulfillmentStatus
    {
        // Only sales documents have fulfillment tracking
        if (! in_array($this->type, [DocumentType::Invoice, DocumentType::SalesOrder], true)) {
            return Enums\FulfillmentStatus::NotApplicable;
        }

        // Get all delivery notes linked to this document
        $deliveryNotes = $this->childDocuments()
            ->where('type', DocumentType::DeliveryNote)
            ->whereIn('status', ['confirmed', 'posted'])
            ->get();

        // If no delivery notes exist, check if document has physical products
        if ($deliveryNotes->isEmpty()) {
            // Check if any lines require physical delivery
            $hasPhysicalProducts = $this->lines()
                ->whereHas('product', fn (Builder $q) => $q->whereRaw('is_physical = true'))
                ->exists();

            return $hasPhysicalProducts
                ? Enums\FulfillmentStatus::NotFulfilled
                : Enums\FulfillmentStatus::NotApplicable;
        }

        // Calculate total ordered quantity vs delivered quantity for each product
        $orderLines = $this->lines;
        $totalOrdered = '0.0000';
        $totalDelivered = '0.0000';

        foreach ($orderLines as $orderLine) {
            /** @var numeric-string $orderedQty */
            $orderedQty = $orderLine->quantity ?? '0.0000';
            $totalOrdered = bcadd($totalOrdered, $orderedQty, 4);

            // Sum delivered quantity for this product from all delivery notes
            /** @var numeric-string $deliveredQty */
            $deliveredQty = '0.0000';
            foreach ($deliveryNotes as $deliveryNote) {
                $deliveredLine = $deliveryNote->lines()
                    ->where('product_id', $orderLine->product_id)
                    ->first();

                if ($deliveredLine !== null) {
                    /** @var numeric-string $lineQty */
                    $lineQty = $deliveredLine->quantity ?? '0.0000';
                    $deliveredQty = bcadd($deliveredQty, $lineQty, 4);
                }
            }

            $totalDelivered = bcadd($totalDelivered, $deliveredQty, 4);
        }

        // Determine fulfillment status
        return match (true) {
            // Nothing ordered or all delivered
            bccomp($totalOrdered, '0', 4) === 0 => Enums\FulfillmentStatus::NotApplicable,
            bccomp($totalDelivered, $totalOrdered, 4) >= 0 => Enums\FulfillmentStatus::Fulfilled,
            bccomp($totalDelivered, '0', 4) === 0 => Enums\FulfillmentStatus::NotFulfilled,
            default => Enums\FulfillmentStatus::PartiallyFulfilled,
        };
    }

    /**
     * Create a new factory instance for the model.
     */
    /** @return DocumentFactory */
    protected static function newFactory(): Factory
    {
        return DocumentFactory::new();
    }
}

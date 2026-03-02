<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\FulfillmentStatus;
use App\Modules\Document\Domain\Enums\PaymentStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $partner_id
 * @property string|null $vehicle_id
 * @property DocumentType $type
 * @property FiscalCategory $fiscal_category
 * @property FiscalStatus $fiscal_status
 * @property DocumentStatus $status
 * @property string $document_number
 * @property \Illuminate\Support\Carbon $document_date
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property string $currency
 * @property numeric-string|null $subtotal
 * @property numeric-string|null $discount_amount
 * @property numeric-string|null $tax_amount
 * @property numeric-string|null $total
 * @property numeric-string|null $balance_due
 * @property string|null $fiscal_hash
 * @property string|null $previous_hash
 * @property int|null $chain_sequence
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string|null $reference
 * @property bool $is_historical
 * @property string|null $external_document_number
 * @property \Illuminate\Support\Carbon|null $external_document_date
 * @property string|null $source_document_id
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property string|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
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
    use HasUuids;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'total',
        'balance_due',
        'fiscal_hash',
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
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'is_historical' => 'boolean',
            'payload' => 'array',
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
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<DocumentVehicleContext, $this>
     */
    public function vehicleContext(): \Illuminate\Database\Eloquent\Relations\HasOne
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
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<\App\Modules\Expense\Domain\ExpenseMetadata, $this>
     */
    public function expenseMetadata(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Modules\Expense\Domain\ExpenseMetadata::class);
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
        $parent = $this->sourceDocument;
        while ($parent !== null) {
            $ancestors->prepend($parent);
            $parent = $parent->sourceDocument;
        }

        // Get all descendants recursively
        $descendants = $this->getAllDescendants();

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
     * @return Collection<int, Document>
     */
    protected function getAllDescendants(): Collection
    {
        $descendants = new Collection;

        foreach ($this->childDocuments as $child) {
            $descendants->push($child);
            $childDescendants = $child->getAllDescendants();
            foreach ($childDescendants as $descendant) {
                $descendants->push($descendant);
            }
        }

        return $descendants;
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
     * Recalculate document totals from lines
     */
    public function recalculateTotals(): void
    {
        $subtotal = '0.00';

        foreach ($this->lines as $line) {
            $lineSubtotal = bcmul($line->quantity, $line->unit_price, 2);
            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
        }

        // Use TaxCalculationService to calculate all taxes (line taxes + stamp duties)
        $taxCalculationService = app(\App\Modules\Taxation\Domain\Services\TaxCalculationService::class);
        $taxResult = $taxCalculationService->calculateDocumentTaxes($this);

        $this->update([
            'subtotal' => $subtotal,
            'line_tax_amount' => $taxResult->lineTaxAmount,
            'stamp_duty_amount' => $taxResult->stampDutyAmount,
            'tax_amount' => $taxResult->totalTaxAmount,  // Total of line_tax + stamp_duty
            'total' => $taxResult->total,
        ]);
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
    public function getOutstandingAmount(): string
    {
        $payableTypes = [
            DocumentType::Invoice,
            DocumentType::SalesOrder,
            DocumentType::PurchaseOrder,
        ];

        if (!in_array($this->type, $payableTypes, true)) {
            return '0.00';
        }

        $total = $this->total ?? '0.00';

        // Sum all payment allocations
        /** @var numeric-string $paid */
        $paid = (string) ($this->allocations()->sum('amount') ?? '0.00');

        // Sum all credit note allocations
        /** @var numeric-string $credited */
        $credited = (string) ($this->creditNoteAllocations()->sum('amount') ?? '0.00');

        // Calculate: Total - Paid - Credited
        $outstanding = bcsub(bcsub($total, $paid, 2), $credited, 2);

        return $outstanding;
    }

    /**
     * Get the payment status for this document.
     *
     * This is COMPUTED from the outstanding amount, not stored.
     * Uses getOutstandingAmount() as the source of truth.
     */
    public function getPaymentStatus(): PaymentStatus
    {
        $payableTypes = [
            DocumentType::Invoice,
            DocumentType::SalesOrder,
            DocumentType::PurchaseOrder,
        ];

        if (!in_array($this->type, $payableTypes, true)) {
            return PaymentStatus::Unpaid;
        }

        $outstanding = $this->getOutstandingAmount();
        $total = $this->total ?? '0.00';

        // Check if any payments are pending bank reconciliation
        $hasPendingPayments = $this->allocations()
            ->whereHas('payment', fn ($q) => $q->where('status', '!=', 'reconciled'))
            ->exists();

        return match (true) {
            // Overpaid: outstanding is negative
            bccomp($outstanding, '0', 2) < 0 => PaymentStatus::Overpaid,

            // Paid: outstanding is zero
            bccomp($outstanding, '0', 2) === 0 => PaymentStatus::Paid,

            // In Payment: no payments yet but some are pending reconciliation
            bccomp($outstanding, $total, 2) === 0 && $hasPendingPayments => PaymentStatus::InPayment,

            // Unpaid: outstanding equals total (no payments)
            bccomp($outstanding, $total, 2) === 0 => PaymentStatus::Unpaid,

            // Partially Paid: 0 < outstanding < total
            default => PaymentStatus::PartiallyPaid,
        };
    }

    /**
     * Get the fulfillment status for this sales document.
     *
     * Tracks delivery/shipment status based on delivery notes.
     * Only applicable to invoices and sales orders.
     *
     * @return \App\Modules\Document\Domain\Enums\FulfillmentStatus
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
            // Check if any lines require physical delivery (not services)
            $hasPhysicalProducts = $this->lines()
                ->whereHas('product', fn ($q) => $q->where('type', '!=', 'service'))
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
    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \Database\Factories\DocumentFactory::new();
    }
}

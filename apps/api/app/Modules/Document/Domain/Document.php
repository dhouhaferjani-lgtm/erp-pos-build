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
use App\Modules\Partner\Domain\Partner;
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
     * @return HasMany<Document, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Document::class, 'source_document_id')
            ->where('type', DocumentType::CreditNote);
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
     * @return array{ancestors: Collection<int, Document>, current: Document, descendants: Collection<int, Document>}
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

        return [
            'ancestors' => $ancestors,
            'current' => $this,
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
        $taxAmount = '0.00';

        foreach ($this->lines as $line) {
            $lineSubtotal = bcmul($line->quantity, $line->unit_price, 2);
            $lineTax = bcmul($lineSubtotal, bcdiv($line->tax_rate ?? '0', '100', 4), 2);

            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
            $taxAmount = bcadd($taxAmount, $lineTax, 2);
        }

        $discountAmount = $this->discount_amount ?? '0.00';
        $total = bcadd(bcsub($subtotal, $discountAmount, 2), $taxAmount, 2);

        $this->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
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
}

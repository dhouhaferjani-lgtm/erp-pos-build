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
use App\Modules\Document\Domain\Enums\FiscalAuthorityStatus;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PaymentStatus;
use App\Modules\Document\Domain\Enums\SupplierInvoiceMatchStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
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
 * @property string|null $document_number NULL while DRAFT — see requireDocumentNumber()
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
 * @property FiscalAuthorityStatus|null $fiscal_authority_status C-QR0a: NULL on every row until C-QR0b activates the dimension
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
            // C-QR0a (SPEC §1 fiscal row). Deliberately absent from `$fillable`:
            // the column is NULL on every row and only the QR acquisition service
            // of C-QR0b may ever write it, never a mass-assigned payload.
            'fiscal_authority_status' => FiscalAuthorityStatus::class,
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
     * The number this document is known by — required to exist.
     *
     * R-2 / LEDGER D-T9-1. `document_number` is NULL for a `Draft` (the number
     * is allocated on the first transition out of `Draft`, by
     * `App\Modules\Document\Domain\Services\DocumentStatusService`),
     * so every consumer that genuinely needs a number is consuming a document
     * that has already left `Draft`: a seal, a fiscal event, a conversion
     * source, a payment allocation, a chain verification.
     *
     * This method is where that expectation is CHECKED rather than assumed. A
     * null here is not a display problem to paper over with `?? ''` — it means
     * a code path reached a numbered-document operation with an unnumbered
     * draft, and the honest outcome is a loud failure before the value is
     * sealed, hashed, or emitted into an immutable event. Consumers that only
     * DISPLAY the number (a PDF filename, a report row, a picker label) must
     * NOT call this: they fall back to the draft placeholder instead.
     *
     * @throws \DomainException when the document has not been numbered yet
     */
    public function requireDocumentNumber(): string
    {
        $number = $this->document_number;

        if ($number === null) {
            throw new \DomainException(sprintf(
                'Document %s (%s, %s) has no document_number: a number is allocated on the first '
                .'transition out of Draft, and this operation requires a numbered document.',
                $this->id,
                $this->type->value,
                $this->status->value,
            ));
        }

        return $number;
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
     * Whether this document may be the source of a customer credit note.
     *
     * TWO conditions, both required (gate r1 MAJOR-6):
     *  1. it IS a customer invoice — `CreditNoteService` loads any `documents`
     *     row by id, so without this a posted delivery note, return note or
     *     supplier invoice would satisfy a helper whose NAME promises otherwise
     *     and mint a customer credit-note draft; and
     *  2. it is SEALED: Posted (still owing) OR Paid (settled — the credit note
     *     then becomes a customer credit). Draft, Confirmed and Cancelled
     *     invoices are never creditable. F-STG-4.
     */
    public function isCreditableInvoiceSource(): bool
    {
        return $this->type === DocumentType::Invoice
            && in_array($this->status, [DocumentStatus::Posted, DocumentStatus::Paid], true);
    }

    /**
     * Has this invoice already been credited in full? The ONE reading of the
     * `payload.fully_credited` flag (gate r2 NEW-1) — `RefundService` wrote the
     * same `isset(...) && === true` expression twice (`:892`, `:1242`).
     *
     * The flag is set by `RefundService::createFullCreditNote()` (`:967`) when a
     * whole invoice is credited in one document.
     */
    public function isFullyCredited(): bool
    {
        $payload = $this->payload ?? [];

        return isset($payload['fully_credited']) && $payload['fully_credited'] === true;
    }

    /**
     * May a credit note be raised against this document RIGHT NOW?
     *
     * The complete operator-facing rule, and the ONE definition behind BOTH
     * `GET /invoices/{id}/can-credit` (`RefundService::canCreditInvoice()`) and
     * the `GET /invoices?creditable=1` list filter — see
     * {@see scopeCreditableSource()}, its SQL twin.
     *
     * Gate r2 NEW-1: the list filter used to check STATUS only, so a
     * fully-credited invoice was offered in the credit-note source picker while
     * the very same API's `/can-credit` called it non-creditable and the create
     * attempt 422'd on the headroom guard
     * (`CreditNoteService::createCreditNote():845-850`). Two API surfaces
     * disagreeing about one noun (rule 22).
     *
     * NOTE the residual, deliberately not folded in here: the create-time guard
     * is arithmetic — `remainingCreditHeadroom()` sums the prior credit notes —
     * and an invoice credited to exhaustion by several PARTIAL credit notes
     * carries no `fully_credited` flag. This predicate mirrors
     * `canCreditInvoice()` exactly, so both surfaces agree; closing the
     * arithmetic gap needs a stored/derived headroom column and is its own lane.
     */
    public function isCreditableSource(): bool
    {
        return $this->isCreditableInvoiceSource() && ! $this->isFullyCredited();
    }

    /**
     * SQL twin of {@see isCreditableSource()} — the same rule, expressed for a
     * list query. Both must move together; that is the point of putting them
     * side by side.
     *
     * The JSON predicate is written as "absent OR not true" rather than
     * `NOT (flag = true)` on purpose: on both engines a missing key yields NULL,
     * and `NOT (NULL = true)` is NULL, which would silently drop every invoice
     * that has never been credited at all.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreditableSource(Builder $query): Builder
    {
        return $query
            ->where('type', DocumentType::Invoice->value)
            ->whereIn('status', [DocumentStatus::Posted->value, DocumentStatus::Paid->value])
            ->where(static function (Builder $inner): void {
                // The `false` arm goes through the underlying query builder on
                // purpose: Larastan's model-property check (phpstan.neon
                // `checkModelProperties`) types `where()`/`orWhere()`'s first
                // argument as a real column of the model, and a JSON arrow path
                // is not one. `whereNull()` has no such constraint, hence the
                // asymmetry. Same SQL either way — verified green on SQLite and
                // on PostgreSQL.
                $inner->whereNull('payload->fully_credited')
                    ->orWhere(static function (Builder $flag): void {
                        $flag->getQuery()->where('payload->fully_credited', false);
                    });
            });
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
     * Whether a RENDERING of this document is a PROFORMA — SPEC §2.4 (F-13, F-64,
     * F-95). `ProformaOutputPolicy` (Application layer — deliberately NOT imported
     * here, Domain does not depend on Application) carries the full reasoning for
     * every clause below; read it there.
     *
     * WHY THE BODY LIVES ON THE AGGREGATE (C-F0w). C-F0 shipped the predicate as an
     * Application service because its only consumer was `DocumentPdfService`, which
     * constructor-injects it. The web needs the SAME answer on every document
     * payload, and `DocumentData::fromModel()` is a STATIC factory with ~20 call
     * sites: it cannot inject anything, and threading a service through all of them
     * would leave any missed call site emitting a silent `false` — a page that
     * renders VAT on an unsealed invoice, which is the exact defect this lane
     * exists to close. Putting a dependency-free predicate where the data already
     * is makes the fail-open shape unrepresentable, and puts it next to
     * `isSealed()` / `isFiscal()`, which `DocumentData` already projects the same
     * way.
     *
     * THE POLICY REMAINS THE NAMED ENTRY POINT and now delegates here, so there is
     * still exactly ONE implementation in the system. `ProformaResourceTest`
     * asserts the resource field against a live policy call on nine document
     * shapes, so a fork would fail the suite rather than drift quietly.
     */
    public function isProformaOutput(): bool
    {
        if (! in_array($this->type, DocumentPostingService::getFiscalDocumentTypes(), true)) {
            return false;
        }

        if ($this->fiscal_hash !== null) {
            return false;
        }

        if ($this->fiscal_status === FiscalStatus::Voided
            || $this->status === DocumentStatus::Cancelled) {
            return false;
        }

        return ! ($this->isHistorical() || $this->fiscal_category === FiscalCategory::NonFiscal);
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
     * Restrict a delivery-note query to records without an invoice stamp.
     *
     * PostgreSQL compiles this JSON selector as `payload->>'invoiced_at' IS NULL`,
     * which deliberately treats an absent key and JSON null alike.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereDeliveryNoteUninvoiced(Builder $query): Builder
    {
        return $query->whereNull('payload->invoiced_at');
    }

    /**
     * Restrict a delivery-note query to records with an invoice stamp.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereDeliveryNoteInvoiced(Builder $query): Builder
    {
        return $query->whereNotNull('payload->invoiced_at');
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
     * Has this document been WITHDRAWN — cancelled, or fiscally voided?
     *
     * W-7 F-6. Two columns, because neither one alone is the whole truth and the
     * database guards neither:
     * - `status = Cancelled` is what `DocumentPostingService::cancel()` writes.
     * - `fiscal_status = Voided` is the fiscal half. The documents immutability
     *   trigger (`2025_12_11_054716_add_document_immutability_trigger.php`) returns
     *   early unless `fiscal_status = 'SEALED'`, so a VOIDED document is exactly the
     *   one the database will happily let a later write mutate.
     */
    public function isWithdrawn(): bool
    {
        return $this->status->isTerminal() || $this->fiscal_status === FiscalStatus::Voided;
    }

    /**
     * The SQL for the outstanding amount — the same invariant as
     * {@see outstandingBalance()}, expressed for the database.
     *
     * A NON-NULL `balance_due` is AUTHORITATIVE and is taken as-is. The allocation
     * formula — copied from `update_document_balance_due()`
     * (`database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php`)
     * and from `DocumentCacheValidationService` — is the FALLBACK for a NULL
     * cache. Type-agnostic, exactly like the trigger: it always joins
     * `credit_note_allocations` on `invoice_id`, so a credit note's own outward
     * allocations never reduce its balance.
     */
    private const OUTSTANDING_BALANCE_SQL = 'COALESCE(documents.balance_due, COALESCE(documents.total, 0)'
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
     * A deliberately coarse SQL pre-filter, not the verdict — callers must still
     * take the exact amount from {@see outstandingBalance()}, because SQLite
     * evaluates the fallback arithmetic in floating point and can leave a settled
     * document a hair above zero.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereOutstanding(Builder $query): Builder
    {
        return $query->whereRaw(self::OUTSTANDING_BALANCE_SQL.' > 0');
    }

    /**
     * How much of this document is still open — the ONE definition (W-6 D2).
     *
     * The invariant, in order:
     *
     * 1. **A NON-NULL `balance_due` is AUTHORITATIVE.** It has exactly two
     *    writers, and both are right:
     *    - the PostgreSQL trigger `update_document_balance_due()`, which keeps it
     *      equal to `total − Σallocations` on every allocation DML; and
     *    - `ArApOpeningService:293-313`, which writes `balance_due = open_amount`
     *      on migrated historical documents where `open_amount <= total` is an
     *      explicitly supported input (`:204-209`) and NO allocation row exists.
     *      A partially settled legacy invoice (total 1 500, open 300) is a
     *      first-class go-live shape (`PartiesBalancesPhase`), and recomputing it
     *      from allocations would report 1 500 — overstating the receivable by
     *      everything the customer paid before the migration, and letting the
     *      allocation cap accept 1 200 too much.
     * 2. **`balance_due IS NULL` is the blindness D2 is about.** The trigger only
     *    ever fires on `payment_allocations` / `credit_note_allocations` DML, so a
     *    posted document that was never allocated against has no trigger event and
     *    stays NULL forever — invisible to every consumer that filtered
     *    `balance_due > 0` (165 invoices / 59 532.410 TND on the demo tenant).
     *    Only there is the amount computed, from the trigger's own formula.
     *
     * Type-agnostic, like the trigger — unlike {@see getOutstandingAmount()}, which
     * returns `'0'` for supplier invoices — so purchase orders and supplier
     * invoices can use it too.
     *
     * Eager-load `allocations` and `creditsAgainstDocument` before calling this in
     * a loop; otherwise the NULL-cache branch lazy-loads two relations per document.
     *
     * @return numeric-string
     */
    public function outstandingBalance(int $scale): string
    {
        if ($this->balance_due !== null) {
            return CurrencyScale::bcround((string) $this->balance_due, $scale);
        }

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

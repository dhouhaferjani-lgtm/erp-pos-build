<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DraftDocumentCreated;
use App\Modules\Document\Domain\Events\DraftLineAdded;
use App\Modules\Document\Domain\Events\DraftLineAddedV2;
use App\Modules\Document\Domain\Events\DraftLineAddedV3;
use App\Modules\Document\Domain\Events\DraftLineModified;
use App\Modules\Document\Domain\Events\DraftLineModifiedV2;
use App\Modules\Document\Domain\Events\DraftLineModifiedV3;
use App\Modules\Document\Domain\Events\DraftLineRemoved;
use App\Modules\Document\Domain\Events\DraftLineRemovedV2;
use App\Modules\Document\Domain\Exceptions\DraftNotEditableException;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\ProductVariantLookup;
use App\Shared\Domain\CurrencyScale;
use App\Shared\DTOs\ProductVariantSummary;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for persisting draft documents with event sourcing.
 *
 * This service handles auto-save operations where documents can be saved
 * with incomplete or partial data. No validation is enforced - the goal
 * is to capture all user actions for fraud detection, not ensure data quality.
 *
 * Events fired:
 * - DraftDocumentCreated: When a new draft is created
 * - DraftLineAdded: When a line item is added
 * - DraftLineModified: When a line item is changed
 * - DraftLineRemoved: When a line item is removed
 */
final class DraftPersistenceService
{
    public function __construct(
        private readonly DocumentNumberingService $numberingService,
        private readonly DocumentTotalsCalculator $totalsCalculator,
        private readonly ProductVariantLookup $variantLookup,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Save a draft document (create or update).
     *
     * This is a lenient upsert that accepts partial data:
     * - No validation errors
     * - Missing fields are acceptable
     * - An EXISTING draft can be saved with 0 lines (the operator clearing the
     *   grid mid-session); a NEW one cannot — see below.
     *
     * RETURNS NULL when there is nothing to author yet (N-14). A document number
     * is spent the moment a row is created — `createNewDraft()` allocates out of
     * the `document_sequences` row that later feeds the fiscal hash chain — so
     * authoring a header for a form nobody has put a line in burns a number for
     * a document that does not exist. The campaign found
     * `PO-2026-0001 … PO-2026-0009` sitting as orphan drafts ahead of the
     * operator's real `PO-2026-0010`
     * (PLAYWRIGHT-first-tenant-campaign-wave2-imports-2026-08-24 §N-14).
     *
     * The first LINE is the trigger. This mirrors what the only real caller
     * already does — `useDraftAutoSave.ts` refuses to fire until
     * `data.lines.length > 0` — and makes it a property of the ENDPOINT rather
     * than of one bundle: the guard has to hold for a stale bundle, a retry, and
     * anything hand-driving the API.
     *
     * Deliberately NOT the full deferred-numbering design (allocate at confirm)
     * that residual R-2 describes
     * (docs/superpowers/tickets/2026-08-23-autosave-residuals.md). That one owns
     * the sequence-gap audit story, the "what does the UI show before a number
     * exists" question, and every consumer that assumes a draft's
     * `document_number` is non-null; two merge gates called it its own lane. A
     * draft that reached one line and was then abandoned still holds its number.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(
        string $tenantId,
        string $companyId,
        string $userId,
        ?string $draftId,
        array $data
    ): ?Document {
        return DB::transaction(function () use ($tenantId, $companyId, $userId, $draftId, $data) {
            // api.document.011: scope by tenant + company so a cross-tenant
            // draftId surfaces as null and a fresh draft is created instead
            // of mutating a foreign tenant's row.
            // Gate P2-4: `lockForUpdate()`, not a bare `find()`. `config/database.php`
            // pins no isolation level, so PostgreSQL's default READ COMMITTED
            // applies and an unlocked SELECT takes no row lock — auto-save could
            // read `Draft`, a concurrent session commit `confirm`, and the guard
            // below would then wave through a line-strip on a now-Confirmed
            // document. The confirm side already locks
            // (`InvoiceController::confirm()` re-fetches with `lockForUpdate()`
            // inside its transaction) and so does the draft-service precedent
            // this guard follows (`DraftPurchaseOrderService::appendLines()`);
            // auto-save was the only participant that did not, so the pair did
            // not serialise.
            $document = $draftId !== null
                ? Document::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->find($draftId)
                : null;

            if ($document === null) {
                // N-14: no line, no document, no number. See the method docblock.
                if (! $this->carriesALine($data)) {
                    return null;
                }

                // Create new draft
                $document = $this->createNewDraft($tenantId, $companyId, $userId, $data);
            } else {
                // Gate R2-1: the request's `type` must describe the document it
                // is aimed at. It is checked FIRST because it is the
                // authorization-carrying one — see assertTypeMatches().
                $this->assertTypeMatches($document, $data);

                // P1 (ticket 2026-08-22 §1): refuse anything that is no longer a
                // draft BEFORE touching its lines. `updateDraftLines()` replaces
                // the whole line set with whatever arrived, so an unguarded
                // `draft_id` pointing at a Confirmed / Posted / Cancelled
                // document was a silent line-stripper. The predicate is the
                // module's own pair (`isDraft()` + `isFiscallyImmutable()`), the
                // same one the other draft-scoped services guard on.
                $this->assertDraftEditable($document);

                // Update existing draft (lines only, header is immutable for now)
                $this->updateDraftLines($document, $companyId, $userId, $data);
            }

            return $document;
        });
    }

    /**
     * Does this payload justify authoring a document (and spending a number)?
     *
     * Only asked on the CREATE branch. `lines` absent and `lines: []` are the
     * same answer here — neither is a line — which is a narrower reading than
     * `updateDraftLines()` gives them (residual R-5), and deliberately so: on an
     * existing draft "absent" is ambiguous, on a new one there is simply nothing
     * to number.
     *
     * @param  array<string, mixed>  $data
     */
    private function carriesALine(array $data): bool
    {
        $lines = $data['lines'] ?? null;

        return is_array($lines) && $lines !== [];
    }

    /**
     * Refuse an auto-save whose `type` disagrees with the document it names.
     *
     * Gate R2-1. This is what makes the per-type `*.create` gate real on the
     * UPDATE branch. `AutoSaveDraftRequest::authorize()` resolves the required
     * ability from the CLIENT-supplied `type`, and this method is the only thing
     * that reads that field on an update — `createNewDraft()` is otherwise its
     * sole consumer. Without it, a caller holding `quotes.create` and nothing
     * else could point `draft_id` at an INVOICE draft, claim `type: quote`, pass
     * the gate, and have every line stripped; a correcting-entry draft
     * (created `Draft`/`Draft`, so the editability guard waves it through) was
     * reachable the same way.
     *
     * With the check, "authorized for the claimed type" AND "claimed type ==
     * persisted type" together mean "authorized for the actual type" — which is
     * what the CREATE branch gets for free, since there the claimed type IS the
     * new document's type. Same shape, both branches.
     *
     * LENIENT ONLY when `type` is absent, which the HTTP surface cannot produce:
     * `AutoSaveDraftRequest` declares it `required` (pinned by
     * `AutoSaveRouteHardeningTest::test_auto_save_rejects_a_missing_document_type`),
     * so every routed request carries one. The absent arm exists for the
     * in-process callers that predate this contract and legitimately omit it on
     * updates (the service's own unit tests), which are not the spoof threat
     * model — the threat is a value a client chooses.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DraftNotEditableException
     */
    private function assertTypeMatches(Document $document, array $data): void
    {
        if (! array_key_exists('type', $data)) {
            return;
        }

        $rawType = $data['type'];
        $suppliedType = is_string($rawType) ? DocumentType::tryFrom($rawType) : null;

        if ($suppliedType !== $document->type) {
            throw DraftNotEditableException::typeMismatch($suppliedType, $document->type);
        }
    }

    /**
     * Refuse an auto-save aimed at a document that has left the draft stage.
     *
     * @throws DraftNotEditableException
     */
    private function assertDraftEditable(Document $document): void
    {
        if ($document->isFiscallyImmutable()) {
            throw DraftNotEditableException::fiscallySealed();
        }

        if (! $document->isDraft()) {
            throw DraftNotEditableException::statusIsNotDraft($document->status);
        }
    }

    /**
     * Create a new draft document.
     *
     * @param  array<string, mixed>  $data
     */
    private function createNewDraft(
        string $tenantId,
        string $companyId,
        string $userId,
        array $data
    ): Document {
        $documentType = DocumentType::from($data['type']);

        // Generate document number
        $documentNumber = $this->numberingService->generateNumber(
            tenantId: $tenantId,
            companyId: $companyId,
            type: $documentType,
        );

        // Gate F1: the row must carry the COMPANY currency. It used to be
        // omitted entirely, so every auto-saved draft silently took the
        // `documents` table default `'EUR'`
        // (2025_11_30_080000_create_documents_table.php:24) — and `currency` is
        // the 4th field of the fiscal hash payload
        // (DocumentPostingService.php:484 → FiscalHashService), which posting
        // does NOT re-derive. A TND/GBP tenant that posted such a draft sealed
        // 'EUR' into the SHA-256 chain input. It also left the row internally
        // inconsistent: line scale was resolved from the real company currency
        // while the row claimed EUR.
        //
        // Same lookup + assignment as the sibling draft creator in this module,
        // DraftPurchaseOrderService.php:89 → :59.
        $company = Company::query()->whereKey($companyId)->firstOrFail();

        // Create document
        $document = Document::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'type' => $documentType,
            'status' => DocumentStatus::Draft,
            'document_number' => $documentNumber,
            'partner_id' => $data['partner_id'] ?? null,
            'document_date' => $data['document_date'] ?? now()->format('Y-m-d'),
            'due_date' => $data['due_date'] ?? null,
            'currency' => $company->currency,
            'total' => '0.00',
            'tax_amount' => '0.00',
            'subtotal' => '0.00',
            'notes' => $data['notes'] ?? null,
        ]);

        // Eager load partner before event.
        //
        // Gate P1-1: null-safe. `partner_id` is legitimately null on the
        // editor's first keystrokes — DocumentForm.tsx:263 defaults it to null
        // and :290 emits `watchedPartnerId || null`, while the debounce fires as
        // soon as one line exists (useDraftAutoSave.ts:227). A bare
        // `$partner->name` raised an ErrorException on the null relation, which
        // the controller's blanket `catch (\Throwable) → 200` turned into
        // `{"error":"silent_failure"}` with the transaction rolled back: every
        // auto-save before a partner was picked reported success and persisted
        // nothing. `DraftDocumentCreated::$partnerName` is already `?string`
        // (event constructor :26-27), so this is rule-8 clean.
        // `instanceof` narrowing rather than `$document->partner?->name`:
        // `documents.partner_id` really is nullable
        // (2026_06_27_110000_make_documents_partner_id_nullable.php) and the
        // relation really does resolve to null, but `Document`'s docblock still
        // annotates `@property string $partner_id` / `@property-read Partner
        // $partner` as non-null — so PHPStan rejects both the null-safe read
        // (`nullsafe.neverNull`) and a `partner_id !== null` guard
        // (`notIdentical.alwaysTrue`). `getRelationValue()` is honestly typed
        // `mixed`, so narrowing it is a real runtime check, not a cast to
        // silence the analyser. Correcting the annotations is the proper fix and
        // is filed as R-10 in
        // docs/superpowers/tickets/2026-08-23-autosave-residuals.md: it surfaces
        // 11 further latent null-dereferences elsewhere in this module, which is
        // a lane of its own.
        $document->load('partner');
        $partner = $document->getRelationValue('partner');
        $partnerName = $partner instanceof Partner ? $partner->name : null;
        event(new DraftDocumentCreated(
            documentId: $document->id,
            tenantId: $tenantId,
            companyId: $companyId,
            userId: $userId,
            documentType: $documentType->value,
            partnerId: $document->partner_id,
            partnerName: $partnerName,
            createdAt: now()->toIso8601String(),
        ));

        // Create lines if provided (batch operations for performance)
        if (isset($data['lines']) && is_array($data['lines']) && count($data['lines']) > 0) {
            $this->addLinesBatch($document, $companyId, $userId, $data['lines']);
        }

        // Reload only lines (partner already loaded)
        return $document->load('lines');
    }

    /**
     * Update draft lines (compare old vs new, fire appropriate events).
     *
     * @param  array<string, mixed>  $data
     */
    private function updateDraftLines(
        Document $document,
        string $companyId,
        string $userId,
        array $data
    ): void {
        $newLines = $data['lines'] ?? [];

        // Get existing line IDs
        $existingLineIds = $document->lines->pluck('id')->toArray();

        // Get new line IDs (those that have 'id' field)
        /** @var array<int, string> $newLineIds */
        $newLineIds = collect(array_values($newLines))
            ->filter(fn (array $line): bool => isset($line['id']))
            ->pluck('id')
            ->toArray();

        // Find removed lines
        $removedLineIds = array_diff($existingLineIds, $newLineIds);

        // Remove deleted lines
        foreach ($removedLineIds as $lineId) {
            $line = $document->lines->firstWhere('id', $lineId);
            if ($line !== null) {
                $this->removeLine($document, $companyId, $userId, $line);
            }
        }

        // Add or update lines
        foreach ($newLines as $lineData) {
            if (isset($lineData['id'])) {
                // Update existing line
                $line = $document->lines->firstWhere('id', $lineData['id']);
                if ($line !== null) {
                    $this->modifyLine($document, $companyId, $userId, $line, $lineData);
                }
            } else {
                // Add new line
                $this->addLine($document, $companyId, $userId, $lineData);
            }
        }

        // Recalculate totals
        $this->totalsCalculator->recalculate($document);
        $document->save();
    }

    /**
     * Add a new line to the document.
     *
     * @param  array<string, mixed>  $lineData
     */
    private function addLine(
        Document $document,
        string $companyId,
        string $userId,
        array $lineData
    ): DocumentLine {
        // api.document.012: scope Product lookup by document's tenant + company.
        /** @var Product|null $product */
        $product = isset($lineData['product_id'])
            ? Product::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('company_id', $companyId)
                ->find($lineData['product_id'])
            : null;

        // api.document.013: scope Service lookup by document's tenant + company.
        /** @var Service|null $service */
        $service = isset($lineData['service_id'])
            ? Service::query()
                ->where('tenant_id', $document->tenant_id)
                ->where('company_id', $companyId)
                ->find($lineData['service_id'])
            : null;

        $defaultName = $service !== null
            ? (string) $service->name
            : ($product !== null ? (string) $product->name : '');

        $designationSnapshot = $defaultName !== '' ? mb_substr($defaultName, 0, 500) : null;

        $overriddenDescription = mb_substr(
            isset($lineData['description']) && is_string($lineData['description']) && $lineData['description'] !== ''
                ? (string) $lineData['description']
                : $defaultName,
            0, 500
        );

        // Resolve the variant scoped to the line's product (variant must belong
        // to $product). A forged or mismatched variant_id resolves to null.
        $variant = $this->resolveVariant($lineData['variant_id'] ?? null, $product);

        $quantityStr = $this->numericStringFromInput($lineData['quantity'] ?? '1', '1');
        $unitPriceStr = $this->numericStringFromInput($lineData['unit_price'] ?? '0', '0');
        $scale = $this->scaleResolver->getScale($document->currency);
        $lineTotal = CurrencyScale::bcformat(bcmul($quantityStr, $unitPriceStr, $scale + 1), $scale);

        // api.document.045: persist the *scoped* lookup result, not the raw
        // request UUID. If the scoped lookup missed (cross-tenant or
        // cross-company), $product / $service is null — write null to the
        // foreign-key column rather than poisoning the line with an
        // attacker-supplied UUID that DocumentLine::product()/::service()
        // (unscoped belongsTo) would later dereference.
        $line = $document->lines()->create([
            'product_id' => $product?->id,
            'variant_id' => $variant?->id,
            'service_id' => $service?->id,
            'line_number' => $document->lines()->count() + 1,
            'description' => $overriddenDescription,
            'designation_default_snapshot' => $designationSnapshot,
            'notes' => isset($lineData['notes']) ? mb_substr((string) $lineData['notes'], 0, 1000) : null,
            'quantity' => $quantityStr,
            'unit_price' => $unitPriceStr,
            'tax_rate' => $lineData['tax_rate'] ?? 0,
            'line_total' => $lineTotal,
        ]);

        // Read the canonical numeric-string values once. The audit events below declare
        // float fields (immutable signatures, Rule 8), so the (float) conversion happens
        // at the event boundary on a string var — never directly on the decimal-cast
        // Eloquent property (precision contract: no (float)$model->decimalProp).
        $quantityFloat = (float) (string) $line->quantity;
        $unitPriceFloat = (float) (string) $line->unit_price;
        $lineTotalFloat = (float) (string) $line->line_total;

        // Fire event (V1 — backward compatible)
        event(new DraftLineAdded(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            productId: $line->product_id ?? '',
            productName: $defaultName,
            quantity: $quantityFloat,
            unitPrice: $unitPriceFloat,
            lineTotal: $lineTotalFloat,
            lineId: $line->id,
            addedAt: now()->toIso8601String(),
        ));

        // Fire V2 event — includes description, notes, and designation snapshot
        event(new DraftLineAddedV2(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            productId: $line->product_id ?? '',
            productName: $defaultName,
            quantity: $quantityFloat,
            unitPrice: $unitPriceFloat,
            lineTotal: $lineTotalFloat,
            description: (string) $line->description,
            notes: $line->notes,
            designationDefaultSnapshot: $line->designation_default_snapshot,
            lineId: $line->id,
            addedAt: now()->toIso8601String(),
        ));

        // Fire V3 event — adds the variant dimension (id, name, sku)
        event(new DraftLineAddedV3(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            productId: $line->product_id ?? '',
            productName: $defaultName,
            quantity: $quantityFloat,
            unitPrice: $unitPriceFloat,
            lineTotal: $lineTotalFloat,
            description: (string) $line->description,
            notes: $line->notes,
            designationDefaultSnapshot: $line->designation_default_snapshot,
            variantId: $line->variant_id,
            variantName: $variant?->nameSuffix,
            variantSku: $variant?->sku,
            lineId: $line->id,
            addedAt: now()->toIso8601String(),
        ));

        return $line;
    }

    /**
     * Modify an existing line.
     *
     * @param  array<string, mixed>  $newData
     */
    private function modifyLine(
        Document $document,
        string $companyId,
        string $userId,
        DocumentLine $line,
        array $newData
    ): void {
        $oldValues = [
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'line_total' => $line->line_total,
        ];

        $hasChanges = false;

        if (isset($newData['quantity']) && (float) $newData['quantity'] !== (float) (string) $line->quantity) {
            $line->quantity = $newData['quantity'];
            $hasChanges = true;
        }

        if (isset($newData['unit_price']) && (float) $newData['unit_price'] !== (float) (string) $line->unit_price) {
            $line->unit_price = $newData['unit_price'];
            $hasChanges = true;
        }

        $trimmedDescription = isset($newData['description']) && is_string($newData['description'])
            ? trim($newData['description'])
            : null;

        if ($trimmedDescription !== null && $trimmedDescription !== '' && $trimmedDescription !== (string) $line->description) {
            $line->description = mb_substr($trimmedDescription, 0, 500);
            $hasChanges = true;
        }

        if (array_key_exists('notes', $newData)) {
            $trimmed = $newData['notes'] !== null ? trim((string) $newData['notes']) : null;
            $normalised = ($trimmed !== null && $trimmed !== '') ? mb_substr($trimmed, 0, 1000) : null;
            if ($normalised !== $line->notes) {
                $line->notes = $normalised;
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $scale = $this->scaleResolver->getScale($document->currency);
            $line->line_total = CurrencyScale::bcformat(
                bcmul((string) $line->quantity, (string) $line->unit_price, $scale + 1),
                $scale
            );
            $line->save();

            // Audit events declare float fields; convert at the event boundary on a
            // string var, never directly on the decimal-cast Eloquent property.
            $quantityFloat = (float) (string) $line->quantity;
            $unitPriceFloat = (float) (string) $line->unit_price;
            $newValues = [
                'quantity' => $quantityFloat,
                'unit_price' => $unitPriceFloat,
                'line_total' => (float) (string) $line->line_total,
            ];

            // Fire event (V1 — backward compatible)
            event(new DraftLineModified(
                documentId: $document->id,
                tenantId: $document->tenant_id,
                companyId: $companyId,
                userId: $userId,
                lineId: $line->id,
                productId: $line->product_id ?? '',
                oldValues: $oldValues,
                newValues: $newValues,
                modifiedAt: now()->toIso8601String(),
            ));

            // Fire V2 event — includes description and notes
            event(new DraftLineModifiedV2(
                documentId: $document->id,
                tenantId: $document->tenant_id,
                companyId: $companyId,
                userId: $userId,
                lineId: $line->id,
                productId: $line->product_id ?? '',
                oldValues: $oldValues,
                newValues: $newValues,
                description: $line->description !== '' ? $line->description : null,
                notes: $line->notes,
                modifiedAt: now()->toIso8601String(),
            ));

            // Fire V3 event — adds the variant dimension. The variant is read
            // from the persisted line (modify does not re-assign the variant);
            // name/sku are resolved via the cross-module contract (Rule 6).
            $variantSummary = $line->variant_id !== null
                ? $this->variantLookup->findById($line->variant_id)
                : null;

            event(new DraftLineModifiedV3(
                documentId: $document->id,
                tenantId: $document->tenant_id,
                companyId: $companyId,
                userId: $userId,
                lineId: $line->id,
                productId: $line->product_id ?? '',
                oldValues: $oldValues,
                newValues: $newValues,
                description: $line->description !== '' ? $line->description : null,
                notes: $line->notes,
                variantId: $line->variant_id,
                variantName: $variantSummary?->nameSuffix,
                variantSku: $variantSummary?->sku,
                modifiedAt: now()->toIso8601String(),
            ));
        }
    }

    /**
     * Remove a line from the document.
     */
    private function removeLine(
        Document $document,
        string $companyId,
        string $userId,
        DocumentLine $line
    ): void {
        $product = $line->product;

        // Read the canonical numeric-string values once. The audit events below declare
        // float fields (immutable signatures, Rule 8), so the (float) conversion happens
        // at the event boundary on a string var — never directly on the decimal-cast
        // Eloquent property (precision contract: no (float)$model->decimalProp).
        $quantityFloat = (float) (string) $line->quantity;
        $lineTotalFloat = (float) (string) $line->line_total;

        // Fire event before deletion
        event(new DraftLineRemoved(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            lineId: $line->id,
            productId: $line->product_id ?? '',
            productName: $product !== null ? $product->name : '',
            quantity: $quantityFloat,
            lineTotal: $lineTotalFloat,
            removedAt: now()->toIso8601String(),
        ));

        // Fire V2 event — adds the variant dimension.
        event(new DraftLineRemovedV2(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            lineId: $line->id,
            productId: $line->product_id ?? '',
            productName: $product !== null ? $product->name : '',
            quantity: $quantityFloat,
            lineTotal: $lineTotalFloat,
            variantId: $line->variant_id,
            removedAt: now()->toIso8601String(),
        ));

        $line->delete();
    }

    /**
     * Add multiple lines in batch for performance optimization.
     *
     * This method batches:
     * - Product lookups (1 query instead of N)
     * - Line inserts (1 query instead of N)
     * - Events are still fired individually for audit trail
     *
     * @param  array<array<string, mixed>>  $linesData
     */
    private function addLinesBatch(
        Document $document,
        string $companyId,
        string $userId,
        array $linesData
    ): void {
        // 1. Batch fetch all products and services (1 query each instead of N).
        // api.document.043: scope batch Product lookup by document tenant + company.
        // The /auto-save route accepts unrestricted $request->all() with no
        // validator, so without this scope a tenant-A user can POST tenant-B
        // product UUIDs in a multi-line lines array and have foreign product
        // names persisted as designation snapshots on tenant-A draft lines.
        $productIds = collect($linesData)->pluck('product_id')->filter()->unique()->toArray();
        $products = Product::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // api.document.044: scope batch Service lookup by document tenant + company.
        $serviceIds = collect($linesData)->pluck('service_id')->filter()->unique()->toArray();
        $services = Service::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('company_id', $companyId)
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        // Fetch variants via the cross-module contract (Rule 6 — no direct
        // Catalog model access). Unique ids are resolved once and keyed by id.
        // The contract's findById does not scope by tenant/company — product-id
        // scoping below ensures a forged variant_id (mismatched product) resolves
        // to null before being written to the line.
        $variantIds = collect($linesData)->pluck('variant_id')->filter()->unique()->values()->toArray();
        /** @var array<string, ProductVariantSummary> $variantSummaries */
        $variantSummaries = [];
        foreach ($variantIds as $vid) {
            if (is_string($vid) && $vid !== '') {
                $found = $this->variantLookup->findById($vid);
                if ($found !== null) {
                    $variantSummaries[$vid] = $found;
                }
            }
        }

        // 2. Prepare line data for batch insert
        $scale = $this->scaleResolver->getScale($document->currency);
        $currentLineNumber = $document->lines()->count();
        $linesToInsert = [];
        $lineInsertData = []; // Store for event firing

        foreach ($linesData as $lineData) {
            $currentLineNumber++;
            $product = $products->get($lineData['product_id'] ?? '');
            $service = $services->get($lineData['service_id'] ?? '');

            // Resolve the variant only when it belongs to the resolved product
            // — a variant whose productId mismatches the line product is
            // dropped (treated as no variant).
            $variantId = isset($lineData['variant_id']) && is_string($lineData['variant_id']) ? $lineData['variant_id'] : '';
            $variant = $variantId !== '' ? ($variantSummaries[$variantId] ?? null) : null;
            if ($variant !== null && ($product === null || $variant->productId !== $product->id)) {
                $variant = null;
            }

            $quantityStr = $this->numericStringFromInput($lineData['quantity'] ?? '1', '1');
            $unitPriceStr = $this->numericStringFromInput($lineData['unit_price'] ?? '0', '0');
            $lineTotalStr = CurrencyScale::bcformat(bcmul($quantityStr, $unitPriceStr, $scale + 1), $scale);

            $batchDefaultName = $service !== null
                ? (string) $service->name
                : ($product !== null ? (string) $product->name : '');

            $batchDescription = mb_substr(
                isset($lineData['description']) && is_string($lineData['description']) && $lineData['description'] !== ''
                    ? (string) $lineData['description']
                    : $batchDefaultName,
                0, 500
            );

            $batchSnapshot = $batchDefaultName !== '' ? mb_substr($batchDefaultName, 0, 500) : null;

            // api.document.045: persist the *scoped* lookup result, not the raw
            // request UUID. If the scoped lookup missed (foreign tenant /
            // foreign company), $product / $service is null and the FK column
            // gets null — preventing later cross-tenant dereference via
            // DocumentLine::product() / DocumentLine::service() unscoped
            // belongsTo relations.
            $insertData = [
                'id' => (string) \Str::uuid(),
                'document_id' => $document->id,
                'product_id' => $product?->id,
                'variant_id' => $variant !== null ? $variant->id : null,
                'service_id' => $service?->id,
                'line_number' => $currentLineNumber,
                'description' => $batchDescription,
                'designation_default_snapshot' => $batchSnapshot,
                'notes' => isset($lineData['notes']) ? mb_substr((string) $lineData['notes'], 0, 1000) : null,
                'quantity' => $quantityStr,
                'unit_price' => $unitPriceStr,
                'tax_rate' => $lineData['tax_rate'] ?? 0,
                'line_total' => $lineTotalStr,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $linesToInsert[] = $insertData;
            // Audit events declare float fields (immutable signatures, Rule 8);
            // convert at the event boundary from the string values, never from
            // a float intermediate (precision contract: no float on money/qty).
            $lineInsertData[] = [
                'id' => $insertData['id'],
                'product_id' => $insertData['product_id'] ?? '',
                'product_name' => $batchDefaultName,
                'quantity' => (float) $quantityStr,
                'unit_price' => (float) $unitPriceStr,
                'line_total' => (float) $lineTotalStr,
                'description' => $batchDescription,
                'notes' => $insertData['notes'],
                'designation_default_snapshot' => $batchSnapshot,
                'variant_id' => $variant !== null ? $variant->id : null,
                'variant_name' => $variant?->nameSuffix,
                'variant_sku' => $variant?->sku,
            ];
        }

        // 3. Batch insert all lines (1 query instead of N)
        if (count($linesToInsert) > 0) {
            DocumentLine::insert($linesToInsert);
        }

        // 4. Fire events (still individual for audit trail)
        foreach ($lineInsertData as $eventData) {
            // V1 event — backward compatible
            event(new DraftLineAdded(
                documentId: $document->id,
                tenantId: $document->tenant_id,
                companyId: $companyId,
                userId: $userId,
                productId: $eventData['product_id'],
                productName: $eventData['product_name'],
                quantity: $eventData['quantity'],
                unitPrice: $eventData['unit_price'],
                lineTotal: $eventData['line_total'],
                lineId: $eventData['id'],
                addedAt: now()->toIso8601String(),
            ));

            // V2 event — includes description, notes, and designation snapshot
            event(new DraftLineAddedV2(
                documentId: $document->id,
                tenantId: $document->tenant_id,
                companyId: $companyId,
                userId: $userId,
                productId: $eventData['product_id'],
                productName: $eventData['product_name'],
                quantity: $eventData['quantity'],
                unitPrice: $eventData['unit_price'],
                lineTotal: $eventData['line_total'],
                description: $eventData['description'],
                notes: $eventData['notes'],
                designationDefaultSnapshot: $eventData['designation_default_snapshot'],
                lineId: $eventData['id'],
                addedAt: now()->toIso8601String(),
            ));

            // V3 event — adds the variant dimension (id, name, sku)
            event(new DraftLineAddedV3(
                documentId: $document->id,
                tenantId: $document->tenant_id,
                companyId: $companyId,
                userId: $userId,
                productId: $eventData['product_id'],
                productName: $eventData['product_name'],
                quantity: $eventData['quantity'],
                unitPrice: $eventData['unit_price'],
                lineTotal: $eventData['line_total'],
                description: $eventData['description'],
                notes: $eventData['notes'],
                designationDefaultSnapshot: $eventData['designation_default_snapshot'],
                variantId: $eventData['variant_id'],
                variantName: $eventData['variant_name'],
                variantSku: $eventData['variant_sku'],
                lineId: $eventData['id'],
                addedAt: now()->toIso8601String(),
            ));
        }
    }

    /**
     * Convert a raw mixed value to a numeric-string safe for bcmath operations.
     *
     * The input arrives as `mixed` from `array<string, mixed>` request data.
     * We perform one (string) cast, then validate with is_numeric(). Non-numeric
     * inputs (empty strings, garbage) fall back to $fallback. This is the single
     * float-free touch-point between untrusted array data and bcmul.
     *
     * @param  numeric-string  $fallback  Must be a literal numeric string (e.g. '1', '0')
     * @return numeric-string
     */
    private function numericStringFromInput(mixed $value, string $fallback): string
    {
        $str = (string) $value;

        if (is_numeric($str)) {
            return $str;
        }

        return $fallback;
    }

    /**
     * Resolve a request-supplied variant id to a ProductVariantSummary that belongs
     * to the resolved product. Returns null when the id is absent, the product is
     * null, or the variant does not belong to that product (forged/mismatched).
     *
     * Uses the cross-module ProductVariantLookup contract — never queries the
     * Catalog Eloquent model directly (Rule 6).
     */
    private function resolveVariant(mixed $variantId, ?Product $product): ?ProductVariantSummary
    {
        if (! is_string($variantId) || $variantId === '' || $product === null) {
            return null;
        }

        $summary = $this->variantLookup->findById($variantId);

        // Product-scoping: reject variants that belong to a different product.
        if ($summary === null || $summary->productId !== $product->id) {
            return null;
        }

        return $summary;
    }
}

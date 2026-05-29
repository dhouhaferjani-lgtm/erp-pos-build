<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
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
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
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
    ) {}

    /**
     * Save a draft document (create or update).
     *
     * This is a lenient upsert that accepts partial data:
     * - No validation errors
     * - Missing fields are acceptable
     * - Can save with 0 lines (just header)
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(
        string $tenantId,
        string $companyId,
        string $userId,
        ?string $draftId,
        array $data
    ): Document {
        return DB::transaction(function () use ($tenantId, $companyId, $userId, $draftId, $data) {
            // api.document.011: scope by tenant + company so a cross-tenant
            // draftId surfaces as null and a fresh draft is created instead
            // of mutating a foreign tenant's row.
            $document = $draftId !== null
                ? Document::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($draftId)
                : null;

            if ($document === null) {
                // Create new draft
                $document = $this->createNewDraft($tenantId, $companyId, $userId, $data);
            } else {
                // Update existing draft (lines only, header is immutable for now)
                $this->updateDraftLines($document, $companyId, $userId, $data);
            }

            return $document;
        });
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
            'total' => '0.00',
            'tax_amount' => '0.00',
            'subtotal' => '0.00',
            'notes' => $data['notes'] ?? null,
        ]);

        // Eager load partner before event
        $document->load('partner');
        $partner = $document->partner;
        $partnerName = $partner->name;
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

        $quantity = (float) ($lineData['quantity'] ?? 1);
        $unitPrice = (float) ($lineData['unit_price'] ?? 0);
        $lineTotal = (string) ($quantity * $unitPrice);

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
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $lineData['tax_rate'] ?? 0,
            'line_total' => $lineTotal,
        ]);

        // Fire event (V1 — backward compatible)
        event(new DraftLineAdded(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            productId: $line->product_id ?? '',
            productName: $defaultName,
            quantity: (float) $line->quantity,
            unitPrice: (float) $line->unit_price,
            lineTotal: (float) $line->line_total,
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
            quantity: (float) $line->quantity,
            unitPrice: (float) $line->unit_price,
            lineTotal: (float) $line->line_total,
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
            quantity: (float) $line->quantity,
            unitPrice: (float) $line->unit_price,
            lineTotal: (float) $line->line_total,
            description: (string) $line->description,
            notes: $line->notes,
            designationDefaultSnapshot: $line->designation_default_snapshot,
            variantId: $line->variant_id,
            variantName: $variant?->name_suffix,
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

        if (isset($newData['quantity']) && (float) $newData['quantity'] !== (float) $line->quantity) {
            $line->quantity = $newData['quantity'];
            $hasChanges = true;
        }

        if (isset($newData['unit_price']) && (float) $newData['unit_price'] !== (float) $line->unit_price) {
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
            $line->line_total = (string) ((float) $line->quantity * (float) $line->unit_price);
            $line->save();

            $newValues = [
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
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
            // name/sku are resolved from the variant when present.
            $variant = $line->variant_id !== null
                ? ProductVariant::query()->find($line->variant_id)
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
                variantName: $variant?->name_suffix,
                variantSku: $variant?->sku,
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

        // Fire event before deletion
        event(new DraftLineRemoved(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            lineId: $line->id,
            productId: $line->product_id ?? '',
            productName: $product !== null ? $product->name : '',
            quantity: (float) $line->quantity,
            lineTotal: (float) $line->line_total,
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
            quantity: (float) $line->quantity,
            lineTotal: (float) $line->line_total,
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

        // Batch fetch variants scoped to this document's tenant + company so a
        // forged variant_id cannot leak a foreign variant's name/sku.
        $variantIds = collect($linesData)->pluck('variant_id')->filter()->unique()->toArray();
        $variants = ProductVariant::query()
            ->where('tenant_id', $document->tenant_id)
            ->where('company_id', $companyId)
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        // 2. Prepare line data for batch insert
        $currentLineNumber = $document->lines()->count();
        $linesToInsert = [];
        $lineInsertData = []; // Store for event firing

        foreach ($linesData as $lineData) {
            $currentLineNumber++;
            $product = $products->get($lineData['product_id'] ?? '');
            $service = $services->get($lineData['service_id'] ?? '');

            // Resolve the variant only when it belongs to the resolved product
            // — a variant whose product_id mismatches the line product is
            // dropped (treated as no variant).
            $variant = $variants->get($lineData['variant_id'] ?? '');
            if ($variant !== null && ($product === null || $variant->product_id !== $product->id)) {
                $variant = null;
            }

            $quantity = (float) ($lineData['quantity'] ?? 1);
            $unitPrice = (float) ($lineData['unit_price'] ?? 0);
            $lineTotal = $quantity * $unitPrice;

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
                'variant_id' => $variant?->id,
                'service_id' => $service?->id,
                'line_number' => $currentLineNumber,
                'description' => $batchDescription,
                'designation_default_snapshot' => $batchSnapshot,
                'notes' => isset($lineData['notes']) ? mb_substr((string) $lineData['notes'], 0, 1000) : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $lineData['tax_rate'] ?? 0,
                'line_total' => (string) $lineTotal,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $linesToInsert[] = $insertData;
            $lineInsertData[] = [
                'id' => $insertData['id'],
                'product_id' => $insertData['product_id'] ?? '',
                'product_name' => $batchDefaultName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'description' => $batchDescription,
                'notes' => $insertData['notes'],
                'designation_default_snapshot' => $batchSnapshot,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name_suffix,
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
     * Resolve a request-supplied variant id to a ProductVariant that belongs to
     * the resolved product. Returns null when the id is absent, the product is
     * null, or the variant does not belong to that product (forged/mismatched).
     */
    private function resolveVariant(mixed $variantId, ?Product $product): ?ProductVariant
    {
        if (! is_string($variantId) || $variantId === '' || $product === null) {
            return null;
        }

        return ProductVariant::query()
            ->where('id', $variantId)
            ->where('product_id', $product->id)
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DraftDocumentCreated;
use App\Modules\Document\Domain\Events\DraftLineAdded;
use App\Modules\Document\Domain\Events\DraftLineModified;
use App\Modules\Document\Domain\Events\DraftLineRemoved;
use App\Modules\Product\Domain\Product;
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
            $document = $draftId !== null
                ? Document::find($draftId)
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
        $document->recalculateTotals();
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
        /** @var Product|null $product */
        $product = Product::find($lineData['product_id']);

        $quantity = (float) ($lineData['quantity'] ?? 1);
        $unitPrice = (float) ($lineData['unit_price'] ?? 0);
        $lineTotal = (string) ($quantity * $unitPrice);
        $productName = $product !== null ? $product->name : '';

        $line = $document->lines()->create([
            'product_id' => $lineData['product_id'] ?? '',
            'line_number' => $document->lines()->count() + 1,
            'description' => $productName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_rate' => $lineData['tax_rate'] ?? 0,
            'line_total' => $lineTotal,
        ]);

        // Fire event
        event(new DraftLineAdded(
            documentId: $document->id,
            tenantId: $document->tenant_id,
            companyId: $companyId,
            userId: $userId,
            productId: $line->product_id ?? '',
            productName: $productName,
            quantity: (float) $line->quantity,
            unitPrice: (float) $line->unit_price,
            lineTotal: (float) $line->line_total,
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

        if ($hasChanges) {
            $line->line_total = (string) ((float) $line->quantity * (float) $line->unit_price);
            $line->save();

            $newValues = [
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ];

            // Fire event
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
        // 1. Batch fetch all products (1 query instead of N)
        $productIds = collect($linesData)->pluck('product_id')->filter()->unique()->toArray();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // 2. Prepare line data for batch insert
        $currentLineNumber = $document->lines()->count();
        $linesToInsert = [];
        $lineInsertData = []; // Store for event firing

        foreach ($linesData as $lineData) {
            $currentLineNumber++;
            $product = $products->get($lineData['product_id'] ?? '');

            $quantity = (float) ($lineData['quantity'] ?? 1);
            $unitPrice = (float) ($lineData['unit_price'] ?? 0);
            $lineTotal = $quantity * $unitPrice;
            $batchProductName = $product !== null ? $product->name : '';

            $insertData = [
                'id' => (string) \Str::uuid(),
                'document_id' => $document->id,
                'product_id' => $lineData['product_id'] ?? '',
                'line_number' => $currentLineNumber,
                'description' => $batchProductName,
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
                'product_id' => $insertData['product_id'],
                'product_name' => $batchProductName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        // 3. Batch insert all lines (1 query instead of N)
        if (count($linesToInsert) > 0) {
            DocumentLine::insert($linesToInsert);
        }

        // 4. Fire events (still individual for audit trail)
        foreach ($lineInsertData as $eventData) {
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
        }
    }
}

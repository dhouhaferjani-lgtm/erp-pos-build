<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

use App\Modules\Document\Domain\Document;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptInput;
use App\Modules\Procurement\Application\DTOs\StandaloneReceiptLineInput;
use Illuminate\Support\Facades\DB;

final readonly class InvoiceFirstOrchestrator
{
    public function __construct(
        private StandaloneReceiptService $standaloneReceiptService,
        private CreateSupplierInvoiceService $createSupplierInvoiceService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createDelivered(array $validated, string $tenantId, string $companyId, string $actorId): Document
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $validated['lines'];

        $receiptResult = $this->standaloneReceiptService->execute(new StandaloneReceiptInput(
            companyId: $companyId,
            supplierId: (string) $validated['partner_id'],
            locationId: (string) $validated['location_id'],
            actorId: $actorId,
            idempotencyKey: (string) $validated['idempotency_key'],
            source: 'invoice_first',
            externalReference: isset($validated['external_reference']) ? (string) $validated['external_reference'] : null,
            externalDate: isset($validated['external_date']) ? (string) $validated['external_date'] : null,
            postImmediately: true,
            lines: $this->standaloneLines($lines),
        ));

        $idempotencyRow = DB::table('procurement_idempotency_keys')
            ->where('company_id', $companyId)
            ->where('idempotency_key', (string) $validated['idempotency_key'])
            ->first();
        if ($idempotencyRow !== null && $idempotencyRow->supplier_invoice_id !== null) {
            /** @var Document $existing */
            $existing = Document::query()
                ->with(['lines', 'partner', 'sourceDocument'])
                ->findOrFail((string) $idempotencyRow->supplier_invoice_id);

            return $existing;
        }

        $purchaseOrder = $receiptResult->purchaseOrder->fresh(['lines']);
        if (! $purchaseOrder instanceof Document) {
            throw new \DomainException('Invoice-first auto purchase order could not be reloaded.');
        }

        $invoiceLines = [];
        foreach (array_values($lines) as $index => $line) {
            $poLine = $purchaseOrder->lines->values()->get($index);
            if ($poLine === null) {
                throw new \DomainException('Invoice-first line mapping drifted from auto-PO line order.');
            }

            $invoiceLines[] = [
                ...$line,
                'source_line_id' => $poLine->id,
            ];
        }

        $supplierInvoice = $this->createSupplierInvoiceService->create([
            ...$validated,
            'source_document_id' => $purchaseOrder->id,
            'source_document_ids' => [$purchaseOrder->id],
            'lines' => $invoiceLines,
        ], $tenantId, $companyId);

        DB::table('procurement_idempotency_keys')
            ->where('company_id', $companyId)
            ->where('idempotency_key', (string) $validated['idempotency_key'])
            ->update([
                'supplier_invoice_id' => $supplierInvoice->id,
                'updated_at' => now(),
            ]);

        return $supplierInvoice;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<StandaloneReceiptLineInput>
     */
    private function standaloneLines(array $lines): array
    {
        return array_values(array_map(
            static fn (array $line): StandaloneReceiptLineInput => new StandaloneReceiptLineInput(
                productId: (string) $line['product_id'],
                variantId: isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                quantity: (string) $line['quantity'],
                freeQuantity: (string) ($line['free_quantity'] ?? $line['free_qty'] ?? '0.0000'),
                unitPrice: (string) $line['unit_price'],
                batch: $line['batch'] ?? null,
            ),
            $lines,
        ));
    }
}

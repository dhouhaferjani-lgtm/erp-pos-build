<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Expense\Application\Exceptions\LinkedCostException;
use App\Shared\Contracts\Document\OperationResolverInterface;

final class OperationResolver implements OperationResolverInterface
{
    /**
     * @return array{side: 'purchase'|'sales', invoice: array<string, mixed>, operations: list<array<string, mixed>>, auto_selected_id: string|null}
     */
    public function resolve(Document $invoice): array
    {
        if (! in_array($invoice->type, [DocumentType::SupplierInvoice, DocumentType::SupplierCreditNote], true)) {
            throw new LinkedCostException('INVOICE_NOT_LINKABLE', 'Only supplier invoices are linkable in Phase 1.');
        }

        /** @var Document|null $operation */
        $operation = $invoice->sourceDocument()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('company_id', $invoice->company_id)
            ->where('type', DocumentType::PurchaseOrder)
            ->first();

        if ($operation === null) {
            throw new LinkedCostException('OPERATION_NOT_FOUND', 'No purchase operation found for supplier invoice.');
        }

        $operation->loadMissing('lines');

        return [
            'side' => 'purchase',
            'invoice' => [
                'id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'currency' => $invoice->currency,
            ],
            'operations' => [$this->operationRef($operation)],
            'auto_selected_id' => $operation->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationRef(Document $operation): array
    {
        return [
            'document_id' => $operation->id,
            'kind' => 'purchase_order',
            'number' => $operation->document_number,
            'date' => $operation->document_date?->toDateString(),
            'status' => $operation->status->value,
            'received_at' => $operation->payload['goods_received_at'] ?? null,
            'line_count' => $operation->lines->count(),
            'total' => $operation->total,
            'currency' => $operation->currency,
        ];
    }
}

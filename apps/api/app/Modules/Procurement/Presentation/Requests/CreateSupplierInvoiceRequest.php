<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the POST /api/v1/supplier-invoices (create supplier invoice) request.
 *
 * Precision regex ceilings per CLAUDE.md precision contract:
 *   money   (unit_price) = /^-?\d+(\.\d{1,3})?$/
 *   quantity             = /^-?\d+(\.\d{1,4})?$/
 *   percent  (vat_rate)  = /^-?\d+(\.\d{1,2})?$/
 *
 * Cross-field validation (after basic rules):
 *   - source_document_ids must be PurchaseOrders for this company.
 *   - Each source_line_id must belong to lines of one referenced PO.
 *   - Every PO must share the request partner_id and currency.
 */
final class CreateSupplierInvoiceRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sourceDocumentIds = $this->input('source_document_ids');

        if (! is_array($sourceDocumentIds)) {
            $sourceDocumentId = $this->input('source_document_id');
            $sourceDocumentIds = is_string($sourceDocumentId) && $sourceDocumentId !== ''
                ? [$sourceDocumentId]
                : [];
        }

        $this->merge([
            'source_document_ids' => $sourceDocumentIds,
            'source_document_id' => $sourceDocumentIds[0] ?? $this->input('source_document_id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        return [
            'partner_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'source_document_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'source_document_ids' => ['required', 'array', 'min:1'],
            'source_document_ids.*' => [
                'required',
                'uuid',
                'distinct',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'currency' => ['required', 'string', 'size:3'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.source_line_id' => ['required', 'uuid'],
            'lines.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'regex:/^-?\d+(\.\d{1,4})?$/',
            ],
            'lines.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
                'regex:/^-?\d+(\.\d{1,3})?$/',
            ],
            'lines.*.vat_rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'regex:/^-?\d+(\.\d{1,2})?$/',
            ],
        ];
    }

    /**
     * Additional cross-field validation after the basic rules pass.
     *
     * Validates:
     *   1. source_document_ids reference PurchaseOrders (not another doc type).
     *   2. Every source_line_id belongs to one of those POs.
     *   3. Every PO shares the request partner_id and currency.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Skip if basic rules already failed (avoids DB queries on invalid input).
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            /** @var array<string, mixed> $data */
            $data = $v->getData();

            /** @var list<string> $sourceDocumentIds */
            $sourceDocumentIds = array_values(array_filter(
                $data['source_document_ids'] ?? [],
                static fn (mixed $id): bool => is_string($id),
            ));
            if ($sourceDocumentIds === []) {
                return;
            }

            /** @var array<string, Document> $purchaseOrders */
            $purchaseOrders = Document::query()
                ->whereIn('id', $sourceDocumentIds)
                ->get()
                ->keyBy('id')
                ->all();

            foreach ($sourceDocumentIds as $idx => $sourceDocumentId) {
                $po = $purchaseOrders[$sourceDocumentId] ?? null;
                if ($po === null || $po->type !== DocumentType::PurchaseOrder) {
                    $v->errors()->add(
                        "source_document_ids.{$idx}",
                        'The source document must be a purchase order.'
                    );
                }
            }
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            foreach ($sourceDocumentIds as $idx => $sourceDocumentId) {
                $po = $purchaseOrders[$sourceDocumentId];
                if ($po->status === DocumentStatus::Cancelled) {
                    $v->errors()->add(
                        "source_document_ids.{$idx}",
                        'Cancelled purchase orders cannot be invoiced.'
                    );
                }
            }
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $partnerId = $data['partner_id'] ?? null;
            foreach ($purchaseOrders as $po) {
                if ($partnerId !== $po->partner_id) {
                    $v->errors()->add(
                        'partner_id',
                        'The partner must match all purchase order partners.'
                    );
                    break;
                }
            }

            $currency = $data['currency'] ?? null;
            if (is_string($currency)) {
                foreach ($purchaseOrders as $po) {
                    if ($currency !== $po->currency) {
                        $v->errors()->add(
                            'currency',
                            'The invoice currency must match all purchase order currencies.'
                        );
                        break;
                    }
                }
            }

            /** @var array<int, array<string, mixed>> $lines */
            $lines = $data['lines'] ?? [];
            $poLineIds = DocumentLine::query()
                ->whereIn('document_id', $sourceDocumentIds)
                ->pluck('id')
                ->all();

            foreach ($lines as $idx => $line) {
                $sourceLineId = $line['source_line_id'] ?? null;
                if (! is_string($sourceLineId)) {
                    continue;
                }

                if (! in_array($sourceLineId, $poLineIds, true)) {
                    $v->errors()->add(
                        "lines.{$idx}.source_line_id",
                        'The source line must belong to one of the referenced purchase orders.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.quantity.regex' => 'Quantity may have at most 4 decimal places.',
            'lines.*.unit_price.regex' => 'Unit price may have at most 3 decimal places.',
            'lines.*.vat_rate.regex' => 'VAT rate may have at most 2 decimal places.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
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
 *   - source_document_id must be a PurchaseOrder for this company.
 *   - Each source_line_id must belong to lines of the referenced PO.
 *   - The PO's partner_id must match partner_id in the request body.
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
                'required',
                'uuid',
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
     *   1. source_document_id references a PurchaseOrder (not another doc type).
     *   2. Every source_line_id belongs to that PO.
     *   3. The PO's partner matches the request partner_id.
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

            $sourceDocumentId = $data['source_document_id'] ?? null;
            if (! is_string($sourceDocumentId)) {
                return;
            }

            /** @var Document|null $po */
            $po = Document::find($sourceDocumentId);

            if ($po === null || $po->type !== DocumentType::PurchaseOrder) {
                $v->errors()->add(
                    'source_document_id',
                    'The source document must be a purchase order.'
                );

                return;
            }

            // Validate partner matches PO partner.
            $partnerId = $data['partner_id'] ?? null;
            if ($partnerId !== $po->partner_id) {
                $v->errors()->add(
                    'partner_id',
                    'The partner must match the purchase order partner.'
                );
            }

            // Validate every source_line_id belongs to the referenced PO.
            /** @var array<int, array<string, mixed>> $lines */
            $lines = $data['lines'] ?? [];
            $poLineIds = DocumentLine::where('document_id', $po->id)
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
                        'The source line must belong to the referenced purchase order.'
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

<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for processing a POS receipt return.
 *
 * Validates terminal, return lines (with quantities), and return reason.
 */
final class StoreReturnRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by Gate in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();

        return [
            // api.pos-stabilization.030 — pos_terminals (T+C).
            'terminal_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('pos_terminals', $company->tenant_id, $company->id),
            ],
            'return_reason' => ['required', 'string', Rule::in(array_column(ReturnReason::cases(), 'value'))],
            'lines' => 'required|array|min:1',
            'lines.*.line_id' => 'required|uuid',
            // min matches the canonical quantity scale (4): the regex already
            // allows 4-decimal quantities, so the floor must be 0.0001 — a
            // 0.001 floor silently rejected the smallest legal partial return.
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
            'notes' => 'nullable|string|max:1000',
            // Client-supplied idempotency key — one per settlement attempt,
            // reused across retries so a replayed POST cannot double-refund.
            'refund_request_id' => ['required', 'uuid'],
            // Cashier-selectable refund destinations only. ExchangeDeferred is
            // a service-layer value reserved for ExchangeService and must never
            // be reachable over HTTP.
            'refund_destination' => [
                'nullable',
                'string',
                Rule::in([
                    RefundDestination::OriginalPayment->value,
                    RefundDestination::Cash->value,
                    RefundDestination::StoreVoucher->value,
                ]),
            ],
            'approval_id' => ['required', 'uuid'],
            'approval_fiscal_event_id' => ['required', 'uuid'],
            'approval_scope' => ['required', 'in:void_or_return_override'],
            'approval_supervisor_user_id' => ['required', 'uuid'],
            'approval_override_event_id' => ['required', 'uuid'],
            'authorized_by_user_id' => ['required', 'uuid', 'same:approval_supervisor_user_id'],
            'override_reason' => ['nullable', 'string', 'max:255'],
            // Per-line disposition + receipt-facts fields (Task 5).
            'lines.*.physical_receipt' => ['sometimes', 'boolean'],
            'lines.*.resalable' => ['nullable', 'boolean'],
            'lines.*.disposition' => ['nullable', 'string', Rule::in(ReturnLineDisposition::values())],
        ];
    }

    /**
     * Cross-field structural validation for per-line disposition + receipt facts.
     *
     * Rules enforced here (business "never" policy is in the service — Task 9):
     *   1. disposition='restock' requires physical_receipt=true AND resalable=true.
     *   2. physical_receipt=false requires resalable to be null/absent, and
     *      disposition to be null or 'not_received'.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var list<array<string, mixed>> $lines */
            $lines = $this->input('lines', []);

            foreach ($lines as $i => $line) {
                /** @var string|null $disposition */
                $disposition = isset($line['disposition']) ? (string) $line['disposition'] : null;
                $physicalReceipt = isset($line['physical_receipt']) ? (bool) $line['physical_receipt'] : null;
                $resalable = isset($line['resalable']) ? (bool) $line['resalable'] : null;

                // Rule 1: restock requires physical_receipt=true AND resalable=true.
                if ($disposition === ReturnLineDisposition::Restock->value) {
                    if ($physicalReceipt !== true) {
                        $validator->errors()->add(
                            "lines.{$i}.disposition",
                            'Disposition "restock" requires physical_receipt to be true.',
                        );
                    }
                    if ($resalable !== true) {
                        $validator->errors()->add(
                            "lines.{$i}.disposition",
                            'Disposition "restock" requires resalable to be true.',
                        );
                    }
                }

                // Rule 2: physical_receipt=false forbids non-null resalable and any
                // disposition other than null or 'not_received'.
                if ($physicalReceipt === false) {
                    if ($resalable !== null) {
                        $validator->errors()->add(
                            "lines.{$i}.resalable",
                            'resalable must be absent or null when physical_receipt is false.',
                        );
                    }
                    if ($disposition !== null && $disposition !== ReturnLineDisposition::NotReceived->value) {
                        $validator->errors()->add(
                            "lines.{$i}.disposition",
                            'When physical_receipt is false, disposition must be null or "not_received".',
                        );
                    }
                }
            }
        });
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'At least one line item is required for a return.',
            'lines.min' => 'At least one line item is required for a return.',
            'lines.*.line_id.required' => 'Each return line must reference an original receipt line.',
            'lines.*.quantity.required' => 'Each return line must have a quantity.',
            'lines.*.quantity.min' => 'Return quantity must be positive.',
            'lines.*.quantity.regex' => 'Return quantity must have at most 4 decimal places.',
            'return_reason.required' => 'A return reason is required.',
            'return_reason.in' => 'Invalid return reason.',
        ];
    }
}

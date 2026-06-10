<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Shared\Presentation\Validation\ScopedExists;
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
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'regex:/^\d+(\.\d{1,4})?$/'],
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
        ];
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

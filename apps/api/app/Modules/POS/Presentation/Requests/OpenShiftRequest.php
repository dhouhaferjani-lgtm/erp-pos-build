<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request validation for opening a shift.
 */
final class OpenShiftRequest extends FormRequest
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
        return true; // Authorization handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;
        $companyId = $company->id;

        return [
            // api.pos-stabilization.006 — pos_terminals (T+C, column=code).
            'terminal_code' => [
                'required', 'string', 'max:50',
                ScopedExists::tenantAndCompany('pos_terminals', $tenantId, $companyId, 'code'),
            ],
            'opening_cash' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            // api.pos-stabilization.033 — users (tenant only).
            'cashier_id' => [
                'nullable', 'uuid',
                ScopedExists::tenant('users', $tenantId),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terminal_code.required' => 'Terminal code is required',
            'terminal_code.exists' => 'Terminal does not exist',
            'opening_cash.required' => 'Opening cash amount is required',
            'opening_cash.numeric' => 'Opening cash must be a valid number',
            'opening_cash.min' => 'Opening cash cannot be negative',
            'opening_cash.regex' => 'Opening cash must have at most 3 decimal places',
        ];
    }
}

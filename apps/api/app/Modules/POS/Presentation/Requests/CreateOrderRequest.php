<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request validation for creating a POS order.
 */
final class CreateOrderRequest extends FormRequest
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
        return true; // Authorization handled by middleware and Gate
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
            // api.pos-stabilization.008 — scope pos_terminals by caller tenant + company.
            'terminal_id' => [
                'required', 'uuid',
                ScopedExists::tenantAndCompany('pos_terminals', $company->tenant_id, $company->id),
            ],
            // pos_shifts not in inventory; defer to a future sweep. Keep
            // bare exists so existing functionality remains intact.
            'shift_id' => ['required', 'uuid', 'exists:pos_shifts,id'],
            // Round-3 Codex Finding 4 — pos_tables has both tenant_id and
            // company_id; bare uuid validation let tenant-A reference
            // tenant-B's table id, which OrderManagementService::createOrder
            // then mutated and persisted onto the tenant-A order.
            'table_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('pos_tables', $company->tenant_id, $company->id),
            ],
            // api.pos-stabilization.009 — scope partners by caller tenant + company.
            'partner_id' => [
                'nullable', 'uuid',
                ScopedExists::tenantAndCompany('partners', $company->tenant_id, $company->id),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'consumption_mode' => ['nullable', 'string', Rule::enum(ConsumptionMode::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
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
            'terminal_id.required' => 'Terminal ID is required',
            'terminal_id.exists' => 'Terminal does not exist',
            'shift_id.required' => 'Shift ID is required',
            'shift_id.exists' => 'Shift does not exist',
            'partner_id.exists' => 'Customer does not exist',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Income\Presentation\Requests;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for income validation — mirror of ExpenseRequest.
 */
class IncomeRequest extends FormRequest
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
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'source_name' => ['nullable', 'string', 'max:255'],
            // The income "category" is a class-7 revenue GL account carrying
            // tenant_id + company_id; scope the FK validator by both, and pin it
            // to a Revenue-type account so non-income accounts cannot be selected.
            'income_account_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId)
                    ->where('type', AccountType::Revenue->value),
            ],
            'payment_method_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'payment_repository_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'payment_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            // Precision rule 19: keep `numeric` AND add the money regex ceiling
            // so no more than 3 decimal places reach the currency-scaled store.
            'total' => ['required', 'numeric', 'min:0.01', 'regex:/^-?\d+(\.\d{1,3})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_received' => ['boolean'],
            'document_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'total.regex' => 'The amount must have at most 3 decimal places.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_name' => 'source',
            'income_account_id' => 'income account',
            'payment_method_id' => 'payment method',
            'payment_repository_id' => 'payment repository',
            'payment_date' => 'payment date',
            'reference_number' => 'reference number',
            'total' => 'amount',
            'notes' => 'notes',
            'is_received' => 'received status',
            'document_date' => 'document date',
        ];
    }
}

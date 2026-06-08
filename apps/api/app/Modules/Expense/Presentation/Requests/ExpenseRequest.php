<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for expense validation.
 */
class ExpenseRequest extends FormRequest
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

        $rules = [
            'vendor_name' => ['nullable', 'string', 'max:255'],
            // api.unmapped.001 (api.accounting): expense_categories carries
            // tenant_id + company_id; scope the FK validator by both.
            'expense_category_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('expense_categories', $tenantId, $companyId),
            ],
            // api.unmapped.002 (api.accounting): payment_methods carries
            // tenant_id + company_id; scope the FK validator by both.
            'payment_method_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            // api.unmapped.003 (api.accounting): payment_repositories carries
            // tenant_id + company_id; scope the FK validator by both.
            'payment_repository_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'payment_date' => ['nullable', 'date'],
            'receipt_number' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'is_paid' => ['boolean'],
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'document_date' => ['nullable', 'date'],
        ];

        return $rules;
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
            'vendor_name' => 'vendor name',
            'expense_category_id' => 'expense category',
            'payment_method_id' => 'payment method',
            'payment_repository_id' => 'payment repository',
            'payment_date' => 'payment date',
            'receipt_number' => 'receipt number',
            'total' => 'amount',
            'notes' => 'notes',
            'internal_notes' => 'internal notes',
            'is_paid' => 'paid status',
            'status' => 'status',
            'document_date' => 'document date',
        ];
    }
}

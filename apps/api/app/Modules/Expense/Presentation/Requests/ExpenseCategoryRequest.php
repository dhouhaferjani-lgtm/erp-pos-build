<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for expense category validation.
 */
class ExpenseCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            // api.unmapped.004 (api.accounting): expense_categories carries
            // tenant_id + company_id; scope the self-reference exists by both.
            'parent_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('expense_categories', $tenantId, $companyId),
            ],
            // api.unmapped.005 (api.accounting): accounts carries tenant_id +
            // company_id; scope the FK validation by both.
            'account_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('accounts', $tenantId, $companyId),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
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
            'name' => 'category name',
            'description' => 'description',
            'parent_id' => 'parent category',
            'account_id' => 'account',
            'sort_order' => 'sort order',
            'is_active' => 'active status',
        ];
    }
}

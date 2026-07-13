<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExpenseRecurrenceRequest extends FormRequest
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

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'expense_category_id' => [
                'sometimes',
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('expense_categories', $tenantId, $companyId),
            ],
            'partner_id' => [
                'sometimes',
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'payment_method_id' => [
                'sometimes',
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'payment_repository_id' => [
                'sometimes',
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'vendor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => [$required, 'string', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'vat_rate' => ['sometimes', 'nullable', 'string', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'vat_deductible_percent' => ['sometimes', 'nullable', 'string', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'vat_amount' => ['sometimes', 'nullable', 'string', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'frequency' => [$required, Rule::enum(RecurrenceFrequency::class)],
            'start_date' => [$required, 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'lead_days' => [
                'sometimes',
                'integer',
                'min:0',
                'max:'.ExpenseRecurrenceTemplate::MAX_LEAD_DAYS,
            ],
            'status' => ['sometimes', Rule::enum(RecurrenceStatus::class)],
        ];
    }
}

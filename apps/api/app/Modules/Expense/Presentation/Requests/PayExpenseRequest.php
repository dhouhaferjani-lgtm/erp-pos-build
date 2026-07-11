<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for settling an expense — POST /expenses/{id}/pay (Wave D,
 * Task 15). No money field here (the amount settled is the expense total,
 * not user input), so no precision-scale regex ceiling is required.
 */
class PayExpenseRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
        parent::__construct();
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the `can:expenses.pay` route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $tenantId = $user->tenant_id;
        $companyId = $this->companyContext->requireCompanyId();

        return [
            'payment_repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'payment_method_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'payment_date' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payment_repository_id' => 'payment repository',
            'payment_method_id' => 'payment method',
            'payment_date' => 'payment date',
        ];
    }
}

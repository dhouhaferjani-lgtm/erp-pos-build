<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'mode' => ['sometimes', 'string', Rule::in(['cash', 'instrument'])],
            'payment_repository_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'payment_method_id' => [
                Rule::requiredIf(fn (): bool => $this->input('mode', 'cash') === 'instrument'),
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'payment_date' => ['required', 'date'],
            'instrument' => [
                Rule::requiredIf(fn (): bool => $this->input('mode', 'cash') === 'instrument'),
                'array',
            ],
            'instrument.kind' => [
                Rule::requiredIf(fn (): bool => $this->input('mode', 'cash') === 'instrument'),
                Rule::enum(InstrumentKind::class),
            ],
            'instrument.reference' => [
                Rule::requiredIf(fn (): bool => $this->input('mode', 'cash') === 'instrument'),
                'string',
                'max:100',
            ],
            'instrument.bank_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenant('banks', $tenantId),
            ],
            'instrument.maturity_date' => [
                'required_if:instrument.kind,'.InstrumentKind::Effet->value,
                'nullable',
                'date',
            ],
            'instrument.drawer_name' => ['nullable', 'string', 'max:255'],
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
            'instrument.kind' => 'instrument kind',
            'instrument.reference' => 'instrument reference',
            'instrument.bank_id' => 'instrument bank',
            'instrument.maturity_date' => 'instrument maturity date',
            'instrument.drawer_name' => 'instrument drawer name',
        ];
    }
}

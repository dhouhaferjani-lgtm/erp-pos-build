<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\User;
use App\Shared\Presentation\Validation\ScopedExists;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExpenseAnalyticsRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        $today = CarbonImmutable::today();

        $this->merge([
            'date_from' => $this->filled('date_from')
                ? $this->input('date_from')
                : $today->subMonthsNoOverflow(5)->startOfMonth()->toDateString(),
            'date_to' => $this->filled('date_to') ? $this->input('date_to') : $today->toDateString(),
            'status' => $this->filled('status') ? $this->input('status') : DocumentStatus::Posted->value,
        ]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany(
                    'expense_categories',
                    $user->tenant_id,
                    $this->companyContext->requireCompanyId(),
                ),
            ],
            'status' => ['required', Rule::enum(DocumentStatus::class)],
        ];
    }
}

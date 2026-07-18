<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Identity\Domain\User;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ExpenseRecurrenceRequest extends FormRequest
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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

    protected function prepareForValidation(): void
    {
        $hasVatAmount = $this->exists('vat_amount');
        $vatAmount = $this->input('vat_amount');
        $explicitlyVatless = $hasVatAmount
            && $this->isVatlessAmount($vatAmount);
        $storedVatless = $this->isMethod('put')
            && ! $hasVatAmount
            && $this->isVatlessAmount($this->existingTemplate()?->vat_amount);

        if (($this->isMethod('post') && ! $hasVatAmount) || $explicitlyVatless || $storedVatless) {
            $this->merge([
                'vat_amount' => null,
                'vat_rate' => null,
                'vat_deductible_percent' => null,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['amount', 'vat_amount', 'vat_rate', 'vat_deductible_percent'] as $field) {
                if ($validator->errors()->has($field)) {
                    return;
                }
            }

            $template = $this->existingTemplate();
            $amount = $this->effectiveString('amount', $template?->amount);
            $vatAmount = $this->effectiveNullableString('vat_amount', $template?->vat_amount);
            $vatRate = $this->effectiveNullableString('vat_rate', $template?->vat_rate);
            $vatDeductiblePercent = $this->effectiveNullableString(
                'vat_deductible_percent',
                $template?->vat_deductible_percent,
            );

            if ($amount === null) {
                return;
            }

            $companyCurrency = (string) $this->companyContext->requireCompany()->currency;
            $scale = $this->scaleResolver->getScale($companyCurrency);

            if ($this->amountExceedsCurrencyScale($amount, $scale)) {
                $validator->errors()->add('amount', 'Amount precision exceeds the company currency scale.');
            }

            if ($vatAmount === null) {
                return;
            }

            if ($this->amountExceedsCurrencyScale($vatAmount, $scale)) {
                $validator->errors()->add('vat_amount', 'VAT precision exceeds the company currency scale.');
            }

            if (bccomp($vatAmount, '0', $scale) === 0) {
                return;
            }

            if ($vatRate === null) {
                $validator->errors()->add('vat_rate', 'VAT rate is required when VAT amount is present.');
            }
            if ($vatDeductiblePercent === null) {
                $validator->errors()->add(
                    'vat_deductible_percent',
                    'VAT deductible percent is required when VAT amount is present.',
                );
            }
            if (bccomp($vatAmount, $amount, $scale + 1) >= 0) {
                $validator->errors()->add('vat_amount', 'VAT amount must be less than the expense total.');
            }
        });
    }

    private function existingTemplate(): ?ExpenseRecurrenceTemplate
    {
        if (! $this->isMethod('put')) {
            return null;
        }

        $id = $this->route('id');
        if (! is_string($id)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return ExpenseRecurrenceTemplate::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId())
            ->whereKey($id)
            ->first();
    }

    /** @return numeric-string|null */
    private function effectiveString(string $field, ?string $stored): ?string
    {
        $value = $this->exists($field) ? $this->input($field) : $stored;

        return is_string($value) && is_numeric($value) ? $value : null;
    }

    /** @return numeric-string|null */
    private function effectiveNullableString(string $field, ?string $stored): ?string
    {
        return $this->effectiveString($field, $stored);
    }

    private function amountExceedsCurrencyScale(string $amount, int $scale): bool
    {
        $decimalPosition = strpos($amount, '.');
        if ($decimalPosition === false) {
            return false;
        }

        $fraction = substr($amount, $decimalPosition + 1);
        $excess = substr($fraction, $scale);

        return trim($excess, '0') !== '';
    }

    private function isVatlessAmount(mixed $amount): bool
    {
        return $amount === null
            || (is_string($amount) && preg_match('/^0+(?:\.0{1,3})?$/', $amount) === 1);
    }
}

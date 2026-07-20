<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use App\Modules\Company\Services\CompanyContext;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatementProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(CompanyContext $companyContext): array
    {
        $company = $companyContext->requireCompany();
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'payment_repository_id' => [
                $required, 'uuid', ScopedExists::tenant('payment_repositories', $company->tenant_id),
            ],
            'name' => [$required, 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'parser_key' => [$required, Rule::in(['csv', 'xlsx'])],
            'column_map' => [$required, 'array'],
            'column_map.*' => ['nullable', 'string', 'max:255'],
            'date_format' => [$required, 'string', 'max:40'],
            'decimal_format' => [$required, Rule::in(['comma', 'comma_decimal', 'dot', 'dot_decimal'])],
            'direction_convention' => [$required, Rule::in(['signed_amount', 'debit_credit_columns'])],
            'header_rows' => [$required, 'integer', 'min:0', 'max:100'],
        ];
    }
}

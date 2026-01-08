<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWithholdingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: Add admin permission check
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_code' => ['required', 'string', 'size:2'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
            'partner_tax_status' => ['nullable', Rule::enum(PartnerTaxStatus::class)],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'is_company_specific' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'rate.max' => 'Rate must be a decimal between 0 and 1 (e.g., 0.05 for 5%)',
        ];
    }
}

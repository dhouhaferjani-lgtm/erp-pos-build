<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWithholdingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('taxation.withholding_rules.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
            'partner_tax_status' => ['nullable', Rule::enum(PartnerTaxStatus::class)],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

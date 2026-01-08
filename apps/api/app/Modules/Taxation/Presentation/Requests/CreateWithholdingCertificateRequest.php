<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWithholdingCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(WithholdingDirection::class)],
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'document_id' => ['nullable', 'uuid', 'exists:documents,id'],
            'payment_id' => ['nullable', 'uuid', 'exists:payments,id'],
            'currency' => ['required', 'string', 'size:3'],
            'gross_amount' => ['required', 'numeric', 'min:0'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
            'manual_rate_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'override_reason' => ['required_with:manual_rate_percentage', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'override_reason.required_with' => 'Override reason is required when specifying manual rate',
        ];
    }
}

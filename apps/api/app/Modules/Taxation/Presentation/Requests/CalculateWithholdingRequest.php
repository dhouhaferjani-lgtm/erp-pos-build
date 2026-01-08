<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use App\Modules\Taxation\Domain\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalculateWithholdingRequest extends FormRequest
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
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'transaction_type' => ['nullable', Rule::enum(TransactionType::class)],
        ];
    }
}

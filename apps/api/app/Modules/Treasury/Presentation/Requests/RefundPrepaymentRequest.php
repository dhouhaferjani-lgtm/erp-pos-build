<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RefundPrepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'repository_id' => ['required', 'uuid', 'exists:payment_repositories,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

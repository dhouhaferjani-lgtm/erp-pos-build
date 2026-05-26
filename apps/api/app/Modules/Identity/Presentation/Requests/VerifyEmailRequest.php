<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            // Tamper-proof tenant qualifier from the verification link
            // (topology r7 B1); read by the pre-auth ResolveTenancy middleware
            // before the token lookup.
            'tenant' => ['nullable', 'string'],
        ];
    }
}

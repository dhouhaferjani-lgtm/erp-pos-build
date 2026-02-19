<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mark Certificate Received Request
 *
 * Validates request to mark that customer's withholding certificate has been received.
 */
class MarkCertificateReceivedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware/policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'certificate_number' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'certificate_number.required' => 'Certificate number is required',
            'certificate_number.max' => 'Certificate number cannot exceed 100 characters',
        ];
    }
}

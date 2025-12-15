<?php

declare(strict_types=1);

namespace App\Modules\Communication\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendDocumentEmailRequest extends FormRequest
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
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'recipient_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cc_emails' => ['sometimes', 'nullable', 'array'],
            'cc_emails.*' => ['email', 'max:255'],
        ];
    }
}

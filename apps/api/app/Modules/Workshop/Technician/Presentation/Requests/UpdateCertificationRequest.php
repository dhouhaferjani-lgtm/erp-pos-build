<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workshop.technicians.manage_certifications') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'certification_name' => ['sometimes', 'string', 'max:200'],
            'issuing_body' => ['sometimes', 'nullable', 'string', 'max:200'],
            'certificate_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

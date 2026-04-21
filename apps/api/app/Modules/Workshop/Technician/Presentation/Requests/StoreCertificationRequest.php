<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCertificationRequest extends FormRequest
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
            'certification_name' => ['required', 'string', 'max:200'],
            'issuing_body' => ['nullable', 'string', 'max:200'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestIncidentAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'subject_user_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'ticket_ref' => ['required', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}

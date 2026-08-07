<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantSensitivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_sensitive' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTerminalRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'location_id' => ['sometimes', 'required', 'uuid', 'exists:locations,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'pos_software_version' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.exists' => 'The selected location does not exist.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestTerminalRequest extends FormRequest
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
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'hardware_identifier' => ['required', 'string', 'max:255'],
            'suggested_name' => ['required', 'string', 'max:255'],
        ];
    }
}

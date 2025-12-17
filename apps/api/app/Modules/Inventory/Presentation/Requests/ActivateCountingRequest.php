<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for activating a draft counting operation.
 *
 * Transitions draft to active status. Requires strict validation.
 */
class ActivateCountingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization checked in controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'activate_immediately' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes and validates a write-off reversal request.
 *
 * Reuses the existing `batches.write-off` permission (a reversal is the same
 * privileged fiscal operation as the write-off it undoes). The reversal target
 * is identified by the route's {movementId}; no body fields are required.
 */
class ReverseWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('batches.write-off') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

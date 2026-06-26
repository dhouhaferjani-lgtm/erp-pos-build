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
     * The reversal target is identified solely by the route's {movementId}; the
     * request carries no body fields. (A previous `notes` rule was dead — neither
     * the controller nor the service ever read it — so it was removed rather than
     * silently dropping client input.)
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}

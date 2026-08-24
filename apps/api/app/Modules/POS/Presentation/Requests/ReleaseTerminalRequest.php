<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Q-7 fix round, F-1 — the release override contract.
 *
 * `reason` keeps the sibling `deactivate()`'s shape (`nullable|string|max:255`)
 * so an ordinary release still reads the same, but it becomes MANDATORY the
 * moment the caller sets `force`. Forcing is the act of knowingly orphaning an
 * open fiscal shift that no server surface can close — see
 * `TerminalController::release()`'s docblock for the projection consequence on
 * the replacement device. An unexplained force leaves the audit register
 * recording that a shift was abandoned and nothing about why.
 */
final class ReleaseTerminalRequest extends FormRequest
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
            'force' => ['sometimes', 'boolean'],
            // `required_if` is an IMPLICIT rule, so it still fires when the key
            // is absent or explicitly null despite `nullable`.
            'reason' => ['nullable', 'required_if:force,true', 'string', 'max:255'],
        ];
    }
}

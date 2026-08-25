<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for `POST /api/v1/fiscal-periods/{id}/reopen` (Session B lane Q-10 (c)).
 *
 * Authorization is NOT done here: the route carries `can:fiscal-periods.reopen`, the same
 * shape the sibling VAT-period lifecycle routes use (`can:reports.manage` in
 * `app/Modules/Taxation/routes.php`). Keeping it on the route means the 403 happens before
 * the controller and before any DB read, and it stays greppable next to the endpoint.
 *
 * `reason` is REQUIRED: reversing a fiscal close is a compliance-visible act, and the
 * justification is persisted to `fiscal_periods.reopen_reason` next to `reopened_by` /
 * `reopened_at`.
 */
final class ReopenFiscalPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to reopen a closed fiscal period.',
            'reason.min' => 'The reopen reason must be at least 3 characters.',
            'reason.max' => 'The reopen reason must not exceed 500 characters.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Company\Presentation\Requests;

use App\Modules\Company\Domain\Enums\FiscalPeriodCloseRefusalCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for `POST /api/v1/fiscal-periods/{id}/close` (Session B2 lane C-24 (i)).
 *
 * Authorization is NOT done here: the route carries `can:fiscal-periods.close`, the same
 * shape as the sibling `fiscal-periods.reopen` route. Keeping it on the route means the
 * 403 happens before the controller and before any DB read, and it stays greppable next
 * to the endpoint.
 *
 * `reason` is OPTIONAL here, unlike {@see ReopenFiscalPeriodRequest} where it is required
 * and persisted. Two deliberate differences:
 *   - closing is the NORMAL end state of a period (the nightly scheduler does it without
 *     any justification at all); reversing a close is the exceptional act;
 *   - there is no `close_reason` column, and this lane ships no migration, so a supplied
 *     reason is logged by `FiscalPeriodCloseService` and nothing more. Demanding a
 *     justification the product then throws away would be dishonest.
 * When one IS supplied it must still be a usable note (3..500), the same bounds as the
 * reopen reason, so an eventual `close_reason` column can adopt the payload unchanged.
 *
 * Body validation runs BEFORE any of the five state refusals: a 422 carrying
 * `error.errors.reason` is a malformed request, a 422 carrying `error.code` is a refused
 * transition. The refusals themselves are then evaluated in the fixed precedence
 * LOCKED → NOT_OPEN → FISCAL_YEAR_CLOSED → NOT_ENDED → PREDECESSOR_OPEN
 * ({@see FiscalPeriodCloseRefusalCode}).
 */
final class CloseFiscalPeriodRequest extends FormRequest
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
            'reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'The close reason must be at least 3 characters.',
            'reason.max' => 'The close reason must not exceed 500 characters.',
        ];
    }
}
